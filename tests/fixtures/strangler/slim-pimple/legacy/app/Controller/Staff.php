<?php
namespace App\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class Staff
{
    public static function addRoutes($app)
    {
        $app->get('', __CLASS__ . ':Home')->setName('staff');
    }

    public function Home(ServerRequestInterface $request, ResponseInterface $response, $args = [])
    {
        $user = sesame_user();
        $who = is_array($user) ? $user['username'] : 'guest';
        $signed = is_array(sesame_user()) ? 'yes' : 'no';
        $response->getBody()->write('staff ' . $who . ' ' . $signed);

        return $response;
    }
}
