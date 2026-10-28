<?php

namespace App\Http;

class Kernel
{
    protected $routeMiddleware = [
        'wayne.cas' => \WSU\WayneCas\WayneAuthenticate::class,
    ];
}
