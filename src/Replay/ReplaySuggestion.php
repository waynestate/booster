<?php

declare(strict_types=1);

namespace Waynestate\Booster\Replay;

use Waynestate\Booster\Plan\Plan;
use Waynestate\Booster\Run\BaselineResult;
use Waynestate\Booster\Run\StockTests;

/**
 * The nudge toward the replay gate: a run whose gate proves little (the
 * built-in syntax check, or a suite of only Laravel's stock examples) and
 * that is not replaying says so once, under the baseline, with the command
 * that records characterization answers instead. Advisory only: it never
 * changes the exit code, and it says nothing when the app has real tests, a
 * --test-command, --replay or --skip-gate.
 */
final readonly class ReplaySuggestion
{
    /** The gate is the built-in php -l check: the app has no tests. */
    public const REASON_SYNTAX = 'syntax';

    /** The gate is Laravel's two stock example tests, which pass on any code. */
    public const REASON_STOCK_TESTS = 'stock-tests';

    public function __construct(
        public string $reason,
        /** Whether booster-replay.json is already in the app, so only --replay is missing. */
        public bool $fileExists,
        /** The plan step most likely to change an answer (the PHP 8.0 hop), or null when the plan has none. */
        public ?int $riskiestStep = null,
        public ?string $riskiestHop = null,
    ) {}

    /**
     * The suggestion for this run, or null when there is nothing to suggest:
     * the run replays already, skips the gate, has no steps, or its gate is
     * the app's own tests (past the stock examples) or the user's command.
     */
    public static function for(Plan $plan, BaselineResult $baseline, string $appPath, bool $replaying, bool $skipGate): ?self
    {
        if ($replaying || $skipGate || $plan->steps === []) {
            return null;
        }
        $reason = match (true) {
            $baseline->gateKind === BaselineResult::GATE_SYNTAX => self::REASON_SYNTAX,
            $baseline->gateKind === BaselineResult::GATE_TESTS && StockTests::onlyStockExamples($appPath) => self::REASON_STOCK_TESTS,
            default => null,
        };
        if ($reason === null) {
            return null;
        }
        $riskiest = null;
        foreach ($plan->steps as $step) {
            if ($step->hop->extraReview) {
                $riskiest = $step;
                break;
            }
        }

        return new self(
            $reason,
            is_file(ReplayFile::locate($appPath, null)),
            $riskiest?->number,
            $riskiest?->hop->id,
        );
    }

    /**
     * The lines a person reads, under the baseline. $pathArg is the app's
     * --path value, already quoted for the shell.
     *
     * @return list<string>
     */
    public function lines(string $program, string $pathArg): array
    {
        $gate = $this->reason === self::REASON_SYNTAX
            ? 'the gate only proves every PHP file parses'
            : "the gate is only Laravel's stock example tests";
        $lines = [sprintf('Replay: off, and %s, so a hop can change what a page answers and still pass.', $gate)];
        $lines[] = $this->fileExists
            ? sprintf('%s is already in the app: run again with --replay to record its answers before the first hop and compare them after every hop.', ReplayFile::DEFAULT_NAME)
            : sprintf('Record the answers first: %s replay init --path=%s drafts %s from the routes; fill in its todo items, then run again with --replay.', $program, $pathArg, ReplayFile::DEFAULT_NAME);
        if ($this->riskiestStep !== null && $this->riskiestHop !== null) {
            $lines[] = sprintf('Step %d (%s) is the hop most likely to change an answer: loose comparisons of numbers and strings, and warnings that now throw.', $this->riskiestStep, $this->riskiestHop);
        }

        return $lines;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'reason' => $this->reason,
            'file_exists' => $this->fileExists,
            'riskiest_step' => $this->riskiestStep,
            'riskiest_hop' => $this->riskiestHop,
        ];
    }
}
