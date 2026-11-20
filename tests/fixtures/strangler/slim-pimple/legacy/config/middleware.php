<?php
return [
    'check_csrf' => function ($app) {
        $c = $app->getContainer();
        return new App\Middleware\CheckCsrf($c['session'], CSRF_KEY);
    },
];
