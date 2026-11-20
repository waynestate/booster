<?php
namespace App\Middleware;

class CheckCsrf
{
    public function __construct(private $session, private string $key)
    {
    }

    public function __invoke($request, $handler)
    {
        if ($request->getMethod() === 'POST' && ($request->getParsedBody()[$this->key] ?? null) !== $this->session->get($this->key)) {
            return (new \Slim\Psr7\Response())->withStatus(400);
        }

        return $handler->handle($request);
    }
}
