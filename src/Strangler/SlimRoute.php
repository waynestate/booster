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
     * @param list<array{string, int, int}> $links each step from the file the registration starts in to the route call: file, call token, the token that walk began at
     * @param list<string> $middlewareFiles the file each middleware argument is written in
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
        public readonly array $links = [],
        public readonly array $middlewareFiles = [],
        public readonly ?string $legacyPattern = null,
    ) {}

    /** The pattern as written in legacy, before an optional segment was expanded. */
    public function written(): string
    {
        return $this->legacyPattern ?? $this->pattern;
    }

    /**
     * One expansion of this route's optional segments (section 10.3): the
     * same route answering one Laravel URI, named only when $named.
     */
    public function withPattern(string $pattern, bool $named): self
    {
        return new self(
            $this->methods,
            $pattern,
            $named ? $this->name : null,
            $this->handlerKind,
            $this->handlerClass,
            $this->handlerMethod,
            $this->handlerText,
            $this->closureUses,
            $this->file,
            $this->line,
            $this->handlerLine,
            $this->guards,
            $this->middleware,
            $this->closureIsStatic,
            $this->links,
            $this->middlewareFiles,
            $this->written(),
        );
    }
}
