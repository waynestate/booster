<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

use Composer\Semver\VersionParser;
use Throwable;
use Waynestate\Booster\Hop\Hop;
use Waynestate\Booster\Hop\HopKind;
use Waynestate\Booster\Refresh\HopDataRefresher;
use Waynestate\Booster\ToolError;

/**
 * Applies a hop's Composer changes to composer.json: raise the PHP or Laravel
 * constraint, swap packages, and move config.platform.php. It edits the
 * manifest only. It never runs composer update; the gate or the person does.
 */
final class ComposerEditor
{
    /** The reason prefix Booster writes into its temporary advisory unblocks. */
    public const UNBLOCK_REASON_PREFIX = 'Booster temporary unblock';

    /** The prefix older Booster runs wrote; still recognised when reading. */
    public const LEGACY_UNBLOCK_REASON_PREFIX = 'BOOSTER temporary unblock';

    /**
     * The wayne-cas package the cas-to-sesame hop removes; also the marker in
     * the repositories urls that point at its private host.
     */
    private const WAYNE_CAS = 'cit-web-infra/wayne-cas';

    /**
     * PHP minors whose x.y.0 was tagged but never released. 8.4.0 was skipped
     * and 8.4.1 was the first 8.4 release, so packages floor at 8.4.1
     * (PHPUnit 13, Symfony 8) and a platform pin of 8.4.0 refuses them (found
     * on WELD, 2026-11-06: phpunit-12-to-13 could not resolve).
     */
    private const FIRST_RELEASES = ['8.4' => '8.4.1'];

    /** The first released patch of a PHP minor, for config.platform.php. */
    public static function firstRelease(string $minor): string
    {
        return self::FIRST_RELEASES[$minor] ?? $minor . '.0';
    }

