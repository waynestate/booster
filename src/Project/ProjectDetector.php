<?php

declare(strict_types=1);

namespace Waynestate\Booster\Project;

use Composer\Semver\Constraint\Bound;
use Composer\Semver\VersionParser;
use Throwable;
use Waynestate\Booster\ToolError;

/**
 * Reads composer.json and package.json and decides which of the five stack
 * permutations the app is. Apps without a composer.json are treated as raw PHP
 * with an unknown version (the caller must supply --from-php), because most
 * of the older apps on the platform have no Composer at all.
 */
final class ProjectDetector
{
    private const WAYNE_CAS_PACKAGE = 'cit-web-infra/wayne-cas';

    private const BOOST_PACKAGE = 'laravel/boost';

    private const HELMET_PACKAGE = 'waynestate/helmet';

    public function detect(string $path): Project
    {
        if (!is_dir($path)) {
            throw new ToolError(sprintf('Path "%s" is not a directory.', $path));
        }

        $composer = $this->readJson($path . '/composer.json');
        $package = $this->readJson($path . '/package.json');

        $require = $this->section($composer, 'require');
        $requireDev = $this->section($composer, 'require-dev');
        $all = $require + $requireDev;

        $laravelConstraint = $all['laravel/framework'] ?? null;
        $laravel = is_string($laravelConstraint) ? $this->laravelMajor($laravelConstraint) : null;

        $phpunitConstraint = $requireDev['phpunit/phpunit'] ?? null;
        $phpunit = is_string($phpunitConstraint) ? $this->lowerBound($phpunitConstraint, 1) : null;

        $deps = $this->section($package, 'dependencies') + $this->section($package, 'devDependencies');
        $vueConstraint = $deps['vue'] ?? null;
        $hasVue = array_key_exists('vue', $deps);
        $vue = is_string($vueConstraint) ? $this->lowerBound($vueConstraint, 1) : null;
        $vueMinor = is_string($vueConstraint) ? $this->lowerBound($vueConstraint, 2) : null;

        $engines = $this->section($package, 'engines');
        $nodeConstraint = $engines['node'] ?? null;
        $node = is_string($nodeConstraint) ? $this->lowerBound($nodeConstraint, 1) : null;

        $isLaravel = array_key_exists('laravel/framework', $all);
        $inertia = array_key_exists('inertiajs/inertia-laravel', $all)
            || array_key_exists('@inertiajs/vue3', $deps)
            || array_key_exists('@inertiajs/vue2', $deps);

        $type = match (true) {
            $isLaravel && $inertia => AppType::LaravelInertiaVue,
            $isLaravel && $hasVue => AppType::LaravelBladeVue,
            $isLaravel => AppType::LaravelBlade,
            $hasVue => AppType::RawPhpVue,
            default => AppType::RawPhp,
        };

        $phpConstraint = $require['php'] ?? null;
        $scripts = $this->section($composer, 'scripts');
        $manifestPhp = is_string($phpConstraint) ? $this->lowerBound($phpConstraint, 2) : null;
        $lock = $this->lockPhpRange($path . '/composer.lock', $composer);

        // The manifest may allow a PHP the lock cannot install on; the lock
        // wins, because that is the PHP the installed tree actually runs on.
        $phpVersion = $manifestPhp;
        if ($lock['floor'] !== null && $manifestPhp !== null && version_compare($manifestPhp, $lock['floor'], '<')) {
            $phpVersion = $lock['floor'];
        }

        return new Project(
            path: $path,
            hasComposer: $composer !== [],
            phpVersion: $phpVersion,
            laravelMajor: $laravel,
            phpunitMajor: $phpunit,
            vueMajor: $vue,
            type: $type,
            usesWayneCas: array_key_exists(self::WAYNE_CAS_PACKAGE, $all),
            hasTestScript: array_key_exists('test', $scripts),
            hasPackageJson: $package !== [],
            nodeMajor: $node,
            hasMixFile: is_file($path . '/webpack.mix.js'),
            vueMinor: $vueMinor,
            hasBoost: array_key_exists(self::BOOST_PACKAGE, $all) || $this->lockHasBoost($path . '/composer.lock'),
            hasHelmet: array_key_exists(self::HELMET_PACKAGE, $all),
            manifestPhp: $manifestPhp,
            lockPhpFloor: $lock['floor'],
            lockPhpCeiling: $lock['ceiling'],
            lockFloorPackage: $lock['floorPackage'],
            lockFloorConstraint: $lock['floorConstraint'],
            lockCeilingPackage: $lock['ceilingPackage'],
            lockCeilingConstraint: $lock['ceilingConstraint'],
        );
    }

    /**
     * The Laravel major a constraint floors at, keeping the minor for 5.x
     * ("^5.8" becomes "5.8") because BOOSTER plans the 5.8 to 6 hop, and whole
     * majors otherwise ("^10.0" becomes "10"). Null when the constraint cannot
     * be parsed.
     */
    private function laravelMajor(string $constraint): ?string
    {
        $bound = $this->lowerBound($constraint, 2);
        if ($bound === null) {
            return null;
        }
        $major = explode('.', $bound)[0];

        return $major === '5' ? $bound : $major;
    }

    /**
     * The lowest version a constraint allows, cut to $parts parts ("7.4.0" to
     * "7.4" for two). Null when the constraint cannot be parsed.
     */
    private function lowerBound(string $constraint, int $parts): ?string
    {
        try {
            $bound = (new VersionParser())->parseConstraints($constraint)->getLowerBound();
        } catch (Throwable) {
            return null;
        }
        if ($bound->isZero()) {
            return null;
        }

        $segments = explode('.', $bound->getVersion());

        return implode('.', array_slice($segments, 0, $parts));
    }

