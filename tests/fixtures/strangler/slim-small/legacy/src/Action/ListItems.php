<?php

declare(strict_types=1);

namespace App\Action;

use App\Service\Catalog;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ListItems
{
    public function __construct(private Catalog $catalog)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        echo json_encode($this->catalog->items);

        return $response->withHeader('Content-Type', 'application/json');
    }
}
