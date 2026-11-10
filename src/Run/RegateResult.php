<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

/**
 * The re-gate before step 1: the gate of the hop that stopped the last run,
 * run again because that hop is no longer in the plan (its edits are in
 * place). A red re-gate stops the run before the first step, the way a red
 * baseline does.
 */
final readonly class RegateResult
{
    /**
     * @param list<string> $lines what ran and how it went, for the report
     */
    public function __construct(
        public string $hopId,
        public bool $passed,
        public array $lines,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'hop' => $this->hopId,
            'status' => $this->passed ? 'passed' : StepResult::GATE_FAILED,
            'lines' => $this->lines,
        ];
    }
}
