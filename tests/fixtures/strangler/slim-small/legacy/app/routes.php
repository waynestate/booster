<?php

declare(strict_types=1);

use App\Action\ContactAction;
use App\Action\ListItems;
use App\Action\ViewItem;
use App\Routes\AdminRoutes;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app) {
    $app->options('/{routes:.*}', function (Request $request, Response $response) {
        return $response;
    });

    $app->get('/', function (Request $request, Response $response) {
        $response->getBody()->write($this->get('greeting') . ' world');
        return $response;
    })->setName('home');

    $app->group('/items', function (RouteCollectorProxy $group) {
        $group->get('', ListItems::class)->setName('items');
        $group->get('/{ id:[0-9]+ }[/]', ViewItem::class . ':show');
    });

    $app->get('/contact', ContactAction::class . ':form');
    $app->post('/contact', ContactAction::class . ':send');

    $app->get('/page[/{n}]', ListItems::class);

    if (($_SESSION['role'] ?? '') === 'admin') {
        $app->group('/admin', function (RouteCollectorProxy $group) {
            AdminRoutes::addRoutes($group);
        });
    }
};
