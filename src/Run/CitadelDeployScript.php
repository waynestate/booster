<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

/**
 * Writes the once-only Citadel deployment script when an upgrade changes
 * composer.lock. Citadel runs each deployments/ script exactly once, in name
 * order, so a fresh leading-zero name makes every existing deployment install
 * the new vendor/ before anything else runs. Booster writes at most one per
 * run, and only when the app actually has a deployments/ directory.
 */
final class CitadelDeployScript
{
    /** The name prefix that sorts this script ahead of every other deployment. */
    public const NAME_PREFIX = '0000_composer_install_';

    /**
     * Writes the script when composer.lock changed since $lockBefore (a change,
     * or a newly created lock) and the app has a deployments/ directory.
     * Returns the created file's name, or null when nothing was written.
     * $timestamp is YmdHi, so the name is 0000_composer_install_YmdHi.
     */
    public function writeIfLockChanged(string $path, ?string $lockBefore, string $timestamp): ?string
    {
        $lockPath = $path . '/composer.lock';
        $lockAfter = is_file($lockPath) ? md5_file($lockPath) : null;
        if ($lockAfter === null || $lockAfter === $lockBefore) {
            return null;
        }
        $deployDir = $path . '/deployments';
        if (!is_dir($deployDir)) {
            return null;
        }

        $name = self::NAME_PREFIX . $timestamp;
        $lines = [
            '#!/bin/sh',
            '# Added by Booster: this upgrade changed composer.lock. Citadel runs each deployment script once, in name order,',
            '# so a new name makes existing deployments install the new vendor/ before anything else runs.',
            'set -e',
            'composer install --no-dev --no-interaction --optimize-autoloader',
        ];
        // Only Laravel apps have artisan; a raw PHP app has no migrations to run.
        if (is_file($path . '/artisan')) {
            $lines[] = 'php artisan migrate --force';
        }
        file_put_contents($deployDir . '/' . $name, implode("\n", $lines) . "\n");
        @chmod($deployDir . '/' . $name, 0755);

        return $name;
    }
}
