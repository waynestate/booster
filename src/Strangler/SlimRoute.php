<?php

declare(strict_types=1);

namespace Waynestate\Booster\Strangler;

/**
 * One Slim route definition as SlimRouteScanner read it: the methods it
 * declares, its full pattern (every enclosing group's prefix joined on), its
 * name, its handler and where it is written.
 */
final class SlimRoute
{
    /**
     * @param list<string> $methods    upper-case, as declared
     * @param string       $handlerKind 'closure', 'class' or 'unknown'
     * @param list<string> $closureUses the variables a closure handler imports with use (...)
     * @param list<string> $guards     the conditions of the if blocks the route is registered inside
     * @param list<string> $middleware the ->add(...) arguments on the route and its groups
     */
    public function __construct(
        public readonly array $methods,
        public readonly string $pattern,
        public readonly ?string $name,
        public readonly string $handlerKind,
        public readonly ?string $handlerClass,
        public readonly ?string $handlerMethod,
        public readonly string $handlerText,
        public readonly array $closureUses,
        public readonly string $file,
        public readonly int $line,
        public readonly int $handlerLine,
        public readonly array $guards,
        public readonly array $middleware,
        public readonly bool $closureIsStatic = false,
    ) {}
}
