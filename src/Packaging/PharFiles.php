<?php

declare(strict_types=1);

namespace Waynestate\Booster\Packaging;

use FilesystemIterator;
use Phar;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;
use Waynestate\Booster\ToolError;

/**
 * Real filesystem paths for the files bundled with BOOSTER.
 *
 * From a checkout every path is the path in the tree. From the phar, the whole
 * phar is unpacked into a temp directory once per run, because child processes
 * (Rector, `docker build`) cannot be pointed at a phar:// path. The temp
 * directory is removed when the process exits.
 */
final class PharFiles
{
    private static ?string $root = null;

    /**
     * Whether this process is running from the BOOSTER phar.
     */
    public static function running(): bool
    {
        return Phar::running(false) !== '';
    }

    /**
     * A real path for a file or directory bundled with BOOSTER, relative to the
     * project root (for example `config/rector.php` or `docker`).
     */
    public static function path(string $relative): string
    {
        $relative = ltrim($relative, '/');

        if (!self::running()) {
            return dirname(__DIR__, 2) . '/' . $relative;
        }

        return self::tempRoot() . '/' . $relative;
    }

    private static function tempRoot(): string
    {
        if (self::$root !== null) {
            return self::$root;
        }

        $pharPath = Phar::running(false);
        if ($pharPath === '') {
            throw new ToolError('BOOSTER is not running from a phar.');
        }

        $root = sys_get_temp_dir() . '/booster-phar-' . bin2hex(random_bytes(6));
        self::$root = $root;
        register_shutdown_function(static function (): void {
            self::removeTree(self::$root);
        });

        try {
            (new Phar($pharPath))->extractTo($root);
        } catch (Throwable $e) {
            throw new ToolError(sprintf('Could not unpack the BOOSTER phar: %s', $e->getMessage()));
        }
        if (!is_dir($root)) {
            throw new ToolError(sprintf('Could not unpack the BOOSTER phar into "%s".', $root));
        }

        return $root;
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
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
