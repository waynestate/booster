<?php

declare(strict_types=1);

/*
 * up.php: a dependency-free health endpoint for apps with no framework (SQUIRE S55).
 *
 * If HELMET is installed, its /up handler answers and exits; otherwise this file answers 200 and nothing else:
 * no session, no database, no includes. Set SQUIRE_HEALTH_PATH=/up.php and SQUIRE_SMOKE_URL to match. A squire
 * answers the call before the knight does.
 */

if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
}

if (function_exists('Waynestate\Helmet\helmet_up')) {
    \Waynestate\Helmet\helmet_up();
}

http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
echo "ok\n";
