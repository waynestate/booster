<?php

declare(strict_types=1);

namespace App\Routes;

use App\Action\ListItems;
use Slim\Routing\RouteCollectorProxy;

final class AdminRoutes
{
    public static function addRoutes(RouteCollectorProxy $group): void
    {
        $group->get('/users', ListItems::class);
    }
}
