<?php

declare(strict_types=1);

namespace Waynestate\Booster\Plan;

use Waynestate\Booster\Project\Project;

/**
 * An ordered list of steps from where the app is to where the policy says it
 * should be, plus warnings the person reading the plan should see first.
 */
final readonly class Plan
{
    /**
     * @param list<PlanStep> $steps
     * @param list<string>   $warnings
     */
    public function __construct(
        public Project $project,
        public string $today,
        public string $policyOrigin,
        public ?string $targetPhp,
        public ?string $targetLaravel,
        public ?string $targetPhpunit,
        public array $steps,
        public array $warnings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'project' => $this->project->toArray(),
            'today' => $this->today,
            'policy' => $this->policyOrigin,
            'target_php' => $this->targetPhp,
            'target_laravel' => $this->targetLaravel,
            'target_phpunit' => $this->targetPhpunit,
            'steps' => array_map(static fn(PlanStep $s): array => $s->toArray(), $this->steps),
            'warnings' => $this->warnings,
        ];
    }
}
