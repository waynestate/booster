<?php
declare(strict_types=1);

namespace App;

use Authentication\AuthenticationService;
use Authentication\AuthenticationServiceInterface;
use Authentication\AuthenticationServiceProviderInterface;
use Authentication\Middleware\AuthenticationMiddleware;
use Cake\Http\BaseApplication;
use Cake\Http\MiddlewareQueue;
use Cake\Routing\Middleware\RoutingMiddleware;
use Psr\Http\Message\ServerRequestInterface;

class Application extends BaseApplication implements AuthenticationServiceProviderInterface
{
    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        // No CSRF middleware here: the fixture's form posts without a token.
        return $middlewareQueue
            ->add(new RoutingMiddleware($this))
            ->add(new AuthenticationMiddleware($this));
    }

    public function getAuthenticationService(ServerRequestInterface $request): AuthenticationServiceInterface
    {
        return new AuthenticationService(['unauthenticatedRedirect' => '/users/login']);
    }
}
