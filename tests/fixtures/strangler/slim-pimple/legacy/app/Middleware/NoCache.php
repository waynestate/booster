<?php
namespace App\Middleware;

class NoCache
{
    public function __invoke($request, $handler)
    {
        return $handler->handle($request)->withHeader('Cache-Control', 'no-store');
    }
}
