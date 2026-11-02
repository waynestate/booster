<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

/**
 * Finds the PHP files an app carries that are not valid UTF-8, so Rector can
 * run without them. Rector writes file contents into its JSON report, and a
 * stray byte in the app's own encoding makes json_encode die with "Malformed
 * UTF-8 characters" before any report is written. A booster names the cargo it
 * cannot carry instead of burning on it.
 */
final class NonUtf8Files
{
    /**
     * Every .php file under the app whose bytes are not valid UTF-8. Blade
     * templates, IDE helper files and .phpstorm.meta.php are not PHP source,
     * and the directories in $skipDirs plus .git, storage, bootstrap/cache
     * and public/build are never entered. Symlinks are not followed, and
     * unreadable files are ignored.
     *
     * @param list<string> $skipDirs app-relative directories not to enter, the output of VendoredDirs::find
     *
     * @return list<string> app-relative paths with forward slashes, no leading or trailing slash, sorted
     */
    public static function find(string $appPath, array $skipDirs): array
    {
        $appPath = rtrim($appPath, '/\\');
        if (!is_dir($appPath)) {
            return [];
        }

        $skip = [];
        foreach ($skipDirs as $dir) {
            $skip[str_replace('\\', '/', trim($dir, '/'))] = true;
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
                if (is_link($full)) {
                    continue;
                }
                if (is_dir($full)) {
                    $rel = str_replace('\\', '/', substr($full, strlen($appPath) + 1));
                    if ($entry === '.git' || $entry === 'storage' || $rel === 'bootstrap/cache' || $rel === 'public/build' || isset($skip[$rel])) {
                        continue; // Never descend into these.
                    }
                    $stack[] = $full;
                    continue;
                }
                if (!is_file($full) || !str_ends_with($entry, '.php')) {
                    continue;
                }
                if (str_ends_with($entry, '.blade.php') || str_contains($entry, '_ide_helper') || $entry === '.phpstorm.meta.php') {
                    continue;
                }
                $contents = @file_get_contents($full);
                if ($contents === false) {
                    continue;
                }
                if (preg_match('//u', $contents) !== 1) {
                    $found[] = str_replace('\\', '/', substr($full, strlen($appPath) + 1));
                }
            }
            closedir($handle);
        }

        sort($found);

        return $found;
    }
}
