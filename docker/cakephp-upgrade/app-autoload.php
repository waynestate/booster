<?php

declare(strict_types=1);

/*
 * The autoloader Booster hands cakephp/upgrade's Rector (--autoload) in place
 * of the app's own vendor/autoload.php. It registers the app's Composer class
 * maps (PSR-0, PSR-4 and the class map), prepended like Composer does, and
 * nothing else: the app's "files" autoloads and its platform check never run.
 *
 * Why: the tool runs on a newer PHP than the app, and an app's files
 * autoloads run code at load time. CakePHP 4's Chronos 2 aliases its classes
 * the moment it is loaded, and Chronos 2 does not load on PHP 8.4 or newer
 * (its createFromTimestamp() clashes with PHP's), so the app's autoload.php
 * died before a single rule ran. Rector only needs to find the classes, and
 * PHPStan reads them without running them, so the maps are enough.
 * (Look, don't touch.)
 *
 * BOOSTER_APP_VENDOR points it at another vendor/ (Booster's own tests do).
 */

$vendor = getenv('BOOSTER_APP_VENDOR') ?: '/app/vendor';
$maps = $vendor . '/composer';

$loader = new \Composer\Autoload\ClassLoader($vendor);
foreach (is_file($maps . '/autoload_namespaces.php') ? require $maps . '/autoload_namespaces.php' : [] as $prefix => $paths) {
    $loader->set($prefix, $paths);
}
foreach (is_file($maps . '/autoload_psr4.php') ? require $maps . '/autoload_psr4.php' : [] as $prefix => $paths) {
    $loader->setPsr4($prefix, $paths);
}
if (is_file($maps . '/autoload_classmap.php')) {
    $loader->addClassMap(require $maps . '/autoload_classmap.php');
}
$loader->register(true);

return $loader;
