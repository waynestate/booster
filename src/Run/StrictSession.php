<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

use PhpToken;
use Waynestate\Booster\ToolError;

/**
 * The session-strict-mode hop's engine. Citadel's shared php.ini leaves
 * session.use_strict_mode at 0, so PHP accepts any session ID a browser
 * sends, including one an attacker picked (session fixation). New stack apps
 * get strict mode from HELMET, SESAME or Squire; an existing raw PHP app that
 * calls plain session_start() gets nothing. This puts one guarded line in
 * front of each of those calls:
 *
 *     if (session_status() === PHP_SESSION_NONE && !headers_sent()) { ini_set(...); ini_set(...); }
 *
 * The guard keeps it quiet: PHP warns when a session setting changes while a
 * session is active or after output has started, and in both cases the
 * session_start() below it would not pick the settings up anyway.
 *
 * Why ini_set() and not a config file: Citadel runs PHP as an Apache module,
 * which never reads a .user.ini (only CGI and FastCGI do), and a php_flag line
 * in .htaccess is a 500 error on any host that runs PHP-FPM. ini_set() works
 * under every SAPI, and the app's own gate runs it.
 *
 * The line goes before the whole statement that holds the call, found with
 * PHP's own tokenizer, so a braceless `if (!isset($_SESSION)) session_start();`
 * gets it before the `if`, never between the `if` and its body. Running it
 * early is harmless: the settings only matter when a session starts.
 *
 * Anything that would make strict mode change how the app hands out session
 * IDs is a stop sign, and the hop refuses and changes nothing: like a bouncer
 * who only lets in the IDs he stamped himself.
 */
final class StrictSession
{
    /** The comment above the guard when it sits on its own line. */
    public const MARKER = '// Booster: strict session IDs, set before the session starts (refuses IDs this server never issued).';

    /** The guarded line Booster puts before each session_start() statement. */
    public const GUARD = "if (session_status() === PHP_SESSION_NONE && !headers_sent()) { ini_set('session.use_strict_mode', '1'); ini_set('session.use_only_cookies', '1'); }";

    private const HELMET_PACKAGE = 'waynestate/helmet';

    /** Keywords that carry on the statement before them. */
    private const CONTINUATIONS = ['else', 'elseif', 'catch', 'finally'];

    /** Control keywords whose `(...):` opens an alternative-syntax block. */
    private const CONTROL = ['if', 'elseif', 'while', 'for', 'foreach', 'switch', 'declare'];

