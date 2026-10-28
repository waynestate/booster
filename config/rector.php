<?php

declare(strict_types=1);

// One Rector config for every hop. BOOSTER passes the app path, the sets to
// apply and the class renames through the environment, so this file works the
// same from the CLI, from a PHAR and from a container, and it never reads
// anything from the app being upgraded.

use Rector\Config\RectorConfig;
use Rector\Renaming\Rector\Name\RenameClassRector;

$appPath = getenv('BOOSTER_APP_PATH');
if (!is_string($appPath) || $appPath === '' || !is_dir($appPath)) {
    throw new RuntimeException('BOOSTER_APP_PATH must name the app directory. Run Rector through `booster`, not directly.');
}

/** @return array<mixed> */
$decode = static function (string $name): array {
    $raw = getenv($name);
    $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];

    return is_array($data) ? $data : [];
};

$sets = [];
foreach ($decode('BOOSTER_RECTOR_SETS') as $constant) {
    if (!is_string($constant) || !defined($constant)) {
        throw new RuntimeException(sprintf('Rector set "%s" does not exist in the installed Rector.', is_string($constant) ? $constant : '?'));
    }
    $sets[] = constant($constant);
}

$skip = [];
foreach (['vendor', 'node_modules', 'storage', 'bootstrap/cache', '.git', 'public/build'] as $dir) {
    $skip[] = $appPath . '/' . $dir;
}
// Blade templates end in .php but are not PHP files.
$skip[] = '*.blade.php';

$cache = getenv('BOOSTER_RECTOR_CACHE');

// Rector reads the app's old PHP constraint from composer.json and would skip
// every rule for the version we are moving to. Tell it the target instead.
$target = getenv('BOOSTER_PHP_TARGET');
// Rector's own PhpVersion constants (PHP_80 = 80000) are the only values it accepts.
$phpVersion = null;
if (is_string($target) && preg_match('/^(\d+)\.(\d+)$/', $target, $m) === 1) {
    $name = 'Rector\ValueObject\PhpVersion::PHP_' . $m[1] . $m[2];
    $value = defined($name) ? constant($name) : null;
    $phpVersion = is_int($value) ? $value : null;
}

$bootstrap = getenv('BOOSTER_BOOTSTRAP');
$config = RectorConfig::configure()
    ->withPaths([$appPath])
    ->withSkip($skip)
    ->withCache(is_string($cache) && $cache !== '' ? $cache : sys_get_temp_dir() . '/booster-rector')
    ->withoutParallel();

if (is_string($bootstrap) && $bootstrap !== '' && is_file($bootstrap)) {
    $config = $config->withBootstrapFiles([$bootstrap]);
}

if ($phpVersion !== null) {
    $config = $config->withPhpVersion($phpVersion); // @phpstan-ignore argument.type (the int comes from the PhpVersion constants above)
}

$renames = $decode('BOOSTER_CLASS_RENAMES');
if ($renames !== []) {
    $config = $config->withConfiguredRule(RenameClassRector::class, $renames);
}

// RectorConfig::sets() rather than the builder's withSets(): Rector 2 prints a
// deprecation banner on stdout for per-version PHP sets, which would corrupt the
// JSON report. A hop is exactly one version's rules, which withPhpSets() cannot give.
return static function (RectorConfig $rectorConfig) use ($config, $sets): void {
    $config($rectorConfig);
    $rectorConfig->sets($sets);
};
