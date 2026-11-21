<?php

declare(strict_types=1);

namespace Waynestate\Booster\Strangler;

/**
 * The legacy docroot's .htaccess, moved into public/.htaccess by the mount
 * (design section 2.4, amended by 10.7): Apache stops reading the old file
 * once the docroot is public/, so its rules move to where Apache still looks,
 * ahead of the dispatcher's front-controller rule, rewritten for the new
 * layout.
 *
 * The URL space is the same before and after, so a rule's pattern, its
 * redirects and its denials move as written. What changes is the filesystem:
 *
 * - A file test (`%{REQUEST_FILENAME} !-f`) looks in the legacy docroot, as
 *   it did before: the helper condition splits REQUEST_FILENAME plus
 *   PATH_INFO (Apache stops the filename at the first folder public/ lacks)
 *   at CONTEXT_DOCUMENT_ROOT (public/), and the test runs on
 *   public/../legacy/<docroot> plus the rest. A PATH_INFO URL to a real
 *   legacy script (/report.php/7) tests the whole path, not the script.
 * - A pass-through (`RewriteRule ^assets - [L]`) becomes three rules: a file
 *   in public/ is served by Apache, a file in the legacy docroot by the
 *   dispatcher (BOOSTER_LEGACY_FILE), and anything else stops rewriting, so
 *   Apache answers its own 404, as before.
 * - A rule that rewrote to another legacy file goes through the dispatcher,
 *   which runs or serves that file (BOOSTER_LEGACY_TARGET); one that rewrote
 *   to the legacy front controller now rewrites to the dispatcher, which
 *   decides the half.
 * - A <Files> or <FilesMatch> block that only denies becomes a [F] rule on
 *   the path's last segment: the dispatcher serves legacy files now, so a
 *   filename section in public/.htaccess would never see them.
 * - php_value, php_flag, Header and mod_alias Redirect lines (and IfModule
 *   blocks of only those) apply per directory, and every request still runs
 *   in public/, so they move to the top of the file as written.
 *
 * Everything else (other blocks, subrequest tests, chains, skips, proxies,
 * filesystem tests it cannot place, %N back-references next to a rewritten
 * file test) stays behind as a MANUAL row naming its line. The front
 * controller block, the Slim BASE lines and the dotfile guard are dropped:
 * the dispatcher does all three itself. A removals van that labels every box
 * it would not lift.
 */
final class LegacyHtaccess
{
    /** The line in Booster's public/.htaccess the moved rewrite rules go above. */
    public const FRONT_MARKER = '    # Send Requests To Front Controller (the dispatcher)...';

    /** Directives that apply per directory and move as written. */
    private const TOP = '/^(php_value|php_flag|Header|Redirect|RedirectMatch|RedirectPermanent|RedirectTemp)\s/i';

    /** Lines the dispatcher (or Booster's public/.htaccess) already covers. */
    private const STANDARD = [
        '/^RewriteEngine\s+on$/i', '/^RewriteBase\s+\/$/i',
        '/^Options(\s+(All|[+-]?FollowSymLinks|[+-]?SymLinksIfOwnerMatch|-MultiViews|-Indexes))+$/i',
        '/^DirectoryIndex(\s+index\.(php|html?))+$/i',
    ];

    /** Rule flags that move: anything else (C, S, N, P, PT, T, H) is MANUAL. */
    private const FLAGS = [
        'l' => 'l', 'last' => 'l', 'end' => 'end', 'nc' => 'nc', 'nocase' => 'nc', 'qsa' => 'qsa', 'qsappend' => 'qsa',
        'qsd' => 'qsd', 'qsdiscard' => 'qsd', 'qsl' => 'qsl', 'qslast' => 'qsl', 'ne' => 'ne', 'noescape' => 'ne',
        'r' => 'r', 'redirect' => 'r', 'f' => 'f', 'forbidden' => 'f', 'g' => 'g', 'gone' => 'g', 'ns' => 'ns',
        'nosubreq' => 'ns', 'e' => 'e', 'env' => 'e', 'co' => 'co', 'cookie' => 'co', 'b' => 'b', 'bnp' => 'bnp',
        'backrefnoplus' => 'bnp', 'bctls' => 'bctls', 'bne' => 'bne',
    ];

    /** @var list<string> */
    private array $top = [];

    /** @var list<string> */
    private array $rewrite = [];

    /** @var list<int> */
    private array $moved = [];

    /** @var list<array{line: int, text: string, why: string}> */
    private array $manual = [];

    /** @var list<string> */
    private array $comments = [];

    /** @var list<array{line: int, text: string}> */
    private array $conds = [];

