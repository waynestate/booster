<?php

declare(strict_types=1);

namespace Waynestate\Booster\Console;

/**
 * How this Booster was started, so a report's "Next:" line names the same
 * command the person just typed: `php bin/booster` from a checkout,
 * `php something.phar` for a phar, or the bare alias when there is no file
 * to hand to php.
 */
final class Invocation
{
    /**
     * The program name for a "Next:" line. Reads argv[0] unless a test passes
     * one. A basename of `booster` (bin/booster) or a `.phar` keeps the path
     * as typed after `php`; a bare `booster` with no such file in the current
     * directory is an alias or a script on PATH, so it stays `booster`.
     * Anything else (phpunit, tests) falls back to the checkout form.
     */
    public static function program(?string $argv0 = null): string
    {
        $argv0 ??= $_SERVER['argv'][0] ?? '';
        if ($argv0 === '') {
            return 'php bin/booster';
        }

        $base = basename($argv0);
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
}
