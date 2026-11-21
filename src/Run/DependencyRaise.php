<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Throwable;
use Waynestate\Booster\Plan\PackageCompat;
use Waynestate\Booster\Plan\PackageFetcher;
use Waynestate\Booster\Plan\PackageFetchResult;
use Waynestate\Booster\ToolError;

/**
 * "Raise when proven": a PHP hop's gate failed inside a dependency that is
 * already on the newest release the app's constraint allows, and a newer
 * major of it installs on the new PHP. Found on the public slim-skeleton
 * 4.5.0 (2026-11-20): php-di/php-di ^6.4 sits on 6.4.0, the last 6.x, whose
 * implicitly nullable parameters are deprecated on PHP 8.4, and the
 * skeleton's error handler turns that deprecation into a 500 on GET /.
 * Composer cannot move past 6.4.0 without a wider constraint, so this
 * proposes one (`<old> || ^<next>`) for the caller to prove with the gate.
 * Nothing here is keyed to a package name except the framework packages the
 * framework hops own, and PHPUnit, which the PHPUnit hops own; those are
 * never raised. Like a Zach Galifianakis cameo, it shows up once per hop at
 * most and only when the scene already went wrong.
 */
final class DependencyRaise
{
    /** Packages other hops own, so a PHP hop never raises them. */
    public const NEVER_RAISED = ['laravel/framework', 'cakephp/cakephp', 'slim/slim', 'phpunit/phpunit'];

    public function __construct(private readonly PackageFetcher $fetcher) {}

    /**
     * The packages a failure names by path, in the order they first appear:
     * every vendor/<vendor>/<package>/ in the text, whatever the separator
     * (a JSON body escapes the slashes, a Windows path uses backslashes).
     *
     * @return list<string>
     */
    public static function tracedPackages(string $text): array
    {
        $packages = [];
        if (preg_match_all('#(?<![A-Za-z0-9_.-])vendor(?:\\\\|/)+([A-Za-z0-9_.-]+)(?:\\\\|/)+([A-Za-z0-9_.-]+)(?:\\\\|/)#', $text, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $name = strtolower($match[1] . '/' . $match[2]);
                if (!in_array($name, $packages, true)) {
                    $packages[] = $name;
                }
            }
        }

        return $packages;
    }

    /**
     * The first package the failure traces to that the app requires directly,
     * that sits on the newest stable release its constraint allows, and whose
     * next major has a stable release for PHP $php. Null when none qualifies,
     * or when Packagist does not answer: an unknown proves nothing.
     *
     * @return array{package: string, section: string, constraint: string, widened: string, installed: string, oldMajor: string, newMajor: string, notes: ?string}|null
     */
    public function candidate(string $appPath, string $php, string $failure): ?array
    {
        $composer = self::readJson(rtrim($appPath, '/\\') . '/composer.json');
        if ($composer === null) {
            return null;
        }
        $locked = self::lockedVersions($appPath);
        foreach (self::tracedPackages($failure) as $package) {
            if (in_array($package, self::NEVER_RAISED, true) || !isset($locked[$package])) {
                continue;
            }
            [$section, $constraint] = self::appConstraint($composer, $package);
            if ($section === null || $constraint === null) {
                continue;
            }
            $found = $this->qualify($package, $constraint, $locked[$package], $php);
            if ($found !== null) {
                return ['package' => $package, 'section' => $section, 'constraint' => $constraint, ...$found];
            }
        }

        return null;
    }

