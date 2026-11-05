<?php

declare(strict_types=1);

namespace Waynestate\Booster\Scan;

use Waynestate\Booster\Run\VendoredDirs;

/**
 * Finds the libraries an app hand-copied into a vendor directory instead of
 * installing with Composer. A real app committed Doctrine 2.2.2, Smarty 3.1.8
 * and Slim 1.x straight under vendor/ in 2012, and those copies crash on
 * modern PHP. The scanner never rewrites them; it only names them, reads the
 * version out of the source where the library still says it, and points at
 * the Composer package that would do the job today.
 */
final class VendoredLibraries
{
    /** How deep version detection looks inside one library directory. */
    private const MAX_DEPTH = 6;

    /** How much of a file version detection reads. */
    private const MAX_BYTES = 65536;

    /** @var array<string, array{0: string, 1: string}> lowercased dirname to display name and Composer package */
    private const KNOWN = [
        'doctrine' => ['Doctrine', 'doctrine/orm'],
        'smarty' => ['Smarty', 'smarty/smarty'],
        'slim' => ['Slim', 'slim/slim'],
        'phpmailer' => ['PHPMailer', 'phpmailer/phpmailer'],
        'swiftmailer' => ['Swift Mailer', 'symfony/mailer'],
        'twig' => ['Twig', 'twig/twig'],
        'monolog' => ['Monolog', 'monolog/monolog'],
    ];

    /**
     * Every hand-copied library under the app's vendor directories, sorted
     * by path. Composer-managed vendor trees yield nothing, and a library
     * whose package a neighboring composer.lock already declares is skipped.
     *
     * @return list<VendoredLibrary>
     */
    public static function find(string $appPath): array
    {
        $appPath = rtrim($appPath, '/\\');
        if (!is_dir($appPath)) {
            return [];
        }

        $libraries = [];
        foreach (VendoredDirs::find($appPath) as $dir) {
            // Only directories named vendor count; node_modules is ignored.
            if (!str_ends_with($dir, '/vendor') && $dir !== 'vendor') {
                continue;
            }
            $full = $appPath . '/' . $dir;
            if (self::composerManaged($full)) {
                continue;
            }
            $locked = self::lockPackageNames(dirname($full));
            $subdirs = self::immediateSubdirs($full);
            if ($subdirs === []) {
                // Loose *.php files straight in the vendor dir count as one
                // anonymous library named after the folder itself.
                if (self::hasPhpFilesDirectly($full)) {
                    $libraries[] = new VendoredLibrary('vendor', $dir, null, null);
                }
                continue;
            }
            foreach ($subdirs as $subdir) {
                if (str_starts_with($subdir, '.') || $subdir === 'bin') {
                    continue;
                }
                if (self::locked($locked, strtolower($subdir))) {
                    continue;
                }
                $libraries[] = self::makeLibrary($subdir, $dir . '/' . $subdir, $full . '/' . $subdir);
            }
        }

        usort($libraries, static fn(VendoredLibrary $a, VendoredLibrary $b): int => strcmp($a->path, $b->path));

        return $libraries;
    }

    /** Whether this vendor tree belongs to Composer and so yields nothing. */
    private static function composerManaged(string $vendorFull): bool
    {
        if (is_file($vendorFull . '/composer/installed.json')) {
            return true;
        }

        return is_file($vendorFull . '/autoload.php') && is_dir($vendorFull . '/composer');
    }

    /**
     * The package names a composer.lock sitting in $folder declares,
     * lowercased. A missing or unreadable lock declares nothing.
     *
     * @return array<string, true>
     */
    private static function lockPackageNames(string $folder): array
    {
        $lock = $folder . '/composer.lock';
        if (!is_file($lock)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($lock), true);
        if (!is_array($decoded)) {
            return [];
        }

        $names = [];
        foreach (['packages', 'packages-dev'] as $key) {
            $packages = $decoded[$key] ?? [];
            if (!is_array($packages)) {
                continue;
            }
            foreach ($packages as $package) {
                if (is_array($package) && isset($package['name']) && is_string($package['name'])) {
                    $names[strtolower($package['name'])] = true;
                }
            }
        }

        return $names;
    }

