<?php

declare(strict_types=1);

namespace Waynestate\Booster\Plan;

/**
 * What the person asked for. Every field left null falls back to the
 * detected value or to the policy.
 */
final readonly class PlanOptions
{
    /**
     * @param list<string> $skip hop ids to leave out
     */
    public function __construct(
        public ?string $fromPhp = null,
        public ?string $fromLaravel = null,
        public ?string $toPhp = null,
        public ?string $toLaravel = null,
        public ?string $toPhpunit = null,
        public bool $sesame = false,
        public bool $withNode = false,
        public array $skip = [],
    ) {}
}
