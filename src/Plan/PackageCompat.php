<?php

declare(strict_types=1);

namespace Waynestate\Booster\Plan;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Waynestate\Booster\Project\Project;

/**
 * Asks Packagist what the app's direct dependencies allow, and reports the
 * first planned hop each one blocks. This is advisory: a blocked hop stays in
 * the plan, with a warning that names the package to replace or upgrade.
 *
 * Only the newest stable release of each package counts. The dev aliases
 * Packagist lists first (the v9.99.x entries) say nothing about what a
 * composer install would give a person today.
 */
final class PackageCompat
{
    private const PACKAGIST = 'https://repo.packagist.org/p2/';

    public function __construct(private readonly PackageFetcher $fetcher) {}

    /**
     * @param list<PlanStep> $steps the plan after skips, in run order
     */
    public function check(Project $project, array $steps): PackageReport
    {
        $composer = $this->readComposer($project->path . '/composer.json');
        if ($composer === null) {
            return new PackageReport([]);
        }

        $checks = [];
        foreach ($this->directDependencies($composer) as $name) {
            $checks[] = $this->checkOne($name, $composer, $steps);
        }

        return new PackageReport($checks);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readComposer(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    /**
     * The packages the app requires directly, require before require-dev,
     * minus the ones BOOSTER plans itself: PHP, extensions, Laravel, its
     * illuminate components and PHPUnit.
     *
     * @param array<string, mixed> $composer
     *
     * @return list<string>
     */
    private function directDependencies(array $composer): array
    {
        $names = [];
        foreach (['require', 'require-dev'] as $section) {
            foreach ((array) ($composer[$section] ?? []) as $name => $constraint) {
                if (!is_string($constraint)) {
                    continue;
                }
                $name = (string) $name;
                if ($this->isSkipped($name) || in_array($name, $names, true)) {
                    continue;
                }
                $names[] = $name;
            }
        }

        return $names;
    }

    private function isSkipped(string $name): bool
    {
        return $name === 'php'
            || str_starts_with($name, 'ext-')
            || str_starts_with($name, 'lib-')
            || $name === 'laravel/framework'
            || str_starts_with($name, 'illuminate/')
            || $name === 'phpunit/phpunit';
    }

    /**
     * @param array<string, mixed> $composer
     * @param list<PlanStep>       $steps
     */
    private function checkOne(string $name, array $composer, array $steps): PackageCheck
    {
        $document = $this->fetchDocument($name, $composer);
        if ($document === null) {
            return new PackageCheck($name, null, null, false, null, false, $this->failureReason($composer), null, null);
        }

        $versions = $this->expandPackages($document)[$name] ?? [];
        if ($versions === []) {
            return new PackageCheck($name, null, null, false, null, false, 'offline', null, null);
        }

        // Start from the newest listed version and keep it unless an earlier
        // entry (Packagist lists newest first) is a stable release.
        $newest = (string) array_key_first($versions);
        $metadata = $versions[$newest];
        foreach ($versions as $version => $entry) {
            if (VersionParser::parseStability($version) === 'stable') {
                $newest = $version;
                $metadata = $entry;
                break;
            }
        }

        $require = (array) ($metadata['require'] ?? []);
        $phpConstraint = $this->stringOrNull($require, 'php');
        $laravelConstraint = $this->stringOrNull($require, 'laravel/framework');
        if ($laravelConstraint === null) {
            $laravelConstraint = $this->loosestIlluminate($require);
        }

        $abandoned = $metadata['abandoned'] ?? null;
        $isAbandoned = $abandoned === true || (is_string($abandoned) && $abandoned !== '');
        $replacement = is_string($abandoned) && $abandoned !== '' ? $abandoned : null;

        $blocks = null;
        foreach ($steps as $step) {
            if ($this->stepBlocks($step, $phpConstraint, $laravelConstraint)) {
                $blocks = $step->hop->id;
                break;
            }
        }

        $blockWarning = null;
        if ($blocks !== null) {
            $blockWarning = sprintf(
                '%s %s supports %s, so it blocks %s. Replace or upgrade it first.',
                $name,
                $newest,
                $this->supportPhrase($phpConstraint, $laravelConstraint),
                $blocks,
            );
        }
        $abandonedWarning = null;
        if ($isAbandoned) {
            $abandonedWarning = $replacement !== null
                ? sprintf('%s is abandoned (use %s instead).', $name, $replacement)
                : sprintf('%s is abandoned with no replacement.', $name);
        }

        return new PackageCheck($name, $newest, $blocks, $isAbandoned, $replacement, true, null, $blockWarning, $abandonedWarning);
    }

    /**
     * The p2 document for one package: Packagist first, then any composer-type
     * repository the app declared. Null when no source answers.
     *
     * @param array<string, mixed> $composer
     *
     * @return array<string, mixed>|null
     */
    private function fetchDocument(string $name, array $composer): ?array
    {
        $result = $this->fetcher->fetch(self::PACKAGIST . $name . '.json');
        if ($result->status === PackageFetchResult::OK) {
            return $result->data;
        }
        foreach ($this->composerRepositoryUrls($composer) as $url) {
            $result = $this->fetcher->fetch(rtrim($url, '/') . '/p2/' . $name . '.json');
            if ($result->status === PackageFetchResult::OK) {
                return $result->data;
            }
        }

        return null;
    }

    /**
     * The base URLs of composer-type repositories in the app's composer.json.
     * VCS repositories are not fetched here; they only change the label of a
     * failure.
     *
     * @param array<string, mixed> $composer
     *
     * @return list<string>
     */
    private function composerRepositoryUrls(array $composer): array
    {
        $urls = [];
        foreach ((array) ($composer['repositories'] ?? []) as $repository) {
            if (!is_array($repository)) {
                continue;
            }
            $type = $repository['type'] ?? null;
            $url = $repository['url'] ?? null;
            if (($type === null || $type === 'composer') && is_string($url) && $url !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * Why an unreachable package was not checked: the app declares a VCS
     * repository (the package may live there), or the network did not answer.
     *
     * @param array<string, mixed> $composer
     */
    private function failureReason(array $composer): string
    {
        foreach ((array) ($composer['repositories'] ?? []) as $repository) {
            if (is_array($repository) && in_array($repository['type'] ?? null, ['vcs', 'git', 'svn', 'hg'], true)) {
                return 'vcs';
            }
        }

        return 'offline';
    }

    /**
     * Un-minifies a composer/2.0 document the way Composer does. Packagist's
     * p2 format lists each package's versions as a list, newest first, and
     * with "minified": "composer/2.0" every entry after the first carries only
     * the keys that changed from the previous one, "__unset" removing a key.
     * The result maps version string to its full entry, newest first.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function expandPackages(array $document): array
    {
        $expanded = [];
        foreach ((array) ($document['packages'] ?? []) as $name => $entries) {
            $versions = [];
            foreach ($this->expandMinified(array_values((array) $entries)) as $entry) {
                if (!is_string($entry['version'] ?? null)) {
                    continue;
                }
                $versions[$entry['version']] = $entry;
            }
            $expanded[(string) $name] = $versions;
        }

        return $expanded;
    }

    /**
     * The metadata-minifier expansion: the first entry is complete, each later
     * entry only carries the keys that changed, and "__unset" removes a key.
     * Uses composer/metadata-minifier when it happens to be installed, and the
     * same algorithm here otherwise, so Booster needs no dependency on it.
     *
     * @param list<mixed> $entries
     *
     * @return list<array<string, mixed>>
     */
    private function expandMinified(array $entries): array
    {
        if (class_exists(\Composer\MetadataMinifier\MetadataMinifier::class)) {
            return array_values(array_filter(
                \Composer\MetadataMinifier\MetadataMinifier::expand($entries),
                'is_array',
            ));
        }

        $expanded = [];
        $expandedVersion = null;
        foreach ($entries as $versionData) {
            if (!is_array($versionData)) {
                continue;
            }
            if (!$expandedVersion) {
                $expandedVersion = $versionData;
                $expanded[] = $expandedVersion;
                continue;
            }
            foreach ($versionData as $key => $val) {
                if ($val === '__unset') {
                    unset($expandedVersion[$key]);
                } else {
                    $expandedVersion[$key] = $val;
                }
            }
            $expanded[] = $expandedVersion;
        }

        return $expanded;
    }

    /**
     * A step blocks when the package's newest release cannot be installed at
     * the step's destination: the Laravel major probed at its floor, the PHP
     * minor probed at its ceiling.
     */
    private function stepBlocks(PlanStep $step, ?string $phpConstraint, ?string $laravelConstraint): bool
    {
        if ($step->laravelAfter !== null && $laravelConstraint !== null
            && !$this->satisfies(sprintf('%s.0.0', $step->laravelAfter), $laravelConstraint)) {
            return true;
        }

        return $phpConstraint !== null && !$this->satisfies($step->phpAfter . '.99', $phpConstraint);
    }

    /** An unparseable constraint cannot prove a block, so it lets the step through. */
    private function satisfies(string $version, string $constraint): bool
    {
        try {
            return Semver::satisfies($version, $constraint);
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Packages that require illuminate/* components rather than
     * laravel/framework give us several constraints at once. The loosest one
     * is the fairest guess at the Laravel ceiling: it is the constraint that
     * allows the most of the majors BOOSTER plans.
     *
     * @param array<string, mixed> $require
     */
    private function loosestIlluminate(array $require): ?string
    {
        $constraints = [];
        foreach ($require as $name => $constraint) {
            if (str_starts_with((string) $name, 'illuminate/') && is_string($constraint)) {
                $constraints[] = $constraint;
            }
        }
        if ($constraints === []) {
            return null;
        }

        $best = $constraints[0];
        $bestScore = -1;
        foreach ($constraints as $constraint) {
            $score = 0;
            foreach ($this->laravelProbes() as $probe) {
                if ($this->satisfies($probe, $constraint)) {
                    $score++;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $constraint;
            }
        }

        return $best;
    }

    /**
     * The Laravel versions the caps probe against: every major BOOSTER plans,
     * at its floor and its ceiling.
     *
     * @return list<string>
     */
    private function laravelProbes(): array
    {
        $probes = [];
        for ($major = 5; $major <= 15; $major++) {
            $probes[] = $major . '.0.0';
            $probes[] = $major . '.99.99';
        }

        return $probes;
    }

    private function supportPhrase(?string $phpConstraint, ?string $laravelConstraint): string
    {
        $parts = [];
        if ($laravelConstraint !== null) {
            $cap = $this->laravelCap($laravelConstraint);
            $parts[] = $cap !== null ? sprintf('Laravel up to %d', $cap) : 'no Laravel version BOOSTER plans';
        }
        if ($phpConstraint !== null) {
            $cap = $this->phpCap($phpConstraint);
            $parts[] = $cap !== null ? sprintf('PHP up to %s', $cap) : 'no PHP version BOOSTER plans';
        }
        if ($parts === []) {
            return 'every Laravel and PHP version BOOSTER plans';
        }

        return count($parts) === 1 ? $parts[0] : $parts[0] . ' (or ' . $parts[1] . ')';
    }

    private function laravelCap(string $constraint): ?int
    {
        for ($major = 15; $major >= 5; $major--) {
            if ($this->satisfies($major . '.99.99', $constraint)) {
                return $major;
            }
        }

        return null;
    }

    private function phpCap(string $constraint): ?string
    {
        foreach ([9, 8, 7] as $major) {
            for ($minor = 99; $minor >= 0; $minor--) {
                if ($this->satisfies(sprintf('%d.%d.99', $major, $minor), $constraint)) {
                    return $minor === 99 ? (string) $major : sprintf('%d.%d', $major, $minor);
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $require
     */
    private function stringOrNull(array $require, string $key): ?string
    {
        $value = $require[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
