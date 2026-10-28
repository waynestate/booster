<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

use Waynestate\Booster\Plan\Plan;

final readonly class RunReport
{
    /**
     * @param list<StepResult> $results
     */
    public function __construct(
        public Plan $plan,
        public bool $dryRun,
        public array $results,
    ) {}

    public function failed(): bool
    {
        foreach ($this->results as $result) {
            if ($result->failed()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tool' => 'booster',
            'dry_run' => $this->dryRun,
            'result' => $this->failed() ? 'fail' : 'pass',
            'plan' => $this->plan->toArray(),
            'results' => array_map(static fn(StepResult $r): array => $r->toArray(), $this->results),
        ];
    }
}
