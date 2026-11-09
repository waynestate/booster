<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

use Composer\Semver\Semver;

/**
 * Which locked packages cannot install on a PHP: the ones whose own
 * require.php leaves it out. A plain `composer install` refuses such a lock
 * outright ("Your lock file does not contain a compatible set of packages"),
 * so a PHP hop updates just those packages instead, and a baseline that
 * failed on one can name the update to run.
 *
 * Found on a real 2012 app (2026-11-09): vendored-to-composer resolved the
 * first lock on PHP 7.4, where symfony/service-contracts sits at v1.1.2
 * (php ^7.1.3), and the 8.0 hop's plain install refused it every time. A
 * whole lock for 7.4 through 8.5 does not exist without hand-picked
 * constraints, so the lock follows the hops instead, one package at a time.
 */
final class LockRefusals
{
    /**
     * The locked packages (packages and packages-dev) whose require.php does
     * not allow $minor, keyed by name, with the locked version and the
     * constraint. Empty when there is no readable lock, or nothing refuses.
     *
     * When composer.json pins config.platform.php, Composer checks the lock
     * against the pin, not the running PHP: the pin when it is on $minor
     * already, else the first release of $minor (where a PHP hop moves it).
     * Without a pin it checks the newest patch of $minor, which is what the
     * gate image runs.
     *
     * @return array<string, array{version: string, constraint: string}>
     */
    public static function find(string $appPath, string $minor): array
    {
        $appPath = rtrim($appPath, '/\\');
        $lock = self::json($appPath . '/composer.lock');
        if ($lock === null) {
            return [];
        }
        $version = self::platformVersion($appPath, $minor);
        $refusing = [];
        foreach (['packages', 'packages-dev'] as $section) {
            $packages = is_array($lock[$section] ?? null) ? $lock[$section] : [];
            foreach ($packages as $package) {
                if (!is_array($package) || !is_string($package['name'] ?? null)) {
                    continue;
                }
                $constraint = $package['require']['php'] ?? null;
                if (!is_string($constraint) || $constraint === '') {
                    continue;
                }
                try {
                    $allowed = Semver::satisfies($version, $constraint);
                } catch (\UnexpectedValueException) {
                    // A constraint Composer's own parser cannot read is
                    // Composer's to report, not a reason to update.
                    continue;
                }
                if (!$allowed) {
                    $refusing[$package['name']] = [
                        'version' => is_string($package['version'] ?? null) ? $package['version'] : '?',
                        'constraint' => $constraint,
                    ];
                }
            }
        }

        return $refusing;
    }

    /**
     * One clause naming each refusing package, for a report line:
     * "symfony/service-contracts v1.1.2 needs php ^7.1.3".
     *
     * @param array<string, array{version: string, constraint: string}> $refusing
     */
    public static function describe(array $refusing): string
    {
        $parts = [];
        foreach ($refusing as $name => $package) {
            $parts[] = sprintf('%s %s needs php %s', $name, $package['version'], $package['constraint']);
        }

        return implode(', ', $parts);
    }

    /** The PHP version Composer checks the lock against on $minor. */
    private static function platformVersion(string $appPath, string $minor): string
    {
        $composer = self::json($appPath . '/composer.json');
        $pin = $composer['config']['platform']['php'] ?? null;
        if (!is_string($pin) || $pin === '') {
            return $minor . '.99';
        }
        if ($pin === $minor) {
            return $minor . '.0';
        }
        if (str_starts_with($pin, $minor . '.')) {
            return $pin;
        }

        return ComposerEditor::firstRelease($minor);
    }

    /**
     * @return array<mixed>|null
     */
    private static function json(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $raw = file_get_contents($file);
        $data = $raw === false ? null : json_decode($raw, true);

        return is_array($data) ? $data : null;
    }
}
