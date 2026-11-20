<?php
namespace App;

if (!$config = parse_ini_file(realpath(__DIR__ . '/../etc/config.ini'), true)) {
    throw new \Exception('Error reading config file.');
}

require '../config/init.php';

$app = new App($config);
$app->run();