    /**
     * The PHP range composer.lock allows: the highest lower bound and the
     * lowest upper bound across every require.php in packages and
     * packages-dev, each parsed with Composer's semver parser. A
     * platform-overrides.php or config.platform.php pin in composer.json is
     * the PHP the lock was resolved for, so it raises the floor too. A
     * missing or broken lock, and a constraint that cannot be parsed, change
     * nothing: the manifest stays the primary source.
     *
     * @param array<string, mixed> $composer
     *
     * @return array{floor: ?string, floorPackage: ?string, floorConstraint: ?string, ceiling: ?string, ceilingPackage: ?string, ceilingConstraint: ?string}
     */
    private function lockPhpRange(string $lockFile, array $composer): array
    {
        $range = ['floor' => null, 'floorPackage' => null, 'floorConstraint' => null, 'ceiling' => null, 'ceilingPackage' => null, 'ceilingConstraint' => null];
        if (!is_file($lockFile)) {
            return $range;
        }
        $raw = file_get_contents($lockFile);
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data)) {
            return $range;
        }

        $parser = new VersionParser();
        foreach (['packages', 'packages-dev'] as $section) {
            $packages = $data[$section] ?? [];
            if (!is_array($packages)) {
                continue;
            }
            foreach ($packages as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $name = $entry['name'] ?? null;
                $require = $entry['require'] ?? null;
                $constraint = is_array($require) ? ($require['php'] ?? null) : null;
                if (!is_string($name) || !is_string($constraint)) {
                    continue;
                }
                try {
                    $bounds = $parser->parseConstraints($constraint);
                } catch (Throwable) {
                    continue;
                }
                $lower = $bounds->getLowerBound();
                if (!$lower->isZero()) {
                    $floor = $this->majorMinor($lower->getVersion());
                    if ($floor !== null && ($range['floor'] === null || version_compare($floor, $range['floor'], '>'))) {
                        $range['floor'] = $floor;
                        $range['floorPackage'] = $name;
                        $range['floorConstraint'] = $constraint;
                    }
                }
                $upper = $bounds->getUpperBound();
                if (!$upper->isPositiveInfinity()) {
                    $ceiling = $this->exclusiveCeiling($upper);
                    if ($ceiling !== null && ($range['ceiling'] === null || version_compare($ceiling, $range['ceiling'], '<'))) {
                        $range['ceiling'] = $ceiling;
                        $range['ceilingPackage'] = $name;
                        $range['ceilingConstraint'] = $constraint;
                    }
                }
            }
        }

        $pin = $this->platformPhp($composer);
        if ($pin !== null && ($range['floor'] === null || version_compare($pin, $range['floor'], '>'))) {
            $range['floor'] = $pin;
            $range['floorPackage'] = 'composer.json';
            $range['floorConstraint'] = $pin;
        }

        return $range;
    }

    /** "8.0.30" to "8.0"; null when the version has no major.minor shape. */
    private function majorMinor(string $version): ?string
    {
        if (preg_match('/^\d+\.\d+/', $version) !== 1) {
            return null;
        }
        $segments = explode('.', $version);

        return $segments[0] . '.' . $segments[1];
    }

    /**
     * The exclusive ceiling a bound sets, as major.minor: "<8.1" gives "8.1",
     * and an inclusive bound gets its minor bumped ("<=8.0" gives "8.1").
     */
    private function exclusiveCeiling(Bound $bound): ?string
    {
        $base = $this->majorMinor($bound->getVersion());
        if ($base === null) {
            return null;
        }
        if ($bound->isInclusive()) {
            $segments = explode('.', $base);
            $minor = ((int) $segments[1]) + 1;
            if ($minor > 9) {
                $segments[0] = (string) (((int) $segments[0]) + 1);
                $minor = 0;
            }
            $base = $segments[0] . '.' . $minor;
        }

        return $base;
    }

    /**
     * The PHP composer.json pins for resolution (platform-overrides.php, else
     * config.platform.php), as major.minor: the PHP the lock was resolved for.
     *
     * @param array<string, mixed> $composer
     */
    private function platformPhp(array $composer): ?string
    {
        $overrides = $composer['platform-overrides'] ?? null;
        $config = $composer['config'] ?? null;
        $platform = is_array($config) ? ($config['platform'] ?? null) : null;
        foreach ([
            is_array($overrides) ? ($overrides['php'] ?? null) : null,
            is_array($platform) ? ($platform['php'] ?? null) : null,
        ] as $pin) {
            if (is_string($pin) && preg_match('/^\d+\.\d+/', $pin) === 1) {
                return $this->majorMinor($pin);
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $raw = file_get_contents($file);
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data)) {
            throw new ToolError(sprintf('"%s" is not valid JSON: %s', $file, json_last_error_msg()));
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * Whether composer.lock still carries laravel/boost in packages or
     * packages-dev. A missing or broken lock counts as no: the manifest is the
     * primary source, and an old app with a stale lock must not break detect.
     */
    private function lockHasBoost(string $file): bool
    {
        if (!is_file($file)) {
            return false;
        }
        $raw = file_get_contents($file);
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data)) {
            return false;
        }

        foreach (['packages', 'packages-dev'] as $section) {
            $packages = $data[$section] ?? [];
            if (!is_array($packages)) {
                continue;
            }
            foreach ($packages as $entry) {
                if (is_array($entry) && ($entry['name'] ?? null) === self::BOOST_PACKAGE) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function section(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        /** @var array<string, mixed> */
        return is_array($value) ? $value : [];
    }
}
