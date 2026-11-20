<?php

declare(strict_types=1);

namespace App\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ContactAction
{
    public function form(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write('<form method="post"><input name="msg"></form>');

        return $response;
    }

    public function send(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $_SESSION['sent'] = true;

        return $response->withStatus(302)->withHeader('Location', '/contact');
    }
}
