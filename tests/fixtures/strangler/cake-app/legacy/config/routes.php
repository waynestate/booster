<?php
use Cake\Routing\Route\DashedRoute;
use Cake\Routing\RouteBuilder;

return function (RouteBuilder $routes): void {
    $routes->setRouteClass(DashedRoute::class);

    $routes->scope('/', function (RouteBuilder $builder): void {
        $builder->connect('/', ['controller' => 'Notes', 'action' => 'index'], ['_name' => 'home']);
        // $builder->connect('/old', ['controller' => 'Old']);
        $builder->fallbacks();
    });
};
