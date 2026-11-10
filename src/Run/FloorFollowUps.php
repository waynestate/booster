<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

/**
 * After a run raises the app's PHP floor, the other places the app names a
 * PHP version: the CI test matrix, the deploy kit's image tags, Parity's
 * parity.json, and the app's own Dockerfile and Compose files (Laravel
 * Sail's runtime too). Booster never edits CI or deploy files (they belong
 * to the app's own pipeline, to Squire's kit and to Parity), so each one
 * below the new floor becomes a "Next" line
 * with its file and line. Left alone, a matrix entry below the floor fails
 * on the first push (Composer's platform check refuses it) and an image tag
 * below it can no longer run the app. A rocket that clears the pad but
 * leaves the launch tower bolted on is still not in orbit.
 */
final class FloorFollowUps
{
    /** The CI files Booster reads: GitLab's, and GitHub's workflows. */
    private const CI_FILES = ['.gitlab-ci.yml', '.gitlab-ci.yaml'];

    private const WORKFLOW_DIR = '.github/workflows';

    /**
     * The files that name the image the app runs on: the Squire deploy kit's,
     * Parity's parity.json (Citadel on your own computer), and the app's own
     * Dockerfile and Compose files (Laravel Sail's included).
     */
    private const DEPLOY_FILES = [
        '.env.squire.example', '.env.squire', 'docker-compose.squire.yml', '.squire/Dockerfile', '.squire/portable.Dockerfile', '.squire/base-fallback.Dockerfile',
        'parity.json',
        'Dockerfile', 'docker-compose.yml', 'docker-compose.yaml', 'compose.yml', 'compose.yaml',
    ];

    /**
     * A key that holds PHP versions: php, php_version, php-versions, and so on.
     * Group 2 is the rest of the line after the colon.
     */
    private const VERSION_KEY = '/^\s*-?\s*["\']?(php(?:[_-]?versions?)?)["\']?\s*:\s*(.*)$/i';

    /** An image whose name ends in php or php-<variant>, tagged major.minor: php:8.3-cli, citadel/php-apache:8.4r5. */
    private const IMAGE = '/(?<![\w.])((?:[a-z0-9][\w.-]*\/)*php(?:-[a-z]+)?):(\d+\.\d+)/i';

    /**
     * Laravel Sail's runtime, which a Sail app's docker-compose.yml names twice:
     * the build context (vendor/laravel/sail/runtimes/8.4) and the image it
     * tags (sail-8.4/app).
     */
    private const SAIL = '#(?:vendor/laravel/sail/runtimes/|(?<![\w.-])sail-)(\d+\.\d+)(?![\d])#';