    /** @var list<array{line: int, text: string}> Denials from <Files> blocks: they go first, as Apache checks them before any rule. */
    private array $denials = [];

    private function __construct(
        private readonly string $legacyDocroot,
        private readonly string $legacyFront,
    ) {}

    /**
     * @param string $legacyDocroot the legacy docroot relative to the app root (legacy/public)
     * @param string $legacyFront the legacy front controller in that docroot (index.php)
     */
    public static function move(string $text, string $legacyDocroot, string $legacyFront = 'index.php'): self
    {
        $move = new self(trim($legacyDocroot, '/'), ltrim($legacyFront, '/'));
        $move->parse($text);

        return $move;
    }

    /** @return list<string> lines for the top of public/.htaccess (outside the mod_rewrite block) */
    public function top(): array
    {
        return $this->top;
    }

    /** @return list<string> lines for the rewrite block, above FRONT_MARKER (empty when nothing moved) */
    public function rewrite(): array
    {
        if ($this->rewrite === [] && $this->denials === []) {
            return [];
        }
        $lines = [
            '    # Laravel\'s own prefix never meets the legacy rules below.',
            '    RewriteRule ^_laravel(?:/|$) index.php [END]',
            '',
        ];
        foreach ($this->denials as $deny) {
            $lines = [...$lines, ...explode("\n", $deny['text']), ''];
        }

        return [...$lines, ...$this->rewrite];
    }

    /** @return list<int> the source lines that moved */
    public function moved(): array
    {
        $moved = array_values(array_unique($this->moved));
        sort($moved);

        return $moved;
    }

    /** @return list<array{line: int, text: string, why: string}> */
    public function manual(): array
    {
        return $this->manual;
    }

    /** The whole public/.htaccess: Booster's file with the moved lines in their two places. */
    public function apply(string $publicHtaccess, string $from): string
    {
        $rewrite = $this->rewrite();
        if ($rewrite !== []) {
            $head = [
                sprintf('    # Moved by Booster\'s strangler mount from %s, in order.', $from),
                sprintf('    # Rewritten for the new layout: file tests look in %s, and rules', $this->legacyDocroot),
                '    # that served or ran a legacy file still do, through index.php.',
            ];
            $block = implode("\n", [...$head, ...$rewrite]) . "\n";
            $at = strpos($publicHtaccess, self::FRONT_MARKER);
            $publicHtaccess = $at === false
                ? $publicHtaccess . "\n<IfModule mod_rewrite.c>\n    RewriteEngine On\n" . $block . "</IfModule>\n"
                : substr($publicHtaccess, 0, $at) . $block . substr($publicHtaccess, $at);
        }
        if ($this->top !== []) {
            $publicHtaccess = implode("\n", [sprintf('# Moved by Booster\'s strangler mount from %s, as written.', $from), ...$this->top]) . "\n\n" . $publicHtaccess;
        }

        return $publicHtaccess;
    }

