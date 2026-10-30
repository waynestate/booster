<?php

declare(strict_types=1);

namespace Waynestate\Booster\Refresh;

use Composer\Semver\VersionParser;
use DateTimeImmutable;
use UnexpectedValueException;
use Waynestate\Booster\Policy\Policy;
use Waynestate\Booster\ToolError;
use Waynestate\Creed\State;

/**
 * Recomputes data/versions.json from the public feeds and CREED's version
 * policy. Chains grow by union with what the feeds still list, so a new
 * major shows up on the next refresh; the recipes that teach each hop stay
 * human work in HopRegistry.
 */
final class HopDataRefresher
{
    /** Packagist p2 feed for phpunit/phpunit. */
    public const FEED_PHPUNIT = 'packagist-phpunit-phpunit';

    /** Packagist p2 feed for laravel/framework. */
    public const FEED_LARAVEL_FRAMEWORK = 'packagist-laravel-framework';

    /** Packagist p2 feed for the laravel/laravel skeleton. */
    public const FEED_LARAVEL_SKELETON = 'packagist-laravel-laravel';

    /** endoflife.date API answer for Laravel. */
    public const FEED_ENDOFLIFE = 'endoflife-laravel';

    /** npm registry document for vite (only its dist-tags are used). */
    public const FEED_VITE = 'npm-vite';

    /** npm registry document for laravel-vite-plugin (dist-tags only). */
    public const FEED_LARAVEL_VITE_PLUGIN = 'npm-laravel-vite-plugin';

    /** npm registry document for @vitejs/plugin-vue (dist-tags only). */
    public const FEED_VUE_PLUGIN = 'npm-vitejs-plugin-vue';

    /** @var list<string> every feed refresh() needs, by name */
    public const FEEDS = [
        self::FEED_PHPUNIT,
        self::FEED_LARAVEL_FRAMEWORK,
        self::FEED_LARAVEL_SKELETON,
        self::FEED_ENDOFLIFE,
        self::FEED_VITE,
        self::FEED_LARAVEL_VITE_PLUGIN,
        self::FEED_VUE_PLUGIN,
    ];

    /** The oldest PHP BOOSTER plans upgrades from, kept ahead of the policy. */
    public const PHP_FLOOR_PREFIX = ['5.5'];

    /** The oldest Laravel majors BOOSTER plans upgrades from, kept ahead of the policy. */
    public const LARAVEL_FLOOR_PREFIX = ['5.8', '6', '7'];

    /** Fixed Tailwind pin BOOSTER uses for the @tailwindcss/upgrade tool; no feed drives it. */
    public const FIXED_TAILWIND_UPGRADE_VERSION = '^4.1';

    /** Fixed Tailwind pin BOOSTER writes into the app's package.json; no feed drives it. */
    public const FIXED_TAILWIND_VERSION = '^4.1';

    /** Inertia composer constraint per Laravel major; null means BOOSTER pins nothing there. */
    public const FIXED_INERTIA_LARAVEL = [
        '5.8' => null,
        '6' => null,
        '7' => null,
        '8' => null,
        '9' => null,
        '10' => null,
        '11' => '^2.0',
        '12' => '^2.0',
        '13' => '^2.0',
    ];

    /** Inertia npm constraint per Laravel major; null means BOOSTER pins nothing there. */
    public const FIXED_INERTIA_VUE3 = [
        '5.8' => null,
        '6' => null,
        '7' => null,
        '8' => null,
        '9' => null,
        '10' => null,
        '11' => '^2.0',
        '12' => '^2.0',
        '13' => '^2.0',
    ];

    public function __construct(private readonly Policy $policy) {}

