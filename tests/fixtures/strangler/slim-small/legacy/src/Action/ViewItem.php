<?php

declare(strict_types=1);

namespace App\Action;

use App\Service\Catalog;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ViewItem
{
    public function __construct(private Catalog $catalog)
    {
    }

    /** @param array<string, string> $args */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $response->getBody()->write($this->catalog->items[(int) $args['id']] ?? 'none');

        return $response;
    }
}
