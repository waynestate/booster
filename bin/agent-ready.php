<?php

declare(strict_types=1);

/*
 * agent-ready.php: makes a static front page readable by agents (ADR-0019).
 *
 * Same bytes in every stack repo; copy it, don't fork it. Squire owns it, and `--sync-to` (run from Squire)
 * is how a site takes a new version, together with the kit's agent-ready.js and agent-ready.css.
 *
 * From the site folder and the README it writes, into the site folder:
 *   <page>.md      the Markdown twin of every page, beside it
 *   llms.txt       the index for agents (llmstxt.org)
 *   llms-full.txt  the README and every twin in one file
 *   robots.txt     Content Signals (search=yes, ai-input=yes, ai-train=no) and the Sitemap line
 *   sitemap.xml    every page
 * and it checks what a person wrote: each page links its twin, each prompt block points at llms.txt and
 * ADVISOR, the commands in a prompt are the README's, and the kit's files are the kit's bytes.
 *
 * Usage:
 *   php bin/agent-ready.php [--root=.]                 write the files
 *   php bin/agent-ready.php [--root=.] --check         write nothing; exit 1 on drift or a finding
 *   php bin/agent-ready.php --sync-to=<repo>           from Squire only: copy the kit and this file
 *   php bin/agent-ready.php --rehash                   from Squire only: record the kit's hashes below
 *
 * Settings come from <root>/agent-ready.json, all optional: site (default "site"), readme ("README.md"),
 * base (composer.json homepage), source (composer.json support.source), exclude (page paths, relative to
 * the site folder, that get no twin and no sitemap entry).
 *
 * Exit codes: 0 pass, 1 drift or findings, 2 the tool itself could not run. Plain PHP 8.3, no Composer
 * packages, so it runs on Windows, macOS and Linux the same way. A squire reads the map so the knight
 * can ask for directions.
 */

final class AgentReadyError extends RuntimeException {}

/**
 * The kit's files, by path inside the site folder, with the SHA-256 of their bytes (LF line endings).
 * Squire's tests fail when these drift from kit/site/assets/.
 */
final class AgentReadyKit
{
    public const GENERATOR = 'Squire agent-ready';

    /** The label older releases wrote into files they generated; detection must accept it too. */
    public const LEGACY_GENERATOR = 'SQUIRE agent-ready';

    public const FILES = [
        'assets/agent-ready.css' => 'be9bf22d00b10ab07774db1abe7fb6c4c282b4b6a9a0fe91191ee0a49e83de71',
        'assets/agent-ready.js' => '17b4c83f48264f96b846102f0e0183d9317b800644e6cf6f0c8219f9960f1cb5',
    ];

    /** The stack's agent entry points, listed in every llms.txt except their own. */
    public const STACK = [
        'advisor' => ['ADVISOR', 'https://advisor.apps.wayne.edu/llms.txt', 'the stack\'s MCP server: read-only tools that answer from CREED\'s policy and the parts\' docs instead of from memory'],
        'weld' => ['WELD', 'https://weld.apps.wayne.edu/llms.txt', 'the Wayne State application stack: every part, and the reference app'],
    ];

    /** Commands in a prompt that must match the README or a page (ADR-0019: prompts are a maintained surface). */
    public const COMMAND = '/^(composer|php|vendor\/bin\/|npm|npx|pnpm|docker|git|curl)\b/';
}

/** A page of the site: where it is, where it lives on the web, and its twin. */
final class AgentReadyPage
{
    public function __construct(
        public readonly string $path,
        public readonly string $url,
        public readonly string $twinPath,
        public readonly string $twinUrl,
        public readonly string $title,
        public readonly string $description,
        public readonly DOMDocument $dom,
    ) {}
}

/** HTML to Markdown, for the pages these sites ship: headings, prose, lists, tables, code, prompt blocks. */
final class AgentReadyMarkdown
{
    private const SKIP = ['script', 'style', 'template', 'svg', 'nav', 'form', 'button', 'input', 'select',
        'textarea', 'option', 'label', 'iframe', 'canvas', 'video', 'audio', 'object', 'head', 'link', 'meta'];