    /**
     * Whether a lock package name starts with the lowercased directory name.
     *
     * @param array<string, true> $names
     */
    private static function locked(array $names, string $prefix): bool
    {
        foreach (array_keys($names) as $name) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The immediate subdirectory names of $dir, sorted. Symlinks are not
     * followed and unreadable directories yield nothing.
     *
     * @return list<string>
     */
    private static function immediateSubdirs(string $dir): array
    {
        $handle = @opendir($dir);
        if ($handle === false) {
            return [];
        }

        $result = [];
        while (($entry = readdir($handle)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_link($full) || !is_dir($full)) {
                continue;
            }
            $result[] = $entry;
        }
        closedir($handle);
        sort($result);

        return $result;
    }

    /** Whether $dir holds at least one regular *.php file of its own. */
    private static function hasPhpFilesDirectly(string $dir): bool
    {
        $handle = @opendir($dir);
        if ($handle === false) {
            return false;
        }

        $found = false;
        while (($entry = readdir($handle)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_link($full) || !is_file($full)) {
                continue;
            }
            if (str_ends_with($entry, '.php')) {
                $found = true;
                break;
            }
        }
        closedir($handle);

        return $found;
    }

    private static function makeLibrary(string $dirName, string $path, string $full): VendoredLibrary
    {
        $known = self::KNOWN[strtolower($dirName)] ?? null;

        return new VendoredLibrary(
            $known[0] ?? $dirName,
            $path,
            self::detectVersion($dirName, $full),
            $known[1] ?? null,
        );
    }

    /** The version a known library prints in its own source, or null. */
    private static function detectVersion(string $dirName, string $libDir): ?string
    {
        $key = strtolower($dirName);
        if (!array_key_exists($key, self::KNOWN)) {
            return null;
        }

        $files = self::collectFiles($libDir);

        return match ($key) {
            'doctrine' => self::doctrineVersion($files),
            'smarty' => self::smartyVersion($files),
            'slim' => self::slimVersion($files),
            'phpmailer' => self::phpmailerVersion($files),
            default => null,
        };
    }

    /**
     * Every regular file under $root, at most MAX_DEPTH levels down, without
     * following symlinks. Unreadable directories simply contribute nothing.
     *
     * @return list<string>
     */
    private static function collectFiles(string $root): array
    {
        $files = [];
        $stack = [['dir' => $root, 'depth' => 0]];
        while (($item = array_pop($stack)) !== null) {
            $handle = @opendir($item['dir']);
            if ($handle === false) {
                continue;
            }
            while (($entry = readdir($handle)) !== false) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $full = $item['dir'] . DIRECTORY_SEPARATOR . $entry;
                if (is_link($full)) {
                    continue;
                }
                if (is_file($full)) {
                    $files[] = $full;
                } elseif (is_dir($full) && $item['depth'] < self::MAX_DEPTH) {
                    $stack[] = ['dir' => $full, 'depth' => $item['depth'] + 1];
                }
            }
            closedir($handle);
        }

        return $files;
    }

    /**
     * The first $pattern match in the first MAX_BYTES of $file, or null.
     * Anything unreadable or unmatched leaves the version unknown.
     */
    private static function firstMatch(string $file, string $pattern): ?string
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return null;
        }
        try {
            $chunk = fread($handle, self::MAX_BYTES);
        } finally {
            fclose($handle);
        }
        if ($chunk === false) {
            return null;
        }
        if (preg_match($pattern, $chunk, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Doctrine prints its version in a Version.php file. Prefer the ORM
     * copy over the DBAL copy over the Common one.
     *
     * @param list<string> $files
     */
    private static function doctrineVersion(array $files): ?string
    {
        $groups = [[], [], []];
        foreach ($files as $file) {
            if (basename($file) !== 'Version.php') {
                continue;
            }
            $path = '/' . str_replace('\\', '/', $file);
            if (str_contains($path, '/ORM/')) {
                $groups[0][] = $file;
            } elseif (str_contains($path, '/DBAL/')) {
                $groups[1][] = $file;
            } elseif (str_contains($path, '/Common/')) {
                $groups[2][] = $file;
            }
        }

        $pattern = "/const\s+VERSION\s*=\s*'([^']+)'/";
        foreach ($groups as $group) {
            foreach ($group as $file) {
                $version = self::firstMatch($file, $pattern);
                if ($version !== null) {
                    return $version;
                }
            }
        }

        return null;
    }

    /**
     * @param list<string> $files
     */
    private static function smartyVersion(array $files): ?string
    {
        $pattern = "/SMARTY_VERSION['\"]?\\s*[,=]\\s*['\"](?:Smarty[- ])?(\\d[^'\"]*)['\"]/";
        foreach ($files as $file) {
            if (basename($file) !== 'Smarty.class.php') {
                continue;
            }
            $version = self::firstMatch($file, $pattern);
            if ($version !== null) {
                return $version;
            }
        }

        return null;
    }

    /**
     * @param list<string> $files
     */
    private static function slimVersion(array $files): ?string
    {
        $pattern = "/const\s+VERSION\s*=\s*'([^']+)'/";
        foreach ($files as $file) {
            if (basename($file) !== 'Slim.php') {
                continue;
            }
            if (basename(dirname($file)) !== 'Slim') {
                continue;
            }
            $version = self::firstMatch($file, $pattern);
            if ($version !== null) {
                return $version;
            }
        }

        return null;
    }

    /**
     * @param list<string> $files
     */
    private static function phpmailerVersion(array $files): ?string
    {
        // sprintf keeps the dollar sign out of the source text entirely.
        $pattern = sprintf('/(?:%sVersion|const\s+VERSION)\s*=\s*\'([^\']+)\'/', '\\$');
        foreach ($files as $file) {
            $name = basename($file);
            if ($name !== 'class.phpmailer.php' && $name !== 'PHPMailer.php') {
                continue;
            }
            $version = self::firstMatch($file, $pattern);
            if ($version !== null) {
                return $version;
            }
        }

        return null;
    }
}
