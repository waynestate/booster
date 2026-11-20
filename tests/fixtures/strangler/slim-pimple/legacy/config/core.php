<?php
return [
    'session' => function ($c) {
        return new App\Session();
    },
    'user' => function ($c) {
        return new App\User($c['session']);
    },
    'files' => function ($c) {
        return new \League\Flysystem\Sftp\SftpAdapter(['host' => 'localhost']);
    },
    'view' => function ($c) {
        return \Slim\Views\Twig::create(PROJECT_ROOT . 'templates');
    },
];