    private const BLOCK = ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'pre', 'blockquote', 'table', 'dl',
        'hr', 'figure', 'section', 'article', 'div', 'header', 'footer', 'main', 'aside', 'details', 'summary',
        'figcaption', 'address', 'li', 'dt', 'dd', 'body', 'hgroup', 'fieldset', 'search'];

    /** @var list<string> the prompt texts met on the last convert() */
    public array $prompts = [];

    public function __construct(private readonly string $pageUrl, private readonly bool $withPrompts = true) {}

    public function convert(DOMNode $root): string
    {
        $this->prompts = [];
        $markdown = implode("\n\n", $this->blocks($root));
        $markdown = (string) preg_replace("/\n{3,}/", "\n\n", $markdown);

        return trim($markdown)."\n";
    }

    /** @return list<string> */
    private function blocks(DOMNode $node): array
    {
        $out = [];
        $inline = '';
        $afterElement = false;
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && $this->skipped($child)) {
                $afterElement = true;

                continue;
            }
            if ($child instanceof DOMElement && $this->isBlock($child)) {
                $this->flush($out, $inline);
                foreach ($this->block($child) as $block) {
                    $out[] = $block;
                }
                $afterElement = false;

                continue;
            }
            $inline = $this->append($inline, $this->inline($child), $afterElement && $child instanceof DOMElement);
            $afterElement = $child instanceof DOMElement;
        }
        $this->flush($out, $inline);

        return $out;
    }

    /** @param list<string> $out */
    private function flush(array &$out, string &$inline): void
    {
        $text = trim((string) preg_replace('/[ \t]+/', ' ', $inline));
        $text = (string) preg_replace('/ *\n */', "\n", $text);
        if ($text !== '') {
            $out[] = $text;
        }
        $inline = '';
    }

    /** @return list<string> */
    private function block(DOMElement $el): array
    {
        if ($el->hasAttribute('data-ar-prompt')) {
            return $this->prompt($el);
        }
        if ($el->hasAttribute('data-ar-page')) {
            return [];
        }
        if (strtolower((string) $el->getAttribute('role')) === 'tabpanel') {
            $label = $this->labelOf($el);
            $blocks = $this->blocks($el);

            return $label === '' ? $blocks : array_merge(['**'.$label.'**'], $blocks);
        }

        $tag = strtolower($el->tagName);

        return match ($tag) {
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => $this->heading((int) $tag[1], $el),
            'p', 'summary', 'figcaption', 'dt', 'address' => $this->paragraph($el, $tag === 'summary' || $tag === 'dt'),
            'ul', 'ol' => [$this->list($el)],
            'pre' => [$this->fence($this->preText($el), $this->language($el))],
            'blockquote' => [$this->quote($this->blocks($el))],
            'table' => $this->table($el),
            'dl' => [$this->definitions($el)],
            'hr' => ['---'],
            default => $this->blocks($el),
        };
    }

    /** @return list<string> */
    private function heading(int $level, DOMElement $el): array
    {
        $text = $this->squash($this->inlineChildren($el));

        return $text === '' ? [] : [str_repeat('#', $level).' '.$text];
    }

    /** @return list<string> */
    private function paragraph(DOMElement $el, bool $strong): array
    {
        $hasBlock = false;
        foreach ($el->childNodes as $child) {
            if ($child instanceof DOMElement && ! $this->skipped($child) && $this->isBlock($child)) {
                $hasBlock = true;
                break;
            }
        }
        if ($hasBlock) {
            return $this->blocks($el);
        }
        $text = $this->squash($this->inlineChildren($el));
        if ($text === '') {
            return [];
        }

        return [$strong ? '**'.$text.'**' : $text];
    }

    /** @return list<string> */
    private function prompt(DOMElement $el): array
    {
        $out = [];
        $title = $this->firstByClass($el, 'ar-prompt__title');
        if ($title !== null) {
            $level = preg_match('/^h([1-6])$/i', $title->tagName, $m) ? (int) $m[1] : 3;
            $out[] = str_repeat('#', $level).' '.$this->squash($this->inlineChildren($title));
        }
        $desc = $this->firstByClass($el, 'ar-prompt__desc');
        if ($desc !== null) {
            $out[] = $this->squash($this->inlineChildren($desc));
        }
        $text = $this->firstByClass($el, 'ar-prompt__text');
        $prompt = $text === null ? '' : self::promptText($text);
        $this->prompts[] = $prompt;
        if (! $this->withPrompts) {
            return [];
        }
        $out[] = 'A prompt for your coding agent. Copy it as it stands:';
        $out[] = $this->fence($prompt, 'text');

        return $out;
    }

    /** The prompt as the page's script copies it: without the padding the HTML may put around it. */
    public static function promptText(DOMElement $text): string
    {
        $raw = str_replace("\r\n", "\n", $text->textContent);
        $raw = (string) preg_replace('/^\s*\n/', '', $raw);

        return rtrim($raw);
    }

    private function list(DOMElement $el, int $depth = 0): string
    {
        $ordered = strtolower($el->tagName) === 'ol';
        $n = $ordered && $el->hasAttribute('start') ? (int) $el->getAttribute('start') : 1;
        $lines = [];
        foreach ($el->childNodes as $li) {
            if (! $li instanceof DOMElement || strtolower($li->tagName) !== 'li' || $this->skipped($li)) {
                continue;
            }
            $marker = $ordered ? ($n++).'. ' : '- ';
            $pad = str_repeat(' ', strlen($marker));
            $parts = [];
            $inline = '';
            foreach ($li->childNodes as $child) {
                if ($child instanceof DOMElement && $this->skipped($child)) {
                    continue;
                }
                if ($child instanceof DOMElement && in_array(strtolower($child->tagName), ['ul', 'ol'], true)) {
                    $this->flush($parts, $inline);
                    $parts[] = $this->list($child, $depth + 1);

                    continue;
                }
                if ($child instanceof DOMElement && $this->isBlock($child)) {
                    $this->flush($parts, $inline);
                    foreach ($this->block($child) as $block) {
                        $parts[] = $block;
                    }

                    continue;
                }
                $inline .= $this->inline($child);
            }
            $this->flush($parts, $inline);
            if ($parts === []) {
                continue;
            }
            $item = implode("\n", $parts);
            $item = str_replace("\n", "\n".$pad, $item);
            $lines[] = $marker.$item;
        }

        return implode("\n", $lines);
    }

    /** @return list<string> */
    private function table(DOMElement $el): array
    {
        $rows = [];
        $caption = '';
        foreach ($this->descendants($el, ['caption', 'tr']) as $node) {
            if (strtolower($node->tagName) === 'caption') {
                $caption = $this->squash($this->inlineChildren($node));

                continue;
            }
            $cells = [];
            foreach ($node->childNodes as $cell) {
                if ($cell instanceof DOMElement && in_array(strtolower($cell->tagName), ['th', 'td'], true)) {
                    $text = $this->squash($this->inlineChildren($cell));
                    $cells[] = str_replace('|', '\\|', $text);
                }
            }
            if ($cells !== []) {
                $rows[] = $cells;
            }
        }
        if ($rows === []) {
            return [];
        }
        $width = max(array_map('count', $rows));
        $lines = [];
        foreach ($rows as $i => $cells) {
            $cells = array_pad($cells, $width, '');
            $lines[] = '| '.implode(' | ', $cells).' |';
            if ($i === 0) {
                $lines[] = '|'.str_repeat(' --- |', $width);
            }
        }
        $out = [];
        if ($caption !== '') {
            $out[] = '**'.$caption.'**';
        }
        $out[] = implode("\n", $lines);

        return $out;
    }

    private function definitions(DOMElement $el): string
    {
        $lines = [];
        $term = null;
        foreach ($this->descendants($el, ['dt', 'dd']) as $node) {
            $text = $this->squash($this->inlineChildren($node));
            if (strtolower($node->tagName) === 'dt') {
                if ($term !== null) {
                    $lines[] = '- **'.$term.'**';
                }
                $term = $text;

                continue;
            }
            $lines[] = $term === null ? '- '.$text : '- **'.$term.'**: '.$text;
            $term = null;
        }
        if ($term !== null) {
            $lines[] = '- **'.$term.'**';
        }

        return implode("\n", $lines);
    }

    /** @param list<string> $blocks */
    private function quote(array $blocks): string
    {
        $text = implode("\n\n", $blocks);

        return '> '.str_replace("\n", "\n> ", $text);
    }

    private function fence(string $text, string $language): string
    {
        preg_match_all('/`+/', $text, $runs);
        $longest = 0;
        foreach ($runs[0] as $run) {
            $longest = max($longest, strlen($run));
        }
        $fence = str_repeat('`', max(3, $longest + 1));

        return $fence.$language."\n".$text."\n".$fence;
    }

    private function preText(DOMElement $el): string
    {
        return rtrim(str_replace("\r\n", "\n", $el->textContent), "\n ");
    }

    private function language(DOMElement $el): string
    {
        foreach ([$el, $this->firstChildElement($el)] as $node) {
            if ($node !== null && preg_match('/\blanguage-([a-z0-9+-]+)/i', (string) $node->getAttribute('class'), $m)) {
                return strtolower($m[1]);
            }
        }

        return '';
    }

    private function inlineChildren(DOMNode $node): string
    {
        $out = '';
        $afterElement = false;
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && $this->skipped($child)) {
                $afterElement = true;

                continue;
            }
            $out = $this->append($out, $this->inline($child), $afterElement && $child instanceof DOMElement);
            $afterElement = $child instanceof DOMElement;
        }

        return $out;
    }

    /**
     * Two elements side by side with no space between them (a number and its title in a contents
     * line, styled apart) are two words to a reader, so they get a space here. Punctuation that
     * follows stays attached.
     */
    private function append(string $text, string $next, bool $gap): string
    {
        if ($gap && $text !== '' && $next !== ''
            && ! ctype_space($text[strlen($text) - 1])
            && ! preg_match('/^[\s.,;:!?)\]]/', $next)
        ) {
            return $text.' '.$next;
        }

        return $text.$next;
    }

    private function inline(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return $this->escape((string) preg_replace('/\s+/', ' ', $node->data));
        }
        if (! $node instanceof DOMElement || $this->skipped($node)) {
            return '';
        }
        $tag = strtolower($node->tagName);
        switch ($tag) {
            case 'br':
                return "\n";
            case 'code':
            case 'kbd':
            case 'samp':
                return $this->code((string) preg_replace('/\s+/', ' ', $node->textContent));
            case 'strong':
            case 'b':
                return $this->wrap('**', $this->inlineChildren($node));
            case 'em':
            case 'i':
            case 'cite':
                return $this->wrap('*', $this->inlineChildren($node));
            case 'del':
            case 's':
                return $this->wrap('~~', $this->inlineChildren($node));
            case 'img':
                $alt = trim($node->getAttribute('alt'));

                return $alt === '' ? '' : '!['.$this->escape($alt).']('.$this->resolve($node->getAttribute('src')).')';
            case 'a':
                $text = $this->squash($this->inlineChildren($node));
                $href = $node->getAttribute('href');
                if ($text === '' || $href === '') {
                    return $text;
                }

                return '['.$text.']('.$this->resolve($href).')';
        }
        $text = $this->inlineChildren($node);

        return $this->isBlock($node) ? ' '.$text.' ' : $text;
    }

    private function wrap(string $mark, string $text): string
    {
        $inner = trim($text);
        if ($inner === '') {
            return $text;
        }
        $lead = ctype_space($text[0]) ? ' ' : '';
        $tail = ctype_space($text[strlen($text) - 1]) ? ' ' : '';

        return $lead.$mark.$inner.$mark.$tail;
    }

    private function code(string $text): string
    {
        if (trim($text) === '') {
            return $text;
        }
        preg_match_all('/`+/', $text, $runs);
        $longest = 0;
        foreach ($runs[0] as $run) {
            $longest = max($longest, strlen($run));
        }
        $tick = str_repeat('`', $longest + 1);
        $pad = $longest > 0 ? ' ' : '';

        return $tick.$pad.$text.$pad.$tick;
    }

    private function escape(string $text): string
    {
        // An underscore inside a word never starts emphasis, so security_until stays as written.
        $text = (string) preg_replace('/([\\\\`*\[\]<])/', '\\\\$1', $text);

        return (string) preg_replace('/(?<![\p{L}\p{N}])_|_(?![\p{L}\p{N}])/u', '\\\\_', $text);
    }

    private function squash(string $text): string
    {
        $text = (string) preg_replace('/[ \t]+/', ' ', $text);

        return trim((string) preg_replace('/ *\n */', "\n", $text));
    }

    public function resolve(string $href): string
    {
        $href = trim($href);
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $href)) {
            return $href;
        }
        $parts = parse_url($this->pageUrl);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');
        if (str_starts_with($href, '//')) {
            return ($parts['scheme'] ?? 'https').':'.$href;
        }
        if (str_starts_with($href, '#') || str_starts_with($href, '?')) {
            return (string) preg_replace('/[?#].*$/', '', $this->pageUrl).$href;
        }
        $suffix = '';
        if (preg_match('/^([^?#]*)([?#].*)$/', $href, $m)) {
            $href = $m[1];
            $suffix = $m[2];
        }
        $dir = str_starts_with($href, '/') ? '' : (string) preg_replace('#[^/]*$#', '', $parts['path'] ?? '/');
        $segments = [];
        foreach (explode('/', $dir.$href) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '.') {
                $segments[] = $segment;
            }
        }
        $path = implode('/', $segments);
        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        return $origin.$path.$suffix;
    }

    private function labelOf(DOMElement $el): string
    {
        $id = $el->getAttribute('aria-labelledby');
        if ($id !== '' && $el->ownerDocument !== null) {
            $label = $el->ownerDocument->getElementById($id);
            if ($label !== null) {
                return $this->squash($label->textContent);
            }
        }

        return $this->squash($el->getAttribute('aria-label'));
    }

    private function skipped(DOMElement $el): bool
    {
        return in_array(strtolower($el->tagName), self::SKIP, true)
            || $el->getAttribute('aria-hidden') === 'true'
            || $el->hasAttribute('data-ar-skip')
            || strtolower($el->getAttribute('role')) === 'tablist'
            || strtolower($el->getAttribute('role')) === 'status';
    }

    private function isBlock(DOMElement $el): bool
    {
        return in_array(strtolower($el->tagName), self::BLOCK, true);
    }

    private function firstByClass(DOMElement $el, string $class): ?DOMElement
    {
        foreach ($el->getElementsByTagName('*') as $node) {
            if (in_array($class, preg_split('/\s+/', $node->getAttribute('class')) ?: [], true)) {
                return $node;
            }
        }

        return null;
    }

    private function firstChildElement(DOMElement $el): ?DOMElement
    {
        foreach ($el->childNodes as $child) {
            if ($child instanceof DOMElement) {
                return $child;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $tags
     * @return list<DOMElement>
     */
    private function descendants(DOMElement $el, array $tags): array
    {
        $out = [];
        foreach ($el->getElementsByTagName('*') as $node) {
            if (in_array(strtolower($node->tagName), $tags, true)) {
                $out[] = $node;
            }
        }

        return $out;
    }
}

final class AgentReady
{
    private string $site;

    private string $readme;

    private string $base;

    private string $source;

    /** @var list<string> */
    private array $exclude;

    /** @var list<string> */
    public array $findings = [];

    public function __construct(private readonly string $root)
    {
        if (! is_dir($root)) {
            throw new AgentReadyError("The folder {$root} does not exist.");
        }
        $config = $this->json($root.'/agent-ready.json', false);
        $composer = $this->json($root.'/composer.json', false);
        $site = is_string($config['site'] ?? null) ? $config['site'] : 'site';
        $this->site = rtrim($root.'/'.$site, '/');
        if (! is_dir($this->site)) {
            throw new AgentReadyError("The site folder {$this->site} does not exist.");
        }
        $this->readme = $root.'/'.(is_string($config['readme'] ?? null) ? $config['readme'] : 'README.md');
        if (! is_file($this->readme)) {
            throw new AgentReadyError("The README {$this->readme} does not exist.");
        }
        $support = is_array($composer['support'] ?? null) ? $composer['support'] : [];
        $base = $config['base'] ?? $composer['homepage'] ?? null;
        if (! is_string($base) || ! preg_match('#^https://[^/]+/?$#', $base)) {
            throw new AgentReadyError('No base URL: set "base" in agent-ready.json or "homepage" in composer.json to https://<host>/.');
        }
        $this->base = rtrim($base, '/').'/';
        $source = $config['source'] ?? $support['source'] ?? '';
        $this->source = is_string($source) ? rtrim($source, '/') : '';
        $exclude = is_array($config['exclude'] ?? null) ? $config['exclude'] : [];
        $this->exclude = array_values(array_filter($exclude, 'is_string'));
    }

    public function siteDir(): string
    {
        return $this->site;
    }

    /**
     * Every file this tool owns, by path relative to the site folder, with its content.
     *
     * @return array<string, string>
     */
    public function build(): array
    {
        $this->findings = [];
        $pages = $this->pages();
        if ($pages === []) {
            throw new AgentReadyError("No pages (*.html) in {$this->site}.");
        }

        $files = [];
        $reference = $this->normalise((string) file_get_contents($this->readme));
        $prompts = [];
        foreach ($pages as $page) {
            $body = $this->body($page->dom);
            $md = new AgentReadyMarkdown($page->url);
            $twin = $md->convert($body);
            if (! preg_match('/^# /m', $twin)) {
                $twin = '# '.$page->title."\n\n".$twin;
            }
            $files[$page->twinPath] = $this->frontMatter($page).$twin;
            $plain = new AgentReadyMarkdown($page->url, false);
            $reference .= "\n".$plain->convert($body);
            foreach ($md->prompts as $prompt) {
                $prompts[] = [$page, $prompt];
            }
            $this->checkPage($page, $md->prompts);
        }
        foreach ($prompts as [$page, $prompt]) {
            $this->checkPrompt($page, $prompt, $reference);
        }
        $this->checkKit();

        $files['llms.txt'] = $this->llms($pages);
        $files['llms-full.txt'] = $this->llmsFull($pages, $files);
        $files['robots.txt'] = $this->robots();
        $files['sitemap.xml'] = $this->sitemap($pages);

        return $files;
    }

    /**
     * Twins this tool wrote earlier whose page has gone.
     *
     * @param  array<string, string>  $files
     * @return list<string>
     */
    public function leftovers(array $files): array
    {
        $out = [];
        foreach ($this->walk($this->site, 'md') as $relative) {
            if (isset($files[$relative])) {
                continue;
            }
            $head = (string) file_get_contents($this->site.'/'.$relative, false, null, 0, 600);
            if (str_contains($head, 'generator: '.AgentReadyKit::GENERATOR)
                || str_contains($head, 'generator: '.AgentReadyKit::LEGACY_GENERATOR)
            ) {
                $out[] = $relative;
            }
        }

        return $out;
    }

    /** True when a file on disk was not written by this tool, so it must not be overwritten. */
    public function foreign(string $relative): bool
    {
        $path = $this->site.'/'.$relative;
        if (! is_file($path)) {
            return false;
        }
        $content = (string) file_get_contents($path);

        return ! str_contains($content, AgentReadyKit::GENERATOR)
            && ! str_contains($content, AgentReadyKit::LEGACY_GENERATOR)
            && ! str_contains($content, "Squire's agent-ready kit")
            && ! str_contains($content, "SQUIRE's agent-ready kit");
    }

    /** @return list<AgentReadyPage> */
    private function pages(): array
    {
        $pages = [];
        foreach ($this->walk($this->site, 'html') as $relative) {
            if (in_array($relative, $this->exclude, true)) {
                continue;
            }
            $html = $this->normalise((string) file_get_contents($this->site.'/'.$relative));
            $dom = new DOMDocument;
            $previous = libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            if ($this->noindex($dom)) {
                continue;
            }
            $path = $relative === 'index.html' ? '' : (string) preg_replace('#(^|/)index\.html$#', '$1', $relative);
            $twinPath = substr($relative, 0, -5).'.md';
            $pages[] = new AgentReadyPage(
                $relative,
                $this->base.$path,
                $twinPath,
                $this->base.$twinPath,
                $this->text($dom, 'title'),
                $this->meta($dom, 'description'),
                $dom,
            );
        }
        // The front page first, then the rest in path order, so every list reads the same on every OS.
        usort($pages, static fn (AgentReadyPage $a, AgentReadyPage $b): int => [$a->path !== 'index.html', $a->path] <=> [$b->path !== 'index.html', $b->path]);

        return $pages;
    }

    /** @return list<string> paths relative to $dir, with forward slashes, sorted */
    private function walk(string $dir, string $extension): array
    {
        $skip = ['assets', 'brand', 'screenshots', 'vendor', 'node_modules'];
        $out = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                static function (SplFileInfo $file, string $key, RecursiveDirectoryIterator $iterator) use ($skip, $dir): bool {
                    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
                    if (str_starts_with($file->getFilename(), '.')) {
                        return false;
                    }
                    if ($iterator->hasChildren()) {
                        return ! in_array($relative, $skip, true);
                    }

                    return true;
                },
            ),
        );
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && strtolower($file->getExtension()) === $extension) {
                $out[] = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
            }
        }
        sort($out);

        return $out;
    }

    private function body(DOMDocument $dom): DOMNode
    {
        $main = $dom->getElementsByTagName('main')->item(0);
        if ($main !== null) {
            return $main;
        }

        return $dom->getElementsByTagName('body')->item(0) ?? $dom;
    }

    private function frontMatter(AgentReadyPage $page): string
    {
        $q = static fn (string $v): string => '"'.addcslashes($v, '"\\').'"';

        return "---\n"
            .'title: '.$q($page->title)."\n"
            .($page->description !== '' ? 'description: '.$q($page->description)."\n" : '')
            .'url: '.$page->url."\n"
            .'llms: '.$this->base."llms.txt\n"
            .'generator: '.AgentReadyKit::GENERATOR."\n"
            ."---\n\n";
    }

    /** @param list<string> $prompts */
    private function checkPage(AgentReadyPage $page, array $prompts): void
    {
        $want = basename($page->twinPath);
        $found = false;
        foreach ($page->dom->getElementsByTagName('link') as $link) {
            if (strtolower($link->getAttribute('rel')) === 'alternate'
                && strtolower($link->getAttribute('type')) === 'text/markdown'
            ) {
                $found = true;
                if ($link->getAttribute('href') !== $want) {
                    $this->findings[] = "{$page->path}: the Markdown link points at \"{$link->getAttribute('href')}\"; it should be \"{$want}\".";
                }
            }
        }
        if (! $found) {
            $this->findings[] = "{$page->path}: add <link rel=\"alternate\" type=\"text/markdown\" href=\"{$want}\"> to the head.";
        }
        if ($page->path === 'index.html') {
            $started = false;
            foreach ($page->dom->getElementsByTagName('*') as $node) {
                if ($node->hasAttribute('data-ar-prompt')) {
                    foreach ($node->getElementsByTagName('*') as $inner) {
                        if (str_contains(' '.$inner->getAttribute('class').' ', ' ar-prompt__title ')
                            && str_starts_with(trim($inner->textContent), 'Get started with ')
                        ) {
                            $started = true;
                        }
                    }
                }
            }
            if (! $started) {
                $this->findings[] = 'index.html: the front page needs a "Get started with <NAME>" prompt block (ADR-0019).';
            }
        }
        if ($prompts !== []) {
            $this->needsKit($page);
        }
    }

    private function needsKit(AgentReadyPage $page): void
    {
        $depth = substr_count($page->path, '/');
        $prefix = str_repeat('../', $depth);
        $script = false;
        $sheet = false;
        foreach ($page->dom->getElementsByTagName('script') as $node) {
            $script = $script || $node->getAttribute('src') === $prefix.'assets/agent-ready.js';
        }
        foreach ($page->dom->getElementsByTagName('link') as $node) {
            $sheet = $sheet || $node->getAttribute('href') === $prefix.'assets/agent-ready.css';
        }
        if (! $script) {
            $this->findings[] = "{$page->path}: has a prompt block but does not load {$prefix}assets/agent-ready.js.";
        }
        if (! $sheet) {
            $this->findings[] = "{$page->path}: has a prompt block but does not load {$prefix}assets/agent-ready.css.";
        }
    }

    private function checkPrompt(AgentReadyPage $page, string $prompt, string $reference): void
    {
        $first = strtok($prompt, "\n");
        $first = $first === false ? '' : $first;
        $name = $page->path.': prompt "'.(strlen($first) > 48 ? substr($first, 0, 45).'...' : $first).'"';
        if ($prompt === '') {
            $this->findings[] = "{$name} is empty.";

            return;
        }
        // Windows PowerShell 5.1 hands a double quote inside an argument to the program unescaped, so the
        // ready commands would arrive cut short there; tabs set off completion when pasted into an old bash.
        if (str_contains($prompt, '"')) {
            $this->findings[] = "{$name} contains a double quote; use single quotes or backticks so the PowerShell command survives Windows PowerShell 5.1.";
        }
        if (str_contains($prompt, "\t")) {
            $this->findings[] = "{$name} contains a tab; indent with spaces so a paste into bash does not trigger completion.";
        }
        if (! str_contains($prompt, $this->base.'llms.txt')) {
            $this->findings[] = "{$name} does not point at {$this->base}llms.txt.";
        }
        if (! str_contains($prompt, 'ADVISOR')) {
            $this->findings[] = "{$name} does not point at ADVISOR.";
        }
        preg_match_all('/`([^`\n]+)`/', $prompt, $spans);
        foreach ($spans[1] as $span) {
            if (preg_match(AgentReadyKit::COMMAND, $span) && ! str_contains($reference, $span)) {
                $this->findings[] = "{$name} runs `{$span}`, which is not in the README or on a page. Prompts must match the docs.";
            }
        }
    }

    private function checkKit(): void
    {
        foreach (AgentReadyKit::FILES as $relative => $hash) {
            $path = $this->site.'/'.$relative;
            if (! is_file($path)) {
                $this->findings[] = "{$relative} is missing. Copy the kit with Squire: php bin/agent-ready.php --sync-to=<this repo>.";

                continue;
            }
            if (hash('sha256', $this->normalise((string) file_get_contents($path))) !== $hash) {
                $this->findings[] = "{$relative} differs from Squire's kit. Never edit it here; change the kit in Squire and sync.";
            }
        }
    }

    /** @param list<AgentReadyPage> $pages */
    private function llms(array $pages): string
    {
        $readme = $this->readmeParts();
        $front = $pages[0];
        $out = '# '.$readme['name']."\n\n";
        if ($front->description !== '') {
            $out .= '> '.$front->description."\n\n";
        }
        if ($readme['intro'] !== '') {
            $out .= $readme['intro']."\n\n";
        }
        $out .= 'Every page below is Markdown. The HTML pages send the same Markdown to a client that asks for '
            ."`text/markdown`. Written by Squire's agent-ready kit from the README and the pages; do not edit.\n\n";

        $out .= "## Pages\n\n";
        foreach ($pages as $page) {
            $out .= '- ['.$page->title.']('.$page->twinUrl.')'.($page->description !== '' ? ': '.$page->description : '')."\n";
        }

        $promptLines = '';
        foreach ($pages as $page) {
            foreach ($page->dom->getElementsByTagName('*') as $node) {
                if (! $node->hasAttribute('data-ar-prompt')) {
                    continue;
                }
                $title = '';
                $desc = '';
                foreach ($node->getElementsByTagName('*') as $inner) {
                    $class = ' '.$inner->getAttribute('class').' ';
                    if ($title === '' && str_contains($class, ' ar-prompt__title ')) {
                        $title = trim((string) preg_replace('/\s+/', ' ', $inner->textContent));
                    }
                    if ($desc === '' && str_contains($class, ' ar-prompt__desc ')) {
                        $desc = trim((string) preg_replace('/\s+/', ' ', $inner->textContent));
                    }
                }
                $anchor = $node->getAttribute('id');
                $promptLines .= '- ['.$title.']('.$page->url.($anchor !== '' ? '#'.$anchor : '').')'
                    .($desc !== '' ? ': '.$desc : '').' The prompt itself is in '.$page->twinUrl.".\n";
            }
        }
        if ($promptLines !== '') {
            $out .= "\n## Prompts\n\n".$promptLines;
        }

        $out .= "\n## Source\n\n";
        if ($this->source !== '') {
            $out .= '- [README]('.$this->source.'/blob/HEAD/'.basename($this->readme).'): the project\'s README, with install, commands and checks'."\n";
        }
        $out .= '- [Everything in one file]('.$this->base.'llms-full.txt): the README and every page above, as one Markdown file'."\n";

        $self = (string) preg_replace('#^https://([^.]+)\..*$#', '$1', $this->base);
        $optional = '';
        foreach (AgentReadyKit::STACK as $key => [$title, $url, $note]) {
            if ($key !== $self) {
                $optional .= '- ['.$title.']('.$url.'): '.$note."\n";
            }
        }
        if ($optional !== '') {
            $out .= "\n## Optional\n\n".$optional;
        }

        return $out;
    }

    /**
     * @param  list<AgentReadyPage>  $pages
     * @param  array<string, string>  $files
     */
    private function llmsFull(array $pages, array $files): string
    {
        $readme = $this->readmeParts();
        $out = '# '.$readme['name'].": everything in one file\n\n";
        if ($pages[0]->description !== '') {
            $out .= '> '.$pages[0]->description."\n\n";
        }
        $out .= 'The README and the Markdown twin of every page, joined. The index is '.$this->base.'llms.txt. '
            ."Written by Squire's agent-ready kit; do not edit.\n";
        $out .= "\n---\n\n## README\n\n";
        if ($this->source !== '') {
            $out .= 'Source: '.$this->source.'/blob/HEAD/'.basename($this->readme)."\n\n";
        }
        $out .= $this->demote($readme['body'])."\n";
        foreach ($pages as $page) {
            $twin = $files[$page->twinPath] ?? '';
            $twin = (string) preg_replace('/\A---\n.*?\n---\n\n/s', '', $twin);
            $out .= "\n---\n\nSource: ".$page->url."\n\n".$this->demote(trim($twin))."\n";
        }

        return $out;
    }

    private function robots(): string
    {
        return '# robots.txt for '.$this->base.", written by Squire's agent-ready kit (ADR-0019); do not edit.\n"
            ."# Content Signals (contentsignals.org): find it and answer from it, yes; train on it, no.\n"
            ."User-agent: *\n"
            ."Content-Signal: search=yes, ai-input=yes, ai-train=no\n"
            ."Allow: /\n"
            ."\n"
            .'Sitemap: '.$this->base."sitemap.xml\n";
    }

    /** @param list<AgentReadyPage> $pages */
    private function sitemap(array $pages): string
    {
        $out = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            ."<!-- Written by Squire's agent-ready kit from the pages in the site folder; do not edit. -->\n"
            ."<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
        foreach ($pages as $page) {
            $out .= '  <url><loc>'.htmlspecialchars($page->url, ENT_XML1)."</loc></url>\n";
        }

        return $out."</urlset>\n";
    }

    /** @return array{name: string, intro: string, body: string} */
    private function readmeParts(): array
    {
        $raw = $this->normalise((string) file_get_contents($this->readme));
        $clean = $this->cleanReadme($raw);
        $name = preg_match('/^# (.+)$/m', $clean, $m) ? trim($m[1]) : basename($this->root);
        $intro = '';
        if (preg_match('/^# [^\n]+\n(.*?)(?=^## |\z)/ms', $clean, $m)) {
            $intro = trim($m[1]);
        }

        return ['name' => $name, 'intro' => $intro, 'body' => trim($clean)];
    }

    /** The README without its pictures and HTML: badges, galleries and the cast are for people. */
    private function cleanReadme(string $raw): string
    {
        $lines = explode("\n", $raw);
        $out = [];
        $fence = false;
        $depth = 0;
        foreach ($lines as $line) {
            if (preg_match('/^\s*(```|~~~)/', $line)) {
                $fence = ! $fence;
            }
            if ($fence || preg_match('/^\s*(```|~~~)/', $line)) {
                if ($depth === 0) {
                    $out[] = $line;
                }

                continue;
            }
            if (preg_match('/^\s*<(div|details|picture|p)\b/i', $line)) {
                $depth += preg_match_all('/<(div|details|picture|p)\b/i', $line) - preg_match_all('/<\/(div|details|picture|p)>/i', $line);
                $depth = max(0, $depth);

                continue;
            }
            if ($depth > 0) {
                $depth += preg_match_all('/<(div|details|picture|p)\b/i', $line) - preg_match_all('/<\/(div|details|picture|p)>/i', $line);
                $depth = max(0, $depth);

                continue;
            }
            $line = (string) preg_replace('/<!--.*?-->/', '', $line);
            $line = (string) preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $line);
            $line = (string) preg_replace('/<\/?[a-z][^>]*>/i', '', $line);
            if ($this->source !== '') {
                $source = $this->source;
                $line = (string) preg_replace_callback(
                    '/\]\((?![a-z][a-z0-9+.-]*:|#|\/)([^)\s]+)\)/i',
                    static fn (array $m): string => ']('.$source.'/blob/HEAD/'.ltrim($m[1], './').')',
                    $line,
                );
            }
            $out[] = rtrim($line);
        }
        $text = implode("\n", $out);
        $text = (string) preg_replace('/^\s*<!--.*?-->\s*$/ms', '', $text);

        return (string) preg_replace("/\n{3,}/", "\n\n", $text);
    }

    /** One level down for every heading, so the joined file keeps a single H1. */
    private function demote(string $markdown): string
    {
        $fence = false;
        $lines = explode("\n", $markdown);
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*(```|~~~)/', $line)) {
                $fence = ! $fence;

                continue;
            }
            if (! $fence && preg_match('/^(#{1,5}) /', $line)) {
                $lines[$i] = '#'.$line;
            }
        }

        return implode("\n", $lines);
    }

    private function noindex(DOMDocument $dom): bool
    {
        return str_contains(strtolower($this->meta($dom, 'robots')), 'noindex');
    }

    private function meta(DOMDocument $dom, string $name): string
    {
        foreach ($dom->getElementsByTagName('meta') as $meta) {
            if (strtolower($meta->getAttribute('name')) === $name) {
                return trim((string) preg_replace('/\s+/', ' ', $meta->getAttribute('content')));
            }
        }

        return '';
    }

    private function text(DOMDocument $dom, string $tag): string
    {
        $node = $dom->getElementsByTagName($tag)->item(0);

        return $node === null ? '' : trim((string) preg_replace('/\s+/', ' ', $node->textContent));
    }

    private function normalise(string $text): string
    {
        return str_replace("\r\n", "\n", $text);
    }

    /** @return array<string, mixed> */
    private function json(string $path, bool $required): array
    {
        if (! is_file($path)) {
            if ($required) {
                throw new AgentReadyError("{$path} is missing.");
            }

            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data)) {
            throw new AgentReadyError("{$path} is not valid JSON.");
        }

        /** @var array<string, mixed> $data */
        return $data;
    }
}

/** @param list<string> $argv */
function agent_ready_main(array $argv): int
{
    $usage = "Usage: php bin/agent-ready.php [--root=.] [--check]\n"
        ."       php bin/agent-ready.php --sync-to=<repo>   (from Squire: copy the kit and this file)\n";
    $root = '.';
    $check = false;
    $syncTo = null;
    $rehash = false;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--rehash') {
            $rehash = true;
        } elseif (str_starts_with($arg, '--root=')) {
            $root = substr($arg, 7);
        } elseif (str_starts_with($arg, '--sync-to=')) {
            $syncTo = substr($arg, 10);
        } elseif ($arg === '--check') {
            $check = true;
        } elseif ($arg === '--help' || $arg === '-h') {
            fwrite(STDOUT, $usage);

            return 0;
        } else {
            fwrite(STDERR, $usage);

            return 2;
        }
    }

    try {
        if ($rehash) {
            return agent_ready_rehash();
        }
        if ($syncTo !== null) {
            return agent_ready_sync($syncTo);
        }
        $tool = new AgentReady(rtrim($root, '/\\'));
        $files = $tool->build();
        $leftovers = $tool->leftovers($files);
        $site = $tool->siteDir();
        $drift = [];
        foreach ($files as $relative => $content) {
            $path = $site.'/'.$relative;
            if ($tool->foreign($relative)) {
                $tool->findings[] = "{$relative} exists and was not written by this tool; move it aside or merge it by hand.";

                continue;
            }
            $onDisk = is_file($path) ? str_replace("\r\n", "\n", (string) file_get_contents($path)) : null;
            if ($onDisk === $content) {
                continue;
            }
            if ($check) {
                $drift[] = $relative.($onDisk === null ? ' (missing)' : ' (out of date)');

                continue;
            }
            $dir = dirname($path);
            if (! is_dir($dir) && ! mkdir($dir, 0o775, true) && ! is_dir($dir)) {
                throw new AgentReadyError("Cannot create {$dir}.");
            }
            if (file_put_contents($path, $content) === false) {
                throw new AgentReadyError("Cannot write {$path}.");
            }
            fwrite(STDOUT, "wrote {$relative}\n");
        }
        foreach ($leftovers as $relative) {
            if ($check) {
                $drift[] = $relative.' (no longer generated)';
            } else {
                unlink($site.'/'.$relative);
                fwrite(STDOUT, "removed {$relative}\n");
            }
        }
        foreach ($drift as $line) {
            fwrite(STDERR, "drift: {$line}\n");
        }
        foreach ($tool->findings as $line) {
            fwrite(STDERR, "finding: {$line}\n");
        }
        if ($drift !== []) {
            fwrite(STDERR, "The agent-ready files are stale. Run php bin/agent-ready.php and commit the site folder.\n");
        }
        if ($drift !== [] || $tool->findings !== []) {
            return 1;
        }
        fwrite(STDOUT, ($check ? 'Agent-ready files are current: ' : 'Agent-ready files written: ').count($files)." file(s).\n");

        return 0;
    } catch (AgentReadyError $e) {
        fwrite(STDERR, 'agent-ready: '.$e->getMessage()."\n");

        return 2;
    }
}

/** Records the SHA-256 of each kit file in AgentReadyKit::FILES, in this file. Squire's maintainers run it. */
function agent_ready_rehash(): int
{
    $kit = dirname(__DIR__).'/kit/site';
    if (! is_dir($kit)) {
        throw new AgentReadyError('--rehash runs from Squire, where the kit lives (kit/site/).');
    }
    $source = str_replace("\r\n", "\n", (string) file_get_contents(__FILE__));
    foreach (array_keys(AgentReadyKit::FILES) as $relative) {
        $hash = hash('sha256', str_replace("\r\n", "\n", (string) file_get_contents($kit.'/'.$relative)));
        $source = (string) preg_replace(
            "/('".preg_quote($relative, '/')."' => ')[^']*(')/",
            '${1}'.$hash.'${2}',
            $source,
            1,
        );
    }
    if (file_put_contents(__FILE__, $source) === false) {
        throw new AgentReadyError('Cannot write '.__FILE__.'.');
    }
    fwrite(STDOUT, "Kit hashes recorded.\n");

    return 0;
}

/** Copies the kit and this file into a repo. Only Squire has the kit, so only Squire can sync. */
function agent_ready_sync(string $repo): int
{
    $kit = dirname(__DIR__).'/kit/site';
    if (! is_dir($kit)) {
        throw new AgentReadyError('--sync-to runs from Squire, where the kit lives (kit/site/). This copy has no kit.');
    }
    $tool = new AgentReady(rtrim($repo, '/\\'));
    $copies = [__FILE__ => rtrim($repo, '/\\').'/bin/agent-ready.php'];
    foreach (array_keys(AgentReadyKit::FILES) as $relative) {
        $copies[$kit.'/'.$relative] = $tool->siteDir().'/'.$relative;
    }
    foreach ($copies as $from => $to) {
        $content = str_replace("\r\n", "\n", (string) file_get_contents($from));
        $old = is_file($to) ? str_replace("\r\n", "\n", (string) file_get_contents($to)) : null;
        if ($old === $content) {
            continue;
        }
        if (! is_dir(dirname($to)) && ! mkdir(dirname($to), 0o775, true) && ! is_dir(dirname($to))) {
            throw new AgentReadyError('Cannot create '.dirname($to).'.');
        }
        if (file_put_contents($to, $content) === false) {
            throw new AgentReadyError("Cannot write {$to}.");
        }
        fwrite(STDOUT, "synced {$to}\n");
    }
    fwrite(STDOUT, "Kit synced. Now run php bin/agent-ready.php in {$repo} and commit.\n");

    return 0;
}

// Run only as a script, so a test can include this file and call the classes.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    /** @var list<string> $arguments */
    $arguments = $_SERVER['argv'] ?? [];
    exit(agent_ready_main($arguments));
}
