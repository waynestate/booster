<?php

declare(strict_types=1);

use App\Service\Catalog;
use DI\ContainerBuilder;

return function (ContainerBuilder $containerBuilder) {
    $containerBuilder->addDefinitions([
        Catalog::class => fn () => new Catalog(['apple', 'pear']),
        'greeting' => 'Hello',
    ]);
};
