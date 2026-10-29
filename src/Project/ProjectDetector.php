<?php

declare(strict_types=1);

namespace Waynestate\Booster\Project;

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
        $laravel = is_string($laravelConstraint) ? $this->lowerBound($laravelConstraint, 1) : null;

        $phpunitConstraint = $requireDev['phpunit/phpunit'] ?? null;
        $phpunit = is_string($phpunitConstraint) ? $this->lowerBound($phpunitConstraint, 1) : null;

        $deps = $this->section($package, 'dependencies') + $this->section($package, 'devDependencies');
        $vueConstraint = $deps['vue'] ?? null;
        $hasVue = array_key_exists('vue', $deps);
        $vue = is_string($vueConstraint) ? $this->lowerBound($vueConstraint, 1) : null;

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

        return new Project(
            path: $path,
            hasComposer: $composer !== [],
            phpVersion: is_string($phpConstraint) ? $this->lowerBound($phpConstraint, 2) : null,
            laravelMajor: $laravel,
            phpunitMajor: $phpunit,
            vueMajor: $vue,
            type: $type,
            usesWayneCas: array_key_exists(self::WAYNE_CAS_PACKAGE, $all),
            hasTestScript: array_key_exists('test', $scripts),
            hasPackageJson: $package !== [],
            nodeMajor: $node,
        );
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
