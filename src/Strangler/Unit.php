<?php

// src/Strangler/Unit.php
declare(strict_types=1);

namespace Waynestate\Booster\Strangler;

/** One move unit: a legacy route, or a form (its GET producer and POST consumer move together). */
final class Unit
{
    /**
     * @param list<string> $methods  upper-case HTTP methods the unit answers
     * @param list<Unit>   $parts    for kind 'form': the routes moving together; else []
     */
    public function __construct(
        public readonly string $id,            // e.g. "GET /users/{id}" or "FORM /articles/add"
        public readonly string $kind,          // 'route' | 'form'
        public readonly array $methods,
        public readonly string $uri,           // Laravel-style URI, e.g. "users/{id}"
        public readonly ?string $name,         // route name to keep, if the legacy route had one
        public readonly string $order,         // 'stateless' | 'page' | 'write' | 'signin' (move order)
        public readonly bool $needsSignIn,
        public readonly string $sourceFile,    // app-relative legacy file that defines it
        public readonly int $sourceLine,
        public readonly array $parts = [],
    ) {}
}
