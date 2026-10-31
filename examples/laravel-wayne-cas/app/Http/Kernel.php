<?php

namespace App\Http;

class Kernel
{
    protected $middlewareGroups = [
        'web' => [
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Session\Middleware\StartSession::class,
            'wayne.cas',
        ],
    ];

    protected $routeMiddleware = [
        'wayne.cas' => \WSU\WayneCas\WayneAuthenticate::class,
    ];
}
