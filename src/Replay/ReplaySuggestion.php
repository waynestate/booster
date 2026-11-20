<?php

declare(strict_types=1);

namespace Waynestate\Booster\Replay;

use Waynestate\Booster\Hop\HopRegistry;
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
        /** The first plan step most likely to change an answer (a hop marked for extra review), or null when the plan has none. */
        public ?int $riskiestStep = null,
        public ?string $riskiestHop = null,
        /**
         * Every such step, in plan order, with what makes it risky; the
         * first is $riskiestStep.
         *
         * @var list<array{int, string}>
         */
        public array $riskySteps = [],
    ) {}

    /**
     * What makes a hop marked for extra review likely to change what a
     * page answers (bug 9, proof run 2026-11-20: a CakePHP or Slim plan was
     * given the PHP 8.0 reasons).
     */
    public static function why(string $hopId): string
    {
        return match ($hopId) {
            HopRegistry::CAKEPHP_46_TO_5_ID => 'a framework major: sign-in moves from AuthComponent to the Authentication plugin, and CakePHP 5 changes what controllers, the ORM and the templates get and return.',
            HopRegistry::SLIM_3_TO_4_ID => 'a framework major: the bootstrap, routes, request and response, error pages and middleware all move to Slim 4.',
            default => 'loose comparisons of numbers and strings, and warnings that now throw.',
        };
    }

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
        $risky = [];
        foreach ($plan->steps as $step) {
            if ($step->hop->extraReview) {
                $risky[] = [$step->number, $step->hop->id];
            }
        }

        return new self(
            $reason,
            is_file(ReplayFile::locate($appPath, null)),
            $risky[0][0] ?? null,
            $risky[0][1] ?? null,
            $risky,
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
        $risky = $this->riskySteps === [] && $this->riskiestStep !== null && $this->riskiestHop !== null
            ? [[$this->riskiestStep, $this->riskiestHop]]
            : $this->riskySteps;
        foreach ($risky as [$step, $hop]) {
            $lines[] = sprintf(
                'Step %d (%s) is %s most likely to change an answer: %s',
                $step,
                $hop,
                count($risky) === 1 ? 'the hop' : 'one of the hops',
                self::why($hop),
            );
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
