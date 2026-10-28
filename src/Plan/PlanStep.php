<?php

declare(strict_types=1);

namespace Waynestate\Booster\Plan;

use Waynestate\Booster\Hop\Hop;

/**
 * A hop placed in order, with the PHP and Laravel versions the app is on when
 * the hop starts and finishes. The gate image uses phpAfter.
 */
final readonly class PlanStep
{
    /**
     * @param list<string> $gates gate ids that must pass before the next step
     */
    public function __construct(
        public int $number,
        public Hop $hop,
        public ?string $phpBefore,
        public ?string $phpAfter,
        public ?string $laravelBefore,
        public ?string $laravelAfter,
        public ?string $phpunitBefore,
        public ?string $phpunitAfter,
        public array $gates,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'step' => $this->number,
            'hop' => $this->hop->toArray(),
            'php_before' => $this->phpBefore,
            'php_after' => $this->phpAfter,
            'laravel_before' => $this->laravelBefore,
            'laravel_after' => $this->laravelAfter,
            'phpunit_before' => $this->phpunitBefore,
            'phpunit_after' => $this->phpunitAfter,
            'gates' => $this->gates,
        ];
    }
}
