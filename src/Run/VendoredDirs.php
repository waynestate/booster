<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

/**
 * Finds the vendored library directories an app carries, at any depth, so
 * Rector skips them all. A real app committed PHPMailer under src/vendor/,
 * and its parse error stopped the whole hop while the config only knew the
 * vendor/ at the app root. A booster burns its own fuel, not the cargo's.
 */
final class VendoredDirs
{
    /**
     * Every directory named vendor or node_modules under the app, at any
     * depth including the root. Matched directories and .git are never
     * descended into, and symlinks are not followed.
     *
     * @return list<string> app-relative paths with forward slashes, no leading or trailing slash, sorted
     */
    public static function find(string $appPath): array
    {
        $appPath = rtrim($appPath, '/\\');
        if (!is_dir($appPath)) {
            return [];
        }

        $found = [];
        $stack = [$appPath];
        while (($current = array_pop($stack)) !== null) {
            $handle = @opendir($current);
            if ($handle === false) {
                continue;
            }
            while (($entry = readdir($handle)) !== false) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $full = $current . DIRECTORY_SEPARATOR . $entry;
                if (is_link($full) || !is_dir($full)) {
                    continue;
                }
                if ($entry === 'vendor' || $entry === 'node_modules') {
                    $found[] = str_replace('\\', '/', substr($full, strlen($appPath) + 1));
                    continue; // Never descend into a matched directory.
                }
                if ($entry === '.git') {
                    continue;
                }
                $stack[] = $full;
            }
            closedir($handle);
        }

        sort($found);

        return $found;
    }

    /**
     * The entries that sit below the app root, i.e. the ones worth naming in
     * a report: the app-root vendor/ and node_modules/ are always skipped,
     * so only the nested surprises deserve a line.
     *
     * @param list<string> $dirs
     *
     * @return list<string>
     */
    public static function nested(array $dirs): array
    {
        return array_values(array_filter($dirs, static fn(string $dir): bool => str_contains($dir, '/')));
    }
}
