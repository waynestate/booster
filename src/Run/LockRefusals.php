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
 *
 * A few releases allow a PHP they do not work on; those are listed in BROKEN
 * and updated the same way. Found on testeval (2026-11-20): Twig 2.5.0 says
 * php ^7.0, but on PHP 7.4 its lexer trims the whitespace before every
 * `{{`, so the 7.4 hop's replay caught "Slimapp - Login" turning into
 * "Slimapp -Login" on every page.
 */
final class LockRefusals
{
    /**
     * Releases that allow a PHP they misbehave on: package, the first PHP
     * they break on, the fixed release per major, and what goes wrong.
     *
     * @var array<string, array{string, array<string, string>, string}>
     */
    private const BROKEN = [
        'twig/twig' => ['7.4', ['1' => '1.39.0', '2' => '2.8.0'], 'its lexer trims the whitespace before every {{ on PHP 7.4 and later'],
    ];

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
     * A release listed in BROKEN for $minor is included too, with a reason,
     * unless $broken is false (a failed install names only what refused it).
     *
     * @return array<string, array{version: string, constraint: string, reason?: string}>
     */
    public static function find(string $appPath, string $minor, bool $broken = true): array
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
                $misbehaves = $broken ? self::broken($package, $version) : null;
                if ($misbehaves !== null) {
                    $refusing[$package['name']] = $misbehaves;

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
     * @param array<string, array{version: string, constraint: string, reason?: string}> $refusing
     */
    public static function describe(array $refusing): string
    {
        $parts = [];
        foreach ($refusing as $name => $package) {
            $parts[] = isset($package['reason'])
                ? sprintf('%s %s: %s', $name, $package['version'], $package['reason'])
                : sprintf('%s %s needs php %s', $name, $package['version'], $package['constraint']);
        }

        return implode(', ', $parts);
    }

    /**
     * The entry for a locked release BROKEN lists on $phpVersion, else null.
     *
     * @param array<mixed> $package
     *
     * @return array{version: string, constraint: string, reason: string}|null
     */
    private static function broken(array $package, string $phpVersion): ?array
    {
        $name = $package['name'] ?? null;
        $version = $package['version'] ?? null;
        if (!is_string($name) || !is_string($version) || !isset(self::BROKEN[$name])) {
            return null;
        }
        [$from, $fixed, $what] = self::BROKEN[$name];
        $bare = ltrim($version, 'vV');
        $major = explode('.', $bare)[0];
        if (!isset($fixed[$major]) || preg_match('/^\d+(\.\d+)*$/', $bare) !== 1 || version_compare($phpVersion, $from, '<')) {
            return null;
        }
        if (version_compare($bare, $fixed[$major], '>=')) {
            return null;
        }

        return [
            'version' => $version,
            'constraint' => '>=' . $fixed[$major],
            'reason' => sprintf('%s (fixed in %s)', $what, $fixed[$major]),
        ];
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
