<?php

declare(strict_types=1);

namespace Waynestate\Booster\Console;

/**
 * How this Booster was started, so a report's "Next:" line names the same
 * command the person just typed: a bin/booster or a .phar exactly as typed
 * after `php`, the bare alias when there is no file to hand to php, and
 * `php bin/booster` when the runner gives nothing away (tests, phpunit).
 */
final class Invocation
{
    /**
     * The program name for a "Next:" line. Reads argv[0] unless a test passes
     * one. A basename of `booster` (bin/booster) or a `.phar` keeps the path
     * as typed after `php` (backslashes count as dir parts, so this works on
     * Windows too); a bare `booster` with no such file in the current
     * directory is an alias or a script on PATH, so it stays `booster`.
     * Anything else (phpunit, tests) falls back to the checkout form.
     */
    public static function program(?string $argv0 = null): string
    {
        $argv0 ??= $_SERVER['argv'][0] ?? '';
        if ($argv0 === '') {
            return 'php bin/booster';
        }

        $base = basename(str_replace('\\', '/', $argv0));
        if ($base !== 'booster' && !str_ends_with($base, '.phar')) {
            return 'php bin/booster';
        }

        $bare = !str_contains($argv0, '/') && !str_contains($argv0, '\\');
        if ($base === 'booster' && $bare && !is_file('booster')) {
            // No file to hand to php: the person typed an alias or a PATH script.
            return 'booster';
        }

        return 'php ' . $argv0;
    }

    /** A path for a shell line: double-quoted only when it contains a space. */
    public static function pathArg(string $path): string
    {
        return str_contains($path, ' ') ? '"' . $path . '"' : $path;
    }

    /**
     * An option value for a shell line: as typed when it is plain (a version,
     * a hop id, a path without spaces), else in double quotes with inner
     * quotes escaped, so `--test-command="php tests/run.php"` survives the
     * copy and paste.
     */
    public static function valueArg(string $value): string
    {
        if (preg_match('#^[A-Za-z0-9_.,:/\\\\=@+{}-]+$#', $value) === 1) {
            return $value;
        }

        return '"' . str_replace('"', '\\"', $value) . '"';
    }

    /**
     * The flags for a "Next:" line, one shell string with a leading space, or
     * '' when there are none.
     *
     * @param list<string> $flags
     */
    public static function flagsText(array $flags): string
    {
        return $flags === [] ? '' : ' ' . implode(' ', $flags);
    }
}
