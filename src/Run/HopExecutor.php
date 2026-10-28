<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

use Waynestate\Booster\Plan\Plan;
use Waynestate\Booster\Plan\PlanStep;
use Waynestate\Booster\ToolError;

/**
 * Walks a plan. For each step: preview with Rector, stop if the diff is bigger
 * than the cap, apply, edit composer.json, then run the gate before the next
 * step. It edits the working copy only: no commits, no branches, no pushes.
 */
final class HopExecutor
{
    public function __construct(
        private readonly RectorRunner $rector,
        private readonly ComposerEditor $composer,
        private readonly TargetImageRunner $gate,
        private readonly ProcessRunner $processes,
    ) {}

    public function execute(Plan $plan, RunOptions $options): RunReport
    {
        $path = realpath($plan->project->path);
        if ($path === false) {
            throw new ToolError(sprintf('Path "%s" does not exist.', $plan->project->path));
        }
        if (!$options->dryRun && !$options->allowDirty) {
            $this->assertCleanGitTree($path);
        }

        $baseline = $this->runBaseline($plan, $path, $options);
        if ($baseline->failed() && !$options->allowFailingBaseline) {
            // The suite is red before BOOSTER touches anything, so a later red gate would be
            // unprovable. Stop before the first hop and say so plainly.
            $results = [];
            foreach ($plan->steps as $step) {
                $results[] = new StepResult($step->number, $step->hop->id, StepResult::NOT_RUN, [], [
                    'Not run because the baseline failed: the app\'s tests fail before any change. Fix them or pass --allow-failing-baseline.',
                ]);
            }

            return new RunReport($plan, $options->dryRun, $baseline, $results);
        }

        $results = [];
        foreach ($plan->steps as $step) {
            $result = $this->runStep($step, $path, $plan, $options, $baseline);
            $results[] = $result;
            if ($result->failed()) {
                // Later steps assume this one worked, so they are not attempted.
                foreach ($plan->steps as $later) {
                    if ($later->number > $step->number) {
                        $results[] = new StepResult($later->number, $later->hop->id, StepResult::NOT_RUN, [], ['Not run because step ' . $step->number . ' did not finish.']);
                    }
                }
                break;
            }
        }

        return new RunReport($plan, $options->dryRun, $baseline, $results);
    }

    /**
     * Runs the app's own tests on the PHP the app is on now, before the first
     * hop, so a later red gate can be told apart from a suite that was already
     * red. Skipped (not run) for a dry run or when the gate is skipped.
     */
    private function runBaseline(Plan $plan, string $path, RunOptions $options): BaselineResult
    {
        $php = $this->startingPhp($plan);
        if ($options->dryRun || $options->skipGate || $php === null) {
            return new BaselineResult(BaselineResult::SKIPPED, $php, null);
        }

        $test = $options->testCommand ?? $this->defaultTestCommand($path);
        $result = $this->gate->run($path, $php, $test, $options->proctorCommand);
        $output = self::lastLines($result->stdout . "\n" . $result->stderr, 20);

        return new BaselineResult(
            $result->successful() ? BaselineResult::PASSED : BaselineResult::FAILED,
            $php,
            $result->exitCode,
            $output,
        );
    }

    /**
     * The PHP the planner starts from: the first step's starting version, or the
     * manifest's when the plan has no steps.
     */
    private function startingPhp(Plan $plan): ?string
    {
        if ($plan->steps === []) {
            return $plan->project->phpVersion;
        }

        return $plan->steps[0]->phpBefore ?? $plan->project->phpVersion;
    }

    /**
     * @return list<string>
     */
    private static function lastLines(string $text, int $count): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        $lines = explode("\n", $text);
        if (count($lines) <= $count) {
            return $lines;
        }
        $kept = [];
        foreach (array_slice($lines, -$count) as $line) {
            $kept[] = $line;
        }