    /**
     * The refreshed hop data, in the file's key order.
     *
     * @param array<string, mixed> $existing the current data/versions.json
     * @param array<string, mixed> $feeds decoded feed payloads, keyed by the FEED_* names
     *
     * @return array<string, mixed>
     *
     * @throws ToolError when a feed is missing or malformed
     */
    public function refresh(array $existing, array $feeds, DateTimeImmutable $today): array
    {
        foreach (self::FEEDS as $name) {
            if (!isset($feeds[$name]) || !is_array($feeds[$name])) {
                throw new ToolError(sprintf('Feed "%s" is missing from the refresh input.', $name));
            }
        }

        $phpChain = $this->extendChain($existing, 'php', array_merge(self::PHP_FLOOR_PREFIX, $this->policy->supported('php', $today)));
        $laravelChain = $this->extendChain($existing, 'laravel', array_merge(self::LARAVEL_FLOOR_PREFIX, $this->policy->supported('laravel', $today)));
        $phpunitChain = $this->extendChain(
            $existing,
            'phpunit',
            self::majors($feeds[self::FEED_PHPUNIT]),
            self::chainFloor($existing, 'phpunit'),
        );

        return [
            'generated_on' => $today->format('Y-m-d'),
            'php' => $phpChain,
            'laravel' => $laravelChain,
            'phpunit' => $phpunitChain,
            'node_pin_target' => $this->nodePinTarget($existing, $today),
            'vite_version' => $this->distTagCaret($feeds[self::FEED_VITE], $existing, 'vite_version'),
            'laravel_vite_plugin_version' => $this->distTagCaret($feeds[self::FEED_LARAVEL_VITE_PLUGIN], $existing, 'laravel_vite_plugin_version'),
            'vue_plugin_version' => $this->distTagCaret($feeds[self::FEED_VUE_PLUGIN], $existing, 'vue_plugin_version'),
            'tailwind_upgrade_version' => $this->fixedScalar($existing, 'tailwind_upgrade_version', self::FIXED_TAILWIND_UPGRADE_VERSION),
            'tailwind_version' => $this->fixedScalar($existing, 'tailwind_version', self::FIXED_TAILWIND_VERSION),
            'vue_27' => $this->keptValue($existing, 'vue_27'),
            'vue_compat' => $this->keptValue($existing, 'vue_compat'),
            'vue_loader_compat' => $this->keptValue($existing, 'vue_loader_compat'),
            'phpunit_php' => $this->phpunitPhp($existing, $phpunitChain, $feeds[self::FEED_PHPUNIT]),
            'laravel_phpunit' => $this->laravelPhpunit($existing, $laravelChain, $feeds[self::FEED_LARAVEL_SKELETON]),
            'laravel_php' => $this->laravelPhp($existing, $laravelChain, $feeds[self::FEED_LARAVEL_FRAMEWORK], $feeds[self::FEED_ENDOFLIFE]),
            'inertia_laravel' => $this->fixedTable($existing, 'inertia_laravel', $laravelChain, self::FIXED_INERTIA_LARAVEL),
            'inertia_vue3' => $this->fixedTable($existing, 'inertia_vue3', $laravelChain, self::FIXED_INERTIA_VUE3),
        ];
    }

    /**
     * Offline shape problems in a hop data file judged against the policy as
     * of $today: one line per problem, empty when the shape is sound. The PHP
     * and Laravel chains must carry every version the policy supports and the
     * oldest version BOOSTER plans upgrades from, and each table must cover
     * every major from its own floor to the top of its chain: no gaps, and
     * the newest major included. Majors below a table's floor are allowed,
     * because a refresh never back-fills majors the table never covered.
     *
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    public function shapeProblems(array $data, DateTimeImmutable $today): array
    {
        $problems = [];
        $phpChain = $this->chainOr($data, 'php', $problems);
        $laravelChain = $this->chainOr($data, 'laravel', $problems);
        $phpunitChain = $this->chainOr($data, 'phpunit', $problems);

        if ($phpChain !== null) {
            foreach ($this->policy->supported('php', $today) as $version) {
                if (!in_array($version, $phpChain, true)) {
                    $problems[] = sprintf('The policy supports PHP %s as of %s, but the php chain does not list it.', $version, $today->format('Y-m-d'));
                }
            }
        }
        if ($laravelChain !== null) {
            foreach ($this->policy->supported('laravel', $today) as $version) {
                if (!in_array($version, $laravelChain, true)) {
                    $problems[] = sprintf('The policy supports Laravel %s as of %s, but the laravel chain does not list it.', $version, $today->format('Y-m-d'));
                }
            }
        }

        if ($phpChain !== null) {
            foreach (self::PHP_FLOOR_PREFIX as $floor) {
                if (!in_array($floor, $phpChain, true)) {
                    $problems[] = sprintf('BOOSTER plans PHP upgrades from %s, but the php chain does not list it.', $floor);
                }
            }
        }
        if ($laravelChain !== null) {
            foreach (self::LARAVEL_FLOOR_PREFIX as $floor) {
                if (!in_array($floor, $laravelChain, true)) {
                    $problems[] = sprintf('BOOSTER plans Laravel upgrades from %s, but the laravel chain does not list it.', $floor);
                }
            }
        }

        $this->tableCoverage($problems, $data, 'laravel_php', $laravelChain);
        $this->tableCoverage($problems, $data, 'laravel_phpunit', $laravelChain);
        $this->tableCoverage($problems, $data, 'phpunit_php', $phpunitChain);
        $this->tableCoverage($problems, $data, 'inertia_laravel', $laravelChain);
        $this->tableCoverage($problems, $data, 'inertia_vue3', $laravelChain);

        return $problems;
    }

    /**
     * The stable major versions a Packagist p2 feed still lists, lowest first.
     *
     * @param array<mixed> $feed
     *
     * @return list<string>
     */
    public static function majors(array $feed): array
    {
        $found = [];
        foreach (self::entries($feed) as $entry) {
            $version = $entry['version'] ?? null;
            $major = is_string($version) ? self::stableMajor($version) : null;
            if ($major !== null && !in_array($major, $found, true)) {
                $found[] = $major;
            }
        }
        usort($found, static fn(string $a, string $b): int => version_compare($a, $b));

        return $found;
    }

