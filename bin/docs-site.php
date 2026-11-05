<?php

declare(strict_types=1);
use League\CommonMark\Environment\Environment;
use League\CommonMark\Exception\InvalidArgumentException;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\CommonMark\Renderer\Block\FencedCodeRenderer;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\HtmlRenderer;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;
use League\CommonMark\Util\Xml;

/*
 * docs-site.php: turns every Markdown file under docs/ into a committed, accessible, WayneUI-styled docs
 * site under site/docs/, and fails CI when the committed pages are stale.
 *
 * Same bytes in every stack repo; copy it, don't fork it. Squire owns it, and `--sync-to` (run from Squire)
 * is how a repo takes a new version, together with the kit's docs.css and docs.js.
 *
 * From docs/ and CHANGELOG.md it writes, into site/docs/:
 *   <path>.html   one page per Markdown file, nav order, with a sidebar, an "On this page" list, prev/next
 *   images        every image a page references, byte for byte, at its path under docs/, so the built page's
 *                 relative src still resolves
 * and it checks what a person wrote: every page is in nav.json, every nav path has a file, every content link
 * reaches a built page, a file the site serves or an external address, a fragment names a real heading, every
 * referenced image exists under docs/, and the kit's files are the kit's bytes. A squire reads the map so the
 * knight can ask for directions.
 *
 * GitHub alert callouts: a blockquote whose first paragraph opens with a marker line alone ([!NOTE], [!TIP],
 * [!WARNING] or [!DANGER], case-insensitive) renders as an aside with a visible label; every other blockquote
 * stays as written.
 *
 * Code tabs: consecutive fenced code blocks whose info string carries tab="LABEL" (double quotes, label up to 40
 * characters) render as one labeled tab set, every panel visible and named so the page works without JavaScript;
 * the kit's docs.js turns each set into an accessible tablist and keeps sets that share a key in step. A lone such
 * block renders as a plain code block with the tab="..." word dropped from its language class, and blocks separated
 * by anything are separate sets.
 * Task lists: a GitHub task list checkbox sits in a label with its item's own text, so it has an accessible name.
 * Theme: an optional theme key in docs/docs.json wears the site's brand on the pages. Its css member names a
 * stylesheet, copied byte for byte to site/docs/theme/<basename> and linked after docs.css on every page; its
 * header member names an HTML partial whose single <header> element replaces the generic docs header, its relative
 * links rewritten to the page depth, its {{project}} and {{version}} placeholders filled, and its first link to the
 * docs home marked aria-current. Colors, fonts and dark mode live in the theme CSS, never in the builder.
 *
 * Generated blocks: a page may hold a region between `<!-- generated:NAME -->` and `<!-- /generated -->`
 * that the builder owns. docs/docs.json defines the blocks by name and rewrites each placed region: json-table
 * runs a command (a leading `php ` means PHP_BINARY, so the same file works on Windows, macOS and Linux) and
 * renders the list at its path as a Markdown table; symfony-commands runs <run> list --format=json and renders
 * the command reference; env scans the scan folders for the prefixed environment variable names the code reads.
 * A plain run rewrites stale regions in the sources and then builds; --check reports a stale region instead of
 * writing.
 *
 * Changelog page: when docs/nav.json lists a page whose path is "changelog", the builder also owns
 * docs/changelog.md, mirrored from CHANGELOG.md under the usual front matter and a marker comment, with the
 * top-level heading dropped, every version heading kept as written, and each relative link rewritten to a blob
 * link under the nav.json source (or dropped, keeping its label, when there is no source). A plain run writes the
 * page before building; --check reports docs/changelog.md (missing) or (out of date) instead of writing, and a
 * nav entry without a CHANGELOG.md is a finding.
 *
 * Tested examples: --examples runs the command examples in the docs and fails when reality drifts from what they
 * promise. A fenced block tagged `run` (bash, sh, shell or console, optionally `exit=N`) runs its lines in order in
 * a fresh temp copy of the examples.cwd folder; the next `output` block is the expected stdout and stderr. Every
 * shell block must be tagged run or carry a `<!-- docs-test: skip REASON -->` line before it, and the skips are
 * counted in the summary. Generated-block regions are ignored, and a plain build or --check never runs examples.
 *
 * Usage:
 *   php bin/docs-site.php [--root=.]                 write site/docs/ from docs/
 *   php bin/docs-site.php [--root=.] --check         write nothing; exit 1 on stale pages or findings
 *   php bin/docs-site.php [--root=.] --examples      run the tested examples; exit 1 on any finding
 *   php bin/docs-site.php --sync-to=<repo>           from Squire only: copy the kit and this file
 *   php bin/docs-site.php --rehash                   from Squire only: record the kit's hashes below
 *
 * The only package it loads is league/commonmark, from <root>/vendor/autoload.php (falling back to this
 * checkout's own vendor, so the tests can run against a bare fixture). Exit codes: 0 pass or written,
 * 1 stale pages or findings, 2 the tool itself could not run (no vendor, bad nav.json or docs.json, a block
 * command that fails or prints no JSON). Plain PHP 8.3.
 */

final class DocsSiteError extends RuntimeException {}

/**
 * The kit's files, by path inside the site folder, with the SHA-256 of their bytes (LF line endings).
 * Squire's tests fail when these drift from kit/docs/.
 */
final class DocsKit
{
    public const MARKER = 'Generated by bin/docs-site.php';

    public const FILES = [
        'assets/docs.css' => '2e7010c6b86fca95309ed834ccccedade299bbe1e071bb0ed83acbc12bf99b75',
        'assets/docs.js' => 'c59aa8cbedfff3707c9048caf95cda5d1d01f2f68d12d8ae2267d11872bccfc8',
    ];
}

/**
 * --examples: runs the command examples in the docs and fails when reality drifts from what they promise.
 *
 * A fenced block whose info string is `bash run`, `sh run`, `console run` or `shell run` (optionally `exit=N`,
 * default 0) is a command block: each non-empty line is a command (a leading `$ ` is stripped), run in order in a
 * fresh temp copy of the `examples.cwd` folder from docs/docs.json. The next fenced block, when its info string is
 * exactly `output`, is the expected output (stdout then stderr, per command). Both sides are normalized before the
 * comparison: CRLF to LF, trailing spaces trimmed per line, trailing blank lines removed, the example working
 * directory shown as `<cwd>`, the repo root as `<root>`, plus every regex rule in `examples.normalize`. Every shell
 * block (bash, sh, shell, console, powershell, pwsh) must carry the run tag or a `<!-- docs-test: skip REASON -->`
 * line directly before it; skips are counted and grouped in the summary line, capped by `examples.maxSkips`.
 * Generated-block regions are ignored, and the plain build and --check never run examples.
 */
final class DocsExamples
{
    /** The languages a command block may use; powershell and pwsh only count for the strictness rule. */
    private const COMMAND_LANGUAGES = ['bash', 'sh', 'shell', 'console'];

    /** The languages that must be tagged run or skipped with a reason. */
    private const STRICT_LANGUAGES = ['bash', 'sh', 'shell', 'console', 'powershell', 'pwsh'];

    private string $root;

    /** @var string|null the repo folder copied into each example working directory */
    private ?string $cwd = null;

    /** @var array<string, string> an alias first word to the command that replaces it */
    private array $alias = [];

    /** @var array<string, string> extra environment variables for the example commands */
    private array $env = [];

    private int $timeout = 60;

    /** @var list<array{0: string, 1: string}> normalize rules as [compiled pattern, replacement] pairs */
    private array $normalize = [];

    private ?int $maxSkips = null;

    /** @var array<string, array{label: string, count: int}> skipped examples, by case-folded reason group */
    private array $skips = [];

    private int $runCount = 0;

    public function __construct(string $root)
    {
        if (! is_dir($root)) {
            throw new DocsSiteError("The folder {$root} does not exist.");
        }
        $this->root = rtrim($root, '/\\');
        $this->loadConfig();
    }

    /**
     * Runs every command example under docs/ and reports what drifted.
     *
     * @return array{findings: list<string>, summary: string}
     */
    public function run(): array
    {
        $findings = [];
        foreach ($this->pages() as $path => $file) {
            $raw = str_replace("\r\n", "\n", (string) file_get_contents($file));
            $lines = $this->blankGeneratedRegions(explode("\n", $raw));
            $fences = $this->fences($lines);
            $workDir = null;
            try {
                foreach ($fences as $index => $fence) {
                    $kind = $this->classify($fence['info']);
                    if ($kind['strict']) {
                        $findings = array_merge($findings, $this->accountStrictness($path, $fence, $lines, $kind));
                    }
                    if (! $kind['command']) {
                        continue;
                    }
                    if ($workDir === null) {
                        $workDir = $this->prepareWorkDir();
                    }
                    $findings = array_merge($findings, $this->runBlock($path, $fence, $fences[$index + 1] ?? null, $lines, $kind, $workDir));
                }
            } finally {
                if ($workDir !== null) {
                    self::removeDir($workDir);
                }
            }
        }
        if ($this->maxSkips !== null && $this->skipTotal() > $this->maxSkips) {
            $findings[] = "docs.json maxSkips {$this->maxSkips} exceeded: {$this->skipTotal()} skipped";
        }

        return ['findings' => $findings, 'summary' => $this->summary()];
    }

    /** Reads the optional examples key of docs/docs.json; a broken config is a tool error (exit 2). */
    private function loadConfig(): void
    {
        $path = $this->root.'/docs/docs.json';
        if (! is_file($path)) {
            return;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || array_is_list($data)) {
            throw new DocsSiteError("{$path} must be a JSON object.");
        }
        if (! array_key_exists('examples', $data)) {
            return;
        }
        $examples = $data['examples'];
        if (! is_array($examples) || array_is_list($examples)) {
            throw new DocsSiteError("{$path}: the \"examples\" key must be an object.");
        }
        if (isset($examples['cwd'])) {
            if (! is_string($examples['cwd']) || $examples['cwd'] === '') {
                throw new DocsSiteError("{$path}: examples.cwd must be a non-empty string.");
            }
            $this->cwd = $examples['cwd'];
            if (! is_dir($this->root.'/'.$this->cwd)) {
                throw new DocsSiteError("{$path}: the examples cwd folder {$this->cwd} does not exist.");
            }
        }
        if (isset($examples['alias'])) {
            if (! is_array($examples['alias']) || array_is_list($examples['alias'])) {
                throw new DocsSiteError("{$path}: examples.alias must be an object of strings.");
            }
            foreach ($examples['alias'] as $word => $replacement) {
                if (! is_string($replacement) || $replacement === '') {
                    throw new DocsSiteError("{$path}: examples.alias.{$word} must be a non-empty string.");
                }
                $this->alias[(string) $word] = $replacement;
            }
        }
        if (isset($examples['env'])) {
            if (! is_array($examples['env']) || array_is_list($examples['env'])) {
                throw new DocsSiteError("{$path}: examples.env must be an object of strings.");
            }
            foreach ($examples['env'] as $key => $value) {
                if (! is_string($value)) {
                    throw new DocsSiteError("{$path}: examples.env.{$key} must be a string.");
                }
                $this->env[(string) $key] = $value;
            }
        }
        if (isset($examples['timeout'])) {
            if (! is_int($examples['timeout']) || $examples['timeout'] < 1) {
                throw new DocsSiteError("{$path}: examples.timeout must be a positive number of seconds.");
            }
            $this->timeout = $examples['timeout'];
        }
        if (isset($examples['normalize'])) {
            if (! is_array($examples['normalize']) || ! array_is_list($examples['normalize'])) {
                throw new DocsSiteError("{$path}: examples.normalize must be a list of [pattern, replacement] pairs.");
            }
            foreach ($examples['normalize'] as $rule) {
                if (! is_array($rule) || count($rule) !== 2 || ! is_string($rule[0]) || ! is_string($rule[1])) {
                    throw new DocsSiteError("{$path}: every examples.normalize rule is a [\"/re/\", \"replacement\"] pair.");
                }
                if (preg_match('#^/(.+)/(.*)$#s', $rule[0], $m) !== 1) {
                    throw new DocsSiteError("{$path}: the normalize pattern {$rule[0]} needs surrounding slashes.");
                }
                $full = '/'.$m[1].'/'.$m[2];
                if (@preg_match($full, '') === false) {
                    throw new DocsSiteError("{$path}: the normalize pattern {$rule[0]} is not a valid regular expression.");
                }
                $this->normalize[] = [$full, $rule[1]];
            }
        }
        if (isset($examples['maxSkips'])) {
            if (! is_int($examples['maxSkips']) || $examples['maxSkips'] < 0) {
                throw new DocsSiteError("{$path}: examples.maxSkips must be a whole number.");
            }
            $this->maxSkips = $examples['maxSkips'];
        }
    }

    /**
     * Every doc page, by path (no .md) with its absolute file, sorted, private pages skipped.
     *
     * @return array<string, string>
     */
    private function pages(): array
    {
        $docs = $this->root.'/docs';
        $out = [];
        foreach ($this->walk($docs, 'md') as $relative) {
            $path = substr($relative, 0, -3);
            $out[$path] = $docs.'/'.$relative;
        }
        ksort($out);

        return $out;
    }

