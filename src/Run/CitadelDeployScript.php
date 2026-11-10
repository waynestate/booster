<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

/**
 * Decides what a real run tells a Citadel app about its next deploy, and
 * writes the once-only install script when that is the right answer.
 *
 * Citadel runs each deployments/ script exactly once, in name order, and
 * copies the old vendor/ into each new deployment. So when an upgrade moves
 * the app's production packages, existing deployments need one fresh script
 * that installs them. Booster writes it only when all of these hold:
 *
 * - the production packages in composer.lock changed (a dev-only change,
 *   such as a PHPUnit major, installs the same vendor/ under --no-dev);
 * - the app has no release script that installs on every release
 *   (scripts/release.sh, Squire's kit), which would already do it;
 * - the app has a deployments/ directory, so it really deploys that way.
 *
 * The script installs and nothing else. Migrations stay with the app's own
 * release flow: a script named 0000_ runs ahead of the app's own hooks, and
 * an app whose first release must do something before it migrates (park old
 * tables, say) breaks when anything migrates first. An app with no deploy
 * scripts at all gets a report line saying what its deploy must do, because
 * Booster cannot see how it deploys and will not guess.
 */
final class CitadelDeployScript
{
    /** The name prefix that sorts this script ahead of every other deployment. */
    public const NAME_PREFIX = '0000_composer_install_';

    /** The release script Squire's deploy kit adds; it installs on every release. */
    public const RELEASE_SCRIPT = 'scripts/release.sh';

    /**
     * Works out the deploy follow-up after a real run and writes the script
     * when it is needed. $lockBefore is composer.lock's content before the
     * first hop (null when there was none). $timestamp is YmdHi, so the name
     * is 0000_composer_install_YmdHi.
     */
    public function decide(string $path, ?string $lockBefore, string $timestamp): DeployScriptResult
    {
        $lockPath = $path . '/composer.lock';
        $lockAfter = is_file($lockPath) ? file_get_contents($lockPath) : false;
        if ($lockAfter === false || $lockAfter === $lockBefore) {
            return new DeployScriptResult(null, []);
        }
        $before = self::productionPackages($lockBefore);
        $after = self::productionPackages($lockAfter);
        if ($before !== null && $after !== null && $before === $after) {
            return new DeployScriptResult(null, [
                'Deploy: composer.lock changed only in dev packages, so a production install (composer install --no-dev) gets the same vendor/. Booster added no deployment script.',
            ]);
        }
        $laravel = is_file($path . '/artisan');
        $release = $path . '/' . self::RELEASE_SCRIPT;
        if (is_file($release) && str_contains((string) file_get_contents($release), 'composer install')) {
            return new DeployScriptResult(null, [
                sprintf('Deploy: composer.lock changed production packages. %s installs them on every release (run it as the Quick Update post-script, as usual), so Booster added no deployment script.', self::RELEASE_SCRIPT),
            ]);
        }
        $deployDir = $path . '/deployments';
        if (!is_dir($deployDir)) {
            return new DeployScriptResult(null, [
                sprintf(
                    'Deploy: composer.lock changed production packages, and the app has no deploy scripts (no deployments/ folder, no %s), so Booster wrote none. On its next release every server must run composer install --no-dev --optimize-autoloader%s. On Citadel, add a deployments/ script that does it, or take Squire\'s release script.',
                    self::RELEASE_SCRIPT,
                    $laravel ? ', then the app\'s own release steps (php artisan migrate --force, when its migrations need it)' : '',
                ),
            ]);
        }

        $name = self::NAME_PREFIX . $timestamp;
        $lines = [
            '#!/bin/sh',
            '# Added by Booster: this upgrade changed the production packages in composer.lock. Citadel runs each deployment',
            '# script once, in name order, so a new name makes existing deployments install the new vendor/ first.',
            '# It only installs: migrations and other release steps stay with the app\'s own deploy scripts.',
            'set -e',
            'composer install --no-dev --no-interaction --optimize-autoloader',
        ];
        file_put_contents($deployDir . '/' . $name, implode("\n", $lines) . "\n");
        @chmod($deployDir . '/' . $name, 0755);

        $report = [
            sprintf('Added deployments/%s: Citadel runs it once, first, so existing deployments install the upgraded production packages.', $name),
            $laravel
                ? 'It runs composer install only. Migrations stay with your own deploy scripts: if none of them runs php artisan migrate --force, run it after this deploy.'
                : 'It runs composer install only; the rest of your release stays with your own deploy scripts.',
            sprintf('Undo with: rm deployments/%s (inside the app).', $name),
        ];

        return new DeployScriptResult($name, $report);
    }

    /**
     * The lock's production packages as name => version and source
     * reference, sorted by name, or null when the lock cannot be read. A
     * missing lock has no packages. Dev packages, the platform settings and
     * the content hash are left out: none of them changes what
     * composer install --no-dev puts in vendor/.
     *
     * @return array<string, string>|null
     */
    public static function productionPackages(?string $lock): ?array
    {
        if ($lock === null) {
            return [];
        }
        $data = json_decode($lock, true);
        if (!is_array($data)) {
            return null;
        }
        $packages = [];
        foreach (is_array($data['packages'] ?? null) ? $data['packages'] : [] as $package) {
            if (!is_array($package) || !is_string($package['name'] ?? null)) {
                continue;
            }
            $version = is_string($package['version'] ?? null) ? $package['version'] : '';
            $reference = $package['dist']['reference'] ?? $package['source']['reference'] ?? '';
            $packages[$package['name']] = $version . '@' . (is_string($reference) ? $reference : '');
        }
        ksort($packages);

        return $packages;
    }
}
