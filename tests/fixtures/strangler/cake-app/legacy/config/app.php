<?php
return [
    'debug' => true,
    'App' => [
        'namespace' => 'App',
        'encoding' => env('APP_ENCODING', 'UTF-8'),
        'base' => false,
        'webroot' => 'webroot/',
        'cssBaseUrl' => 'css/',
        'jsBaseUrl' => 'js/',
    ],
    'Asset' => [
        //'timestamp' => true,
    ],
];
