<?php
namespace App\Controller;

return [
    'login' => function ($app) {
        Login::addRoutes($app);
    },

    'staff' => function ($app) {
        if (in_array('staff', $app->getContainer()['user']->roles)) {
            $app->group('/staff/', function ($app) {
                Staff::addRoutes($app);
            });
        }
    },
];
