<?php
namespace App\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class Login
{
    public static function addRoutes($app)
    {
        $app->get('/[login[/]]', __CLASS__ . ':Login')->setName('login');
        $app->post('/[login[/]]', __CLASS__ . ':PostLogin');

        if ($app->getContainer()['user']->isAuth()) {
            $app->get('/logout/', __CLASS__ . ':Logout')->add(new \App\Middleware\NoCache());
        }
    }

    public function Login(ServerRequestInterface $request, ResponseInterface $response, $args = [])
    {
        $response->getBody()->write('login');

        return $response;
    }

    public function PostLogin(ServerRequestInterface $request, ResponseInterface $response, $args = [])
    {
        return $response->withHeader('Location', '/')->withStatus(302);
    }

    public function Logout(ServerRequestInterface $request, ResponseInterface $response, $args = [])
    {
        return $response->withHeader('Location', '/')->withStatus(302);
    }
}
