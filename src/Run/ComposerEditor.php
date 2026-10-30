<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

use Composer\Semver\VersionParser;
use Throwable;
use Waynestate\Booster\Hop\Hop;
use Waynestate\Booster\Hop\HopKind;
use Waynestate\Booster\ToolError;

/**
 * Applies a hop's Composer changes to composer.json: raise the PHP or Laravel
 * constraint, swap packages, and move config.platform.php. It edits the
 * manifest only. It never runs composer update; the gate or the person does.
 */
final class ComposerEditor
{
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
        foreach ($hop->composerRemove as $package) {
            foreach (['require', 'require-dev'] as $section) {
                if (isset($data[$section][$package])) {
                    unset($data[$section][$package]);
                    $changes[] = sprintf('Removed %s from %s.', $package, $section);
                }
            }
        }
        if ($hop->kind === HopKind::Php && isset($data['config']['platform']['php'])) {
            $data['config']['platform']['php'] = $hop->to . '.0';
            $changes[] = sprintf('Set config.platform.php to %s.0.', $hop->to);
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

        $rule = [
            'constraint' => $constraint,
            'reason' => sprintf(
                'BOOSTER temporary unblock: Packagist advisories %s block the tagged releases of %s needed for the upgrade. Remove this entry once the app is off them.',
                implode(', ', $advisoryIds),
                $package,
            ),
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
     * @param array<mixed> $data
     *
     * @return list<string>
     */
    private function set(array &$data, string $package, string $constraint, Hop $hop): array
    {
        $isPhp = $package === 'php';
        $section = isset($data['require'][$package]) ? 'require' : (!$isPhp && isset($data['require-dev'][$package]) ? 'require-dev' : null);

        if ($section === null) {
            // Only the SESAME swap adds a package that was not there; a PHP or Laravel bump never does.
            if ($hop->kind !== HopKind::Sesame) {
                return [];
            }
            $section = 'require';
            $data[$section][$package] = $constraint;
            ksort($data[$section]);

            return [sprintf('Set %s to %s in %s.', $package, $constraint, $section)];
        }
        if ($hop->kind === HopKind::Sesame || $this->alreadyAtLeast((string) $data[$section][$package], $hop->to, $isPhp)) {
            return [];
        }

        $data[$section][$package] = $constraint;

        return [sprintf('Set %s to %s in %s.', $package, $constraint, $section)];
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
