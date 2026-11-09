<?php

require_once('../lib/WayneAuth/WayneAuth.php');

$config = parse_ini_file('../config/config.ini.example', true);

$wayne_auth = new WayneAuth($config['wayneauth']);
if ($wayne_auth->authenticateSSO() == true) {
    $accessid = $wayne_auth->getAccessID();
    echo 'Hello, ' . htmlspecialchars($accessid);
} else {
    header('Location: ' . $config['auth']['login_url']);
    exit;
}
