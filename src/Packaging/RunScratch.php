<?php

declare(strict_types=1);

namespace Waynestate\Booster\Packaging;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Waynestate\Booster\ToolError;

/**
 * One scratch directory per Booster process, removed when the process exits.
 *
 * Rector and PHPStan keep caches (Rector's file cache, PHPStan's DI container
 * and result cache) that store absolute paths into the code that ran them.
 * From the phar that code lives in a random temp directory (PharFiles) that is
 * gone after the run, so a cache shared through sys_get_temp_dir() sends the
 * next run to files that no longer exist, and Rector dies on hop 1. Every
 * cache and temp directory those child processes use lives here instead, so
 * no run ever reads another run's cache. Groundhog Day, but only once.
 */
final class RunScratch
{
    private static ?string $root = null;

    /**
     * The scratch root for this process, created on first use.
     */
    public static function root(): string
    {
        if (self::$root !== null && is_dir(self::$root)) {
            return self::$root;
        }

        $root = sys_get_temp_dir() . '/booster-run-' . bin2hex(random_bytes(6));
        if (!mkdir($root, 0700, true) && !is_dir($root)) {
            throw new ToolError(sprintf('Could not create the scratch directory "%s".', $root));
        }
        if (self::$root === null) {
            register_shutdown_function(static function (): void {
                self::removeTree(self::$root);
            });
        }
        self::$root = $root;

        return $root;
    }

    /**
     * A directory under the scratch root, created if missing.
     */
    public static function dir(string $name): string
    {
        $dir = self::root() . '/' . trim($name, '/');
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new ToolError(sprintf('Could not create the scratch directory "%s".', $dir));
        }

        return $dir;
    }

    /**
     * Environment for a child PHP process (Rector, PHPStan) so that anything it
     * writes through sys_get_temp_dir() lands in this run's scratch directory.
     *
     * @return array<string, string>
     */
    public static function tempEnv(): array
    {
        $tmp = self::dir('tmp');

        return ['TMPDIR' => $tmp, 'TMP' => $tmp, 'TEMP' => $tmp];
    }

    private static function removeTree(?string $dir): void
    {
        if ($dir === null || !is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            // A file a child left read-only or locked must not turn a clean
            // exit into a crash; the OS temp cleaner gets the rest.
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