    /**
     * Replaces the content of every generated-block region with blank lines, keeping the line count and numbers.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function blankGeneratedRegions(array $lines): array
    {
        $count = count($lines);
        $inFence = false;
        $fenceChar = '';
        $fenceLength = 0;
        $i = 0;
        while ($i < $count) {
            if (docs_site_fence_line($lines[$i], $inFence, $fenceChar, $fenceLength)) {
                $i++;

                continue;
            }
            if (! $inFence && preg_match('/^\s*<!--\s+generated:([A-Za-z0-9_-]+)\s+-->\s*$/', $lines[$i]) === 1) {
                $close = -1;
                for ($j = $i + 1; $j < $count; $j++) {
                    if (docs_site_fence_line($lines[$j], $inFence, $fenceChar, $fenceLength)) {
                        continue;
                    }
                    if (preg_match('/^\s*<!--\s+\/generated\s+-->\s*$/', $lines[$j]) === 1) {
                        $close = $j;

                        break;
                    }
                }
                $end = $close === -1 ? $count - 1 : $close - 1;
                for ($k = $i + 1; $k <= $end; $k++) {
                    $lines[$k] = '';
                }
                $i = $close === -1 ? $count : $close + 1;

                continue;
            }
            $i++;
        }

        return array_values($lines);
    }

    /**
     * The fenced blocks of one page, in document order. start and end are 0-based line indexes of the content.
     *
     * @param  list<string>  $lines
     * @return list<array{line: int, info: string, start: int, end: int}>
     */
    private function fences(array $lines): array
    {
        $out = [];
        $count = count($lines);
        $i = 0;
        while ($i < $count) {
            if (preg_match('/^ {0,3}(`{3,}|~{3,})\s*(.*)$/', $lines[$i], $open) !== 1) {
                $i++;

                continue;
            }
            $char = $open[1][0];
            $length = strlen($open[1]);
            $info = trim($open[2]);
            if ($char === '`' && str_contains($info, '`')) {
                $i++;

                continue;
            }
            $close = -1;
            for ($j = $i + 1; $j < $count; $j++) {
                if (preg_match('/^ {0,3}'.$char.'{'.$length.',}[ \t]*$/', $lines[$j]) === 1) {
                    $close = $j;

                    break;
                }
            }
            if ($close === -1) {
                $out[] = ['line' => $i + 1, 'info' => $info, 'start' => $i + 1, 'end' => $count - 1];

                break;
            }
            $out[] = ['line' => $i + 1, 'info' => $info, 'start' => $i + 1, 'end' => $close - 1];
            $i = $close + 1;
        }

        return $out;
    }

    /**
     * What a fence info string means for the gate.
     *
     * @return array{language: string, command: bool, strict: bool, exit: int}
     */
    private function classify(string $info): array
    {
        $tokens = preg_split('/\s+/', trim($info)) ?: [];
        $language = strtolower($tokens[0] ?? '');
        $exit = 0;
        foreach ($tokens as $token) {
            if (preg_match('/^exit=(\d+)$/', $token, $m) === 1) {
                $exit = (int) $m[1];
            }
        }

        return [
            'language' => $language,
            'command' => in_array($language, self::COMMAND_LANGUAGES, true) && in_array('run', $tokens, true),
            'strict' => in_array($language, self::STRICT_LANGUAGES, true),
            'exit' => $exit,
        ];
    }

    /**
     * The strictness rule: a shell block must be tagged run or skipped with a reason. Tagged blocks count as run;
     * skipped ones are grouped by the words before the first comma, case-folded.
     *
     * @param  array{line: int, info: string, start: int, end: int}  $fence
     * @param  array{language: string, command: bool, strict: bool, exit: int}  $kind
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function accountStrictness(string $path, array $fence, array $lines, array $kind): array
    {
        if (in_array('run', preg_split('/\s+/', trim($fence['info'])) ?: [], true)) {
            $this->runCount++;

            return [];
        }
        $previous = $fence['line'] >= 2 ? $lines[$fence['line'] - 2] : '';
        $reason = $this->skipMarker($previous);
        if ($reason === null) {
            return ["docs/{$path}.md:{$fence['line']} shell block without run or a skip reason"];
        }
        if ($reason === '') {
            return ["docs/{$path}.md:".($fence['line'] - 1).' skip without a reason'];
        }
        $group = strtolower(trim(explode(',', $reason)[0]));
        $entry = $this->skips[$group] ?? ['label' => trim(explode(',', $reason)[0]), 'count' => 0];
        $entry['count']++;
        $this->skips[$group] = $entry;

        return [];
    }

    /**
     * The reason on a `<!-- docs-test: skip REASON -->` line: the reason, '' when it has none, null when the line is
     * not such a marker.
     */
    private function skipMarker(string $line): ?string
    {
        if (preg_match('/^\s*<!--\s*(.*?)\s*-->\s*$/', $line, $m) !== 1) {
            return null;
        }
        if (preg_match('/^docs-test:\s+skip\s*(.*)$/', trim($m[1]), $m) !== 1) {
            return null;
        }

        return trim($m[1]);
    }

    /**
     * Runs one command block and compares its output with the following output block, when there is one. A failed
     * command stops the rest of its block; the other blocks and pages still run.
     *
     * @param  array{line: int, info: string, start: int, end: int}  $fence
     * @param  array{line: int, info: string, start: int, end: int}|null  $next
     * @param  array{language: string, command: bool, strict: bool, exit: int}  $kind
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function runBlock(string $path, array $fence, ?array $next, array $lines, array $kind, string $workDir): array
    {
        $commands = [];
        for ($k = $fence['start']; $k <= $fence['end']; $k++) {
            $line = trim($lines[$k]);
            if ($line === '') {
                continue;
            }
            $commands[] = preg_replace('/^\$\s+/', '', $line) ?? $line;
        }
        $expected = [];
        if ($next !== null && trim($next['info']) === 'output') {
            for ($k = $next['start']; $k <= $next['end']; $k++) {
                $expected[] = $lines[$k];
            }
        }
        $raw = '';
        $findings = [];
        $failed = false;
        foreach ($commands as $command) {
            $result = $this->execute($workDir, $this->applyAlias($command));
            if ($result['timedOut']) {
                $findings[] = "docs/{$path}.md:{$fence['line']} timed out after {$this->timeout} seconds";
                $failed = true;

                break;
            }
            if ($result['code'] !== $kind['exit']) {
                $findings[] = "docs/{$path}.md:{$fence['line']} exited {$result['code']}, expected {$kind['exit']}";
                $failed = true;

                break;
            }
            if ($result['stdout'] !== '' && ! str_ends_with($result['stdout'], "\n")) {
                $raw .= "\n";
            }
            $raw .= $result['stdout'].$result['stderr'];
        }
        if (! $failed) {
            $want = $this->normaliseOutput(implode("\n", $expected), $workDir);
            $got = $this->normaliseOutput($raw, $workDir);
            if ($want !== $got) {
                $findings[] = "docs/{$path}.md:{$fence['line']} output differs";
                foreach ($this->diffLines(explode("\n", $want), explode("\n", $got)) as $line) {
                    $findings[] = $line;
                }
            }
        }

        return $findings;
    }

    /**
     * Replaces the first word of a command with its alias, when one matches.
     */
    private function applyAlias(string $command): string
    {
        $parts = preg_split('/\s+/', trim($command), 2) ?: [];
        $word = $parts[0] ?? '';
        if (! isset($this->alias[$word])) {
            return $command;
        }
        $rest = $parts[1] ?? '';
        $replacement = $this->expandReplacement($this->alias[$word]);

        return $rest === '' ? $replacement : $replacement.' '.$rest;
    }

    /**
     * A leading `php ` in the replacement means PHP_BINARY; a relative script path resolves against the root; words
     * with spaces get quoted so the command string survives the shell.
     */
    private function expandReplacement(string $replacement): string
    {
        $parts = preg_split('/\s+/', trim($replacement)) ?: [];
        if (($parts[0] ?? '') === 'php') {
            $parts[0] = PHP_BINARY;
        }
        foreach ($parts as $k => $part) {
            if ($k === 0 || str_starts_with($part, '-') || self::isAbsolutePath($part)) {
                continue;
            }
            $parts[$k] = $this->root.'/'.$part;

            break;
        }
        foreach ($parts as $k => $part) {
            if (preg_match('/\s/', $part) === 1) {
                $parts[$k] = '"'.$part.'"';
            }
        }

        return implode(' ', $parts);
    }