    /**
     * @return list<string> human-readable lines about what changed
     */
    public function apply(Hop $hop, string $appPath): array
    {
        $file = rtrim($appPath, '/\\') . '/composer.json';
        if (!is_file($file)) {
            return ['No composer.json: nothing to raise. Set the PHP version in your image or server config.'];
        }

        $raw = file_get_contents($file);
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data)) {
            throw new ToolError(sprintf('"%s" is not valid JSON.', $file));
        }

        $changes = [];
        foreach ($hop->composerSet as $package => $constraint) {
            $changes = [...$changes, ...$this->set($data, $package, $constraint, $hop)];
        }
        foreach ($hop->composerSetIfPresent as $package => $constraint) {
            $changes = [...$changes, ...$this->setIfPresent($data, $package, $constraint)];
        }
        foreach ($hop->composerRemove as $package) {
            foreach (['require', 'require-dev'] as $section) {
                if (isset($data[$section][$package])) {
                    unset($data[$section][$package]);
                    $changes[] = sprintf('Removed %s from %s.', $package, $section);
                }
            }
            // The package's own Composer repository would survive the removal
            // and, pointing at a private host, stop composer update to ask for
            // credentials. Drop it with the package.
            if ($package === self::WAYNE_CAS) {
                $changes = [...$changes, ...$this->dropWayneCasRepositories($data)];
            }
        }
        if ($hop->kind === HopKind::Sesame) {
            $changes = [...$changes, ...$this->ensureHttpClient($data)];
        }
        if ($hop->kind === HopKind::Php && isset($data['config']['platform']['php'])) {
            $pin = self::firstRelease($hop->to);
            $data['config']['platform']['php'] = $pin;
            $changes[] = sprintf('Set config.platform.php to %s.', $pin);
        }

        if ($changes === []) {
            return [];
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($file, $json . "\n") === false) {
            throw new ToolError(sprintf('Could not write "%s".', $file));
        }

        return $changes;
    }

    /**
     * The temporary advisory unblock Composer 2.10's policy blocking needs
     * while an upgrade moves the lock past tagged releases Packagist has
     * flagged. It writes one scoped entry into composer.json: limited to the
     * package and the constraint Composer just refused, kept visible to
     * `composer audit` (on-audit false), and carrying a reason PROCTOR can
     * find and report until the app is off the blocked versions. Removing
     * the entry is the app's job once the upgrade is done.
     *
     * @param list<string> $advisoryIds
     *
     * @return list<string> human-readable lines about what changed
     */
    public function advisoryUnblock(string $appPath, string $package, string $constraint, array $advisoryIds): array
    {
        $file = rtrim($appPath, '/\\') . '/composer.json';
        if (!is_file($file)) {
            throw new ToolError(sprintf('No composer.json at %s, so the advisory unblock has nowhere to go.', $appPath));
        }
        $raw = file_get_contents($file);
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data)) {
            throw new ToolError(sprintf('"%s" is not valid JSON.', $file));
        }

        // Without named IDs the refusal was the quiet kind: Composer locked a
        // branch version instead of failing, so the reason says that.
        $reason = $advisoryIds === []
            ? sprintf(
                '%s Packagist advisories block the tagged releases of %s needed for the upgrade, so the lock fell back to a branch version. Remove this entry once the app is off them.',
                self::UNBLOCK_REASON_PREFIX,
                $package,
            )
            : sprintf(
                '%s Packagist advisories %s block the tagged releases of %s needed for the upgrade. Remove this entry once the app is off them.',
                self::UNBLOCK_REASON_PREFIX,
                implode(', ', $advisoryIds),
                $package,
            );
        $rule = [
            'constraint' => $constraint,
            'reason' => $reason,
            'on-block' => true,
            'on-audit' => false,
        ];

        $ignore = $data['config']['policy']['advisories']['ignore'] ?? [];
        if (!is_array($ignore)) {
            throw new ToolError(sprintf('The config.policy.advisories.ignore setting in "%s" is neither a list nor a map.', $file));
        }
        // A plain list of names is the other allowed shape; normalise it to a map.
        if ($ignore !== [] && array_is_list($ignore)) {
            $ignore = array_fill_keys(array_map('strval', $ignore), null);
        }

        $existing = $ignore[$package] ?? null;
        if ($existing === null) {
            $existing = [];
        } elseif (!is_array($existing)) {
            $existing = [['reason' => (string) $existing]];
        } elseif (!array_is_list($existing)) {
            $existing = [$existing];
        }
        if (in_array($rule, $existing, true)) {
            return [];
        }
        $ignore[$package] = [...$existing, $rule];
        $data['config']['policy']['advisories']['ignore'] = $ignore;

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($file, $json . "\n") === false) {
            throw new ToolError(sprintf('Could not write "%s".', $file));
        }

        return [sprintf(
            'Added a temporary advisory unblock to composer.json: config.policy.advisories.ignore.%s for the constraint %s.',
            $package,
            $constraint,
        )];
    }

    /**
     * The packages whose config.policy.advisories.ignore entries carry one of
     * Booster's temporary-unblock reasons (the current prefix or the legacy
     * all-caps one). Empty when composer.json has none, is missing, or is not
     * valid JSON: a manifest problem is the gate's to report, not this read.
     *
     * @return list<string>
     */
    public function temporaryUnblocks(string $appPath): array
    {
        $file = rtrim($appPath, '/\\') . '/composer.json';
        if (!is_file($file)) {
            return [];
        }
        $raw = file_get_contents($file);
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }
        $ignore = $data['config']['policy']['advisories']['ignore'] ?? [];
        if (!is_array($ignore)) {
            return [];
        }
        $packages = [];
        foreach ($ignore as $package => $rules) {
            // A package maps to a list of rule objects; older shapes (a bare
            // string reason, a single object, a null from a normalised name
            // list) are handled without complaint.
            if (!is_array($rules) || $rules === []) {
                continue;
            }
            $list = array_is_list($rules) ? $rules : [$rules];
            foreach ($list as $rule) {
                $reason = is_array($rule) ? ($rule['reason'] ?? null) : null;
                if (is_string($reason) && (str_starts_with($reason, self::UNBLOCK_REASON_PREFIX) || str_starts_with($reason, self::LEGACY_UNBLOCK_REASON_PREFIX))) {
                    $packages[] = (string) $package;
                    break;
                }
            }
        }

        return $packages;
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<string>
     */
    private function set(array &$data, string $package, string $constraint, Hop $hop): array
    {
        $isPhp = $package === 'php';
        $section = isset($data['require'][$package]) ? 'require' : (!$isPhp && isset($data['require-dev'][$package]) ? 'require-dev' : null);

        if ($section === null) {
            // The SESAME swap and the WayneUI move add packages that were not
            // there, and a PHP hop adds the php constraint itself when the app
            // never declared one; a Laravel, PHPUnit or other bump never does.
            $addsMissingPackage = $hop->kind === HopKind::Sesame
                || $hop->kind === HopKind::WayneUi
                || ($hop->kind === HopKind::Php && $isPhp);
            if (!$addsMissingPackage) {
                return [];
            }
            $section = 'require';
            // Append the package, and sort the section the way Composer does
            // only when config.sort-packages is on; otherwise the app's own
            // order is kept.
            $data[$section][$package] = $constraint;
            if (($data['config']['sort-packages'] ?? false) === true) {
                $this->sortRequireBlock($data[$section]);
            }

            return [sprintf('Set %s to %s in %s.', $package, $constraint, $section)];
        }
        if ($hop->kind === HopKind::Sesame || $this->alreadyAtLeast((string) $data[$section][$package], $hop->to, $isPhp)) {
            return [];
        }

        $data[$section][$package] = $constraint;

        return [sprintf('Set %s to %s in %s.', $package, $constraint, $section)];
    }

    /**
     * Raises a companion package's constraint in the section where the app
     * already lists it: require first, then require-dev. A package the app
     * does not list is never added, and a constraint that already floors at
     * or above the pin's floor is left alone, so the hop never narrows a
     * range the app chose. The floor is judged against the pin itself rather
     * than the hop's target, because a companion's major differs from the
     * framework's.
     *
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function setIfPresent(array &$data, string $package, string $constraint): array
    {
        $section = isset($data['require'][$package]) ? 'require' : (isset($data['require-dev'][$package]) ? 'require-dev' : null);
        if ($section === null) {
            return [];
        }
        if ($this->atOrAboveFloor((string) $data[$section][$package], $constraint)) {
            return [];
        }

        $data[$section][$package] = $constraint;

        return [sprintf('Set %s to %s in %s.', $package, $constraint, $section)];
    }

    /**
     * True when $current already floors at or above the floor of $target, so
     * raising it would only narrow the range. A constraint with no usable
     * floor ("*" or unparseable) is raised, the way HopExecutor treats it.
     */
    private static function atOrAboveFloor(string $current, string $target): bool
    {
        $floor = HopDataRefresher::lowestMajor($current);
        $targetFloor = HopDataRefresher::lowestMajor($target);
        if ($floor === null || $targetFloor === null) {
            return false;
        }

        return version_compare($floor, $targetFloor, '>=');
    }

    /**
     * Drops every repositories entry whose url names the wayne-cas
     * repository, in both shapes Composer allows: a list of objects and an
     * object keyed by repository name. The section goes away when the last
     * entry leaves it; other entries stay untouched.
     *
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function dropWayneCasRepositories(array &$data): array
    {
        $repositories = $data['repositories'] ?? null;
        if (!is_array($repositories) || $repositories === []) {
            return [];
        }

        $changes = [];
        if (array_is_list($repositories)) {
            $kept = [];
            foreach ($repositories as $entry) {
                $url = is_array($entry) && is_string($entry['url'] ?? null) ? $entry['url'] : null;
                if ($url !== null && self::isWayneCasUrl($url)) {
                    $changes[] = sprintf('composer.json: removed the wayne-cas repository entry (%s)', $url);
                    continue;
                }
                $kept[] = $entry;
            }
            $repositories = $kept;
        } else {
            foreach ($repositories as $name => $entry) {
                $url = is_array($entry) && is_string($entry['url'] ?? null) ? $entry['url'] : null;
                if ($url === null || !self::isWayneCasUrl($url)) {
                    continue;
                }
                unset($repositories[$name]);
                $changes[] = sprintf('composer.json: removed the wayne-cas repository entry (%s)', $url);
            }
        }

        if ($repositories === []) {
            unset($data['repositories']);
        } else {
            $data['repositories'] = $repositories;
        }

        return $changes;
    }

    /**
     * Whether a repositories url names the wayne-cas repository, in any of
     * the shapes apps carry: the old cit-web-infra path on the private
     * host, the public waynestate mirror, or a path or vcs entry whose last
     * path segment is wayne-cas (a local checkout or a bare git url).
     */
    private static function isWayneCasUrl(string $url): bool
    {
        $url = strtolower(trim($url));
        if (str_contains($url, self::WAYNE_CAS) || str_contains($url, 'waynestate/wayne-cas')) {
            return true;
        }
        $bare = rtrim($url, '/');
        if (str_ends_with($bare, '.git')) {
            $bare = substr($bare, 0, -4);
        }

        return $bare === 'wayne-cas' || str_ends_with($bare, '/wayne-cas');
    }

    /**
     * SESAME only suggests guzzlehttp/guzzle, and its Laravel provider builds
     * a Guzzle client when the app binds no PSR-18 client, so an app with
     * neither Guzzle nor the Symfony HTTP client cannot sign in. Require
     * Guzzle when both are absent, through the same require edit as
     * composerSet, so the gate's composer update picks it up.
     *
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function ensureHttpClient(array &$data): array
    {
        $require = $data['require'] ?? [];
        if (isset($require['guzzlehttp/guzzle']) || isset($require['symfony/http-client'])) {
            return [];
        }
        $data['require']['guzzlehttp/guzzle'] = '^7.8';
        // Composer only sorts the require block when config.sort-packages is
        // on; leave the app's own order alone otherwise.
        if (($data['config']['sort-packages'] ?? false) === true) {
            $this->sortRequireBlock($data['require']);
        }

        return ['composer.json: added guzzlehttp/guzzle ^7.8 (SESAME needs an HTTP client)'];
    }

    /**
     * Sorts a require block the way Composer does with config.sort-packages
     * on: php first, then the php-, ext- and lib- platform packages in that
     * group order, then every other package alphabetically.
     *
     * @param array<string, mixed> $require
     */
    private function sortRequireBlock(array &$require): void
    {
        uksort($require, static function (string $a, string $b): int {
            $groupA = self::requireBlockGroup($a);
            $groupB = self::requireBlockGroup($b);
            if ($groupA !== $groupB) {
                return $groupA <=> $groupB;
            }

            return strcmp($a, $b);
        });
    }

    /** The sort group of one require key; see sortRequireBlock(). */
    private static function requireBlockGroup(string $package): int
    {
        return match (true) {
            $package === 'php' => 0,
            str_starts_with($package, 'php-') => 1,
            str_starts_with($package, 'ext-') => 2,
            str_starts_with($package, 'lib-') => 3,
            default => 4,
        };
    }

    private function alreadyAtLeast(string $current, string $target, bool $isPhp): bool
    {
        if ($isPhp === false && !preg_match('/^\d+$/', $target)) {
            return false;
        }
        try {
            $bound = (new VersionParser())->parseConstraints($current)->getLowerBound()->getVersion();
        } catch (Throwable) {
            return false;
        }
        $parts = array_slice(explode('.', $bound), 0, $isPhp ? 2 : 1);

        return version_compare(implode('.', $parts), $target, '>=');
    }
}