    /**
     * The effective value of $section[$package] for the oldest and the newest
     * stable release of every major a p2 feed lists. Packagist's minified
     * format omits fields that repeat the previous (newer) entry, so the
     * nearest explicit value is carried forward while walking newest to oldest.
     *
     * @param array<mixed> $feed
     *
     * @return array<string, array{oldest: ?string, newest: ?string}>
     */
    public static function constraintsPerMajor(array $feed, string $section, string $package): array
    {
        $carried = null;
        $result = [];
        foreach (self::entries($feed) as $entry) {
            $version = $entry['version'] ?? null;
            $major = is_string($version) ? self::stableMajor($version) : null;
            if ($major === null) {
                continue;
            }
            $sectionValue = $entry[$section] ?? null;
            $value = is_array($sectionValue) ? ($sectionValue[$package] ?? null) : null;
            if (is_string($value) && $value !== '') {
                $carried = $value;
            }
            if (!isset($result[$major])) {
                $result[$major] = ['oldest' => $carried, 'newest' => $carried];
            }
            $result[$major]['oldest'] = $carried;
        }

        return $result;
    }

    /**
     * A PHP constraint as the major.minor it floors at ("^8.0.2" becomes "8.0").
     * Null when the constraint has no usable lower bound.
     */
    public static function phpFloor(string $constraint): ?string
    {
        $bound = self::lowerBound($constraint);
        if ($bound === null) {
            return null;
        }
        $parts = explode('.', str_replace('-dev', '', $bound));
        if (count($parts) < 2) {
            return null;
        }

        return $parts[0] . '.' . $parts[1];
    }

    /**
     * A dependency constraint as the lowest major it accepts ("^11.5.50"
     * becomes "11", "^8.5.8|^9.3.3" becomes "8"). Null for "*".
     */
    public static function lowestMajor(string $constraint): ?string
    {
        $bound = self::lowerBound($constraint);
        if ($bound === null) {
            return null;
        }
        $major = explode('.', str_replace('-dev', '', $bound))[0];

        return $major === '0' ? null : $major;
    }

    /** An npm dist-tag as a caret requirement on its major.minor ("8.3.5" becomes "^8.3"). */
    public static function caretFromTag(string $tag): ?string
    {
        $parts = explode('.', ltrim($tag, 'v'));
        if (count($parts) < 2 || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
            return null;
        }

        return '^' . $parts[0] . '.' . $parts[1];
    }