    private static function isAbsolutePath(string $path): bool
    {
        if (str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            return true;
        }

        return preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    /**
     * Runs one command string in the example working directory and collects its output, with a timeout.
     *
     * @return array{code: int, stdout: string, stderr: string, timedOut: bool}
     */
    private function execute(string $workDir, string $command): array
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $workDir, $this->environment());
        if (! is_resource($process)) {
            return ['code' => 127, 'stdout' => '', 'stderr' => 'could not start the command', 'timedOut' => false];
        }
        fclose($pipes[0]);
        $stdout = '';
        $stderr = '';
        $eofOut = false;
        $eofErr = false;
        $timedOut = false;
        $deadline = microtime(true) + $this->timeout;
        while (! ($eofOut && $eofErr)) {
            $read = [];
            if (! $eofOut) {
                $read[] = $pipes[1];
            }
            if (! $eofErr) {
                $read[] = $pipes[2];
            }
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0.0) {
                $timedOut = true;

                break;
            }
            $write = null;
            $except = null;
            $selected = @stream_select($read, $write, $except, 0, (int) min(1000000, $remaining * 1000000));
            if ($selected === false) {
                usleep(10000);

                continue;
            }
            if ($selected === 0) {
                $timedOut = true;

                break;
            }
            foreach ($read as $stream) {
                $chunk = (string) fread($stream, 8192);
                if ($chunk === '') {
                    if ($stream === $pipes[1]) {
                        $eofOut = true;
                    } else {
                        $eofErr = true;
                    }

                    continue;
                }
                if ($stream === $pipes[1]) {
                    $stdout .= $chunk;
                } else {
                    $stderr .= $chunk;
                }
            }
        }
        if ($timedOut) {
            proc_terminate($process);
        }
        // proc_close closes the pipes itself and waits for the command to finish.
        $code = proc_close($process);

        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr, 'timedOut' => $timedOut];
    }

    /**
     * The environment of the example commands: the current environment plus the configured variables.
     *
     * @return array<string, string>
     */
    private function environment(): array
    {
        $env = getenv() ?: [];
        foreach ($this->env as $key => $value) {
            $env[$key] = $value;
        }

        return $env;
    }

    /**
     * Collapses one side of an output comparison: line endings, trailing blanks, path placeholders, normalize rules.
     */
    private function normaliseOutput(string $text, string $workDir): string
    {
        $lines = array_map('rtrim', explode("\n", str_replace("\r\n", "\n", $text)));
        while ($lines !== [] && $lines[count($lines) - 1] === '') {
            array_pop($lines);
        }
        $text = implode("\n", $lines);
        $text = $this->replacePaths($text, $workDir, '<cwd>');
        $text = $this->replacePaths($text, $this->root, '<root>');
        foreach ($this->normalize as [$pattern, $replacement]) {
            $text = (string) preg_replace($pattern, $replacement, $text);
        }

        return $text;
    }

    /** Replaces every form of a path (slash and backslash) with its placeholder. */
    private function replacePaths(string $text, string $dir, string $placeholder): string
    {
        return str_replace([$dir, str_replace('/', '\\', $dir)], $placeholder, $text);
    }

    /**
     * A line diff of two outputs: `- ` for expected lines, `+ ` for actual lines, at most 20 lines.
     *
     * @param  list<string>  $expected
     * @param  list<string>  $actual
     * @return list<string>
     */
    private function diffLines(array $expected, array $actual): array
    {
        $n = count($expected);
        $m = count($actual);
        $dp = [];
        for ($i = 0; $i <= $n; $i++) {
            $dp[$i] = array_fill(0, $m + 1, 0);
        }
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $dp[$i][$j] = $expected[$i] === $actual[$j] ? $dp[$i + 1][$j + 1] + 1 : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
            }
        }
        $out = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($expected[$i] === $actual[$j]) {
                $i++;
                $j++;
            } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
                $out[] = '- '.$expected[$i];
                $i++;
            } else {
                $out[] = '+ '.$actual[$j];
                $j++;
            }
        }
        while ($i < $n) {
            $out[] = '- '.$expected[$i];
            $i++;
        }
        while ($j < $m) {
            $out[] = '+ '.$actual[$j];
            $j++;
        }

        return array_slice($out, 0, 20);
    }

    /**
     * A fresh temp dir seeded with the examples cwd folder, when one is configured.
     */
    private function prepareWorkDir(): string
    {
        $dir = sys_get_temp_dir().'/squire-examples-'.bin2hex(random_bytes(6));
        mkdir($dir, 0o755, true);
        if ($this->cwd !== null) {
            $this->copyTree($this->root.'/'.$this->cwd, $dir);
        }

        return $dir;
    }

    /** Copies a tree into another, skipping vendor folders anywhere in it. */
    private function copyTree(string $from, string $to): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
                static fn (SplFileInfo $item): bool => ! ($item->isDir() && $item->getFilename() === 'vendor'),
            ),
        );
        foreach ($iterator as $item) {
            if (! $item instanceof SplFileInfo || ! $item->isFile()) {
                continue;
            }
            $relative = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($from))), '/');
            $target = $to.'/'.$relative;
            if (! is_dir(dirname($target)) && ! mkdir(dirname($target), 0o755, true) && ! is_dir(dirname($target))) {
                throw new DocsSiteError('Cannot create '.dirname($target).'.');
            }
            if (! copy($item->getPathname(), $target)) {
                throw new DocsSiteError("Cannot copy {$item->getPathname()} to {$target}.");
            }
        }
    }

    /**
     * Removes a directory tree; a missing directory is fine. A timed-out command may still hold a file or the
     * directory open, so failures are ignored: the leftovers expire when that command exits.
     */
    private static function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if (! $item instanceof SplFileInfo) {
                continue;
            }
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }

    /** @return list<string> the Markdown files under a folder, relative, sorted, dot and underscore files skipped */
    private function walk(string $dir, string $extension): array
    {
        if (! is_dir($dir)) {
            return [];
        }
        $out = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                static function (SplFileInfo $file): bool {
                    return ! str_starts_with($file->getFilename(), '.') && ! str_starts_with($file->getFilename(), '_');
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

    private function skipTotal(): int
    {
        $total = 0;
        foreach ($this->skips as $entry) {
            $total += $entry['count'];
        }

        return $total;
    }

    /** The one-line tally: Examples: N run, M skipped (reasons: label count, ...), biggest groups first. */
    private function summary(): string
    {
        $total = $this->skipTotal();
        $line = 'Examples: '.$this->runCount.' run, '.$total.' skipped';
        if ($total === 0) {
            return $line;
        }
        $ordered = [];
        foreach ($this->skips as $entry) {
            $ordered[] = $entry;
        }
        usort($ordered, static function (array $a, array $b): int {
            $ac = (int) $a['count'];
            $bc = (int) $b['count'];
            if ($ac === $bc) {
                return strcasecmp((string) $a['label'], (string) $b['label']);
            }

            return $bc - $ac;
        });
        $parts = [];
        foreach ($ordered as $entry) {
            $parts[] = $entry['label'].' '.$entry['count'];
        }

        return $line.' (reasons: '.implode(', ', $parts).')';
    }
}

final class DocsSite
{
    /** @var array<string, true> the application-level options every Symfony command accepts, listed once, never per command */
    private const APPLICATION_OPTIONS = [
        '--help' => true,
        '--quiet' => true,
        '--verbose' => true,
        '--version' => true,
        '--ansi' => true,
        '--no-ansi' => true,
        '--no-interaction' => true,
    ];

    /** @var list<string> the GitHub alert marker words a callout may carry, lower case */
    private const CALLOUT_KINDS = ['note', 'tip', 'warning', 'danger'];

    private string $root;

    private string $docs;

    private string $siteDocs;

    /** @var array<string, mixed> */
    private array $nav;

    /** @var list<string> */
    public array $findings = [];

    /** @var list<string> stale generated blocks, as `docs/<path>.md (generated block "<name>" is out of date)` */
    public array $staleBlocks = [];

    /** @var array<string, string> the referenced images, by path under docs/ (relative), with their bytes */
    private array $images = [];

    /** @var list<array{title: string, path: string}> the nav pages, in nav order */
    private array $order = [];

    /** @var array<string, array<string, mixed>> the blocks from docs/docs.json, by name */
    private array $blocks = [];

    /** @var array<string, string>|null the rendered block content, by name, once computed */
    private ?array $computed = null;

    /** @var array<string, array<string, list<string>>> the kept commands per cli name: command name to its option keys */
    private array $commandSets = [];

    /** @var string|null the theme stylesheet, as a path under docs/, when docs.json sets one */
    private ?string $themeCss = null;

    /** @var string|null the theme header element, guards passed, when docs.json sets one */
    private ?string $themeHeader = null;

    private bool $writeBlocks = false;

    private bool $requireTag = false;

    private bool $requireRelease = false;

    public function __construct(string $root)
    {
        if (! is_dir($root)) {
            throw new DocsSiteError("The folder {$root} does not exist.");
        }
        $this->root = rtrim($root, '/\\');
        $this->docs = $this->root.'/docs';
        $this->siteDocs = $this->root.'/site/docs';
        $this->nav = $this->loadNav();
        $this->loadBlocks();
        $this->loadTheme();
    }

    /** A plain run rewrites stale block regions in the sources; --check never writes. */
    public function setWriteBlocks(bool $write): void
    {
        $this->writeBlocks = $write;
    }

    /** --require-tag: fail when the latest version tag does not match the docs stamp. Off by default. */
    public function setRequireTag(bool $require): void
    {
        $this->requireTag = $require;
    }

    /** --require-release: fail when CHANGELOG.md has no released heading yet. Off by default. */
    public function setRequireRelease(bool $require): void
    {
        $this->requireRelease = $require;
    }

    public function rootDir(): string
    {
        return $this->root;
    }

    /**
     * Every page this tool owns, by path relative to the repo root, with its content.
     *
     * @return array<string, string>
     */
    public function build(): array
    {
        $this->findings = [];
        $this->staleBlocks = [];
        $this->images = [];
        $sources = $this->sources();
        $order = $this->flattenNav();
        $this->order = $order;
        $this->checkNav($sources, $order);
        $this->checkSource();
        $this->changelogPage();
        $this->checkBlocks($sources);
        $contents = [];
        foreach ($sources as $path => $file) {
            $contents[$path] = $this->normalise((string) file_get_contents($file));
        }
        if ($this->writeBlocks) {
            $this->rewriteBlocks($sources, $contents);
        }
        $this->checkCoverage($sources, $contents);
        $stamp = $this->versionStamp();
        $this->checkStamp($stamp);
        $project = $this->projectTitle();
        $this->checkWayneUi();
        $this->checkKit();

        $files = [];
        foreach ($sources as $path => $file) {
            $files['site/docs/'.$path.'.html'] = $this->renderPage($path, $contents[$path], $sources, $stamp, $project);
        }
        ksort($files);
        $this->checkLinks($files, $sources);
        $this->checkImages($sources, $contents, $files);

        return $files;
    }

    /**
     * The referenced images, ready to land under site/docs/: their bytes, keyed by site/docs/<relative>.
     *
     * @return array<string, string>
     */
    public function binaryFiles(): array
    {
        $out = [];
        foreach ($this->images as $relative => $bytes) {
            $out['site/docs/'.$relative] = $bytes;
        }
        // The theme stylesheet is builder-owned like a copied image: its bytes land under site/docs/theme/.
        if ($this->themeCss !== null) {
            $out['site/docs/theme/'.basename($this->themeCss)] = (string) file_get_contents($this->docs.'/'.$this->themeCss);
        }
        ksort($out);

        return $out;
    }

    /**
     * Generated pages on disk whose source is gone. Copied images are never leftovers: the builder removes only
     * its own pages, so an image outlives the page that last referenced it.
     *
     * @param  array<string, string>  $files
     * @param  array<string, string>  $binaries
     * @return list<string>
     */
    public function leftovers(array $files, array $binaries = []): array
    {
        $out = [];
        if (! is_dir($this->siteDocs)) {
            return $out;
        }
        foreach ($this->walk($this->siteDocs, 'html') as $relative) {
            $key = 'site/docs/'.$relative;
            if (isset($files[$key]) || isset($binaries[$key])) {
                continue;
            }
            $head = (string) file_get_contents($this->siteDocs.'/'.$relative, false, null, 0, 400);
            if (str_contains($head, DocsKit::MARKER)) {
                $out[] = $key;
            }
        }
        // A theme stylesheet is builder-owned like a copied image: a file under site/docs/theme/ the theme no
        // longer produces is a leftover.
        $themeDir = $this->siteDocs.'/theme';
        if (is_dir($themeDir)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($themeDir, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                    continue;
                }
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($themeDir) + 1));
                if (! isset($binaries['site/docs/theme/'.$relative])) {
                    $out[] = 'site/docs/theme/'.$relative;
                }
            }
        }
        sort($out);

        return $out;
    }

    /**
     * The link gate for the built pages, run once the pages are rendered so it reads the hrefs as they will be
     * served. Every link a person wrote in a page's article must reach something the site serves: a pure fragment
     * must be an id on the same page, a page link with a fragment must reach a built page that carries the id, and
     * a relative link to anything else must exist under site/. Scheme links (https:, mailto:, //) and the site's
     * assets are skipped, and the chrome the builder writes around the article is its own, so it is not scanned.
     *
     * @param  array<string, string>  $files
     * @param  array<string, string>  $sources
     */
    private function checkLinks(array $files, array $sources): void
    {
        $pages = [];
        foreach ($files as $key => $html) {
            $dom = $this->loadDom($html);
            if ($dom === null) {
                continue;
            }
            $pages[$key] = ['dom' => $dom, 'ids' => $this->collectIds($dom)];
        }
        foreach ($pages as $key => $page) {
            $source = $this->sourceLabel($key, $sources);
            $xpath = new DOMXPath($page['dom']);
            $anchors = $xpath->query('//article//a[@href]');
            if ($anchors === false) {
                continue;
            }
            foreach ($anchors as $anchor) {
                if (! $anchor instanceof DOMElement) {
                    continue;
                }
                $this->checkHref((string) $anchor->getAttribute('href'), $key, $source, $files, $pages);
            }
        }
    }

    /**
     * One content href against the built pages and the files under site/.
     *
     * @param  array<string, string>  $files
     * @param  array<string, array{dom: DOMDocument, ids: list<string>}>  $pages
     */
    private function checkHref(string $href, string $key, string $source, array $files, array $pages): void
    {
        if ($href === '' || preg_match('/^(?:[a-z][a-z0-9+.-]*:|\/\/)/i', $href)) {
            return;
        }
        $frag = '';
        $pathPart = $href;
        $hash = strpos($href, '#');
        if ($hash !== false) {
            $pathPart = substr($href, 0, $hash);
            $frag = substr($href, $hash + 1);
        }
        $pathPart = (string) preg_replace('/\?.*$/s', '', $pathPart);
        if (preg_match('/^(?:(?:\.\.?\/)+)?assets\//', $pathPart)) {
            return;
        }
        $segments = $pathPart === '' ? explode('/', $key) : explode('/', dirname($key));
        foreach (explode('/', $pathPart) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
            } else {
                $segments[] = $segment;
            }
        }
        $resolved = implode('/', $segments);
        if (str_starts_with($resolved, 'site/assets/')) {
            return;
        }
        if (isset($files[$resolved])) {
            if ($frag !== '' && isset($pages[$resolved]) && ! in_array($frag, $pages[$resolved]['ids'], true)) {
                $this->findings[] = "{$source} links to {$href} but no heading there has that id";
            }

            return;
        }
        if ($pathPart !== '' && ! is_file($this->root.'/'.$resolved)) {
            $this->findings[] = "{$source} links to {$href} but the site does not serve that file";
        }
    }

    /**
     * The image gate for the built pages, run once the pages are rendered so it reads the srcs as they will be
     * served. Every relative src a page's article points at (a Markdown image or an HTML <img>) must name a file
     * under docs/, resolved against the source page's own folder; the build copies exactly those files, byte for
     * byte, to the same relative path under site/docs/, so the built page's relative src still resolves.
     * Unreferenced files under docs/ are never copied. Scheme links, data: URIs and / absolute paths are skipped.
     *
     * @param  array<string, string>  $sources
     * @param  array<string, string>  $contents
     * @param  array<string, string>  $files
     */
    private function checkImages(array $sources, array $contents, array $files): void
    {
        foreach ($files as $key => $html) {
            $source = $this->sourceLabel($key, $sources);
            $docPath = substr($key, strlen('site/docs/'), -strlen('.html'));
            $folder = dirname($docPath) === '.' ? '' : (string) dirname($docPath);
            $dom = $this->loadDom($html);
            if ($dom === null) {
                continue;
            }
            $xpath = new DOMXPath($dom);
            $images = $xpath->query('//article//img[@src]');
            if ($images === false) {
                continue;
            }
            foreach ($images as $image) {
                if (! $image instanceof DOMElement) {
                    continue;
                }
                $this->checkImageSrc((string) $image->getAttribute('src'), $source, $folder);
            }
        }
    }

    /**
     * One content img src against the files under docs/. A relative src resolves against the source page's folder;
     * a file that exists is recorded for the copy into site/docs/, one that does not is a finding.
     */
    private function checkImageSrc(string $src, string $source, string $folder): void
    {
        if ($src === '' || str_starts_with($src, '#') || preg_match('/^(?:[a-z][a-z0-9+.-]*:|\/\/)/i', $src) || str_starts_with($src, '/')) {
            return;
        }
        $pathPart = (string) preg_replace('/([?#].*)$/s', '', $src);
        if ($pathPart === '') {
            return;
        }
        $segments = $folder === '' ? [] : explode('/', $folder);
        foreach (explode('/', $pathPart) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                // A path cannot climb above docs/, so an empty stack clamps instead of popping.
                if ($segments !== []) {
                    array_pop($segments);
                }
            } else {
                $segments[] = $segment;
            }
        }
        $resolved = implode('/', $segments);
        if (! is_file($this->docs.'/'.$resolved)) {
            $this->findings[] = "{$source} references image {$src} but docs/{$resolved} does not exist";

            return;
        }
        $this->images[$resolved] = (string) file_get_contents($this->docs.'/'.$resolved);
    }

    /** A built page as a DOMDocument, or null when it cannot be parsed. */
    private function loadDom(string $html): ?DOMDocument
    {
        $dom = new DOMDocument;
        $keep = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($keep);

        return $loaded ? $dom : null;
    }

    /** The id attributes of every element in the page, so a fragment can be checked against them. */
    /** @return list<string> */
    private function collectIds(DOMDocument $dom): array
    {
        $ids = [];
        foreach ($dom->getElementsByTagName('*') as $element) {
            $id = $element->getAttribute('id');
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * The docs source behind a built page, for the finding text.
     *
     * @param  array<string, string>  $sources
     */
    private function sourceLabel(string $key, array $sources): string
    {
        $docPath = substr($key, strlen('site/docs/'), -strlen('.html'));
        if (isset($sources[$docPath])) {
            return 'docs/'.$docPath.'.md';
        }

        return $key;
    }

    /** @return array<string, string> doc path (no .md) => absolute file, sorted, private skipped */
    private function sources(): array
    {
        $out = [];
        foreach ($this->walk($this->docs, 'md') as $relative) {
            $path = substr($relative, 0, -3);
            $out[$path] = $this->docs.'/'.$relative;
        }
        ksort($out);

        return $out;
    }

    /** @return list<array{title: string, path: string}> the nav pages in nav order */
    private function flattenNav(): array
    {
        $out = [];
        foreach ((array) ($this->nav['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (isset($item['items'])) {
                foreach ((array) $item['items'] as $child) {
                    if (is_array($child) && isset($child['path']) && is_string($child['path'])) {
                        $out[] = ['title' => is_string($child['title'] ?? '') ? $child['title'] : '', 'path' => $child['path']];
                    }
                }
            } elseif (isset($item['path']) && is_string($item['path'])) {
                $out[] = ['title' => is_string($item['title'] ?? '') ? $item['title'] : '', 'path' => $item['path']];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $sources
     * @param  list<array{title: string, path: string}>  $order
     */
    private function checkNav(array $sources, array $order): void
    {
        $seen = [];
        foreach ($order as $entry) {
            $path = $entry['path'];
            if (isset($seen[$path])) {
                $this->findings[] = "nav.json lists \"{$path}\" more than once.";
            }
            $seen[$path] = true;
            if (! isset($sources[$path])) {
                $this->findings[] = "nav.json points at docs/{$path}.md, which does not exist.";
            }
        }
        foreach (array_keys($sources) as $path) {
            if (! isset($seen[$path])) {
                $this->findings[] = "docs/{$path}.md is not in nav.json.";
            }
        }
    }

    /** The optional "source" and "mirrors" keys name the repo; they must be https URLs or they are findings. */
    private function checkSource(): void
    {
        if (isset($this->nav['source']) && ! $this->isHttpsUrl($this->nav['source'])) {
            $this->findings[] = 'nav.json source must be an https URL';
        }
        foreach ((array) ($this->nav['mirrors'] ?? []) as $mirror) {
            if (is_array($mirror) && isset($mirror['url']) && ! $this->isHttpsUrl($mirror['url'])) {
                $this->findings[] = 'nav.json source must be an https URL';
            }
        }
    }

    private function isHttpsUrl(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, 'https://');
    }

    /** @return ?string the canonical repo URL from nav.json, when set and an https URL */
    private function sourceUrl(): ?string
    {
        $source = $this->nav['source'] ?? null;
        if (! is_string($source) || ! str_starts_with($source, 'https://')) {
            return null;
        }

        return $source;
    }

    /** @return list<array{title: string, url: string}> the mirror links from nav.json, https only */
    private function mirrorLinks(): array
    {
        $out = [];
        foreach ((array) ($this->nav['mirrors'] ?? []) as $mirror) {
            if (! is_array($mirror)) {
                continue;
            }
            $url = $mirror['url'] ?? null;
            if (! is_string($url) || ! str_starts_with($url, 'https://')) {
                continue;
            }
            $title = $mirror['title'] ?? '';
            $out[] = ['title' => is_string($title) ? $title : '', 'url' => $url];
        }

        return $out;
    }

    /** The Source anchor for the header or footer, or '' when nav.json has no https source. */
    private function sourceAnchor(string $class = ''): string
    {
        $url = $this->sourceUrl();
        if ($url === null) {
            return '';
        }
        $attr = $class === '' ? '' : ' class="'.$class.'"';

        return "<a{$attr} rel=\"noopener\" href=\"".htmlspecialchars($url, ENT_QUOTES).'">Source</a>';
    }

    /** The footer's "Mirrors:" links from nav.json, or '' when there are none. */
    private function mirrorsLine(): string
    {
        $links = $this->mirrorLinks();
        if ($links === []) {
            return '';
        }
        $out = 'Mirrors:';
        foreach ($links as $link) {
            $label = $link['title'] !== '' ? $link['title'] : 'Mirror';
            $out .= ' <a rel="noopener" href="'.htmlspecialchars($link['url'], ENT_QUOTES).'">'.htmlspecialchars($label, ENT_QUOTES).'</a>';
        }

        return $out;
    }

    /** Whether the nav lists the generated changelog page, in any group. */
    private function navListsChangelog(): bool
    {
        foreach ($this->flattenNav() as $entry) {
            if ($entry['path'] === 'changelog') {
                return true;
            }
        }

        return false;
    }

    /**
     * The generated changelog page, when the nav lists one: [path under the repo root, content]. Null when the
     * nav does not list a changelog page, or when it does and CHANGELOG.md is missing (a finding).
     *
     * @return array{0: string, 1: string}|null
     */
    public function changelogPage(): ?array
    {
        if (! $this->navListsChangelog()) {
            return null;
        }
        $path = $this->root.'/CHANGELOG.md';
        if (! is_file($path)) {
            $this->findings[] = 'nav.json lists changelog but CHANGELOG.md does not exist';

            return null;
        }
        $text = $this->normalise((string) file_get_contents($path));
        $body = (string) preg_replace('/\A#[ \t]+\S[^\n]*\n+/', '', $text);
        $body = rtrim($this->rewriteChangelogLinks($body), "\n");
        $page = "---\ntitle: \"Changelog\"\ndescription: All notable changes to this project.\n---\n\n"
            ."<!-- Generated from CHANGELOG.md by bin/docs-site.php. Edit CHANGELOG.md, not this file. -->\n\n"
            .$body."\n";

        return ['docs/changelog.md', $page];
    }

    /**
     * The link rules for the generated changelog page: http(s) and other scheme links stay, a pure fragment
     * stays, and a relative link becomes a blob link under the nav.json source, or loses its link and keeps its
     * label when there is no source. Markdown images are left alone.
     */
    private function rewriteChangelogLinks(string $body): string
    {
        $source = $this->sourceUrl();

        return (string) preg_replace_callback(
            '/(?<!!)\[([^\]]*)\]\(([^()\n]+)\)/',
            static function (array $m) use ($source): string {
                $label = $m[1];
                $target = trim($m[2]);
                if (preg_match('/^(.*?)\s+["\'][^"\']*["\']$/', $target, $t) === 1) {
                    $target = trim($t[1]);
                }
                if (str_starts_with($target, '<') && str_ends_with($target, '>')) {
                    $target = substr($target, 1, -1);
                }
                if ($target === '' || str_starts_with($target, '#')) {
                    return $m[0];
                }
                if (preg_match('/^(?:[a-z][a-z0-9+.-]*:|\/\/)/i', $target) === 1) {
                    return $m[0];
                }
                if ($source === null) {
                    return $label;
                }

                return '['.$label.']('.rtrim($source, '/').'/-/blob/main/'.$target.')';
            },
            $body,
        );
    }

    private function versionStamp(): string
    {
        $path = $this->root.'/CHANGELOG.md';
        if (! is_file($path)) {
            return 'unreleased';
        }
        $text = $this->normalise((string) file_get_contents($path));
        if (preg_match('/^##\s+\[?(\d+(?:\.\d+)*)\]?\b/m', $text, $m)) {
            return $m[1];
        }

        return 'unreleased';
    }

    /**
     * The version stamp gates: composer.json's version field must agree with the stamp, and the opt-in
     * --require-release and --require-tag flags demand a released heading and a latest tag that matches it.
     */
    private function checkStamp(string $stamp): void
    {
        $composer = $this->root.'/composer.json';
        if (is_file($composer)) {
            $data = json_decode((string) file_get_contents($composer), true);
            $version = is_array($data) ? ($data['version'] ?? null) : null;
            if (is_string($version) && $version !== '' && $version !== $stamp) {
                $this->findings[] = "composer.json version {$version} does not match the docs stamp {$stamp}";
            }
        }
        if ($this->requireRelease && $stamp === 'unreleased') {
            $this->findings[] = 'CHANGELOG.md has no released heading';
        }
        if (! $this->requireTag) {
            return;
        }
        $process = proc_open(['git', 'tag', '--list', '--sort=-v:refname'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root);
        if (! is_resource($process)) {
            $this->findings[] = '--require-tag: git is not available';

            return;
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) {
            $detail = trim($stderr);
            $more = $detail === '' ? '' : ': '.$detail;
            $this->findings[] = "--require-tag: git tag --list exited with code {$code}{$more}";

            return;
        }
        $tag = null;
        foreach (explode("\n", $this->normalise($stdout)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^v?(\d+\.\d+\.\d+)/', $line, $m) === 1) {
                $tag = $m[1];

                break;
            }
        }
        if ($tag === null) {
            $this->findings[] = '--require-tag: the repo has no version tags';

            return;
        }
        if ($tag !== $stamp) {
            $this->findings[] = "the docs stamp {$stamp} does not match the latest tag {$tag}";
        }
    }

    private function projectTitle(): string
    {
        return is_string($this->nav['title'] ?? null) ? $this->nav['title'] : basename($this->root);
    }

    private function checkWayneUi(): void
    {
        if (! is_file($this->root.'/site/assets/wayne-ui/wayne-ui.css')) {
            $this->findings[] = 'site/assets/wayne-ui/wayne-ui.css is missing; the pages link it.';
        }
    }

    private function checkKit(): void
    {
        foreach (DocsKit::FILES as $relative => $hash) {
            $path = $this->root.'/site/'.$relative;
            if (! is_file($path)) {
                $this->findings[] = "site/{$relative} is missing. Copy the kit with Squire: php bin/docs-site.php --sync-to=<this repo>.";

                continue;
            }
            if (hash('sha256', $this->normalise((string) file_get_contents($path))) !== $hash) {
                $this->findings[] = "site/{$relative} differs from Squire's kit. Never edit it here; change the kit in Squire and sync.";
            }
        }
    }

    /** The optional docs/docs.json defines the generated blocks, by name, when it carries a blocks key. */
    private function loadBlocks(): void
    {
        $path = $this->docs.'/docs.json';
        if (! is_file($path)) {
            return;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || array_is_list($data)) {
            throw new DocsSiteError("{$path} must be a JSON object.");
        }
        if (! isset($data['blocks'])) {
            return;
        }
        if (! is_array($data['blocks'])) {
            throw new DocsSiteError("{$path} needs a \"blocks\" object.");
        }
        foreach ($data['blocks'] as $name => $block) {
            $name = (string) $name;
            if ($name === '' || ! is_array($block) || ! isset($block['type']) || ! is_string($block['type'])) {
                throw new DocsSiteError("{$path} lists a block that is not an object with a type.");
            }
            $this->blocks[$name] = $block;
        }
    }

    /**
     * The optional theme key of docs/docs.json: a css file copied to site/docs/theme/ and linked after docs.css,
     * and a header partial that replaces the generic docs header. Both are plain relative paths under docs/, and a
     * broken config is a tool error naming the key.
     */
    private function loadTheme(): void
    {
        $path = $this->docs.'/docs.json';
        if (! is_file($path)) {
            return;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || array_is_list($data) || ! array_key_exists('theme', $data)) {
            return;
        }
        $theme = $data['theme'];
        // An empty object is a no-op: every generated byte matches the unthemed build.
        if (! is_array($theme) || ($theme !== [] && array_is_list($theme))) {
            throw new DocsSiteError("{$path}: the \"theme\" key must be an object.");
        }
        foreach ($theme as $key => $value) {
            $key = (string) $key;
            if ($key !== 'css' && $key !== 'header') {
                throw new DocsSiteError("{$path}: the theme key \"{$key}\" is unknown; theme takes css and header.");
            }
            if (! is_string($value) || $value === '') {
                throw new DocsSiteError("{$path}: theme.{$key} must be a non-empty string.");
            }
            if (! $this->plainRelativePath($value)) {
                throw new DocsSiteError("{$path}: theme.{$key} must be a plain relative path: no leading slash, no drive letter, no .. segment, no backslash.");
            }
            if (! is_file($this->docs.'/'.$value)) {
                throw new DocsSiteError("{$path}: theme.{$key} points at docs/{$value}, which does not exist.");
            }
            if ($key === 'css') {
                $this->themeCss = $value;
            } else {
                $this->themeHeader = $this->readHeaderPartial($value);
            }
        }
    }

    /** A plain relative path: no leading slash, no drive letter, no .. segment, no backslash. */
    private function plainRelativePath(string $path): bool
    {
        if ($path === '' || str_contains($path, '\\') || str_starts_with($path, '/')) {
            return false;
        }
        if (preg_match('/^[A-Za-z]:/', $path) === 1) {
            return false;
        }

        return ! in_array('..', explode('/', $path), true);
    }

    /**
     * The theme header partial, read and guarded: exactly one closed <header> element, no script, no main
     * landmark, no skip link, no mobile menu, and only the {{project}} and {{version}} placeholders.
     */
    private function readHeaderPartial(string $relative): string
    {
        $html = $this->normalise((string) file_get_contents($this->docs.'/'.$relative));
        if (preg_match_all('/<header\b/i', $html) !== 1) {
            throw new DocsSiteError('the theme header must contain exactly one <header> element.');
        }
        if (preg_match('/<header\b[^>]*>.*?<\/header>/is', $html, $element) !== 1) {
            throw new DocsSiteError('the theme header must contain one closed <header> element.');
        }
        foreach ([
            '<script' => 'a <script> element',
            'id="main"' => 'an id="main" attribute',
            'wsu-skip-link' => 'a wsu-skip-link',
            'docs-menu' => 'a docs-menu',
            '<main' => 'a <main> element',
        ] as $needle => $what) {
            if (stripos($html, $needle) !== false) {
                throw new DocsSiteError("the theme header carries {$what}; the builder owns that part of the page.");
            }
        }
        $unknown = [];
        if (preg_match_all('/\{\{[^}]*\}\}/', $html, $matches) > 0) {
            foreach ($matches[0] as $placeholder) {
                $name = trim(substr($placeholder, 2, -2));
                if ($name !== 'project' && $name !== 'version') {
                    $unknown[] = $placeholder;
                }
            }
        }
        if ($unknown !== []) {
            throw new DocsSiteError('the theme header uses unknown placeholders '.implode(', ', $unknown).'; the builder fills {{project}} and {{version}}.');
        }

        return $element[0];
    }

    /**
     * Findings for the block wiring, then the staleness of every placed region.
     *
     * @param  array<string, string>  $sources
     */
    private function checkBlocks(array $sources): void
    {
        $markers = $this->blockMarkers($sources);
        $placed = [];
        foreach ($markers as $marker) {
            $placed[$marker['name']][] = $marker;
        }
        foreach ($placed as $name => $list) {
            if (! isset($this->blocks[$name])) {
                $this->findings[] = "docs/{$list[0]['path']}.md places a generated block \"{$name}\" that docs.json does not define.";

                continue;
            }
            if (count($list) > 1) {
                $where = [];
                foreach ($list as $marker) {
                    $where[] = 'docs/'.$marker['path'].'.md';
                }
                $this->findings[] = 'generated block "'.$name.'" is placed more than once: '.implode(', ', $where).'.';
            }
        }
        foreach (array_keys($this->blocks) as $name) {
            if (! isset($placed[$name])) {
                $this->findings[] = "docs.json defines a generated block \"{$name}\" that no page places.";
            }
        }
        $computed = $this->computeBlocks(array_keys($placed));
        $this->computed = $computed;
        foreach ($markers as $marker) {
            $name = $marker['name'];
            if (count($placed[$name]) !== 1 || ! array_key_exists($name, $computed)) {
                continue;
            }
            if ($marker['content'] !== $computed[$name]) {
                $this->staleBlocks[] = "docs/{$marker['path']}.md (generated block \"{$name}\" is out of date)";
            }
        }
    }

    /**
     * Every closed block region in every source, with its current content. An opening marker with no closing
     * marker is a finding here, so it is recorded exactly once.
     *
     * @param  array<string, string>  $sources
     * @return list<array{path: string, name: string, start: int, end: int, content: string}>
     */
    private function blockMarkers(array $sources): array
    {
        $markers = [];
        foreach ($sources as $path => $file) {
            $raw = $this->normalise((string) file_get_contents($file));
            foreach ($this->blockRegions($raw) as $region) {
                if (! $region['closed']) {
                    $this->findings[] = "docs/{$path}.md opens a generated block \"{$region['name']}\" without a closing marker.";

                    continue;
                }
                $markers[] = [
                    'path' => $path,
                    'name' => $region['name'],
                    'start' => $region['start'],
                    'end' => $region['end'],
                    'content' => substr($raw, $region['start'], $region['end'] - $region['start']),
                ];
            }
        }

        return $markers;
    }

    /**
     * The block markers in one source, in order. A closed region carries the byte offsets of the content between
     * the markers; an opening marker with no closing marker carries closed = false and ends the scan.
     *
     * @return list<array{name: string, closed: bool, start: int, end: int}>
     */
    private function blockRegions(string $raw): array
    {
        $regions = [];
        $lines = explode("\n", $raw);
        $starts = [];
        $offset = 0;
        foreach ($lines as $i => $line) {
            $starts[$i] = $offset;
            $offset += strlen($line) + 1;
        }
        $count = count($lines);
        $inFence = false;
        $fenceChar = '';
        $fenceLength = 0;
        for ($i = 0; $i < $count; $i++) {
            if (docs_site_fence_line($lines[$i], $inFence, $fenceChar, $fenceLength)) {
                continue;
            }
            if ($inFence || preg_match('/^\s*<!--\s+generated:([A-Za-z0-9_-]+)\s+-->\s*$/', $lines[$i], $open) !== 1) {
                continue;
            }
            $name = $open[1];
            $close = null;
            for ($j = $i + 1; $j < $count; $j++) {
                if (docs_site_fence_line($lines[$j], $inFence, $fenceChar, $fenceLength)) {
                    continue;
                }
                if (preg_match('/^\s*<!--\s+\/generated\s+-->\s*$/', $lines[$j]) === 1) {
                    $close = $j;

                    break;
                }
            }
            if ($close === null) {
                $regions[] = ['name' => $name, 'closed' => false, 'start' => 0, 'end' => 0];

                return $regions;
            }
            $regions[] = [
                'name' => $name,
                'closed' => true,
                'start' => $starts[$i] + strlen($lines[$i]) + 1,
                'end' => $starts[$close],
            ];
            $i = $close;
        }

        return $regions;
    }

    /**
     * The two-way coverage gate for the symfony-commands blocks: a code span or fenced line that starts with the
     * cli name must name a real command (help and list always pass), and every --option on it must be an option
     * of that command or an application option; every kept command and option must appear in the docs at least
     * once, where the generated block counts. Runs once the contents are final, before the pages render.
     *
     * @param  array<string, string>  $sources
     * @param  array<string, string>  $contents
     */
    private function checkCoverage(array $sources, array $contents): void
    {
        if ($this->computed === null) {
            $this->computed = [];
        }
        foreach ($this->blocks as $name => $block) {
            if ((string) ($block['type'] ?? '') !== 'symfony-commands' || array_key_exists($name, $this->computed)) {
                continue;
            }
            $this->computed[$name] = $this->renderSymfonyCommands($name, $block);
        }
        foreach ($this->commandSets as $cli => $set) {
            $seen = [];
            $all = '';
            foreach ($sources as $path => $file) {
                $all .= $contents[$path]."\n";
                foreach ($this->codeRegions($contents[$path]) as $line) {
                    $text = ltrim($line);
                    if (str_starts_with($text, '$ ')) {
                        $text = substr($text, 2);
                    }
                    if (str_starts_with($text, 'vendor/bin/')) {
                        $text = substr($text, strlen('vendor/bin/'));
                    }
                    if (preg_match('/^'.preg_quote($cli, '/').'\s+([a-z][a-z:_-]*)/', $text, $m) !== 1) {
                        continue;
                    }
                    $command = $m[1];
                    if ($command !== 'help' && $command !== 'list' && ! isset($set[$command])) {
                        $key = 'c|'.$path.'|'.$command;
                        if (! isset($seen[$key])) {
                            $seen[$key] = true;
                            $this->findings[] = "docs/{$path}.md mentions \"{$cli} {$command}\" but the CLI has no command {$command}.";
                        }

                        continue;
                    }
                    $allowed = self::APPLICATION_OPTIONS;
                    foreach ($set[$command] ?? [] as $option) {
                        $allowed['--'.$option] = true;
                    }
                    if (preg_match_all('/--[A-Za-z][A-Za-z0-9-]*(?:=[^\s]*)?/', $text, $tokens) === 0) {
                        continue;
                    }
                    foreach ($tokens[0] as $token) {
                        $name = '--'.(string) preg_replace('/=.*$/', '', substr($token, 2));
                        if (isset($allowed[$name])) {
                            continue;
                        }
                        $key = 'o|'.$path.'|'.$command.'|'.$name;
                        if (! isset($seen[$key])) {
                            $seen[$key] = true;
                            $this->findings[] = "docs/{$path}.md uses {$cli} {$command} {$name}, which {$command} does not accept.";
                        }
                    }
                }
            }
            foreach ($set as $command => $options) {
                if (preg_match('/(?<![\w-])'.preg_quote($cli.' '.$command, '/').'(?![\w-])/', $all) !== 1) {
                    $this->findings[] = "command \"{$command}\" is in the CLI but not documented.";
                }
                foreach ($options as $option) {
                    if (preg_match('/(?<![\w-])--'.preg_quote($option, '/').'(?![\w-])/', $all) !== 1) {
                        $this->findings[] = "option \"--{$option}\" of {$command} is in the CLI but not documented.";
                    }
                }
            }
        }
    }

    /**
     * The code spans and fenced code lines of one page, from the CommonMark AST. Plain prose is never scanned,
     * and the content between generated-block markers is removed first, so the builder's own regions stay out of
     * the gate. A fence whose info string carries docs-test: skip is not scanned.
     *
     * @return list<string>
     */
    private function codeRegions(string $raw): array
    {
        foreach (array_reverse($this->blockRegions($raw)) as $region) {
            if (! $region['closed']) {
                continue;
            }
            $raw = substr($raw, 0, $region['start']).substr($raw, $region['end']);
        }
        [, $body] = $this->frontMatter($raw);
        $env = new Environment;
        $env->addExtension(new CommonMarkCoreExtension);
        $env->addExtension(new GithubFlavoredMarkdownExtension);
        $document = (new MarkdownParser($env))->parse($body);
        $out = [];
        foreach ($document->iterator() as $node) {
            if ($node instanceof Code) {
                $out[] = (string) $node->getLiteral();

                continue;
            }
            if ($node instanceof FencedCode) {
                if (str_contains((string) $node->getInfo(), 'docs-test: skip')) {
                    continue;
                }
                foreach (explode("\n", (string) $node->getLiteral()) as $line) {
                    $out[] = $line;
                }
            }
        }

        return $out;
    }

    /**
     * The rendered content of the given blocks, by name; absent for a type that is not implemented yet.
     * A failed command stops the build.
     *
     * @param  list<string>  $names
     * @return array<string, string>
     */
    private function computeBlocks(array $names): array
    {
        $out = [];
        foreach ($names as $name) {
            if (! isset($this->blocks[$name])) {
                continue;
            }
            $block = $this->blocks[$name];
            $type = (string) $block['type'];
            if ($type === 'json-table') {
                $out[$name] = $this->renderJsonTable($name, $block);

                continue;
            }
            if ($type === 'symfony-commands') {
                $out[$name] = $this->renderSymfonyCommands($name, $block);

                continue;
            }
            if ($type === 'env') {
                $out[$name] = $this->renderEnv($name, $block);

                continue;
            }
            $line = "block type {$type} is not implemented";
            if (! in_array($line, $this->findings, true)) {
                $this->findings[] = $line;
            }
        }

        return $out;
    }

    /**
     * Rewrites every stale block region in the sources, writing a file only when its content changed.
     *
     * @param  array<string, string>  $sources
     * @param  array<string, string>  $contents
     */
    private function rewriteBlocks(array $sources, array &$contents): void
    {
        $computed = $this->computed;
        if ($computed === null) {
            return;
        }
        foreach ($sources as $path => $file) {
            $raw = $contents[$path];
            $new = $raw;
            foreach (array_reverse($this->blockRegions($raw)) as $region) {
                if (! $region['closed'] || ! array_key_exists($region['name'], $computed)) {
                    continue;
                }
                $new = substr($new, 0, $region['start']).$computed[$region['name']].substr($new, $region['end']);
            }
            if ($new === $raw) {
                continue;
            }
            if (file_put_contents($file, $new) === false) {
                throw new DocsSiteError("Cannot write {$file}.");
            }
            $contents[$path] = $new;
            fwrite(STDOUT, "updated docs/{$path}.md\n");
        }
    }

    /**
     * Runs the block's command, takes the list at its dotted path and renders it as a Markdown table.
     *
     * @param  array<string, mixed>  $block
     */
    private function renderJsonTable(string $name, array $block): string
    {
        $run = $block['run'] ?? null;
        if (! is_string($run) || trim($run) === '') {
            throw new DocsSiteError("docs.json block \"{$name}\" needs a run command.");
        }
        $data = $this->runBlockCommand($name, $run);
        $path = is_string($block['path'] ?? null) ? $block['path'] : '';
        $rows = $this->pathValue($data, $path);
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new DocsSiteError("docs.json block \"{$name}\": the path \"{$path}\" did not reach a list.");
        }
        $columns = $block['columns'] ?? null;
        if (! is_array($columns) || $columns === []) {
            throw new DocsSiteError("docs.json block \"{$name}\" needs a columns list.");
        }
        $head = [];
        $keys = [];
        foreach ($columns as $column) {
            if (! is_array($column) || count($column) < 2 || ! is_string($column[0]) || ! is_string($column[1])) {
                throw new DocsSiteError("docs.json block \"{$name}\": each column is [heading, key, optional \"code\"].");
            }
            $head[] = $this->cellText($column[0]);
            $keys[] = [$column[1], isset($column[2]) && $column[2] === 'code'];
        }
        $lines = ['| '.implode(' | ', $head).' |', '| '.implode(' | ', array_fill(0, count($head), '---')).' |'];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($keys as $key) {
                $value = is_array($row) ? ($row[$key[0]] ?? null) : null;
                $text = $this->cellText($value);
                $cells[] = $key[1] && $text !== '' ? '`'.$text.'`' : $text;
            }
            $lines[] = '| '.implode(' | ', $cells).' |';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * The symfony-commands type: runs <run> list --format=json and renders the command reference: an overview
     * table, then per command a heading, its description, its usage lines, and its argument and option tables.
     *
     * @param  array<string, mixed>  $block
     */
    private function renderSymfonyCommands(string $name, array $block): string
    {
        $run = $block['run'] ?? null;
        if (! is_string($run) || trim($run) === '') {
            throw new DocsSiteError("docs.json block \"{$name}\" needs a run command.");
        }
        $cli = $block['cli'] ?? null;
        if (! is_string($cli) || trim($cli) === '') {
            throw new DocsSiteError("docs.json block \"{$name}\" needs a cli name for the code spans.");
        }
        $level = $block['level'] ?? 3;
        if (! is_int($level) || $level < 1 || $level > 6) {
            throw new DocsSiteError("docs.json block \"{$name}\": level must be an integer from 1 to 6.");
        }
        $data = $this->runBlockCommand($name, trim($run).' list --format=json');
        $commands = $data['commands'] ?? null;
        if (! is_array($commands) || ! array_is_list($commands)) {
            throw new DocsSiteError("docs.json block \"{$name}\": the command output needs a commands list.");
        }
        $kept = [];
        foreach ($commands as $command) {
            if (! is_array($command) || ! isset($command['name']) || ! is_string($command['name'])) {
                continue;
            }
            if (in_array($command['name'], ['help', 'list', 'completion', '_complete'], true) || (bool) ($command['hidden'] ?? false)) {
                continue;
            }
            $kept[] = $command;
        }
        $this->commandSets[$cli] = [];
        foreach ($kept as $command) {
            $keys = [];
            $definition = $command['definition'] ?? [];
            $options = is_array($definition) ? ($definition['options'] ?? []) : [];
            if (is_array($options)) {
                foreach ($options as $key => $option) {
                    $name = is_array($option) && is_string($option['name'] ?? null) && $option['name'] !== '' ? ltrim($option['name'], '-') : '';
                    if ($name === '' && is_string($key) && $key !== '') {
                        $name = $key;
                    }
                    if ($name !== '') {
                        $keys[] = $name;
                    }
                }
            }
            $this->commandSets[$cli][$command['name']] = $keys;
        }
        $hash = str_repeat('#', $level);
        $lines = ['| Command | What it does |', '| --- | --- |'];
        foreach ($kept as $command) {
            $lines[] = '| `'.$cli.' '.$command['name'].'` | '.$this->cellText($command['description'] ?? '').' |';
        }
        $appOptions = [];
        foreach ($kept as $command) {
            $lines[] = '';
            $lines[] = $hash.' `'.$cli.' '.$command['name'].'`';
            $description = $this->cellText($command['description'] ?? '');
            if ($description !== '') {
                $lines[] = '';
                $lines[] = $description;
            }
            $usage = $command['usage'] ?? [];
            if (is_array($usage)) {
                foreach ($usage as $entry) {
                    if (! is_string($entry) || trim($entry) === '') {
                        continue;
                    }
                    $lines[] = '';
                    $lines[] = 'Usage: `'.$cli.' '.$entry.'`';
                }
            }
            $definition = $command['definition'] ?? [];
            if (! is_array($definition)) {
                $definition = [];
            }
            $arguments = $definition['arguments'] ?? [];
            if (is_array($arguments) && $arguments !== []) {
                $lines[] = '';
                $lines[] = '| Argument | Required | Meaning |';
                $lines[] = '| --- | --- | --- |';
                foreach ($arguments as $key => $argument) {
                    if (! is_array($argument)) {
                        continue;
                    }
                    $argName = is_string($argument['name'] ?? null) && $argument['name'] !== '' ? $argument['name'] : (string) $key;
                    $required = ($argument['is_required'] ?? false) === true ? 'yes' : 'no';
                    $lines[] = '| `'.$argName.'` | '.$required.' | '.$this->cellText($argument['description'] ?? '').' |';
                }
            }
            $options = $definition['options'] ?? [];
            if (is_array($options)) {
                $rows = [];
                foreach ($options as $key => $option) {
                    if (! is_array($option)) {
                        continue;
                    }
                    $optName = is_string($option['name'] ?? null) && $option['name'] !== '' ? $option['name'] : (string) $key;
                    if (isset(self::APPLICATION_OPTIONS[$optName])) {
                        $appOptions[$optName] ??= $option;

                        continue;
                    }
                    $rows[] = '| '.$this->optionCell($option, $optName).' | '.$this->cellText($option['description'] ?? '').' | '.$this->defaultCell($option['default'] ?? null).' |';
                }
                if ($rows !== []) {
                    $lines[] = '';
                    $lines[] = '| Option | Meaning | Default |';
                    $lines[] = '| --- | --- | --- |';
                    foreach ($rows as $row) {
                        $lines[] = $row;
                    }
                }
            }
        }
        if ($appOptions !== []) {
            $lines[] = '';
            $lines[] = $hash.' Options every command accepts';
            $lines[] = '';
            $lines[] = '| Option | Meaning | Default |';
            $lines[] = '| --- | --- | --- |';
            foreach ($appOptions as $option) {
                $optName = is_string($option['name'] ?? null) && $option['name'] !== '' ? $option['name'] : '';
                $lines[] = '| '.$this->optionCell($option, $optName).' | '.$this->cellText($option['description'] ?? '').' | '.$this->defaultCell($option['default'] ?? null).' |';
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * The option cell: the shortcut first when there is one, then the name, with =VALUE when the option takes a value.
     *
     * @param  array<string, mixed>  $option
     */
    private function optionCell(array $option, string $name): string
    {
        $label = ($option['accept_value'] ?? false) === true ? $name.'=VALUE' : $name;
        $shortcut = $option['shortcut'] ?? null;
        if (is_string($shortcut) && $shortcut !== '') {
            return '`'.$shortcut.'`, `'.$label.'`';
        }

        return '`'.$label.'`';
    }

    /** The default cell: the default in a code span, or empty when null or false. */
    private function defaultCell(mixed $default): string
    {
        if ($default === null || $default === false) {
            return '';
        }
        $text = is_bool($default) ? 'true' : $this->cellText($default);

        return $text === '' ? '' : '`'.$text.'`';
    }

    /**
     * The env type: scans the scan folders for quoted environment variable names that start with prefix and
     * renders them sorted by name, with the meaning and default from describe and the first file that reads them.
     *
     * @param  array<string, mixed>  $block
     */
    private function renderEnv(string $name, array $block): string
    {
        $scan = $block['scan'] ?? null;
        if (! is_array($scan) || $scan === [] || ! array_is_list($scan)) {
            throw new DocsSiteError("docs.json block \"{$name}\" needs a scan list of folders.");
        }
        $prefix = $block['prefix'] ?? null;
        if (! is_string($prefix) || $prefix === '') {
            throw new DocsSiteError("docs.json block \"{$name}\" needs a prefix.");
        }
        $ignore = $block['ignore'] ?? [];
        if (! is_array($ignore)) {
            throw new DocsSiteError("docs.json block \"{$name}\": ignore must be a list of names.");
        }
        $ignoreList = [];
        foreach ($ignore as $entry) {
            $ignoreList[] = $entry;
        }
        $describe = $block['describe'] ?? [];
        if (! is_array($describe)) {
            throw new DocsSiteError("docs.json block \"{$name}\": describe must be an object of [meaning, default].");
        }
        $described = [];
        foreach ($describe as $variable => $entry) {
            if (! is_string($variable) || ! is_array($entry)) {
                throw new DocsSiteError("docs.json block \"{$name}\": describe must be an object of [meaning, default].");
            }
            $described[$variable] = [$entry[0] ?? null, $entry[1] ?? null];
        }
        $files = [];
        foreach ($scan as $folder) {
            if (! is_string($folder) || trim($folder) === '') {
                throw new DocsSiteError("docs.json block \"{$name}\": each scan folder must be a path.");
            }
            $folder = rtrim($folder, '/\\');
            if (! is_dir($this->root.'/'.$folder)) {
                $this->findings[] = "env block \"{$name}\": the scan folder {$folder} does not exist.";

                continue;
            }
            foreach ($this->walk($this->root.'/'.$folder, 'php') as $relative) {
                $files[$folder.'/'.$relative] = true;
            }
        }
        ksort($files);
        $found = [];
        foreach (array_keys($files) as $file) {
            $source = (string) file_get_contents($this->root.'/'.$file);
            $matches = [];
            if (preg_match_all('/[\'"]([A-Z][A-Z0-9_]*)[\'"]/', $source, $matches) === false) {
                continue;
            }
            foreach ($matches[1] as $variable) {
                if (! str_starts_with($variable, $prefix) || $this->envIgnored($variable, $ignoreList)) {
                    continue;
                }
                $found[$variable] ??= $file;
            }
        }
        ksort($found);
        foreach ($found as $variable => $file) {
            if (! array_key_exists($variable, $described)) {
                $this->findings[] = "env block \"{$name}\": {$variable} is read in {$file} but has no describe entry.";
            }
        }
        foreach (array_keys($described) as $variable) {
            if (! isset($found[$variable])) {
                $this->findings[] = "env block \"{$name}\": describe lists {$variable}, which no scanned file reads.";
            }
        }
        $lines = ['| Variable | Meaning | Default | Read in |', '| --- | --- | --- | --- |'];
        foreach ($found as $variable => $file) {
            $entry = $described[$variable] ?? [null, null];
            $lines[] = '| '.$this->cellText($variable).' | '.$this->cellText($entry[0]).' | '.$this->cellText($entry[1]).' | '.$this->cellText($file).' |';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Whether an env name matches the ignore list: an exact name, or a trailing * for a prefix.
     *
     * @param  list<mixed>  $ignore
     */
    private function envIgnored(string $name, array $ignore): bool
    {
        foreach ($ignore as $entry) {
            if (! is_string($entry) || $entry === '') {
                continue;
            }
            if (str_ends_with($entry, '*')) {
                if (str_starts_with($name, substr($entry, 0, -1))) {
                    return true;
                }

                continue;
            }
            if ($entry === $name) {
                return true;
            }
        }

        return false;
    }

    /** A cell's text: booleans print yes or no, lists print comma separated, Symfony console style tags are stripped, pipes are escaped, newlines collapse to spaces. */
    private function cellText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                $parts[] = $this->cellText($item);
            }

            return implode(', ', $parts);
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (! is_string($value)) {
            return '';
        }
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = (string) preg_replace('/\s*\n+\s*/', ' ', $value);
        // Symfony console style tags would otherwise reach the table as literal angle text, so strip them before
        // escaping. Other angle text (for example a <path> placeholder) is left alone.
        $value = (string) preg_replace('/<\/?(?:info|comment|error|question)>|<fg=[^>]*>|<\/>/', '', $value);

        return str_replace('|', '\|', trim($value));
    }

    /**
     * The value at a dotted path inside decoded JSON, or null when the path does not reach.
     *
     * @param  array<mixed>  $data
     */
    private function pathValue(array $data, string $path): mixed
    {
        if ($path === '') {
            return $data;
        }
        $value = $data;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Runs a block's command from the repo root and returns its stdout as decoded JSON. A leading "php " runs
     * PHP_BINARY, so the same docs.json works on Windows, macOS and Linux.
     *
     * @return array<mixed>
     */
    private function runBlockCommand(string $name, string $command): array
    {
        $argv = preg_split('/\s+/', trim($command)) ?: [];
        if ($argv === []) {
            throw new DocsSiteError("docs.json block \"{$name}\" has an empty run command.");
        }
        if ($argv[0] === 'php') {
            $argv = array_merge([PHP_BINARY], array_slice($argv, 1));
        }
        $process = proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root);
        if (! is_resource($process)) {
            throw new DocsSiteError("Could not run the command for docs.json block \"{$name}\": {$command}");
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) {
            $detail = trim($stderr);
            $more = $detail === '' ? '' : ' '.$detail;
            throw new DocsSiteError("The command for docs.json block \"{$name}\" ({$command}) exited with code {$code}.".$more);
        }
        $data = json_decode($stdout, true);
        if (! is_array($data)) {
            throw new DocsSiteError("The command for docs.json block \"{$name}\" ({$command}) did not print JSON.");
        }

        return $data;
    }

    /**
     * GitHub alert callouts: a blockquote whose first paragraph opens with a marker line alone ([!NOTE], [!TIP],
     * [!WARNING] or [!DANGER], case-insensitive) is flagged for the callout renderer, and the marker line leaves
     * the body. Any other blockquote stays as written.
     */
    private function transformCallouts(Node $document): void
    {
        $quotes = [];
        foreach ($document->iterator() as $node) {
            if ($node instanceof BlockQuote) {
                $quotes[] = $node;
            }
        }
        foreach ($quotes as $quote) {
            $paragraph = $quote->firstChild();
            if (! $paragraph instanceof Paragraph) {
                continue;
            }
            $marker = $paragraph->firstChild();
            if (! $marker instanceof Text) {
                continue;
            }
            $line = trim($marker->getLiteral());
            if (! preg_match('/^\[!([A-Za-z]+)\]$/', $line, $match)) {
                continue;
            }
            $kind = strtolower($match[1]);
            if (! in_array($kind, self::CALLOUT_KINDS, true)) {
                continue;
            }
            $break = $marker->next();
            $marker->detach();
            if ($break instanceof Newline) {
                $break->detach();
            }
            if ($paragraph->firstChild() === null) {
                $paragraph->detach();
            }
            $quote->data->set('docs-callout', $kind);
        }
    }

    /**
     * Code tabs: every fenced block whose info string carries a valid tab="LABEL" word (double quotes, label up to
     * 40 characters) drops the tab="..." word from its info, so a language class never carries it. Every run of two
     * or more such siblings is marked as one set: each member is flagged, the first carries the set key and the last
     * a close marker, so the fence renderer wraps the run in one labeled set, every panel visible and named. Blocks
     * separated by anything are separate sets.
     */
    private function transformTabs(Node $document): void
    {
        foreach ($document->iterator() as $node) {
            if (! $node instanceof FencedCode) {
                continue;
            }
            [$language, $label, $clean] = $this->tabInfo((string) $node->getInfo());
            if ($clean !== (string) $node->getInfo()) {
                $node->setInfo($clean);
            }
            if ($label !== null) {
                $node->data->set('docs-tab', $label);
                $node->data->set('docs-language', $language);
            }
        }
        $parents = [];
        foreach ($document->iterator() as $node) {
            if ($node->hasChildren()) {
                $parents[] = $node;
            }
        }
        foreach ($parents as $parent) {
            $run = [];
            foreach ($parent->children() as $child) {
                if ($child instanceof FencedCode && is_string($child->data->get('docs-tab', null))) {
                    $run[] = $child;

                    continue;
                }
                $this->markTabRun($run);
                $run = [];
            }
            $this->markTabRun($run);
        }
    }

    /**
     * Marks a run of flagged fences as one set when it holds two or more: every member is flagged, the first carries
     * the set key (lowercased labels joined by a pipe) and the last a close marker, so the fence renderer can wrap the
     * run. A single flagged fence is left unmarked and renders as a plain block.
     *
     * @param  list<FencedCode>  $run
     */
    private function markTabRun(array $run): void
    {
        if (count($run) < 2) {
            return;
        }
        $key = implode('|', array_map(
            static fn (FencedCode $fence): string => strtolower((string) $fence->data->get('docs-tab')),
            $run,
        ));
        foreach ($run as $fence) {
            $fence->data->set('docs-tab-set', true);
        }
        $run[0]->data->set('docs-tab-key', $key);
        $run[count($run) - 1]->data->set('docs-tab-close', true);
    }

    /**
     * Splits a fence info string into its language, its tab label and the info without the tab="..." word. The
     * label is null when no word is a double-quoted tab="..." of at most 40 characters.
     *
     * @return array{0: string, 1: string|null, 2: string}
     */
    private function tabInfo(string $info): array
    {
        $language = '';
        $label = null;
        $kept = [];
        foreach (preg_split('/\s+/', trim($info)) ?: [] as $word) {
            if (preg_match('/^tab="([^"]*)"$/', $word, $match) === 1) {
                if ($label === null && strlen($match[1]) >= 1 && strlen($match[1]) <= 40) {
                    $label = $match[1];
                }

                continue;
            }
            if ($language === '') {
                $language = $word;
            }
            $kept[] = $word;
        }

        return [$language, $label, implode(' ', $kept)];
    }

    /**
     * @param  array<string, string>  $sources
     */
    private function renderPage(string $path, string $raw, array $sources, string $stamp, string $project): string
    {
        [$meta, $body] = $this->frontMatter($raw);
        $folder = dirname($path) === '.' ? '' : (string) dirname($path);

        $env = new Environment;
        $env->addExtension(new CommonMarkCoreExtension);
        $env->addExtension(new GithubFlavoredMarkdownExtension);
        // Callouts: a flagged blockquote renders as an aside with a label; every other blockquote keeps the core
        // markup byte for byte. Registered after the extensions, ahead of the core renderer's priority.
        $env->addRenderer(BlockQuote::class, new class implements NodeRendererInterface
        {
            /** @return Stringable */
            public function render(Node $node, ChildNodeRendererInterface $childRenderer)
            {
                if (! $node instanceof BlockQuote) {
                    throw new InvalidArgumentException('Unexpected node type: '.get_class($node));
                }
                $kind = $node->data->get('docs-callout', null);
                if (is_string($kind)) {
                    $label = new HtmlElement('p', ['class' => 'docs-callout__label'], ucfirst($kind));
                    $body = $childRenderer->renderNodes($node->children());

                    return new HtmlElement(
                        'aside',
                        ['class' => ['docs-callout', 'docs-callout--'.$kind], 'role' => 'note'],
                        (string) $label.$body,
                    );
                }
                $attrs = $node->data->get('attributes');
                $attrs = is_array($attrs) ? $attrs : [];
                $filling = $childRenderer->renderNodes($node->children());
                $innerSeparator = $childRenderer->getInnerSeparator();
                if ($filling === '') {
                    return new HtmlElement('blockquote', $attrs, $innerSeparator);
                }

                return new HtmlElement('blockquote', $attrs, $innerSeparator.$filling.$innerSeparator);
            }
        }, 10);
        // Code tabs: a fence marked as a set member renders as one labeled panel, the first of the run opening the
        // set and the last closing it, every panel visible and named so the page works without JavaScript; the kit
        // turns the set into an accessible tablist. Every other fenced block keeps the core markup byte for byte.
        $env->addRenderer(FencedCode::class, new class implements NodeRendererInterface
        {
            private FencedCodeRenderer $plain;

            public function __construct()
            {
                $this->plain = new FencedCodeRenderer;
            }

            /** @return Stringable */
            public function render(Node $node, ChildNodeRendererInterface $childRenderer)
            {
                if (! $node instanceof FencedCode) {
                    throw new InvalidArgumentException('Unexpected node type: '.get_class($node));
                }
                if ($node->data->get('docs-tab-set', null) !== true) {
                    return $this->plain->render($node, $childRenderer);
                }
                $label = (string) $node->data->get('docs-tab', '');
                $language = (string) $node->data->get('docs-language', '');
                $class = $language;
                if ($class !== '' && ! str_starts_with($class, 'language-')) {
                    $class = 'language-'.$class;
                }
                $attributes = $class === '' ? [] : ['class' => $class];
                $code = new HtmlElement('code', $attributes, Xml::escape($node->getLiteral()));
                $heading = new HtmlElement('p', ['class' => 'docs-tabs__label'], $label);
                $pre = new HtmlElement('pre', [], $code);
                $panel = new HtmlElement(
                    'div',
                    ['class' => 'docs-tabs__panel', 'data-tab-label' => $label],
                    (string) $heading.$pre,
                );
                $key = $node->data->get('docs-tab-key', null);
                $close = $node->data->get('docs-tab-close', null) === true;
                if (! is_string($key) && ! $close) {
                    return $panel;
                }
                $html = is_string($key) ? '<div class="docs-tabs" data-tabs="'.Xml::escape($key).'">' : '';
                $html .= (string) $panel;
                if ($close) {
                    $html .= '</div>';
                }

                // The wrapper tags span sibling nodes, so the fragment is returned as a Stringable, matching the
                // renderer contract.
                return new class($html) implements Stringable
                {
                    public function __construct(private readonly string $body) {}

                    public function __toString(): string
                    {
                        return $this->body;
                    }
                };
            }
        }, 10);
        $document = (new MarkdownParser($env))->parse($body);
        $this->transformCallouts($document);
        $this->transformTabs($document);
        [$toc, $h1] = $this->decorate($document, $folder, $sources);
        $content = (string) (new HtmlRenderer($env))->renderDocument($document)->getContent();
        $content = $this->wrapTables($content);
        $content = $this->labelTasks($content);

        $title = trim((string) ($meta['title'] ?? ''));
        if ($title === '') {
            $title = $h1;
        }
        if ($title === '') {
            $title = $this->navTitle($path);
        }
        if ($title === '') {
            $title = $this->prettify($path);
        }
        $description = trim((string) ($meta['description'] ?? ''));

        $up = str_repeat('../', 1 + substr_count($path, '/'));
        $name = basename($path);
        $nav = $this->navMarkup($path, $up);

        return $this->assemble($path, $title, $description, $stamp, $project, $up, $name, $nav, $content, $toc);
    }

    /**
     * Give headings ids and permalinks, rewrite the links, and collect the h2/h3 for the "On this page" list.
     *
     * @param  array<string, string>  $sources
     * @return array{0: list<array{level: int, id: string, text: string}>, 1: string}
     */
    private function decorate(Node $document, string $folder, array $sources): array
    {
        $headings = [];
        $links = [];
        foreach ($document->iterator() as $node) {
            if ($node instanceof Heading) {
                $headings[] = $node;
            } elseif ($node instanceof Link) {
                $links[] = $node;
            }
        }

        $used = [];
        $toc = [];
        $h1 = '';
        foreach ($headings as $heading) {
            $text = $this->nodeText($heading);
            $level = $heading->getLevel();
            if ($level === 1 && $h1 === '') {
                $h1 = $text;
            }
            $base = $this->slug($text);
            $id = $base;
            $n = 1;
            while (isset($used[$id])) {
                $id = $base.'-'.$n++;
            }
            $used[$id] = true;
            $heading->data->set('attributes', ['id' => $id]);
            $permalink = new Link('#'.$id);
            $permalink->data->set('attributes', ['class' => 'docs-heading-link', 'aria-label' => 'Link to this section']);
            $permalink->appendChild(new Text('#'));
            $heading->appendChild($permalink);
            if ($level === 2 || $level === 3) {
                $toc[] = ['level' => $level, 'id' => $id, 'text' => $text];
            }
        }

        foreach ($links as $link) {
            $this->decorateLink($link, $folder, $sources);
        }

        return [$toc, $h1];
    }

    /**
     * @param  array<string, string>  $sources
     */
    private function decorateLink(Link $link, string $folder, array $sources): void
    {
        $url = $link->getUrl();
        if ($url === '' || str_starts_with($url, '#')) {
            return;
        }
        if (preg_match('/^(?:[a-z][a-z0-9+.-]*:|\/\/)/i', $url)) {
            $attrs = $link->data->get('attributes');
            $attrs = is_array($attrs) ? $attrs : [];
            $attrs['rel'] = 'noopener';
            $link->data->set('attributes', $attrs);

            return;
        }
        if (! preg_match('/\.md(?:$|[?#])/i', $url)) {
            return;
        }
        $target = $this->resolveDocPath($folder, $url);
        if ($target === null || ! isset($sources[$target])) {
            $where = $folder === '' ? 'the docs home' : "docs/{$folder}";
            $this->findings[] = "{$where}: the link \"{$url}\" points at a page that does not exist.";

            return;
        }
        $suffix = '';
        if (preg_match('/^(.*?)([#?].*)?$/', $url, $parts)) {
            $suffix = isset($parts[2]) ? $parts[2] : '';
            $url = $parts[1];
        }
        $link->setUrl(preg_replace('/\.md$/i', '.html', $url).$suffix);
    }

    /** Resolve a .md href against a page's folder to a doc path (no extension), or null for a pure fragment. */
    private function resolveDocPath(string $folder, string $href): ?string
    {
        $pathPart = (string) preg_replace('/([?#].*)$/s', '', $href);
        if ($pathPart === '') {
            return null;
        }
        $segments = $folder === '' ? [] : explode('/', $folder);
        foreach (explode('/', $pathPart) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
            } else {
                $segments[] = $segment;
            }
        }
        $resolved = implode('/', $segments);

        return preg_replace('/\.md$/i', '', $resolved);
    }

    /** The plain text of a node and its inline children, for slugs and titles. */
    private function nodeText(Node $node): string
    {
        if (method_exists($node, 'getLiteral')) {
            return (string) $node->getLiteral();
        }
        $out = '';
        foreach ($node->children() as $child) {
            $out .= $this->nodeText($child);
        }

        return $out;
    }

    private function slug(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = (string) preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim($text, '-');

        return $text === '' ? 'section' : $text;
    }

    /**
     * A GitHub task list item renders a bare disabled checkbox, which has no accessible name (axe: label). Wrap the
     * checkbox and its item's own inline text in a label, stopping before a nested list, a second paragraph or the
     * end of the item, so the checkbox is named by what it checks.
     */
    private function labelTasks(string $html): string
    {
        return preg_replace_callback(
            '#(<li>(?:\s*<p>)?)(<input\b[^>]*\btype="checkbox"[^>]*>)((?:[^<]++|<(?!/?(?:li|ul|ol|p|div|pre|blockquote|table)\b))*+)#',
            static function (array $m): string {
                $text = rtrim($m[3]);

                return $m[1].'<label class="docs-task">'.$m[2].$text.'</label>'.substr($m[3], strlen($text));
            },
            $html,
        ) ?? $html;
    }

    private function wrapTables(string $html): string
    {
        return (string) preg_replace_callback(
            '/<table\b.*?<\/table>/is',
            static fn (array $m): string => '<div class="docs-table" role="region" aria-label="Table" tabindex="0">'.$m[0].'</div>',
            $html,
        );
    }

    /** @return array{0: array<string, string>, 1: string} front matter keys and the body */
    private function frontMatter(string $raw): array
    {
        if (! preg_match('/\A---\n(.*?)\n---\n?/s', $raw, $m)) {
            return [[], $raw];
        }
        $meta = [];
        foreach (explode("\n", $m[1]) as $line) {
            if (preg_match('/^([A-Za-z0-9_-]+):\s*(.*)$/', $line, $kv)) {
                $value = trim($kv[2]);
                if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
                    $value = substr($value, 1, -1);
                }
                $meta[$kv[1]] = $value;
            }
        }

        return [$meta, substr($raw, strlen($m[0]))];
    }

    private function navTitle(string $path): string
    {
        foreach ($this->order as $entry) {
            if ($entry['path'] === $path && $entry['title'] !== '') {
                return $entry['title'];
            }
        }

        return '';
    }

    private function prettify(string $path): string
    {
        $name = basename($path);
        $name = (string) preg_replace('/[_-]+/', ' ', $name);

        return ucfirst(trim($name));
    }

    /** The sidebar and menu list, with the current page marked. */
    private function navMarkup(string $current, string $up): string
    {
        $lines = [];
        foreach ((array) ($this->nav['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (isset($item['items'])) {
                $label = htmlspecialchars(is_string($item['title'] ?? '') ? $item['title'] : '', ENT_QUOTES);
                $children = '';
                foreach ((array) $item['items'] as $child) {
                    if (! is_array($child) || ! isset($child['path']) || ! is_string($child['path'])) {
                        continue;
                    }
                    $children .= $this->navItem($child['path'], is_string($child['title'] ?? '') ? $child['title'] : '', $current, $up);
                }
                if ($children !== '') {
                    $lines[] = "<li class=\"docs-nav__group\"><span class=\"docs-nav__label\">{$label}</span><ul>{$children}</ul></li>";
                }
            } elseif (isset($item['path']) && is_string($item['path'])) {
                $lines[] = $this->navItem($item['path'], is_string($item['title'] ?? '') ? $item['title'] : '', $current, $up);
            }
        }

        return '<ul class="docs-nav__list">'.implode('', $lines).'</ul>';
    }

    private function navItem(string $path, string $title, string $current, string $up): string
    {
        $href = $up.'docs/'.$path.'.html';
        $label = htmlspecialchars($title !== '' ? $title : $this->prettify($path), ENT_QUOTES);
        $mark = $path === $current ? ' aria-current="page"' : '';

        return "<li><a href=\"{$href}\"{$mark}>{$label}</a></li>";
    }

    /** @param list<array{level: int, id: string, text: string}> $toc */
    private function tocMarkup(array $toc): string
    {
        if ($toc === []) {
            return '';
        }
        $lines = '';
        foreach ($toc as $entry) {
            // A sub-item carries the base class too, so every docs-toc__item rule (and a theme's) reaches it.
            $indent = $entry['level'] === 3 ? 'docs-toc__item docs-toc__item--sub' : 'docs-toc__item';
            $lines .= "<li class=\"{$indent}\"><a href=\"#{$entry['id']}\">".htmlspecialchars($entry['text'], ENT_QUOTES).'</a></li>';
        }

        return '<nav class="docs-toc" aria-label="On this page"><h2 class="docs-toc__title">On this page</h2><ul class="docs-toc__list">'.$lines.'</ul></nav>';
    }

    private function pagerMarkup(string $current, string $up): string
    {
        $index = null;
        foreach ($this->order as $i => $entry) {
            if ($entry['path'] === $current) {
                $index = $i;

                break;
            }
        }
        $prev = $index !== null && $index > 0 ? $this->order[$index - 1] : null;
        $next = $index !== null && isset($this->order[$index + 1]) ? $this->order[$index + 1] : null;
        $links = '';
        if ($prev !== null) {
            $links .= $this->pagerLink($prev, $up, 'Previous', false);
        }
        if ($next !== null) {
            $links .= $this->pagerLink($next, $up, 'Next', true);
        }

        return '<nav class="docs-pager" aria-label="Pages">'.$links.'</nav>';
    }

    /** @param array{title: string, path: string} $entry */
    private function pagerLink(array $entry, string $up, string $dir, bool $next): string
    {
        $href = $up.'docs/'.$entry['path'].'.html';
        $label = htmlspecialchars($entry['title'] !== '' ? $entry['title'] : $this->prettify($entry['path']), ENT_QUOTES);
        $class = $next ? 'docs-pager__link docs-pager__link--next' : 'docs-pager__link';

        return "<a class=\"{$class}\" href=\"{$href}\"><span class=\"docs-pager__dir\">{$dir}</span><span class=\"docs-pager__title\">{$label}</span></a>";
    }

    /** @param list<array{level: int, id: string, text: string}> $toc */
    private function assemble(
        string $path,
        string $title,
        string $description,
        string $stamp,
        string $project,
        string $up,
        string $name,
        string $nav,
        string $content,
        array $toc,
    ): string {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
        $pageTitle = $e($title).' | '.$e($project).' docs';
        $tocHtml = $this->tocMarkup($toc);
        $pager = $this->pagerMarkup($path, $up);
        $twin = $name.'.md';
        $layoutClass = $tocHtml !== '' ? 'docs-layout docs-layout--toc' : 'docs-layout';
        $headerSource = $this->sourceAnchor('docs-header__home');
        $footerSource = $this->sourceAnchor();
        $mirrors = $this->mirrorsLine();
        $headerRight = $headerSource === ''
            ? "  <a class=\"docs-header__home\" href=\"{$up}index.html\">Back to {$e($project)}</a>\n"
            : "  <span>{$headerSource} <a class=\"docs-header__home\" href=\"{$up}index.html\">Back to {$e($project)}</a></span>\n";
        if ($footerSource === '' && $mirrors === '') {
            $footerRight = "  <a href=\"{$twin}\">Markdown</a>\n";
        } else {
            $parts = array_filter([$footerSource, $mirrors, "<a href=\"{$twin}\">Markdown</a>"]);
            $footerRight = '  <span>'.implode(' ', $parts)."</span>\n";
        }
        // The theme stylesheet links after docs.css, so its rules win; with no theme the head is unchanged.
        $themeCssLink = $this->themeCss === null
            ? ''
            : "<link rel=\"stylesheet\" href=\"{$up}docs/theme/".basename($this->themeCss)."\">\n";
        if ($this->themeHeader === null) {
            $header = "<header class=\"docs-header\">\n"
                ."  <a class=\"docs-header__brand\" href=\"{$up}docs/index.html\">{$e($project)}</a>\n"
                .$headerRight
                ."</header>\n";
        } else {
            $header = $this->renderThemeHeader($up, $project, $stamp);
        }

        return "<!DOCTYPE html>\n"
            .'<!-- '.DocsKit::MARKER." from docs/{$path}.md. Do not edit. -->\n"
            ."<html lang=\"en\">\n"
            ."<head>\n"
            ."<meta charset=\"utf-8\">\n"
            ."<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
            ."<title>{$pageTitle}</title>\n"
            ."<meta name=\"description\" content=\"{$e($description)}\">\n"
            ."<meta name=\"docs-version\" content=\"{$e($stamp)}\">\n"
            ."<link rel=\"stylesheet\" href=\"{$up}assets/wayne-ui/wayne-ui.css\">\n"
            ."<link rel=\"stylesheet\" href=\"{$up}assets/docs.css\">\n"
            .$themeCssLink
            ."<link rel=\"alternate\" type=\"text/markdown\" href=\"{$twin}\">\n"
            ."<script src=\"{$up}assets/docs.js\" defer></script>\n"
            ."</head>\n"
            ."<body>\n"
            ."<a class=\"wsu-skip-link\" href=\"#main\">Skip to content</a>\n"
            .$header
            ."<div class=\"{$layoutClass}\">\n"
            ."  <details class=\"docs-menu\">\n"
            ."    <summary>Menu</summary>\n"
            ."    <nav class=\"docs-nav\" aria-label=\"Documentation\">{$nav}</nav>\n"
            ."  </details>\n"
            ."  <nav class=\"docs-nav docs-nav--sidebar\" aria-label=\"Documentation\">{$nav}</nav>\n"
            ."  <div class=\"docs-content\">\n"
            ."    <main id=\"main\">\n"
            ."      <article class=\"docs-article\">{$content}</article>\n"
            ."    </main>\n"
            ."    {$pager}\n"
            ."  </div>\n"
            .($tocHtml !== '' ? "  {$tocHtml}\n" : '')
            ."</div>\n"
            ."<footer class=\"docs-footer\">\n"
            ."  <span>Documents {$e($project)} <span data-docs-version>{$e($stamp)}</span></span>\n"
            .$footerRight
            ."</footer>\n"
            ."</body>\n"
            ."</html>\n";
    }

    /**
     * The theme header for one page: {{project}} and {{version}} filled, relative links prefixed to the page
     * depth, and aria-current on the first link to the docs home.
     */
    private function renderThemeHeader(string $up, string $project, string $stamp): string
    {
        $html = str_replace(
            ['{{project}}', '{{version}}'],
            [htmlspecialchars($project, ENT_QUOTES), htmlspecialchars($stamp, ENT_QUOTES)],
            (string) $this->themeHeader
        );
        $html = preg_replace_callback(
            '/(^|\s)(href|src)\s*=\s*(["\'])(.*?)\3/is',
            static fn (array $m): string => $m[1].$m[2].'='.$m[3].self::rewriteThemeUrl($m[4], $up).$m[3],
            $html
        ) ?? $html;
        $html = preg_replace_callback(
            '/(^|\s)srcset\s*=\s*(["\'])(.*?)\2/is',
            static fn (array $m): string => $m[1].'srcset='.$m[2].self::rewriteThemeSrcset($m[3], $up).$m[2],
            $html
        ) ?? $html;
        // The first link to the docs home carries aria-current, unless it already has one.
        if (preg_match_all('/<a\b[^>]*>/i', $html, $anchors, PREG_OFFSET_CAPTURE)) {
            foreach ($anchors[0] as [$tag, $offset]) {
                if (preg_match('/\bhref\s*=\s*(["\'])(.*?)\1/i', $tag, $href) !== 1) {
                    continue;
                }
                // The href is already rewritten to page depth above; compare it as-is.
                $target = $href[2];
                if ($target !== $up.'docs/index.html' && $target !== $up.'docs/') {
                    continue;
                }
                if (stripos($tag, 'aria-current') === false) {
                    // Insert before the tag's closing >, which $at points at.
                    $at = $offset + strlen($tag) - 1;
                    $html = substr($html, 0, $at).' aria-current="page"'.substr($html, $at);
                }

                break;
            }
        }

        return $html;
    }

    /** A relative URL in the theme header, prefixed to the page depth; fragment, absolute and scheme URLs stay put. */
    private static function rewriteThemeUrl(string $url, string $up): string
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, '#') || str_starts_with($url, '/')) {
            return $url;
        }
        if (preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $url) === 1) {
            return $url;
        }

        return $up.$url;
    }

    /** Each url of a comma list of "url descriptor" pairs, rewritten like a plain relative URL. */
    private static function rewriteThemeSrcset(string $srcset, string $up): string
    {
        $out = [];
        foreach (explode(',', $srcset) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $entry, 2) ?: [];
            $parts[0] = self::rewriteThemeUrl($parts[0], $up);
            $out[] = count($parts) === 2 ? $parts[0].' '.$parts[1] : $parts[0];
        }

        return implode(', ', $out);
    }

    /** @return list<string> paths relative to $dir, forward slashes, sorted, private skipped */
    private function walk(string $dir, string $extension): array
    {
        if (! is_dir($dir)) {
            return [];
        }
        $out = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                static function (SplFileInfo $file, string $key, RecursiveDirectoryIterator $it): bool {
                    if (str_starts_with($file->getFilename(), '.') || str_starts_with($file->getFilename(), '_')) {
                        return false;
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

    private function normalise(string $text): string
    {
        return str_replace("\r\n", "\n", $text);
    }

    /** @return array<string, mixed> */
    private function loadNav(): array
    {
        $path = $this->docs.'/nav.json';
        if (! is_file($path)) {
            throw new DocsSiteError("{$path} is missing.");
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data)) {
            throw new DocsSiteError("{$path} is not valid JSON.");
        }
        if (! isset($data['items']) || ! is_array($data['items'])) {
            throw new DocsSiteError("{$path} needs an \"items\" array.");
        }

        /** @var array<string, mixed> $data */
        return $data;
    }
}

/**
 * Advances the fence state over one line, the same way DocsExamples::fences() finds them: a line starting with
 * three or more backticks or tildes opens a fence, and the next line of the same character at least as long,
 * with nothing after it, closes it. Returns true for every line that belongs to a fence (the opening line, the
 * content lines and the closing line), so a generated-block marker on such a line is an example, not a region.
 */
function docs_site_fence_line(string $line, bool &$inFence, string &$fenceChar, int &$fenceLength): bool
{
    if (preg_match('/^ {0,3}(`{3,}|~{3,})\s*(.*)$/', $line, $m) !== 1) {
        return false;
    }
    $char = $m[1][0];
    $length = strlen($m[1]);
    if ($inFence) {
        if ($char === $fenceChar && $length >= $fenceLength && trim($m[2]) === '') {
            $inFence = false;
        }

        return true;
    }
    if ($char === '`' && str_contains($m[2], '`')) {
        return false;
    }
    $inFence = true;
    $fenceChar = $char;
    $fenceLength = $length;

    return true;
}

/** Load league/commonmark from the repo's vendor, falling back to this checkout's own vendor. */
function docs_site_require_commonmark(string $root): void
{
    $candidates = [
        rtrim($root, '/\\').'/vendor/autoload.php',
        dirname(__DIR__).'/vendor/autoload.php',
    ];
    foreach ($candidates as $autoload) {
        if (is_file($autoload)) {
            require_once $autoload;
        }
    }
    if (! class_exists(Environment::class)) {
        throw new DocsSiteError('league/commonmark is not installed; run composer install.');
    }
}

/** @param list<string> $argv */
function docs_site_main(array $argv): int
{
    $usage = "Usage: php bin/docs-site.php [--root=.] [--check] [--require-tag] [--require-release]\n"
        ."       php bin/docs-site.php [--root=.] --examples (run the tested examples; exit 1 on any finding)\n"
        ."       php bin/docs-site.php --sync-to=<repo>   (from Squire: copy the kit and this file)\n"
        ."       php bin/docs-site.php --rehash           (from Squire: record the kit's hashes)\n";
    $root = '.';
    $check = false;
    $requireTag = false;
    $requireRelease = false;
    $examples = false;
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
        } elseif ($arg === '--require-tag') {
            $requireTag = true;
        } elseif ($arg === '--require-release') {
            $requireRelease = true;
        } elseif ($arg === '--examples') {
            $examples = true;
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
            return docs_site_rehash();
        }
        if ($syncTo !== null) {
            return docs_site_sync($syncTo);
        }
        $root = rtrim($root, '/\\');
        if ($examples) {
            $gate = new DocsExamples($root);
            $result = $gate->run();
            foreach ($result['findings'] as $line) {
                fwrite(STDERR, "example: {$line}\n");
            }
            fwrite(STDOUT, $result['summary']."\n");

            return $result['findings'] === [] ? 0 : 1;
        }
        docs_site_require_commonmark($root);
        $tool = new DocsSite($root);
        $tool->setWriteBlocks(! $check);
        $tool->setRequireTag($requireTag);
        $tool->setRequireRelease($requireRelease);
        $changelog = $tool->changelogPage();
        if ($changelog !== null && ! $check) {
            [$relative, $content] = $changelog;
            $path = $tool->rootDir().'/'.$relative;
            $onDisk = is_file($path) ? str_replace("\r\n", "\n", (string) file_get_contents($path)) : null;
            if ($onDisk !== $content) {
                $dir = dirname($path);
                if (! is_dir($dir) && ! mkdir($dir, 0o775, true) && ! is_dir($dir)) {
                    throw new DocsSiteError("Cannot create {$dir}.");
                }
                if (file_put_contents($path, $content) === false) {
                    throw new DocsSiteError("Cannot write {$path}.");
                }
                fwrite(STDOUT, "wrote {$relative}\n");
            }
        }
        $files = $tool->build();
        $binaries = $tool->binaryFiles();
        $leftovers = $tool->leftovers($files, $binaries);
        $drift = [];
        foreach ($files as $relative => $content) {
            $path = $tool->rootDir().'/'.$relative;
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
                throw new DocsSiteError("Cannot create {$dir}.");
            }
            if (file_put_contents($path, $content) === false) {
                throw new DocsSiteError("Cannot write {$path}.");
            }
            fwrite(STDOUT, "wrote {$relative}\n");
        }
        foreach ($binaries as $relative => $bytes) {
            $path = $tool->rootDir().'/'.$relative;
            $onDisk = is_file($path) ? (string) file_get_contents($path) : null;
            if ($onDisk === $bytes) {
                continue;
            }
            if ($check) {
                $drift[] = $relative.($onDisk === null ? ' (missing)' : ' (out of date)');

                continue;
            }
            $dir = dirname($path);
            if (! is_dir($dir) && ! mkdir($dir, 0o775, true) && ! is_dir($dir)) {
                throw new DocsSiteError("Cannot create {$dir}.");
            }
            if (file_put_contents($path, $bytes) === false) {
                throw new DocsSiteError("Cannot write {$path}.");
            }
            fwrite(STDOUT, "wrote {$relative}\n");
        }
        if ($changelog !== null && $check) {
            [$relative, $content] = $changelog;
            $path = $tool->rootDir().'/'.$relative;
            $onDisk = is_file($path) ? str_replace("\r\n", "\n", (string) file_get_contents($path)) : null;
            if ($onDisk !== $content) {
                $drift[] = $relative.($onDisk === null ? ' (missing)' : ' (out of date)');
            }
        }
        foreach ($leftovers as $relative) {
            if ($check) {
                $drift[] = $relative.' (no longer generated)';
            } else {
                unlink($tool->rootDir().'/'.$relative);
                fwrite(STDOUT, "removed {$relative}\n");
            }
        }
        foreach ($drift as $line) {
            fwrite(STDERR, "stale: {$line}\n");
        }
        if ($check) {
            foreach ($tool->staleBlocks as $line) {
                fwrite(STDERR, "stale: {$line}\n");
            }
        }
        foreach ($tool->findings as $line) {
            fwrite(STDERR, "finding: {$line}\n");
        }
        $stale = $check ? $tool->staleBlocks : [];
        if ($drift !== [] || $stale !== []) {
            fwrite(STDERR, "The docs site is stale. Run php bin/docs-site.php and commit site/docs/.\n");
        }
        if ($drift !== [] || $stale !== [] || $tool->findings !== []) {
            return 1;
        }
        fwrite(STDOUT, ($check ? 'Docs site is current: ' : 'Docs site written: ').count($files)." page(s).\n");

        return 0;
    } catch (DocsSiteError $e) {
        fwrite(STDERR, 'docs-site: '.$e->getMessage()."\n");

        return 2;
    }
}

/** Records the SHA-256 of each kit file in DocsKit::FILES, in this file. Squire's maintainers run it. */
function docs_site_rehash(): int
{
    $kit = dirname(__DIR__).'/kit/docs';
    if (! is_dir($kit)) {
        throw new DocsSiteError('--rehash runs from Squire, where the kit lives (kit/docs/).');
    }
    $source = str_replace("\r\n", "\n", (string) file_get_contents(__FILE__));
    foreach (array_keys(DocsKit::FILES) as $relative) {
        $hash = hash('sha256', str_replace("\r\n", "\n", (string) file_get_contents($kit.'/'.basename($relative))));
        $source = (string) preg_replace(
            "/('".preg_quote($relative, '/')."' => ')[^']*(')/",
            '${1}'.$hash.'${2}',
            $source,
            1,
        );
    }
    if (file_put_contents(__FILE__, $source) === false) {
        throw new DocsSiteError('Cannot write '.__FILE__.'.');
    }
    fwrite(STDOUT, "Kit hashes recorded.\n");

    return 0;
}

/** Copies the kit and this file into a repo. Only Squire has the kit, so only Squire can sync. */
function docs_site_sync(string $repo): int
{
    $kit = dirname(__DIR__).'/kit/docs';
    if (! is_dir($kit)) {
        throw new DocsSiteError('--sync-to runs from Squire, where the kit lives (kit/docs/). This copy has no kit.');
    }
    $repo = rtrim($repo, '/\\');
    $copies = [__FILE__ => $repo.'/bin/docs-site.php'];
    foreach (array_keys(DocsKit::FILES) as $relative) {
        $copies[$kit.'/'.basename($relative)] = $repo.'/site/'.$relative;
    }
    foreach ($copies as $from => $to) {
        $content = str_replace("\r\n", "\n", (string) file_get_contents($from));
        $old = is_file($to) ? str_replace("\r\n", "\n", (string) file_get_contents($to)) : null;
        if ($old === $content) {
            continue;
        }
        if (! is_dir(dirname($to)) && ! mkdir(dirname($to), 0o775, true) && ! is_dir(dirname($to))) {
            throw new DocsSiteError('Cannot create '.dirname($to).'.');
        }
        if (file_put_contents($to, $content) === false) {
            throw new DocsSiteError("Cannot write {$to}.");
        }
        fwrite(STDOUT, "synced {$to}\n");
    }
    fwrite(STDOUT, "Kit synced. Now run php bin/docs-site.php in {$repo} and commit.\n");

    return 0;
}

// Run only as a script, so a test can include this file and call the classes.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    /** @var list<string> $arguments */
    $arguments = $_SERVER['argv'] ?? [];
    exit(docs_site_main($arguments));
}
