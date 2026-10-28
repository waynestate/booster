<?php

declare(strict_types=1);

namespace Waynestate\Booster\Hop;

enum HopKind: string
{
    case Php = 'php';
    case Laravel = 'laravel';
    case Phpunit = 'phpunit';
    case Sesame = 'sesame';
    case Vue = 'vue';
}
