<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

use Composer\Semver\VersionParser;
use Throwable;

/**
 * Builds the vendor/composer/installed.json that Rector reads to decide which
 * version-bound rules apply (rector-laravel binds many rules to
 * laravel/framework constraints). The app may have no vendor directory, or one
 * built for the version it is leaving, so Booster writes a throwaway one from
 * composer.lock (or the composer.json lower bounds) and pins
 * laravel/framework to the version the hop moves to.
 */
final class InstalledJsonBuilder
{
    /**
     * @return array{packages: list<array{name: string, version: string, version_normalized: string}>}
     */
    public function build(string $appPath, ?string $laravelMajor, ?string $phpunitMajor = null): array
    {
        $packages = $this->fromLock($appPath) ?? $this->fromComposerJson($appPath);

        if ($laravelMajor !== null) {
            $packages['laravel/framework'] = $laravelMajor . '.0.0.0';
        }
        if ($phpunitMajor !== null) {
            $packages['phpunit/phpunit'] = $phpunitMajor . '.0.0.0';
        }

        $out = [];
        foreach ($packages as $name => $normalized) {
            $out[] = ['name' => $name, 'version' => $normalized, 'version_normalized' => $normalized];
        }

        return ['packages' => $out];
    }

    /**
     * @return array<string, string>|null
     */
    private function fromLock(string $appPath): ?array
    {
        $lock = $this->json($appPath . '/composer.lock');
        if ($lock === null) {
            return null;
        }

        $out = [];
        foreach ([$lock['packages'] ?? [], $lock['packages-dev'] ?? []] as $list) {
            foreach (is_array($list) ? $list : [] as $package) {
                if (is_array($package) && is_string($package['name'] ?? null)) {
                    $version = is_string($package['version'] ?? null) ? $package['version'] : '';
                    $out[$package['name']] = $this->normalise($version);
                }
            }
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function fromComposerJson(string $appPath): array
    {
        $composer = $this->json($appPath . '/composer.json') ?? [];
        $out = [];
        foreach (['require', 'require-dev'] as $section) {
            $list = $composer[$section] ?? [];
            foreach (is_array($list) ? $list : [] as $name => $constraint) {
                if (!is_string($name) || !is_string($constraint) || $name === 'php' || str_starts_with($name, 'ext-')) {
                    continue;
                }
                try {
                    $bound = (new VersionParser())->parseConstraints($constraint)->getLowerBound();
                } catch (Throwable) {
                    continue;
                }
                if (!$bound->isZero()) {
                    $out[$name] = $bound->getVersion();
                }
            }
        }

        return $out;
    }

    private function normalise(string $version): string
    {
        try {
            return (new VersionParser())->normalize($version);
        } catch (Throwable) {
            return '0.0.0.0';
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function json(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);

        /** @var array<string, mixed>|null */
        return is_array($data) ? $data : null;
    }
}