    /**
     * The follow-up lines for an app whose floor is now $floor (major.minor),
     * or [] when nothing names an older PHP.
     *
     * @return list<string>
     */
    public static function find(string $appPath, string $floor): array
    {
        $lines = [];
        foreach (self::ciFiles($appPath) as $file) {
            foreach (self::ciLines($appPath, $file, $floor) as $line) {
                $lines[] = $line;
            }
        }
        foreach (self::DEPLOY_FILES as $file) {
            foreach (self::imageLines($appPath, $file, $floor) as $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * The PHP floor composer.json states now (major.minor), or null when it
     * names none: the first version in require.php, which for ^8.5, >=8.5,
     * 8.5.* and ^7.4 || ^8.0 alike is the lowest one it allows.
     */
    public static function composerFloor(string $appPath): ?string
    {
        $raw = is_file($appPath . '/composer.json') ? file_get_contents($appPath . '/composer.json') : false;
        $data = $raw === false ? null : json_decode($raw, true);
        $php = is_array($data) && is_array($data['require'] ?? null) ? ($data['require']['php'] ?? null) : null;
        if (!is_string($php) || preg_match('/(\d+)\.(\d+)/', $php, $m) !== 1) {
            return null;
        }

        return $m[1] . '.' . $m[2];
    }

    /** @return list<string> the CI files the app has, relative to it */
    private static function ciFiles(string $appPath): array
    {
        $files = [];
        foreach (self::CI_FILES as $file) {
            if (is_file($appPath . '/' . $file)) {
                $files[] = $file;
            }
        }
        // Two globs, not GLOB_BRACE: musl (Alpine) has no brace expansion.
        $workflows = [...(glob($appPath . '/' . self::WORKFLOW_DIR . '/*.yml') ?: []), ...(glob($appPath . '/' . self::WORKFLOW_DIR . '/*.yaml') ?: [])];
        sort($workflows);
        foreach ($workflows as $path) {
            $files[] = self::WORKFLOW_DIR . '/' . basename($path);
        }

        return $files;
    }

    /**
     * A CI file's version lists and PHP images below the floor. A list may
     * sit on its key's line (php_versions: ["8.3", "8.5"]) or under it, one
     * "- 8.3" per line.
     *
     * @return list<string>
     */
    private static function ciLines(string $appPath, string $file, string $floor): array
    {
        $rows = self::rows($appPath . '/' . $file);
        $out = [];
        $count = count($rows);
        for ($i = 0; $i < $count; $i++) {
            $row = $rows[$i];
            if (self::isComment($row)) {
                continue;
            }
            if (preg_match(self::VERSION_KEY, $row, $m) === 1) {
                $value = trim($m[2]);
                $below = self::below(self::versions($value), $floor);
                if ($value === '') {
                    // A block list: the "- 8.3" lines that follow the key.
                    for ($j = $i + 1; $j < $count && preg_match('/^\s*-\s*["\']?(\d+\.\d+)/', $rows[$j], $item) === 1; $j++) {
                        if (version_compare($item[1], $floor, '<')) {
                            $out[] = self::matrixLine($file, $j + 1, [$item[1]], $floor);
                        }
                    }
                } elseif ($below !== []) {
                    $out[] = self::matrixLine($file, $i + 1, $below, $floor);
                }
                continue;
            }
            foreach (self::imageMatches($row, $floor) as [$image, $version]) {
                $out[] = self::imageLine($file, $i + 1, $image, $version, $floor);
            }
        }

        return $out;
    }

    /** @return list<string> one line per PHP image below the floor in $file */
    private static function imageLines(string $appPath, string $file, string $floor): array
    {
        $out = [];
        foreach (self::rows($appPath . '/' . $file) as $i => $row) {
            if (self::isComment($row)) {
                continue;
            }
            foreach (self::imageMatches($row, $floor) as [$image, $version]) {
                $out[] = self::imageLine($file, $i + 1, $image, $version, $floor);
            }
            if (preg_match(self::SAIL, $row, $sail) === 1 && version_compare($sail[1], $floor, '<')) {
                $out[] = sprintf(
                    'Next: %1$s:%2$d names Laravel Sail\'s PHP %3$s runtime, below the new floor %4$s, which can no longer run the app. Move it to %4$s (the runtimes/%4$s build context and the sail-%4$s/app image go together; update laravel/sail first if it has no %4$s runtime yet).',
                    $file,
                    $i + 1,
                    $sail[1],
                    $floor,
                );
            }
        }

        return $out;
    }

    /** @return list<array{0: string, 1: string}> [image name, its major.minor tag] for each image below the floor */
    private static function imageMatches(string $row, string $floor): array
    {
        if (preg_match_all(self::IMAGE, $row, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }
        $found = [];
        foreach ($matches as $match) {
            if (version_compare($match[2], $floor, '<')) {
                $found[] = [$match[1], $match[2]];
            }
        }

        return $found;
    }

    /** @param list<string> $versions */
    private static function matrixLine(string $file, int $line, array $versions, string $floor): string
    {
        return sprintf(
            'Next: %s:%d tests PHP %s, below the new floor %s: %s on the first push (Composer refuses to install below the floor). Drop %s from the list.',
            $file,
            $line,
            self::join($versions),
            $floor,
            count($versions) === 1 ? 'that job fails' : 'those jobs fail',
            count($versions) === 1 ? 'it' : 'them',
        );
    }

    private static function imageLine(string $file, int $line, string $image, string $version, string $floor): string
    {
        return sprintf(
            'Next: %s:%d names %s:%s, below the new floor %s, which can no longer run the app. Move it to a PHP %s image%s.',
            $file,
            $line,
            $image,
            $version,
            $floor,
            $floor,
            self::imageHint($file, $image),
        );
    }

    private static function imageHint(string $file, string $image): string
    {
        if (!str_starts_with(strtolower($image), 'citadel/')) {
            return '';
        }
        // A Citadel tag carries a build revision (8.5r3) Booster cannot know.
        $hint = ' (Citadel tags carry a build revision, such as 8.5r3, that Booster cannot know, so it leaves the edit to you';

        // Parity runs only the Citadel images it can check, and says which.
        return $hint . ($file === 'parity.json' ? '; parity image list shows the ones Parity can run)' : ')');
    }

    /**
     * @param list<string> $versions
     *
     * @return list<string>
     */
    private static function below(array $versions, string $floor): array
    {
        return array_values(array_filter($versions, static fn(string $v): bool => version_compare($v, $floor, '<')));
    }

    /** @return list<string> every major.minor in a value such as ["8.3", "8.4"] or '8.3' */
    private static function versions(string $value): array
    {
        preg_match_all('/(?<![\d.])(\d+\.\d+)(?![\d])/', $value, $m);

        return array_values(array_unique($m[1]));
    }

    /** @param list<string> $versions */
    private static function join(array $versions): string
    {
        if (count($versions) < 2) {
            return implode('', $versions);
        }
        $last = array_pop($versions);

        return implode(', ', $versions) . ' and ' . $last;
    }

    private static function isComment(string $row): bool
    {
        return str_starts_with(ltrim($row), '#');
    }

    /** @return list<string> the file's lines, or [] when it is absent */
    private static function rows(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $raw = file_get_contents($path);

        if ($raw === false) {
            return [];
        }

        return preg_split('/\r\n|\n|\r/', $raw) ?: [];
    }
}
