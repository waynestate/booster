<?php

/*
 * bin/install-legacy.php: installs legacy/, the old app's own Composer root,
 * every time this (Laravel) root installs or updates. Written by Booster's
 * mount step; app-owned; the finish step deletes it with legacy/.
 *
 * composer.json runs it from post-install-cmd and post-update-cmd, so every
 * place that already runs `composer install` (Squire's release script,
 * Parity, CI, the gate, you) installs both roots and nothing else changes.
 * A `--no-dev` install here (Composer says so with COMPOSER_DEV_MODE=0) is a
 * `--no-dev --optimize-autoloader` install there. It always installs legacy/
 * from its own lock, never updates it: that root moves with its own hops
 * (`booster run --path=legacy`). Two roots, one `composer install`, zero
 * extra steps for whoever deploys it. Buy one, get one free.
 */

$legacy = dirname(__DIR__) . '/legacy';
if (!is_file($legacy . '/composer.json')) {
    fwrite(STDERR, "install-legacy: no legacy/composer.json, nothing to install.\n");
    exit(0);
}

$composer = getenv('COMPOSER_BINARY');
$command = $composer !== false && $composer !== '' && is_file($composer)
    ? [PHP_BINARY, $composer]
    : ['composer'];
array_push($command, 'install', '--working-dir=' . $legacy, '--no-interaction');
if (getenv('COMPOSER_DEV_MODE') === '0') {
    array_push($command, '--no-dev', '--optimize-autoloader');
}

// The child must read legacy/composer.json, not a file the outer run named.
putenv('COMPOSER');
fwrite(STDERR, 'install-legacy: ' . implode(' ', array_slice($command, $composer !== false && $composer !== '' && is_file($composer) ? 1 : 0)) . "\n");

$process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
if (!is_resource($process)) {
    fwrite(STDERR, "install-legacy: could not start Composer for legacy/.\n");
    exit(1);
}
exit(proc_close($process));
