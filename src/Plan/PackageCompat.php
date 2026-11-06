<?php

declare(strict_types=1);

namespace Waynestate\Booster\Plan;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Project\Project;

/**
 * Asks Packagist what the app's direct dependencies allow, and reports the
 * first planned hop each one blocks. This is advisory: a blocked hop stays in
 * the plan, with a warning that names the package to replace or upgrade. A
 * package whose newest release is out of reach before the first hop runs (a
 * floor above the plan's starting version, or a ceiling below it) gets its own
 * finding instead: no hop is to blame for where the plan starts.
 *
 * Only the newest stable release of each package counts. The dev aliases
 * Packagist lists first (the v9.99.x entries) say nothing about what a
 * composer install would give a person today.
 */
final class PackageCompat
{
    private const PACKAGIST = 'https://repo.packagist.org/p2/';

    /** A version far beyond anything Booster plans, used to test for an open-ended constraint. */
    private const OPEN_ENDED_PROBE = '99.99.99';

    public function __construct(
        private readonly PackageFetcher $fetcher,
        private readonly HopRegistry $registry,
    ) {}

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
            $checks[] = $this->checkOne($name, $composer, $steps, $project);
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
     * minus the ones Booster plans itself (PHP, extensions, Laravel, its
     * illuminate components and PHPUnit) and the scan tools Booster ships
     * (Pinstripe, PHPCompatibility, PHP_CodeSniffer, PHPStan and its rules,
     * and the CodeSniffer installer plugin).
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
            || $name === 'phpunit/phpunit'
            || $name === 'waynestate/pinstripe'
            || $name === 'phpcompatibility/php-compatibility'
            || $name === 'squizlabs/php_codesniffer'
            || $name === 'phpstan/phpstan'
            || $name === 'phpstan/phpstan-strict-rules'
            || $name === 'phpstan/phpstan-deprecation-rules'
            || $name === 'dealerdirect/phpcodesniffer-composer-installer';
    }

    /**
     * @param array<string, mixed> $composer
     * @param list<PlanStep>       $steps
     */
    private function checkOne(string $name, array $composer, array $steps, Project $project): PackageCheck
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

        // Where the plan starts: the first step carries the running versions,
        // and an empty plan sits where the detector found the app.
        $startPhp = $steps !== [] ? $steps[0]->phpBefore : $project->phpVersion;
        $startLaravel = $steps !== [] ? $steps[0]->laravelBefore : $project->laravelMajor;

        $blocks = null;
        $failed = [];
        $blockingStep = null;
        foreach ($steps as $step) {
            $failed = $this->stepFailures($step, $phpConstraint, $laravelConstraint);
            if ($failed !== []) {
                $blocks = $step->hop->id;
                $blockingStep = $step;
                break;
            }
        }

        // Dimensions the newest release is out of reach on before any hop
        // runs: a floor above the starting version, or a ceiling below it. No
        // hop is to blame for those, because no hop put the app there.
        $outOfReach = [];
        if ($startPhp !== null && $phpConstraint !== null && !$this->intersects($startPhp . '.*', $phpConstraint)) {
            $outOfReach[] = 'php';
        }
        if ($startLaravel !== null && $laravelConstraint !== null
            && !$this->intersects(sprintf('^%s.0', $startLaravel), $laravelConstraint)) {
            $outOfReach[] = 'laravel';
        }

        $blockWarning = null;
        $startWarning = null;
        if ($blockingStep !== null) {
            $phraseFailed = [];
            foreach (['laravel', 'php'] as $dimension) {
                if (in_array($dimension, $failed, true) || in_array($dimension, $outOfReach, true)) {
                    $phraseFailed[] = $dimension;
                }
            }
            $blockWarning = sprintf(
                '%s %s %s, so it blocks %s. Replace or upgrade it first.',
                $name,
                $newest,
                $this->supportPhrase($phraseFailed, $phpConstraint, $laravelConstraint, $blockingStep->phpAfter),
                $blocks,
            );
        } else {
            $findings = [];
            foreach (['laravel', 'php'] as $dimension) {
                if (in_array($dimension, $outOfReach, true)) {
                    $findings[] = $this->startFinding($dimension, $name, $newest, $phpConstraint, $laravelConstraint, $startPhp, $startLaravel, $steps);
                }
            }
            $startWarning = $findings === [] ? null : implode(' ', $findings);
        }
        $abandonedWarning = null;
        if ($isAbandoned) {
            $abandonedWarning = $replacement !== null
                ? sprintf('%s is abandoned (use %s instead).', $name, $replacement)
                : sprintf('%s is abandoned with no replacement.', $name);
        }

        return new PackageCheck($name, $newest, $blocks, $isAbandoned, $replacement, true, null, $blockWarning, $abandonedWarning, $startWarning);
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
     * Which dimensions the step's destination violates, among the dimensions
     * the step actually moves: 'laravel' when the package's newest release
     * cannot be installed at the step's Laravel target, 'php' when it cannot
     * be installed at the step's PHP. A hop that leaves a dimension untouched
     * cannot fail on it: a Node pin moves nothing in PHP, so a PHP floor does
     * not block it. Empty when the step passes. The destination major or
     * minor is probed as a whole (any X.y.z of it), so a constraint that
     * allows any version of the destination passes, even when it floors above
     * the range's start: "^10.1" still allows plenty of Laravel 10, and
     * ">=8.2.5" still allows plenty of PHP 8.2.
     *
     * @return list<'laravel'|'php'>
     */
    private function stepFailures(PlanStep $step, ?string $phpConstraint, ?string $laravelConstraint): array
    {
        $failed = [];
        if ($this->stepMoves($step, 'laravel') && $laravelConstraint !== null
            && !$this->intersects(sprintf('^%s.0', $step->laravelAfter), $laravelConstraint)) {
            $failed[] = 'laravel';
        }
        if ($this->stepMoves($step, 'php') && $phpConstraint !== null
            && !$this->intersects($step->phpAfter . '.*', $phpConstraint)) {
            $failed[] = 'php';
        }

        return $failed;
    }

    /** Whether the step moves the given dimension at all. */
    private function stepMoves(PlanStep $step, string $dimension): bool
    {
        if ($dimension === 'php') {
            return $step->phpBefore !== null && $step->phpBefore !== $step->phpAfter;
        }

        return $step->laravelBefore !== null && $step->laravelBefore !== $step->laravelAfter;
    }

    /**
     * The finding for a dimension the package's newest release is out of
     * reach on at the plan's starting point, reported on its own because no
     * hop is to blame for where the plan starts. A floor above the start
     * names the version the plan reaches, when it ever does; a ceiling below
     * the start names the ceiling and the version the app already sits on.
     *
     * @param list<PlanStep> $steps
     */
    private function startFinding(
        string $dimension,
        string $name,
        string $newest,
        ?string $phpConstraint,
        ?string $laravelConstraint,
        ?string $startPhp,
        ?string $startLaravel,
        array $steps,
    ): string {
        $component = $dimension === 'php' ? 'PHP' : 'Laravel';
        $constraint = $dimension === 'php' ? (string) $phpConstraint : (string) $laravelConstraint;
        $start = $dimension === 'php' ? (string) $startPhp : (string) $startLaravel;
        $allows = $dimension === 'php'
            ? fn(string $version): bool => $this->intersects($version . '.*', $constraint)
            : fn(string $version): bool => $this->intersects(sprintf('^%s.0', $version), $constraint);

        if ($this->targetBelowFloor($start, $constraint)) {
            $reached = null;
            foreach ($steps as $step) {
                if (!$this->stepMoves($step, $dimension)) {
                    continue;
                }
                $after = $dimension === 'php' ? $step->phpAfter : $step->laravelAfter;
                if ($after !== null && $allows($after)) {
                    $reached = $after;
                    break;
                }
            }
            if ($reached !== null) {
                return sprintf(
                    '%s %s needs %s %s or newer, but the plan starts from %s %s; it installs once the plan reaches %s %s.',
                    $name,
                    $newest,
                    $component,
                    $this->floorOf($constraint),
                    $component,
                    $start,
                    $component,
                    $reached,
                );
            }
            $last = $steps !== [] ? $steps[count($steps) - 1] : null;
            $end = $last === null ? null : ($dimension === 'php' ? $last->phpAfter : $last->laravelAfter);

            return sprintf(
                '%s %s needs %s %s or newer, but the plan ends at %s %s.',
                $name,
                $newest,
                $component,
                $this->floorOf($constraint),
                $component,
                $end ?? $start,
            );
        }

        $cap = $dimension === 'php' ? $this->phpCap($constraint) : $this->laravelCap($constraint);
        $phrase = $cap !== null
            ? sprintf('supports %s up to %s', $component, $cap)
            : sprintf('supports no %s version Booster plans', $component);

        return sprintf('%s %s %s, but the app already sits on %s %s.', $name, $newest, $phrase, $component, $start);
    }

    /**
     * Whether any version exists that satisfies both constraints. An
     * unparseable constraint cannot prove a block, so it lets the step through.
     */
    private function intersects(string $range, string $constraint): bool
    {
        try {
            $parser = new VersionParser();

            return $parser->parseConstraints($constraint)->matches($parser->parseConstraints($range));
        } catch (\Throwable) {
            return true;
        }
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
     * allows the most of the majors Booster plans.
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
     * The Laravel versions the caps probe against: every major Booster plans,
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

    /**
     * What the package's newest release supports, naming the dimensions it
     * cannot be installed on after the blocking step: the hop's own failures
     * plus anything it already missed at the plan's starting point. A cap is
     * printed only when the constraint really has one and the hop's target is
     * not below the floor; a constraint with no upper bound, or a PHP floor
     * above the target, names its floor instead.
     *
     * @param list<'laravel'|'php'> $failed
     */
    private function supportPhrase(array $failed, ?string $phpConstraint, ?string $laravelConstraint, ?string $phpTarget = null): string
    {
        $parts = [];
        foreach ($failed as $dimension) {
            if ($dimension === 'laravel' && $laravelConstraint !== null) {
                $parts[] = $this->dimensionPhrase('Laravel', $laravelConstraint, $this->laravelCap($laravelConstraint));
            } elseif ($dimension === 'php' && $phpConstraint !== null) {
                $parts[] = $this->dimensionPhrase('PHP', $phpConstraint, $this->phpCap($phpConstraint), $phpTarget);
            }
        }
        if (count($parts) === 1) {
            return $parts[0];
        }
        if (str_starts_with($parts[0], 'supports') && str_starts_with($parts[1], 'supports')) {
            return 'supports ' . substr($parts[0], strlen('supports ')) . ' and ' . substr($parts[1], strlen('supports '));
        }

        return $parts[0] . '; ' . $parts[1];
    }

    /**
     * One dimension of the phrase. When the hop's target sits below the
     * constraint's floor, the floor is named ("needs X F or newer") because
     * that is the real reason the step fails. Otherwise a constraint with a
     * real upper bound names the newest version Booster plans that it allows
     * ("supports X up to N"); one without names its floor.
     */
    private function dimensionPhrase(string $component, string $constraint, ?string $cap, ?string $target = null): string
    {
        if ($component === 'PHP' && $target !== null && $this->targetBelowFloor($target, $constraint)) {
            return sprintf('needs PHP %s or newer', $this->floorOf($constraint));
        }
        if ($this->hasUpperBound($constraint)) {
            return $cap !== null
                ? sprintf('supports %s up to %s', $component, $cap)
                : sprintf('supports no %s version Booster plans', $component);
        }

        return sprintf('needs %s %s or newer', $component, $this->floorOf($constraint));
    }

    /** Whether the hop's target PHP is strictly below the constraint's floor. */
    private function targetBelowFloor(string $target, string $constraint): bool
    {
        try {
            return Semver::satisfies($target, '<' . $this->floorOf($constraint));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Whether the constraint has a real upper bound. One that still allows a
     * version far beyond anything Booster plans is open-ended, so the phrase
     * names its floor instead of a cap. (The parsed branch bounds cannot be
     * trusted for this: composer/semver merges caret unions into branches
     * whose individual bounds do not reflect the union's.)
     */
    private function hasUpperBound(string $constraint): bool
    {
        return !$this->satisfies(self::OPEN_ENDED_PROBE, $constraint);
    }

    /** The constraint's lower bound, trimmed for display: "8.2.5.0-dev" becomes "8.2.5". */
    private function floorOf(string $constraint): string
    {
        $lower = (new VersionParser())->parseConstraints($constraint)->getLowerBound()->getVersion();
        $lower = (string) preg_replace('/-dev$/', '', $lower);

        return (string) preg_replace('/(\.0)+$/', '', $lower);
    }

    /**
     * The newest Laravel version Booster plans that the constraint allows, or
     * null when it allows none. Probed the same way steps are judged: any
     * X.y.z of the destination major.
     */
    private function laravelCap(string $constraint): ?string
    {
        foreach (array_reverse($this->registry->laravelChain()) as $version) {
            if ($this->intersects(sprintf('^%d.0', (int) $version), $constraint)) {
                return $version;
            }
        }

        return null;
    }

    /**
     * The newest PHP version Booster knows that the constraint allows, or
     * null when it allows none: the highest minor allowed, as a real version
     * (a constraint capping at 7.x reads "7.4", not "7").
     */
    private function phpCap(string $constraint): ?string
    {
        foreach (array_reverse($this->registry->phpChain()) as $version) {
            if ($this->satisfies($version, $constraint)) {
                return $version;
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