    private function parse(string $text): void
    {
        $lines = preg_split('/\r\n|\n|\r/', $text) ?: [];
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $no = $i + 1;
            $line = trim($lines[$i]);
            if ($line === '') {
                $this->comments = [];
                continue;
            }
            if ($line[0] === '#') {
                $this->comments[] = $line;
                continue;
            }
            if (str_ends_with($line, '\\')) {
                $this->addManual($no, $line, 'a continued line');
                continue;
            }
            if (preg_match('/^<IfModule\s+(mod_rewrite\.c|rewrite_module)\s*>$/i', $line) === 1 || preg_match('/^<\/IfModule>$/i', $line) === 1) {
                $this->comments = [];
                continue;
            }
            if (preg_match('/^<([A-Za-z]+)/', $line, $m) === 1) {
                $end = self::blockEnd($lines, $i);
                if ($end === null) {
                    $this->addManual($no, $line, 'a block with no end');
                    break;
                }
                $body = [];
                for ($j = $i + 1; $j < $end; $j++) {
                    $inner = trim($lines[$j]);
                    if ($inner !== '' && $inner[0] !== '#') {
                        $body[$j + 1] = $inner;
                    }
                }
                $this->block($m[1], $line, $body, array_map('rtrim', array_slice($lines, $i, $end - $i + 1)), $no, $end + 1);
                $i = $end;
                continue;
            }
            $this->directive($line, $no);
        }
        foreach ($this->conds as $cond) {
            $this->addManual($cond['line'], $cond['text'], 'a RewriteCond with no RewriteRule after it');
        }
    }

    /** @param list<string> $lines */
    private static function blockEnd(array $lines, int $start): ?int
    {
        $depth = 0;
        for ($j = $start, $count = count($lines); $j < $count; $j++) {
            $line = trim($lines[$j]);
            if (str_starts_with($line, '</')) {
                $depth--;
                if ($depth === 0) {
                    return $j;
                }
            } elseif (preg_match('/^<[A-Za-z]/', $line) === 1) {
                $depth++;
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $body the block's directives by line number
     * @param list<string> $raw the block as written, open to close
     */
    private function block(string $kind, string $open, array $body, array $raw, int $start, int $end): void
    {
        $comments = $this->takeComments();
        $range = sprintf('lines %d-%d', $start, $end);
        if (strcasecmp($kind, 'IfModule') === 0) {
            $standard = array_filter($body, fn(string $l): bool => $this->standard($l));
            if (count($standard) === count($body)) {
                return;
            }
            if (array_filter($body, static fn(string $l): bool => preg_match(self::TOP, $l) !== 1 && preg_match('/^<\/?IfModule\b/i', $l) !== 1) === []) {
                $this->top = [...$this->top, ...($this->top !== [] ? [''] : []), ...$comments, ...$raw];
                array_push($this->moved, ...range($start, $end));

                return;
            }
            $this->addManual($start, $open, $range . ', a module block that holds more than per-directory settings');

            return;
        }
        if (in_array(strtolower($kind), ['files', 'filesmatch'], true)) {
            $deny = $body !== [] && array_filter($body, static fn(string $l): bool => preg_match('/^(Require\s+all\s+denied|Order\s+(allow,\s*deny|deny,\s*allow)|Deny\s+from\s+all|<IfModule\s+!?mod_authz_core\.c>|<\/IfModule>)$/i', $l) !== 1) === []
                && array_filter($body, static fn(string $l): bool => preg_match('/^(Require\s+all\s+denied|Deny\s+from\s+all|Order\s+allow,\s*deny)$/i', $l) === 1) !== [];
            if ($deny && preg_match('/^<Files\s+"?~?\s*"?\\\\?\.ht\S*>$/i', $open) === 1) {
                return; // The dotfile guard: the dispatcher never serves dotfiles.
            }
            $regex = self::filesRegex($kind, $open);
            if ($deny && $regex !== null) {
                $text = implode("\n", [
                    ...array_map(static fn(string $c): string => '    ' . $c, $comments),
                    sprintf('    # Was %s (denied): the last path segment, as Apache matched the filename.', $open),
                    sprintf('    RewriteCond $1 "%s"', $regex),
                    '    RewriteRule (?:^|/)([^/]*)$ - [F]',
                ]);
                $this->denials[] = ['line' => $start, 'text' => $text];
                array_push($this->moved, ...range($start, $end));

                return;
            }
            $this->addManual($start, $open, $range . ($deny ? ', a filename pattern Booster cannot turn into a rule' : ', a filename block: the dispatcher serves legacy files now, so it would never match them'));

            return;
        }
        $this->addManual($start, $open, $range . ', a block Booster does not move');
    }

    /** The PCRE a <Files> or <FilesMatch> opening line matches basenames with, or null. */
    private static function filesRegex(string $kind, string $open): ?string
    {
        if (preg_match('/^<' . $kind . '\s+(~\s+)?(?:"([^"]*)"|(\S+?))\s*>$/i', $open, $m) !== 1) {
            return null;
        }
        $value = $m[2] !== '' ? $m[2] : ($m[3] ?? '');
        if ($value === '' || preg_match('/[\s"]/', $value) === 1) {
            return null;
        }
        if (strcasecmp($kind, 'FilesMatch') === 0 || $m[1] !== '') {
            return $value;
        }
        if (preg_match('/[\[\]\\\\]/', $value) === 1) {
            return null;
        }

        return '^' . strtr(preg_quote($value, '/'), ['\\*' => '[^/]*', '\\?' => '[^/]']) . '$';
    }

    private function directive(string $line, int $no): void
    {
        if (preg_match('/^RewriteCond\s/i', $line) === 1) {
            $this->conds[] = ['line' => $no, 'text' => $line];

            return;
        }
        if (preg_match('/^RewriteRule\s/i', $line) === 1) {
            $this->rule($line, $no);

            return;
        }
        $comments = $this->takeComments();
        if ($this->standard($line)) {
            return;
        }
        if (preg_match('/^RewriteBase\s/i', $line) === 1) {
            $this->addManual($no, $line, 'public/.htaccess has no RewriteBase; add it there if the app sits under an Alias');

            return;
        }
        if (preg_match(self::TOP, $line) === 1) {
            $this->top = [...$this->top, ...($comments !== [] && $this->top !== [] ? [''] : []), ...$comments, $line];
            $this->moved[] = $no;

            return;
        }
        $this->addManual($no, $line, 'not a rule Booster moves');
    }

    private function standard(string $line): bool
    {
        foreach (self::STANDARD as $pattern) {
            if (preg_match($pattern, $line) === 1) {
                return true;
            }
        }

        return false;
    }

    private function rule(string $line, int $no): void
    {
        $conds = $this->conds;
        $this->conds = [];
        $comments = $this->takeComments();
        $lines = [...array_column($conds, 'line'), $no];
        $refuse = function (string $why) use ($line, $no, $conds): void {
            $this->addManual($no, $line, $why . ($conds === [] ? '' : sprintf(' (with its RewriteCond lines from line %d)', $conds[0]['line'])));
        };

        $args = self::args(substr($line, 11));
        if ($args === null || count($args) < 2 || count($args) > 3) {
            $refuse('a rule Booster could not read');

            return;
        }
        [$pattern, $subst] = [$args[0][1], $args[1][0]];
        $flags = [];
        foreach (count($args) === 3 ? self::flagList($args[2][0]) : [] as $flag) {
            $name = strtolower(explode('=', $flag, 2)[0]);
            if (!isset(self::FLAGS[$name])) {
                $refuse(sprintf('the [%s] flag', $flag));

                return;
            }
            $flags[] = ['name' => self::FLAGS[$name], 'raw' => $flag];
        }
        $names = array_column($flags, 'name');

        $parsed = [];
        $fileTests = 0;
        $or = false;
        foreach ($conds as $cond) {
            $cargs = self::args(substr($cond['text'], 11));
            if ($cargs === null || count($cargs) < 2 || count($cargs) > 3) {
                $refuse(sprintf('the RewriteCond on line %d could not be read', $cond['line']));

                return;
            }
            foreach (count($cargs) === 3 ? self::flagList($cargs[2][0]) : [] as $flag) {
                $flag = strtolower($flag);
                if (!in_array($flag, ['nc', 'nocase', 'or', 'ornext', 'nv', 'novary'], true)) {
                    $refuse(sprintf('the [%s] flag on line %d', $flag, $cond['line']));

                    return;
                }
                $or = $or || in_array($flag, ['or', 'ornext'], true);
            }
            [$test, $cpattern] = [$cargs[0][0], $cargs[1][0]];
            $fileTest = preg_match('/^%\{(REQUEST|SCRIPT)_FILENAME\}$/i', $test) === 1 && preg_match('/^!?-[fdlLhs]$/', $cpattern) === 1;
            if (!$fileTest && (preg_match('/%\{(REQUEST_FILENAME|SCRIPT_FILENAME|DOCUMENT_ROOT|CONTEXT_DOCUMENT_ROOT)\}/i', $test) === 1 || preg_match('/^!?-[fdlLhsFUx]$/', $cpattern) === 1)) {
                $refuse(sprintf('the RewriteCond on line %d tests a filesystem path Booster cannot place', $cond['line']));

                return;
            }
            $fileTests += $fileTest ? 1 : 0;
            $parsed[] = ['text' => $cond['text'], 'file' => $fileTest, 'op' => $cpattern, 'flags' => count($cargs) === 3 ? ' ' . $cargs[2][0] : ''];
        }

        // Dropped: what the dispatcher (or Booster's public/.htaccess) does already.
        $front = preg_match('/^\/?(index\.php|' . preg_quote($this->legacyFront, '/') . ')$/', $subst) === 1;
        if ($front && $fileTests === count($parsed) && array_diff($names, ['l', 'end', 'qsa', 'nc', 'ns']) === []
            && array_filter($parsed, static fn(array $c): bool => preg_match('/^!-[fdl]$/', $c['op']) !== 1) === []) {
            return;
        }
        if ($subst === '-' && count($flags) === 1 && preg_match('/^E=(BASE:%1|HTTP_AUTHORIZATION:%\{HTTP:Authorization\})$/i', $flags[0]['raw']) === 1) {
            return;
        }

        $kind = match (true) {
            array_intersect($names, ['f', 'g']) !== [] => 'deny',
            in_array('r', $names, true) || preg_match('#^[a-z][a-z0-9+.-]*://#i', $subst) === 1 => 'redirect',
            $subst === '-' => array_intersect($names, ['l', 'end']) !== [] ? 'pass' : 'env',
            preg_match('/^\/?(index\.php|' . preg_quote($this->legacyFront, '/') . ')(\?.*)?$/', $subst) === 1 => 'front',
            default => 'target',
        };
        $all = implode(' ', [...array_column($conds, 'text'), $line]);
        if (($fileTests > 0 || $kind === 'pass') && ($or || preg_match('/(?<!%)%[0-9]/', $all) === 1)) {
            $refuse($or ? 'an [OR] condition next to a file test Booster rewrites' : 'a %N back-reference next to a file test Booster rewrites');

            return;
        }
        $path = explode('?', $subst, 2)[0];
        if ($kind === 'target' && (preg_match('/^[^\s,"]+$/', $path) !== 1 || str_contains($path, '..'))) {
            $refuse('a rewrite target Booster cannot send through the dispatcher');

            return;
        }

        $condLines = [];
        foreach ($parsed as $cond) {
            if ($cond['file']) {
                $condLines[] = '    RewriteCond %{CONTEXT_DOCUMENT_ROOT}::%{REQUEST_FILENAME}%{PATH_INFO} ^(.*?)/?::\1(.*)$';
                $condLines[] = sprintf('    RewriteCond %%1/../%s%%2 %s%s', $this->legacyDocroot, $cond['op'], $cond['flags']);
            } else {
                $condLines[] = '    ' . $cond['text'];
            }
        }
        $kept = implode(',', array_column(array_filter($flags, static fn(array $f): bool => !in_array($f['name'], ['l', 'end'], true)), 'raw'));
        $kept = $kept === '' ? '' : $kept . ',';
        $out = array_map(static fn(string $c): string => '    ' . $c, $comments);
        if ($kind === 'pass') {
            $out[] = '    # A pass-through: a file in public/, a legacy file (the dispatcher serves it), or Apache\'s 404.';
            $out = [
                ...$out,
                ...$condLines,
                '    RewriteCond %{REQUEST_FILENAME} -f',
                '    ' . $line,
                ...$condLines,
                '    RewriteCond %{CONTEXT_DOCUMENT_ROOT}::%{REQUEST_FILENAME}%{PATH_INFO} ^(.*?)/?::\1(.*)$',
                sprintf('    RewriteCond %%1/../%s%%2 -f', $this->legacyDocroot),
                sprintf('    RewriteRule %s index.php [%sEND,E=BOOSTER_LEGACY_FILE:1]', $pattern, $kept),
                ...$condLines,
                '    ' . $line,
            ];
        } elseif ($kind === 'target') {
            $query = str_contains($subst, '?') ? '?' . explode('?', $subst, 2)[1] : '';
            $out[] = sprintf('    # Was %s: the dispatcher runs or serves that legacy file.', $subst);
            $out = [...$out, ...$condLines, sprintf('    RewriteRule %s index.php%s [%sEND,E=BOOSTER_LEGACY_TARGET:%s]', $pattern, $query, $kept, ltrim($path, '/'))];
        } else {
            $out = [...$out, ...$condLines, '    ' . $line];
        }
        $this->rewrite = [...$this->rewrite, ...$out, ''];
        array_push($this->moved, ...$lines);
    }

    /**
     * Apache-style arguments: whitespace-separated, double or single quotes
     * group, a backslash escapes a space. Each is [value, as written].
     *
     * @return list<array{0: string, 1: string}>|null
     */
    private static function args(string $text): ?array
    {
        $args = [];
        $text = trim($text);
        while ($text !== '') {
            if ($text[0] === '"' || $text[0] === "'") {
                $close = strpos($text, $text[0], 1);
                if ($close === false) {
                    return null;
                }
                $args[] = [substr($text, 1, $close - 1), substr($text, 0, $close + 1)];
                $text = ltrim(substr($text, $close + 1));
                continue;
            }
            if (preg_match('/^(?:\\\\\s|\S)+/', $text, $m) !== 1) {
                return null;
            }
            $args[] = [str_replace('\\ ', ' ', $m[0]), $m[0]];
            $text = ltrim(substr($text, strlen($m[0])));
        }

        return $args;
    }

    /** @return list<string> */
    private static function flagList(string $flags): array
    {
        if (preg_match('/^\[(.*)\]$/', $flags, $m) !== 1) {
            return [$flags];
        }

        return array_values(array_filter(array_map('trim', explode(',', $m[1])), static fn(string $f): bool => $f !== ''));
    }

    /** @return list<string> */
    private function takeComments(): array
    {
        $comments = $this->comments;
        $this->comments = [];

        return $comments;
    }

    private function addManual(int $line, string $text, string $why): void
    {
        $this->comments = [];
        $this->manual[] = ['line' => $line, 'text' => $text, 'why' => $why];
    }
}