        return $kept;
    }

    private function runStep(PlanStep $step, string $path, Plan $plan, RunOptions $options, BaselineResult $baseline): StepResult
    {
        $hop = $step->hop;
        if ($hop->isManual()) {
            return new StepResult($step->number, $hop->id, StepResult::MANUAL, [], ['Follow the playbook; BOOSTER has no automatic change for this hop.', ...$hop->notes]);
        }

        try {
            $preview = $this->rector->run($hop, $path, true, $step->phpAfter ?? '', $step->laravelAfter);
            if ($preview->errors !== []) {
                return new StepResult($step->number, $hop->id, StepResult::ERROR, $preview->files, ['Rector reported errors; nothing was changed.', ...$preview->errors]);
            }
            if ($preview->changedCount() > $options->maxChanges) {
                return new StepResult($step->number, $hop->id, StepResult::CAPPED, $preview->files, [sprintf(
                    'Rector wants to change %d files and the cap is %d. Nothing was changed. Review with --dry-run, then raise --max-changes or use --skip to split the work.',
                    $preview->changedCount(),
                    $options->maxChanges,
                )]);
            }
            if ($options->dryRun) {
                return new StepResult($step->number, $hop->id, StepResult::PREVIEWED, $preview->files, ['Dry run: nothing was changed.', ...$hop->notes]);
            }

            $applied = $this->rector->run($hop, $path, false, $step->phpAfter ?? '', $step->laravelAfter);
            $messages = $this->composer->apply($hop, $path);
            if ($applied->errors !== []) {
                return new StepResult($step->number, $hop->id, StepResult::ERROR, $applied->files, ['Rector reported errors while applying.', ...$applied->errors]);
            }

            if (!$options->skipGate) {
                $gate = $this->runGate($step, $path, $options, $baseline);
                if ($gate !== null) {
                    return new StepResult($step->number, $hop->id, StepResult::GATE_FAILED, $applied->files, [...$messages, ...$gate]);
                }
                $messages[] = 'Gate passed: tests are green on PHP ' . ($step->phpAfter ?? '?') . '.';
            } else {
                $messages[] = 'Gate skipped. Run the tests on PHP ' . ($step->phpAfter ?? '?') . ' before the next step.';
            }

            return new StepResult($step->number, $hop->id, StepResult::APPLIED, $applied->files, [...$messages, ...$hop->notes]);
        } catch (ToolError $e) {
            return new StepResult($step->number, $hop->id, StepResult::ERROR, [], [$e->getMessage()]);
        }
    }

    /**
     * @return list<string>|null failure messages, or null when the gate passed
     */
    private function runGate(PlanStep $step, string $path, RunOptions $options, BaselineResult $baseline): ?array
    {
        $php = $step->phpAfter ?? '';
        $test = $options->testCommand ?? $this->defaultTestCommand($path);
        $result = $this->gate->run($path, $php, $test, $options->proctorCommand);
        if ($result->successful()) {
            return null;
        }

        $messages = [
            sprintf('Gate failed: "%s" exited %d on PHP %s. The working copy keeps the changes so you can read them.', $test, $result->exitCode, $php),
            trim($result->stdout . "\n" . $result->stderr),
            'Undo with: git checkout . (inside the app).',
        ];
        if ($baseline->failed()) {
            $messages[] = 'The baseline was already failing, so this gate failure may predate the upgrade.';
        }

        return $messages;
    }

    private function defaultTestCommand(string $path): string
    {
        $composer = is_file($path . '/composer.json') ? json_decode((string) file_get_contents($path . '/composer.json'), true) : null;
        $scripts = is_array($composer) && is_array($composer['scripts'] ?? null) ? $composer['scripts'] : [];

        return array_key_exists('test', $scripts) ? 'composer test' : 'vendor/bin/phpunit';
    }

    private function assertCleanGitTree(string $path): void
    {
        $status = $this->processes->run(['git', '-C', $path, 'status', '--porcelain'], null, [], 60);
        if (!$status->successful()) {
            throw new ToolError('The app is not a git working copy (or git is missing), so BOOSTER cannot make the change reversible. Put it under git, or pass --allow-dirty.');
        }
        if (trim($status->stdout) !== '') {
            throw new ToolError('The working copy has uncommitted changes. Commit or stash them so the upgrade diff stands alone, or pass --allow-dirty.');
        }
    }
}