    /**
     * Writes the widened constraint into composer.json, in the section the
     * app required the package from, keeping every other byte of meaning.
     *
     * @param array{package: string, section: string, widened: string} $candidate
     */
    public static function widen(string $appPath, array $candidate): void
    {
        $file = rtrim($appPath, '/\\') . '/composer.json';
        $data = self::readJson($file);
        if ($data === null) {
            throw new ToolError(sprintf('"%s" is not valid JSON.', $file));
        }
        $data[$candidate['section']][$candidate['package']] = $candidate['widened'];
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($file, $json . "\n") === false) {
            throw new ToolError(sprintf('Could not write "%s".', $file));
        }
    }

    /**
     * @return array{widened: string, installed: string, oldMajor: string, newMajor: string, notes: ?string}|null
     */
    private function qualify(string $package, string $constraint, string $installed, string $php): ?array
    {
        $result = $this->fetcher->fetch(PackageCompat::PACKAGIST . $package . '.json');
        if ($result->status !== PackageFetchResult::OK || $result->data === null) {
            return null;
        }
        $releases = [];
        foreach (PackageCompat::expandPackages($result->data)[$package] ?? [] as $version => $entry) {
            if (VersionParser::parseStability((string) $version) === 'stable') {
                $releases[(string) $version] = $entry;
            }
        }
        $parser = new VersionParser();
        try {
            $installedNormal = $parser->normalize($installed);
        } catch (Throwable) {
            return null;
        }
        // Composer could still move inside the app's own range: that is an
        // update, not a raise, so leave it to the person (or the next run).
        $newestNormal = null;
        foreach (array_keys($releases) as $version) {
            try {
                $normal = $parser->normalize($version);
            } catch (Throwable) {
                continue;
            }
            if (self::satisfies($version, $constraint) && ($newestNormal === null || version_compare($normal, $newestNormal, '>'))) {
                $newestNormal = $normal;
            }
        }
        if ($newestNormal === null || $newestNormal !== $installedNormal) {
            return null;
        }
        $oldMajor = (int) explode('.', $installedNormal)[0];
        // The lowest major above the installed one with a release for $php.
        $next = null;
        foreach ($releases as $version => $entry) {
            try {
                $major = (int) explode('.', $parser->normalize($version))[0];
            } catch (Throwable) {
                continue;
            }
            if ($major <= $oldMajor || ($next !== null && $major >= $next['major'])) {
                continue;
            }
            $requirePhp = $entry['require']['php'] ?? null;
            if (is_string($requirePhp) && !self::intersects($php . '.*', $requirePhp)) {
                continue;
            }
            $next = ['major' => $major, 'entry' => $entry];
        }
        if ($next === null) {
            return null;
        }
        $support = (array) ($next['entry']['support'] ?? []);
        $notes = null;
        foreach ([$support['docs'] ?? null, $support['source'] ?? null, $next['entry']['source']['url'] ?? null] as $url) {
            if (is_string($url) && preg_match('#^https://#', $url) === 1) {
                $notes = (string) preg_replace('#\.git$#', '', $url);
                break;
            }
        }

        return [
            'widened' => $constraint . ' || ^' . $next['major'],
            'installed' => ltrim($installed, 'v'),
            'oldMajor' => (string) $oldMajor,
            'newMajor' => (string) $next['major'],
            'notes' => $notes,
        ];
    }

    /**
     * The section and constraint the app requires $package with, or nulls
     * when it names none Booster can widen (a dev branch, an alias, a star).
     *
     * @param array<string, mixed> $composer
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function appConstraint(array $composer, string $package): array
    {
        foreach (['require', 'require-dev'] as $section) {
            $constraint = $composer[$section][$package] ?? null;
            if (!is_string($constraint)) {
                continue;
            }
            $constraint = trim($constraint);
            if ($constraint === '' || $constraint === '*' || str_contains($constraint, 'dev-') || str_contains($constraint, ' as ')) {
                return [null, null];
            }
            try {
                (new VersionParser())->parseConstraints($constraint);
            } catch (Throwable) {
                return [null, null];
            }

            return [$section, $constraint];
        }

        return [null, null];
    }

    /**
     * @return array<string, string> locked package name => version
     */
    private static function lockedVersions(string $appPath): array
    {
        $lock = self::readJson(rtrim($appPath, '/\\') . '/composer.lock');
        $locked = [];
        foreach (['packages', 'packages-dev'] as $section) {
            foreach ((array) ($lock[$section] ?? []) as $entry) {
                if (is_array($entry) && is_string($entry['name'] ?? null) && is_string($entry['version'] ?? null)) {
                    $locked[strtolower($entry['name'])] = $entry['version'];
                }
            }
        }

        return $locked;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function readJson(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }

    private static function satisfies(string $version, string $constraint): bool
    {
        try {
            return Semver::satisfies($version, $constraint);
        } catch (Throwable) {
            return false;
        }
    }

    private static function intersects(string $range, string $constraint): bool
    {
        try {
            $parser = new VersionParser();

            return $parser->parseConstraints($constraint)->matches($parser->parseConstraints($range));
        } catch (Throwable) {
            return false;
        }
    }
}
