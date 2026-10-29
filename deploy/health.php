<?php

declare(strict_types=1);

/*
 * health.php: the Citadel health check, with no framework boot and no artisan (SQUIRE S55).
 *
 * Citadel runs the Health Check script every 5 minutes with a 15 second timeout, as root, from the app directory.
 * In the dashboard wizard set it to:   ["php", "health.php"]
 * Exit 0 is healthy and 1 is unhealthy (2 is reserved by Docker, so it is never used here).
 *
 * It asks the local web server for the health URL, so a release that installs cleanly but serves nothing is caught.
 * It reads no database and calls no other service; a slow dependency must not mark a healthy app as failing.
 *
 * Settings (environment variables, all optional):
 *   SQUIRE_HEALTH_PATH     the path to request (default /up for Laravel, /up.php for raw PHP)
 *   SQUIRE_HEALTH_PORT     the port (default 80)
 *   SQUIRE_HEALTH_TIMEOUT  seconds to wait (default 5, which stays inside Citadel's 15)
 *   SQUIRE_HEALTH_VENDOR   1 also requires vendor/autoload.php (default 1)
 *
 * A health check is the only exam where "just show up" is the whole syllabus.
 */

$root = __DIR__;
$laravel = is_file($root . '/artisan');

$path = getenv('SQUIRE_HEALTH_PATH') ?: ($laravel ? '/up' : '/up.php');
$port = (int) (getenv('SQUIRE_HEALTH_PORT') ?: 80);
$timeout = max(1, min(14, (int) (getenv('SQUIRE_HEALTH_TIMEOUT') ?: 5)));
$needsVendor = getenv('SQUIRE_HEALTH_VENDOR') !== '0';
$host = getenv('CITADEL_SERVER_NAME') ?: '127.0.0.1';

$unhealthy = static function (string $reason): never {
    fwrite(STDOUT, 'unhealthy: ' . $reason . PHP_EOL);
    exit(1);
};

if ($needsVendor && !is_file($root . '/vendor/autoload.php')) {
    $unhealthy('vendor/autoload.php is missing; the release did not finish');
}

if ($path === '' || $path[0] !== '/' || preg_match('/[\r\n\s]/', $path) === 1) {
    $unhealthy('SQUIRE_HEALTH_PATH must be a path that starts with /');
}

$socket = @fsockopen('127.0.0.1', $port, $errno, $error, $timeout);
if ($socket === false) {
    $unhealthy(sprintf('cannot connect to 127.0.0.1:%d (%s)', $port, $error));
}
stream_set_timeout($socket, $timeout);

$request = "GET {$path} HTTP/1.0\r\nHost: {$host}\r\nUser-Agent: squire-health\r\nConnection: close\r\n\r\n";
fwrite($socket, $request);
$statusLine = fgets($socket, 256);
fclose($socket);

if ($statusLine === false || preg_match('#^HTTP/\S+\s+(\d{3})#', $statusLine, $matches) !== 1) {
    $unhealthy('no HTTP status line from ' . $path);
}
if ($matches[1] !== '200') {
    $unhealthy(sprintf('%s answered HTTP %s, expected 200', $path, $matches[1]));
}

fwrite(STDOUT, sprintf('healthy: %s answered HTTP 200%s', $path, PHP_EOL));
exit(0);
