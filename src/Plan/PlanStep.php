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
        public ?string $frameworkBefore = null,
        public ?string $frameworkAfter = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = [
            'step' => $this->number,
            'hop' => $this->hop->toArray(),
            'php_before' => $this->phpBefore,
            'php_after' => $this->phpAfter,
            'laravel_before' => $this->laravelBefore,
            'laravel_after' => $this->laravelAfter,
            'phpunit_before' => $this->phpunitBefore,
            'phpunit_after' => $this->phpunitAfter,
        ];
        // Only a CakePHP or Slim app carries the keys, so every other plan keeps its shape.
        if ($this->frameworkBefore !== null || $this->frameworkAfter !== null) {
            $array['framework_before'] = $this->frameworkBefore;
            $array['framework_after'] = $this->frameworkAfter;
        }
        $array['gates'] = $this->gates;

        return $array;
    }
}