    /**
     * The version entries of the one package a p2 feed carries, newest first.
     *
     * @param array<mixed> $feed
     *
     * @return list<array<mixed>>
     */
    private static function entries(array $feed): array
    {
        $packages = $feed['packages'] ?? null;
        if (!is_array($packages) || $packages === []) {
            throw new ToolError('Packagist feed has no packages.');
        }
        $entries = null;
        foreach ($packages as $candidate) {
            $entries = $candidate;
            break;
        }
        if (!is_array($entries)) {
            throw new ToolError('Packagist feed has no packages.');
        }
        $validated = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                throw new ToolError('Packagist feed entry is malformed.');
            }
            $validated[] = $entry;
        }

        return $validated;
    }

    /** The stable major of a version string, or null for pre-releases and oddballs. */
    private static function stableMajor(string $version): ?string
    {
        $version = ltrim($version, 'v');
        if (str_contains($version, '-')) {
            return null;
        }
        $parts = explode('.', $version);
        if (count($parts) < 2 || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
            return null;
        }

        return $parts[0];
    }

    /** The normalized lower bound of a constraint, or null when it cannot be parsed. */
    private static function lowerBound(string $constraint): ?string
    {
        try {
            $bound = (new VersionParser())->parseConstraints($constraint)->getLowerBound();
        } catch (UnexpectedValueException) {
            return null;
        }

        return $bound->getVersion();
    }

    /**
     * @param array<string, mixed> $existing
     *
     * @return list<string>
     */
    private static function chainOf(array $existing, string $key): array
    {
        $value = $existing[$key] ?? null;
        if (!is_array($value) || $value === [] || array_diff_key($value, array_keys($value)) !== []) {
            throw new ToolError(sprintf('Hop data key "%s" must be a non-empty list of version strings.', $key));
        }
        $chain = [];
        foreach ($value as $version) {
            if (!is_string($version) || $version === '') {
                throw new ToolError(sprintf('Hop data key "%s" must contain only version strings.', $key));
            }
            $chain[] = $version;
        }

        return $chain;
    }

    /**
     * @param array<string, mixed> $existing
     */
    private static function chainFloor(array $existing, string $key): string
    {
        $chain = self::chainOf($existing, $key);

        return $chain[0];
    }

    /**
     * A chain for shape checking: the chain when the key holds one, and a
     * problem line instead of an exception when it does not.
     *
     * @param array<string, mixed> $data
     * @param list<string> $problems
     *
     * @return list<string>|null
     */
    private function chainOr(array $data, string $key, array &$problems): ?array
    {
        try {
            return self::chainOf($data, $key);
        } catch (ToolError $e) {
            $problems[] = $e->getMessage();

            return null;
        }
    }

    /**
     * Table coverage for shape checking: every major from the table's own
     * floor to the top of its chain must be a key, so a gap or a missing
     * newest major is a problem.
     *
     * @param list<string> $problems
     * @param array<string, mixed> $data
     * @param list<string>|null $chain
     */
    private function tableCoverage(array &$problems, array $data, string $key, ?array $chain): void
    {
        $table = $data[$key] ?? null;
        if (!is_array($table) || $table === []) {
            $problems[] = sprintf('Hop data key "%s" must be a non-empty JSON object.', $key);

            return;
        }
        $majors = [];
        foreach (array_keys($table) as $major) {
            $major = (string) $major;
            if (preg_match('/^\d+(\.\d+)?$/', $major) !== 1) {
                $problems[] = sprintf('Hop data key "%s" has a malformed major "%s".', $key, $major);
                continue;
            }
            $majors[$major] = true;
        }
        if ($majors === [] || $chain === null || $chain === []) {
            return;
        }
        $floor = null;
        foreach (array_keys($majors) as $major) {
            $major = (string) $major;
            if ($floor === null || version_compare($major, $floor, '<')) {
                $floor = $major;
            }
        }
        $top = $chain[count($chain) - 1];
        foreach ($chain as $major) {
            if (version_compare($major, $floor, '<') || isset($majors[$major])) {
                continue;
            }
            $problems[] = sprintf('Hop data key "%s" has no entry for major %s, but its chain runs from %s to %s.', $key, $major, $floor, $top);
        }
    }

    /**
     * @param array<string, mixed> $existing
     * @param list<string> $candidates
     *
     * @return list<string>
     */
    private function extendChain(array $existing, string $key, array $candidates, ?string $floor = null): array
    {
        $chain = self::chainOf($existing, $key);
        foreach ($candidates as $candidate) {
            if ($floor !== null && version_compare($candidate, $floor, '<')) {
                continue;
            }
            if (!in_array($candidate, $chain, true)) {
                $chain[] = $candidate;
            }
        }
        usort($chain, static fn(string $a, string $b): int => version_compare($a, $b));

        return $chain;
    }

    /**
     * The majors a table keeps: the ones it already has, plus any newer major
     * the chain gained. This stops a refresh from back-filling old majors the
     * table never covered.
     *
     * @param array<string, mixed> $existing
     * @param list<string> $chain
     *
     * @return list<string>
     */
    private function tableMajors(array $existing, string $key, array $chain): array
    {
        $table = $existing[$key] ?? [];
        if (!is_array($table)) {
            throw new ToolError(sprintf('Hop data key "%s" must be a JSON object.', $key));
        }
        $keys = [];
        $highest = null;
        foreach (array_keys($table) as $major) {
            $major = (string) $major;
            $keys[] = $major;
            if ($highest === null || version_compare($major, $highest, '>')) {
                $highest = $major;
            }
        }
        $wanted = [];
        foreach ($chain as $major) {
            if ($highest === null || in_array($major, $keys, true) || version_compare($major, $highest, '>')) {
                $wanted[] = $major;
            }
        }

        return $wanted;
    }

    /**
     * @param array<string, mixed> $existing
     */
    private function nodePinTarget(array $existing, DateTimeImmutable $today): string
    {
        $active = [];
        $supported = [];
        foreach ($this->policy->supported('node', $today) as $version) {
            if ($this->policy->stateOf('node', $version, $today) === State::Active) {
                $active[] = $version;
            } else {
                $supported[] = $version;
            }
        }
        $pick = $active !== [] ? $active : $supported;
        if ($pick !== []) {
            usort($pick, static fn(string $a, string $b): int => version_compare($a, $b));

            return $pick[count($pick) - 1];
        }
        $current = $existing['node_pin_target'] ?? null;
        if (is_string($current) && $current !== '') {
            return $current;
        }

        throw new ToolError('The policy lists no supported Node major and the hop data has no node_pin_target.');
    }

    /**
     * @param array<mixed> $tags the feed's dist-tags map
     * @param array<string, mixed> $existing
     */
    private function distTagCaret(array $tags, array $existing, string $key): string
    {
        $latest = $tags['latest'] ?? null;
        $caret = is_string($latest) ? self::caretFromTag($latest) : null;
        if ($caret !== null) {
            return $caret;
        }
        $current = $existing[$key] ?? null;
        if (is_string($current) && $current !== '') {
            return $current;
        }

        throw new ToolError(sprintf('No usable "latest" tag for %s and none on file.', $key));
    }

    /**
     * A fixed scalar a refresh carries through unchanged: the value on file
     * wins when it is a non-empty string, otherwise the seed. No feed drives
     * these keys, so a drift never resets them.
     *
     * @param array<string, mixed> $existing
     */
    private function fixedScalar(array $existing, string $key, string $seed): string
    {
        $current = $existing[$key] ?? null;
        if (is_string($current) && $current !== '') {
            return $current;
        }

        return $seed;
    }

    /**
     * A recipe pin no feed computes: carried through the refresh unchanged,
     * so the committed file stays the single home of the value.
     *
     * @param array<string, mixed> $existing
     */
    private function keptValue(array $existing, string $key): string
    {
        $current = $existing[$key] ?? null;
        if (is_string($current) && $current !== '') {
            return $current;
        }

        throw new ToolError(sprintf('Hop data key "%s" must be a non-empty string.', $key));
    }

    /**
     * A fixed table a refresh carries through per major: the value on file
     * wins for every major the table already holds (an explicit null counts
     * as a decision), majors the chain gained fall back to the seed, and a
     * major in neither lands on null.
     *
     * @param array<string, mixed> $existing
     * @param list<string> $chain
     * @param array<int|string, string|null> $seed PHP casts numeric string keys to int, so a seed like "6" => null carries an int key; array_key_exists still finds it by its string form
     *
     * @return array<string, string|null>
     */
    private function fixedTable(array $existing, string $key, array $chain, array $seed): array
    {
        $onFile = $existing[$key] ?? [];
        if (!is_array($onFile)) {
            throw new ToolError(sprintf('Hop data key "%s" must be a JSON object.', $key));
        }
        $table = [];
        foreach ($this->tableMajors($existing, $key, $chain) as $major) {
            if (array_key_exists($major, $onFile)) {
                $value = $onFile[$major];
                if (!is_string($value) && !is_null($value)) {
                    throw new ToolError(sprintf('Hop data key "%s" must hold version strings or null.', $key));
                }
                $table[$major] = $value;
                continue;
            }
            $table[$major] = array_key_exists($major, $seed) ? $seed[$major] : null;
        }

        return $table;
    }

    /**
     * @param array<string, mixed> $existing
     * @param list<string> $chain
     * @param array<mixed> $feed
     *
     * @return array<string, string>
     */
    private function phpunitPhp(array $existing, array $chain, array $feed): array
    {
        $effective = self::constraintsPerMajor($feed, 'require', 'php');
        $table = [];
        foreach ($this->tableMajors($existing, 'phpunit_php', $chain) as $major) {
            $constraint = $effective[$major]['oldest'] ?? null;
            $floor = $constraint === null ? null : self::phpFloor($constraint);
            if ($floor === null) {
                $previous = $existing['phpunit_php'][$major] ?? null;
                $floor = is_string($previous) && $previous !== '' ? $previous : null;
            }
            if ($floor !== null) {
                $table[$major] = $floor;
            }
        }

        return $table;
    }

    /**
     * @param array<string, mixed> $existing
     * @param list<string> $chain
     * @param array<mixed> $feed
     *
     * @return array<string, string>
     */
    private function laravelPhpunit(array $existing, array $chain, array $feed): array
    {
        $effective = self::constraintsPerMajor($feed, 'require-dev', 'phpunit/phpunit');
        $table = [];
        foreach ($this->tableMajors($existing, 'laravel_phpunit', $chain) as $major) {
            $constraint = $effective[$major]['newest'] ?? null;
            $majorOf = $constraint === null ? null : self::lowestMajor($constraint);
            if ($majorOf === null) {
                $previous = $existing['laravel_phpunit'][$major] ?? null;
                $majorOf = is_string($previous) && $previous !== '' ? $previous : null;
            }
            if ($majorOf !== null) {
                $table[$major] = $majorOf;
            }
        }

        return $table;
    }

    /**
     * @param array<string, mixed> $existing
     * @param list<string> $chain
     * @param array<mixed> $frameworkFeed
     * @param array<mixed> $endoflifeFeed
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function laravelPhp(array $existing, array $chain, array $frameworkFeed, array $endoflifeFeed): array
    {
        $floors = self::constraintsPerMajor($frameworkFeed, 'require', 'php');
        $ranges = self::endoflifeRanges($endoflifeFeed);
        $table = [];
        foreach ($this->tableMajors($existing, 'laravel_php', $chain) as $major) {
            $constraint = $floors[$major]['oldest'] ?? null;
            $low = $constraint === null ? null : self::phpFloor($constraint);
            $high = $ranges[$major][1] ?? null;
            if ($low !== null && $high !== null) {
                $table[$major] = [$low, $high];
                continue;
            }
            $previous = $existing['laravel_php'][$major] ?? null;
            if (is_array($previous) && count($previous) === 2 && is_string($previous[0]) && is_string($previous[1])) {
                $table[$major] = [$previous[0], $previous[1]];
            }
        }

        return $table;
    }

    /**
     * The PHP ranges endoflife.date reports for each Laravel major.
     *
     * The /api/<project>.json endpoint answers with a bare list of cycles;
     * the fuller document wraps the same releases under result.releases.
     *
     * @param array<mixed> $feed
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private static function endoflifeRanges(array $feed): array
    {
        $releases = null;
        if (array_is_list($feed)) {
            $releases = $feed;
        } else {
            $result = $feed['result'] ?? null;
            $releases = is_array($result) ? ($result['releases'] ?? null) : null;
        }
        if (!is_array($releases) || $releases === []) {
            throw new ToolError('The endoflife feed has no release list.');
        }
        $ranges = [];
        foreach ($releases as $release) {
            if (!is_array($release)) {
                continue;
            }
            $name = $release['cycle'] ?? $release['name'] ?? null;
            $range = $release['supportedPhpVersions'] ?? null;
            if (!is_string($range)) {
                $custom = $release['custom'] ?? null;
                $range = is_array($custom) ? ($custom['supportedPhpVersions'] ?? null) : null;
            }
            if (!is_string($name) || !is_string($range) || preg_match('/^(\d+\.\d+)\s*-\s*(\d+\.\d+)$/', $range, $match) !== 1) {
                continue;
            }
            $ranges[$name] = [$match[1], $match[2]];
        }

        return $ranges;
    }
}
