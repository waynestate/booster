<?php

declare(strict_types=1);

namespace App\Service;

final class Catalog
{
    /** @param list<string> $items */
    public function __construct(public readonly array $items)
    {
    }
}