    /** Tokens before a name that make it a method, constant or declaration, not a call. */
    private const NOT_A_CALL = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST];

    /** Read-only: what the hop would change, leave alone or refuse. */
    public function inspect(string $appPath): StrictSessionInspection
    {
        return $this->work(rtrim($appPath, '/\\'))['inspection'];
    }

    /**
     * Puts the guard before every uncovered session_start() statement. A dry
     * run, a refusal or an app with nothing to cover writes nothing.
     */
    public function migrate(string $appPath, bool $dryRun): StrictSessionResult
    {
        $appPath = rtrim($appPath, '/\\');
        $work = $this->work($appPath);
        $inspection = $work['inspection'];
        if ($inspection->refusals !== []) {
            return new StrictSessionResult([], [], [], $inspection->refusals, $inspection->covered);
        }
        if ($inspection->appWide !== null) {
            return new StrictSessionResult([], [], [], [], [sprintf('Nothing to do: %s.', $inspection->appWide)]);
        }
        if ($inspection->calls === []) {
            return new StrictSessionResult([], [], [], [], [
                ...$inspection->covered,
                $inspection->covered === []
                    ? 'Nothing to do: found no session_start() call outside vendor/ and node_modules/.'
                    : 'Nothing to do: every session_start() call outside vendor/ and node_modules/ already runs with strict session IDs.',
            ]);
        }

        $would = $dryRun ? 'Dry run: would ' : '';
        $changes = [];
        $files = [];
        foreach ($work['edits'] as $file => $edit) {
            $lines = $inspection->calls[$file];
            $changes[] = sprintf(
                '%s%s %s: strict session IDs set before %s (line%s %s).',
                $would,
                $dryRun ? 'edit' : 'Edited',
                $file,
                count($lines) === 1 ? 'its session_start() call' : count($lines) . ' session_start() calls',
                count($lines) === 1 ? '' : 's',
                implode(', ', $lines),
            );
            $files[] = ['file' => $file, 'diff' => $edit['diff']];
            if (!$dryRun && file_put_contents($appPath . '/' . $file, $edit['content']) === false) {
                throw new ToolError(sprintf('Could not write "%s".', $appPath . '/' . $file));
            }
        }
        $changes[] = sprintf(
            '%s %s: each one now sets session.use_strict_mode and session.use_only_cookies to 1 first.',
            $dryRun ? 'Dry run: would cover' : 'Covered',
            $inspection->summary(),
        );

        return new StrictSessionResult($changes, $files, $inspection->manual, [], $inspection->covered);
    }

    /**
     * Reads the app once: the calls, the coverage, the stop signs and, for
     * each file that needs it, the rewritten content. Nothing is written here.
     *
     * @return array{inspection: StrictSessionInspection, edits: array<string, array{content: string, diff: string}>}
     */
    private function work(string $appPath): array
    {
        [$phpFiles, $configFiles] = self::files($appPath);

        $covered = [];
        $refusals = [];
        $handlers = [];
        $appWide = null;
        $coveredDirs = [];
        foreach ($configFiles as $file) {
            $text = (string) file_get_contents($appPath . '/' . $file);
            $dir = dirname($file) === '.' ? '' : dirname($file) . '/';
            $htaccess = basename($file) === '.htaccess';
            $settings = self::configSettings($text, $htaccess);
            if (isset($settings['use_strict_mode'])) {
                if ($htaccess) {
                    // mod_php reads .htaccess for its own folder and below.
                    if ($dir === '') {
                        $appWide ??= sprintf('%s sets session.use_strict_mode for the whole app', $file);
                    } else {
                        $coveredDirs[] = $dir;
                        $covered[] = sprintf('%s sets session.use_strict_mode for %s; files there were left alone.', $file, $dir);
                    }
                } elseif (str_starts_with($file, '.squire/') || str_contains($file, '/.squire/')) {
                    $appWide ??= sprintf('%s sets session.use_strict_mode, and Squire\'s PHP reads it', $file);
                } else {
                    $covered[] = sprintf('%s sets session.use_strict_mode, but Citadel\'s PHP does not read that file, so the hop still adds the lines.', $file);
                }
            }
            foreach (self::configHazards($settings) as $hazard) {
                $refusals[] = sprintf('%s %s.', $file, $hazard);
            }
        }

        $calls = [];
        $edits = [];
        foreach ($phpFiles as $file) {
            $source = (string) file_get_contents($appPath . '/' . $file);
            if (stripos($source, 'session') === false && stripos($source, 'helmet_boot') === false && !str_contains($source, 'SID')) {
                continue;
            }
            $found = self::analyse($source);
            foreach ($found['stops'] as [$line, $reason]) {
                $refusals[] = sprintf('%s:%d %s.', $file, $line, $reason);
            }
            foreach ($found['handlers'] as $line) {
                $handlers[] = sprintf('%s:%d', $file, $line);
            }
            if ($found['helmet'] !== null) {
                $appWide ??= sprintf('the app calls helmet_boot() (%s:%d), which turns strict session IDs on. Check that every page that starts a session boots HELMET first', $file, $found['helmet']);
            }
            if ($found['sites'] === []) {
                continue;
            }
            if ($found['names']) {
                $covered[] = sprintf('%s names session.use_strict_mode already; left alone.', $file);
                continue;
            }
            foreach ($coveredDirs as $dir) {
                if (str_starts_with($file, $dir)) {
                    continue 2;
                }
            }
            if ($found['inserts'] === null) {
                $refusals[] = sprintf('%s: Booster could not follow the statements around its session_start() call, so it will not guess where the setting goes. Put the two ini_set() lines before the call by hand', $file);
                continue;
            }
            $lines = array_values(array_unique(array_map(static fn(array $site): int => $site['line'], $found['sites'])));
            $unsafe = array_values(array_filter($found['sites'], static fn(array $site): bool => $site['insert'] === null));
            if ($unsafe !== []) {
                $refusals[] = sprintf('%s:%d starts the session inside a <?= ?> tag, where a statement cannot go. Move the call into a <?php block', $file, $unsafe[0]['line']);
                continue;
            }
            $edit = self::rewrite($source, $found['tokens'], $found['inserts']);
            if (self::parses($source) && !self::parses($edit['content'])) {
                $refusals[] = sprintf('%s: Booster\'s edit would not parse, so it changed nothing. Put the two ini_set() lines before the call by hand', $file);
                continue;
            }
            $calls[$file] = $lines;
            $edits[$file] = $edit;
        }

        $manual = [];
        if ($calls !== []) {
            $manual[] = 'MANUAL: Sign in, use a page that keeps session data, and sign out on pre-production. A session the server already holds keeps working; a browser that sends an ID the server never issued (or already cleaned up) now gets a fresh one.';
            foreach ($handlers as $where) {
                $manual[] = sprintf('MANUAL: %s registers its own session handler. PHP only enforces strict mode when that handler implements validateId() (SessionUpdateTimestampHandlerInterface), and the built-in SessionHandler class does not; add it, or strict mode stays off for those sessions.', $where);
            }
            if (self::requiresHelmet($appPath)) {
                $manual[] = 'MANUAL: The app requires HELMET. Calling helmet_boot() before the session starts sets these two settings and the rest of HELMET\'s session defaults, and would replace the lines Booster added; it also renames the default PHPSESSID cookie, which signs everyone out once.';
            }
        }

        return [
            'inspection' => new StrictSessionInspection($calls, $covered, array_values(array_unique($refusals)), $manual, $appWide),
            'edits' => $edits,
        ];
    }

    /**
     * Reads one PHP file with PHP's tokenizer: where each session_start()
     * call is, where its statement starts, and what else in the file matters
     * to strict mode.
     *
     * @return array{
     *     tokens: list<PhpToken>,
     *     sites: list<array{line: int, insert: int|null}>,
     *     inserts: list<int>|null,
     *     stops: list<array{0: int, 1: string}>,
     *     handlers: list<int>,
     *     helmet: int|null,
     *     names: bool
     * }
     */
    private static function analyse(string $source): array
    {
        $tokens = array_values(PhpToken::tokenize($source));
        $sig = [];
        foreach ($tokens as $i => $token) {
            if (!$token->isIgnorable()) {
                $sig[] = $i;
            }
        }
        $at = array_flip($sig);
        $prev = static fn(int $i): ?PhpToken => isset($at[$i], $sig[$at[$i] - 1]) ? $tokens[$sig[$at[$i] - 1]] : null;
        $next = static fn(int $i, int $by = 1): ?PhpToken => isset($at[$i], $sig[$at[$i] + $by]) ? $tokens[$sig[$at[$i] + $by]] : null;

        $stops = [];
        $handlers = [];
        $helmet = null;
        $names = false;
        foreach ($sig as $i) {
            $token = $tokens[$i];
            if ($token->id === T_CONSTANT_ENCAPSED_STRING) {
                $setting = self::sessionSetting($token->text);
                if ($setting === 'use_strict_mode') {
                    $names = true;
                    continue;
                }
                if ($setting === null) {
                    continue;
                }
                $separator = $next($i);
                if ($separator === null || !in_array($separator->text, [',', '=>'], true)) {
                    // ini_get('session.use_cookies') and friends only read it.
                    continue;
                }
                $value = self::literal($next($i, 2), $next($i, 3));
                $hazard = self::hazard($setting, $value);
                if ($hazard !== null) {
                    $stops[] = [$token->line, $hazard];
                }
                continue;
            }
            if (!in_array($token->id, [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            $name = strtolower(ltrim($token->text, '\\'));
            $before = $prev($i);
            $after = $next($i);
            $call = $after !== null && $after->text === '(' && ($before === null || !in_array($before->id, self::NOT_A_CALL, true));
            if ($token->text === 'SID' && ($after === null || !in_array($after->text, ['(', '::'], true)) && ($before === null || !in_array($before->id, [...self::NOT_A_CALL, T_CLASS], true))) {
                $stops[] = [$token->line, 'uses the SID constant, which carries the session ID in URLs; strict mode with cookie-only IDs would drop those sessions'];
            } elseif ($call && $name === 'session_id' && $next($i, 2)?->text !== ')') {
                $stops[] = [$token->line, 'sets the session ID itself with session_id(...); strict mode replaces any ID this server did not issue, so that hand-off would start a fresh session'];
            } elseif ($call && $name === 'session_set_save_handler') {
                $handlers[] = $token->line;
            } elseif ($call && $name === 'helmet_boot') {
                $helmet ??= $token->line;
            } elseif ($token->text === 'SessionHardening' && $after?->id === T_DOUBLE_COLON && $next($i, 2)?->text === 'apply') {
                $helmet ??= $token->line;
            }
        }

        $walk = self::walk($tokens, $sig, $at);
        $sites = $walk ?? self::sites($tokens, $sig, $at);
        $inserts = null;
        if ($walk !== null) {
            $inserts = [];
            foreach ($walk as $site) {
                if ($site['insert'] !== null) {
                    $inserts[$site['insert']] = true;
                }
            }
            $inserts = array_keys($inserts);
            sort($inserts);
        }

        return [
            'tokens' => $tokens,
            'sites' => array_map(static fn(array $site): array => ['line' => $site['line'], 'insert' => $site['insert']], $sites),
            'inserts' => $inserts,
            'stops' => $stops,
            'handlers' => $handlers,
            'helmet' => $helmet,
            'names' => $names,
        ];
    }

    /**
     * The session_start() calls without their statements, for a file the
     * walk could not follow: enough to name it in the refusal.
     *
     * @param list<PhpToken>   $tokens
     * @param list<int>        $sig
     * @param array<int, int>  $at
     *
     * @return list<array{line: int, insert: int|null}>
     */
    private static function sites(array $tokens, array $sig, array $at): array
    {
        $sites = [];
        foreach ($sig as $i) {
            if (self::isSessionStart($tokens, $sig, $at, $i)) {
                $sites[] = ['line' => $tokens[$i]->line, 'insert' => null];
            }
        }

        return $sites;
    }

    /**
     * @param list<PhpToken>  $tokens
     * @param list<int>       $sig
     * @param array<int, int> $at
     */
    private static function isSessionStart(array $tokens, array $sig, array $at, int $i): bool
    {
        $token = $tokens[$i];
        if (!in_array($token->id, [T_STRING, T_NAME_FULLY_QUALIFIED], true) || strtolower(ltrim($token->text, '\\')) !== 'session_start') {
            return false;
        }
        $k = $at[$i];
        $after = isset($sig[$k + 1]) ? $tokens[$sig[$k + 1]] : null;
        $before = $k > 0 ? $tokens[$sig[$k - 1]] : null;

        return $after !== null && $after->text === '(' && ($before === null || !in_array($before->id, self::NOT_A_CALL, true));
    }

    /**
     * Follows the statements of a file and returns, for each session_start()
     * call, the token its statement starts at: the place a new statement can
     * go without changing what any other statement means. Returns null when
     * the brackets do not balance, so the caller refuses instead of guessing.
     *
     * Each statement list (the file, a block, a function or closure body)
     * keeps the start of the statement it is in. A statement ends at `;`, at
     * `?>`, at the `}` of a block it owns (not a closure's, which sits inside
     * an expression), and at the `:` after a case label or an alternative
     * syntax header. `else`, `elseif`, `catch`, `finally`, and the `while` of
     * a do-while carry the previous statement on, so a call in a braceless
     * else branch gets its line before the whole `if`.
     *
     * @param list<PhpToken>  $tokens
     * @param list<int>       $sig
     * @param array<int, int> $at
     *
     * @return list<array{line: int, insert: int|null}>|null
     */
    private static function walk(array $tokens, array $sig, array $at): ?array
    {
        $frames = [self::statementFrame(true)];
        /** @var list<array{depth: int, ends: bool}> $bodies */
        $bodies = [];
        $lastClose = null;
        $sites = [];
        $previous = null;
        foreach ($sig as $k => $i) {
            $token = $tokens[$i];
            $text = $token->text;
            $lower = strtolower($text);
            $top = count($frames) - 1;
            $statementTop = $frames[$top]['type'] === 'statement';

            if ($statementTop && !in_array($text, [';', '}', ')', ']', ':'], true) && !in_array($token->id, [T_CLOSE_TAG, T_INLINE_HTML], true)) {
                $frame = &$frames[$top];
                if ($frame['start'] === null) {
                    if ($frame['ended'] && (in_array($lower, self::CONTINUATIONS, true) || ($lower === 'while' && $frame['prevFirst'] === 'do'))) {
                        $frame['start'] = $frame['prevStart'];
                        $frame['consumedWhile'] = $lower === 'while';
                    } else {
                        $frame['start'] = $i;
                        $frame['label'] = $token->id === T_CASE || $token->id === T_DEFAULT;
                        $frame['consumedWhile'] = false;
                    }
                    $frame['ended'] = false;
                }
                unset($frame);
            }

            if (self::isSessionStart($tokens, $sig, $at, $i)) {
                $insert = null;
                for ($f = count($frames) - 1; $f >= 0; $f--) {
                    if ($frames[$f]['type'] === 'statement') {
                        $insert = $frames[$f]['start'];
                        break;
                    }
                }
                if ($insert !== null && $tokens[$insert]->id === T_OPEN_TAG_WITH_ECHO) {
                    $insert = null;
                }
                $sites[] = ['line' => $token->line, 'insert' => $insert];
            }

            if ($token->id === T_FUNCTION) {
                $after = $sig[$k + 1] ?? null;
                $afterNext = $sig[$k + 2] ?? null;
                $closure = $after !== null && ($tokens[$after]->text === '(' || ($tokens[$after]->text === '&' && $afterNext !== null && $tokens[$afterNext]->text === '('));
                $bodies[] = ['depth' => count($frames), 'ends' => !$closure];
            } elseif ($token->id === T_CLASS && $previous !== null && $previous->id === T_NEW) {
                $bodies[] = ['depth' => count($frames), 'ends' => false];
            }

            if ($text === '(') {
                $frames[] = ['type' => 'paren', 'owner' => $previous !== null ? strtolower($previous->text) : ''];
            } elseif ($text === '[' || $token->id === T_ATTRIBUTE) {
                $frames[] = ['type' => 'bracket', 'owner' => ''];
            } elseif ($token->id === T_CURLY_OPEN || $token->id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $frames[] = ['type' => 'expression', 'owner' => ''];
            } elseif ($text === '{') {
                $dynamic = $previous !== null && (in_array($previous->id, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_VARIABLE], true) || $previous->text === '$');
                $match = $previous !== null && $previous->text === ')' && $lastClose !== null && $lastClose['index'] === $sig[$k - 1] && $lastClose['owner'] === 'match';
                if ($dynamic || $match) {
                    $frames[] = ['type' => 'expression', 'owner' => ''];
                } else {
                    $ends = true;
                    for ($b = count($bodies) - 1; $b >= 0; $b--) {
                        if ($bodies[$b]['depth'] === count($frames)) {
                            $ends = $bodies[$b]['ends'];
                            array_splice($bodies, $b, 1);
                            break;
                        }
                    }
                    $frames[] = self::statementFrame($ends);
                }
            } elseif ($text === ')' || $text === ']') {
                $want = $text === ')' ? 'paren' : 'bracket';
                if ($frames[$top]['type'] !== $want) {
                    return null;
                }
                $closed = array_pop($frames);
                if ($text === ')') {
                    $lastClose = ['index' => $i, 'owner' => (string) ($closed['owner'] ?? '')];
                }
            } elseif ($text === '}') {
                if ($top === 0 || !in_array($frames[$top]['type'], ['statement', 'expression'], true)) {
                    return null;
                }
                $closed = array_pop($frames);
                $parent = count($frames) - 1;
                if (($closed['type'] ?? '') === 'statement' && ($closed['ends'] ?? false) === true && $frames[$parent]['type'] === 'statement') {
                    self::endStatement($frames[$parent], $tokens);
                }
            } elseif ($statementTop && ($text === ';' || $token->id === T_CLOSE_TAG)) {
                self::endStatement($frames[$top], $tokens);
                $bodies = array_values(array_filter($bodies, static fn(array $body): bool => $body['depth'] !== count($frames)));
            } elseif ($statementTop && $text === '?') {
                $frames[$top]['ternary']++;
            } elseif ($statementTop && $text === ':') {
                $frame = &$frames[$top];
                $alternative = $previous !== null
                    && (($previous->text === ')' && $lastClose !== null && $lastClose['index'] === $sig[$k - 1] && in_array($lastClose['owner'], self::CONTROL, true))
                        || strtolower($previous->text) === 'else');
                if ($frame['ternary'] > 0) {
                    $frame['ternary']--;
                } elseif ($frame['label'] || $alternative) {
                    $frame['start'] = null;
                    $frame['ended'] = false;
                    $frame['label'] = false;
                    $frame['ternary'] = 0;
                }
                unset($frame);
            }

            $previous = $token;
        }

        return count($frames) === 1 ? $sites : null;
    }

    /**
     * @return array{type: string, owner: string, ends: bool, start: int|null, prevStart: int|null, prevFirst: string, ended: bool, label: bool, ternary: int, consumedWhile: bool}
     */
    private static function statementFrame(bool $ends): array
    {
        return ['type' => 'statement', 'owner' => '', 'ends' => $ends, 'start' => null, 'prevStart' => null, 'prevFirst' => '', 'ended' => false, 'label' => false, 'ternary' => 0, 'consumedWhile' => false];
    }

    /**
     * @param array<string, mixed> $frame
     * @param list<PhpToken>       $tokens
     */
    private static function endStatement(array &$frame, array $tokens): void
    {
        $start = $frame['start'];
        if (!is_int($start)) {
            // An empty statement, or a close tag right after a block: nothing
            // ended here, so an else on the next line still carries on.
            return;
        }
        $frame['prevStart'] = $start;
        $frame['prevFirst'] = $frame['consumedWhile'] ? '' : strtolower($tokens[$start]->text);
        $frame['start'] = null;
        $frame['ended'] = true;
        $frame['label'] = false;
        $frame['ternary'] = 0;
        $frame['consumedWhile'] = false;
    }

    /**
     * Writes the guard in front of each statement start. A statement that
     * begins its own line gets the comment and the guard on lines of their
     * own, at the same indent; one that shares a line gets the guard inline.
     *
     * @param list<PhpToken> $tokens
     * @param list<int>      $inserts token indexes, ascending
     *
     * @return array{content: string, diff: string}
     */
    private static function rewrite(string $source, array $tokens, array $inserts): array
    {
        $eol = str_contains($source, "\r\n") ? "\r\n" : "\n";
        $content = $source;
        $diff = [];
        foreach (array_reverse($inserts) as $index) {
            $token = $tokens[$index];
            $lineStart = strrpos(substr($source, 0, $token->pos), "\n");
            $lineStart = $lineStart === false ? 0 : $lineStart + 1;
            $indent = substr($source, $lineStart, $token->pos - $lineStart);
            $lineEnd = strpos($source, "\n", $token->pos);
            $line = rtrim(substr($source, $lineStart, ($lineEnd === false ? strlen($source) : $lineEnd) - $lineStart));
            if (trim($indent, " \t") === '') {
                $text = self::MARKER . $eol . $indent . self::GUARD . $eol . $indent;
                array_unshift($diff, sprintf('@@ line %d', $token->line), '+ ' . self::MARKER, '+ ' . self::GUARD, '  ' . trim($line));
            } else {
                $text = self::GUARD . ' ';
                array_unshift($diff, sprintf('@@ line %d', $token->line), '- ' . trim($line), '+ ' . trim(substr($line, 0, $token->pos - $lineStart) . $text . substr($line, $token->pos - $lineStart)));
            }
            $content = substr($content, 0, $token->pos) . $text . substr($content, $token->pos);
        }

        return ['content' => $content, 'diff' => implode("\n", $diff)];
    }

    private static function parses(string $source): bool
    {
        try {
            PhpToken::tokenize($source, TOKEN_PARSE);

            return true;
        } catch (\CompileError) {
            return false;
        }
    }

    /**
     * The session setting a string literal names ("session.use_trans_sid" for
     * ini_set(), "use_trans_sid" for a session_start() option), or null.
     */
    private static function sessionSetting(string $literal): ?string
    {
        $value = strtolower(substr($literal, 1, -1));
        if (str_starts_with($value, 'session.')) {
            $value = substr($value, 8);
        }

        return in_array($value, ['use_strict_mode', 'use_trans_sid', 'use_only_cookies', 'use_cookies'], true) ? $value : null;
    }

    /**
     * The value a setting is given, when it is one literal: true, false, or
     * null when Booster cannot tell (a variable, a constant, an expression).
     */
    private static function literal(?PhpToken $value, ?PhpToken $after): ?bool
    {
        if ($value === null || $after === null || !in_array($after->text, [')', ',', ']'], true)) {
            return null;
        }
        $text = strtolower($value->text);

        return match (true) {
            $value->id === T_LNUMBER => (int) $value->text !== 0,
            $value->id === T_CONSTANT_ENCAPSED_STRING => self::truthy(substr($value->text, 1, -1)),
            $text === 'true' => true,
            $text === 'false', $text === 'null' => false,
            default => null,
        };
    }

    private static function truthy(string $value): bool
    {
        return !in_array(strtolower(trim($value, " \t\"'")), ['', '0', 'off', 'false', 'no', 'none'], true);
    }

    /** Why a setting stops the hop, or null when it is safe with strict mode. */
    private static function hazard(string $setting, ?bool $value): ?string
    {
        return match ($setting) {
            'use_trans_sid' => $value === false ? null : 'turns session.use_trans_sid on (or to a value Booster cannot read), so session IDs travel in URLs',
            'use_only_cookies' => $value === true ? null : 'turns session.use_only_cookies off (or to a value Booster cannot read), so session IDs may come from URLs',
            'use_cookies' => $value === true ? null : 'turns session.use_cookies off (or to a value Booster cannot read), so the app carries session IDs some other way',
            default => null,
        };
    }

    /**
     * The session settings a .htaccess (php_flag or php_value lines) or an
     * ini file sets, by name without the "session." prefix.
     *
     * @return array<string, string>
     */
    private static function configSettings(string $text, bool $htaccess): array
    {
        $pattern = $htaccess
            ? '/^[ \t]*php_(?:admin_)?(?:flag|value)[ \t]+session\.(use_strict_mode|use_trans_sid|use_only_cookies|use_cookies)[ \t]+("?)([^"\s]*)\2/mi'
            : '/^[ \t]*session\.(use_strict_mode|use_trans_sid|use_only_cookies|use_cookies)[ \t]*=[ \t]*("?)([^"\s;#]*)\2/mi';
        $settings = [];
        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $settings[strtolower($match[1])] = $match[3];
            }
        }

        return $settings;
    }

    /**
     * @param array<string, string> $settings
     *
     * @return list<string>
     */
    private static function configHazards(array $settings): array
    {
        $hazards = [];
        foreach ($settings as $name => $value) {
            $hazard = self::hazard($name, self::truthy($value));
            if ($hazard !== null) {
                $hazards[] = $hazard;
            }
        }

        return $hazards;
    }

    private static function requiresHelmet(string $appPath): bool
    {
        $composer = $appPath . '/composer.json';
        if (!is_file($composer)) {
            return false;
        }
        $data = json_decode((string) file_get_contents($composer), true);

        return is_array($data) && (isset($data['require'][self::HELMET_PACKAGE]) || isset($data['require-dev'][self::HELMET_PACKAGE]));
    }

    /**
     * The app's PHP sources, and the .htaccess and ini files that can set a
     * session option, relative and sorted, outside any vendor/, node_modules/
     * or .git/ folder.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function files(string $appPath): array
    {
        $php = [];
        $config = [];
        $stack = [$appPath];
        while (($dir = array_pop($stack)) !== null) {
            $handle = @opendir($dir);
            if ($handle === false) {
                continue;
            }
            while (($entry = readdir($handle)) !== false) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $full = $dir . '/' . $entry;
                if (is_link($full)) {
                    continue;
                }
                if (is_dir($full)) {
                    if (!in_array($entry, ['vendor', 'node_modules', '.git'], true)) {
                        $stack[] = $full;
                    }
                    continue;
                }
                $relative = substr($full, strlen($appPath) + 1);
                if (preg_match('/\.(php|inc|phtml)$/i', $entry) === 1) {
                    $php[] = $relative;
                } elseif ($entry === '.htaccess' || preg_match('/\.ini$/i', $entry) === 1) {
                    $config[] = $relative;
                }
            }
            closedir($handle);
        }
        sort($php);
        sort($config);

        return [$php, $config];
    }
}
