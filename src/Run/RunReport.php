<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

use Waynestate\Booster\Plan\Plan;
use Waynestate\Booster\Replay\ReplayOutcome;
use Waynestate\Booster\Replay\ReplaySuggestion;

final readonly class RunReport
{
    /**
     * @param list<StepResult> $results
     */
    public function __construct(
        public Plan $plan,
        public bool $dryRun,
        public BaselineResult $baseline,
        public array $results,
        public ?ProctorResult $proctor = null,
        public bool $proctorAdvisory = false,
        public ?ProctorComparison $proctorComparison = null,
        /** @var list<string> keys of the --test-env variables passed to the baseline and gates; values never appear */
        public array $testEnvKeys = [],
        /** The deployments/ script this run wrote because composer.lock changed, or null when it wrote none. */
        public ?string $deployScript = null,
        /** The replay baseline (--replay): recorded, failed or skipped; null when the run did not replay. */
        public ?ReplayOutcome $replay = null,
        /** The rector/rector version that ran the hops, or null when no Rector hop ran. */
        public ?string $rectorVersion = null,
        /** The nudge toward --replay when the gate proves little and the run does not replay; null otherwise. Advisory. */
        public ?ReplaySuggestion $replaySuggestion = null,
    ) {}

    public function failed(): bool
    {
        if ($this->baseline->failed() || ($this->replay?->failed() ?? false)) {
            return true;
        }
        foreach ($this->results as $result) {
            if ($result->failed()) {
                return true;
            }
        }
        if ($this->proctor === null || $this->proctorAdvisory) {
            return false;
        }
        $comparison = $this->proctorComparison;

        return $comparison !== null ? $comparison->blocking : $this->proctor->blocking();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'tool' => 'booster',
            'dry_run' => $this->dryRun,
            'test_env' => $this->testEnvKeys,
            'result' => $this->failed() ? 'fail' : 'pass',
            'baseline' => $this->baseline->toArray(),
            'plan' => $this->plan->toArray(),
            'results' => array_map(static fn(StepResult $r): array => $r->toArray(), $this->results),
            'proctor' => $this->proctorArray(),
            'proctor_advisory' => $this->proctorAdvisory,
            'deploy_script' => $this->deployScript,
            'rector_version' => $this->rectorVersion,
        ];
        // Only runs with --replay carry the key, so every other report keeps its shape.
        if ($this->replay !== null) {
            $data['replay'] = $this->replay->toArray();
        }
        // Likewise only a run that was nudged toward --replay carries this key.
        if ($this->replaySuggestion !== null) {
            $data['replay_suggestion'] = $this->replaySuggestion->toArray();
        }

        return $data;
    }

    /**
     * The PROCTOR section of the JSON report: the final run, whether it blocks
     * the run, its failing findings split into new, already present and fixed
     * against the baseline measured before the first hop, and why the baseline
     * could not be taken when it was not.
     *
     * @return array<string, mixed>|null
     */
    private function proctorArray(): ?array
    {
        if ($this->proctor === null) {
            return null;
        }
        $comparison = $this->proctorComparison;
        $blocking = $comparison?->blocking;
        $new = $comparison?->new;
        $alreadyPresent = $comparison?->alreadyPresent;
        $fixed = $comparison?->fixed;
        $baselineTaken = $comparison?->baselineTaken;

        return [
            'status' => $this->proctor->status,
            'exit_code' => $this->proctor->exitCode,
            'php' => $this->proctor->php,
            'failures' => $this->proctor->failures,
            'error' => $this->proctor->error,
            'blocking' => $blocking ?? $this->proctor->blocking(),
            'new' => $new ?? [],
            'already_present' => $alreadyPresent ?? [],
            'fixed' => $fixed ?? [],
            'baseline' => [
                'taken' => $baselineTaken ?? false,
                'error' => $comparison?->baselineError,
            ],
        ];
    }
}
