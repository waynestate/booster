<?php
// Router for PHP's built-in server when the request file names a front
// controller (serve.front_controller): a file that exists under the docroot is
// served as it is, and every other path goes to the front controller, the way
// an Apache rewrite or nginx try_files would send it. Must parse on PHP 5.5.

$booster_front = getenv('BOOSTER_REPLAY_FRONT');
$booster_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$booster_file = $_SERVER['DOCUMENT_ROOT'] . $booster_path;
if ($booster_path !== '/' && is_file($booster_file) && substr($booster_file, -4) !== '.php') {
    return false;
}
if ($booster_path !== '/' && is_file($booster_file)) {
    // A real PHP script, such as a raw-PHP page next to the front controller.
    $booster_script = $booster_file;
    $booster_name = $booster_path;
} else {
    $booster_script = $_SERVER['DOCUMENT_ROOT'] . '/' . $booster_front;
    $booster_name = '/' . $booster_front;
}
$_SERVER['SCRIPT_FILENAME'] = $booster_script;
$_SERVER['SCRIPT_NAME'] = $booster_name;
$_SERVER['PHP_SELF'] = $booster_name;
chdir(dirname($booster_script));
unset($booster_front, $booster_path, $booster_file, $booster_name, $booster_script);
require $_SERVER['SCRIPT_FILENAME'];
