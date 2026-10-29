<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

use Waynestate\Booster\Hop\Hop;
use Waynestate\Booster\Hop\HopKind;
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
        private readonly ?NodeRunner $node = null,
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
            if ($hop->kind === HopKind::Node) {
                return $this->runNodeHop($step, $path, $options, $baseline);
            }

            $phpunitTarget = $hop->kind === HopKind::Phpunit ? $hop->to : null;
            $preview = $this->rector->run($hop, $path, true, $step->phpAfter ?? '', $step->laravelAfter, $phpunitTarget);
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
                // A Laravel hop previews through the app's own autoloader; without vendor/ Rector
                // cannot see the app's classes, so the diff is a lower bound, not the whole change.
                $incomplete = $hop->kind === HopKind::Laravel && !is_file($path . '/vendor/autoload.php');
                if ($incomplete) {
                    $messages = [sprintf('Preview incomplete: %s needs the app\'s vendor/ to recognise Laravel classes, and it is missing. Run composer install in the app, then dry-run again.', $hop->id), ...$hop->notes];
                } elseif ($preview->files === []) {
                    $messages = ['Dry run: nothing was changed.', ...$hop->notes];
                } else {
                    $messages = $hop->notes;
                }

                return new StepResult($step->number, $hop->id, StepResult::PREVIEWED, $preview->files, $messages, $incomplete);
            }

            $applied = $this->rector->run($hop, $path, false, $step->phpAfter ?? '', $step->laravelAfter, $phpunitTarget);
            $messages = $this->composer->apply($hop, $path);
            if ($applied->errors !== []) {
                return new StepResult($step->number, $hop->id, StepResult::ERROR, $applied->files, ['Rector reported errors while applying.', ...$applied->errors]);
            }
            if ($applied->changedCount() === 0 && $messages === []) {
                // Nothing changed, so there is nothing for the gate to prove; skip it.
                return new StepResult($step->number, $hop->id, StepResult::NOTHING_TO_DO, [], [
                    'Nothing to do: Rector changed no files and composer.json already allows the target, so the gate was skipped.',
                    ...$hop->notes,
                ]);
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

    /**
     * A Node hop edits package.json and .nvmrc instead of running Rector. The
     * build gate (when the hop asks for one) runs npm ci and npm run build in
     * a node container; otherwise the usual test gate applies.
     */
    private function runNodeHop(PlanStep $step, string $path, RunOptions $options, BaselineResult $baseline): StepResult
    {
        $hop = $step->hop;
        if ($options->dryRun) {
            return new StepResult($step->number, $hop->id, StepResult::PREVIEWED, [], [
                sprintf('Dry run: would set %s in package.json and write .nvmrc with %s.', implode(', ', self::npmSetPhrases($hop->npmSet)), $hop->to),
                ...$hop->notes,
            ]);
        }

        [$changes, $files] = $this->applyNodeChanges($hop, $path);
        if ($changes === []) {
            return new StepResult($step->number, $hop->id, StepResult::NOTHING_TO_DO, [], [
                'Nothing to do: package.json already sets ' . implode(' and ', self::npmSetPhrases($hop->npmSet)) . ' and .nvmrc already pins ' . $hop->to . ', so the gate was skipped.',
                ...$hop->notes,
            ]);
        }

        if ($hop->buildGate) {
            $failure = $this->runBuildGate($path);
            if ($failure !== null) {
                return new StepResult($step->number, $hop->id, StepResult::GATE_FAILED, $files, [...$changes, ...$failure]);
            }
            $changes[] = 'Build gate passed: npm ci and npm run build are green in the Node container.';
        } elseif (!$options->skipGate) {
            $gate = $this->runGate($step, $path, $options, $baseline);
            if ($gate !== null) {
                return new StepResult($step->number, $hop->id, StepResult::GATE_FAILED, $files, [...$changes, ...$gate]);
            }
            $changes[] = 'Gate passed: tests are green on PHP ' . ($step->phpAfter ?? '?') . '.';
        } else {
            $changes[] = 'Gate skipped. Run the tests on PHP ' . ($step->phpAfter ?? '?') . ' before the next step.';
        }

        return new StepResult($step->number, $hop->id, StepResult::APPLIED, $files, [...$changes, ...$hop->notes]);
    }

    /**
     * Applies the hop's package.json values and .nvmrc pin.
     *
     * @return array{0: list<string>, 1: list<array{file: string, diff: string}>} what changed and which files
     */
    private function applyNodeChanges(Hop $hop, string $path): array
    {
        $changes = [];
        $files = [];
        $packageFile = $path . '/package.json';

        if (is_file($packageFile)) {
            $package = json_decode((string) file_get_contents($packageFile), true);
            if (!is_array($package)) {
                throw new ToolError(sprintf('package.json is not valid JSON: %s', $packageFile));
            }
            $edited = false;
            foreach ($hop->npmSet as $key => $value) {
                if (self::getNested($package, $key) !== $value) {
                    $package = self::setNested($package, $key, $value);
                    $changes[] = 'package.json: ' . $key . ' to ' . $value;
                    $files[] = ['file' => 'package.json', 'diff' => $key . ' to ' . $value];
                    $edited = true;
                }
            }
            if ($edited) {
                file_put_contents($packageFile, json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
            }
        }

        $nvmrc = $path . '/.nvmrc';
        if (!is_file($nvmrc) || trim((string) file_get_contents($nvmrc)) !== $hop->to) {
            file_put_contents($nvmrc, $hop->to . "\n");
            $changes[] = '.nvmrc: ' . $hop->to;
            $files[] = ['file' => '.nvmrc', 'diff' => 'pins ' . $hop->to];
        }

        return [$changes, $files];
    }

    /**
     * @return list<string>|null failure messages, or null when the build passed
     */
    private function runBuildGate(string $path): ?array
    {
        if ($this->node === null) {
            return ['The build gate needs a Node runner, but this BOOSTER has none configured.'];
        }
        foreach ([['npm', 'ci'], ['npm', 'run', 'build']] as $argv) {
            $result = $this->node->run($path, $argv);
            if (!$result->successful()) {
                return [
                    sprintf('Build gate failed: "%s" exited %d in the Node container. The working copy keeps the changes so you can read them.', implode(' ', $argv), $result->exitCode),
                    trim($result->stdout . "\n" . $result->stderr),
                    'Undo with: git checkout . (inside the app).',
                ];
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $npmSet
     *
     * @return list<string>
     */
    private static function npmSetPhrases(array $npmSet): array
    {
        $phrases = [];
        foreach ($npmSet as $key => $value) {
            $phrases[] = $key . ' to ' . $value;
        }

        return $phrases;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return mixed
     */
    private static function getNested(array $data, string $dotPath): mixed
    {
        $current = $data;
        foreach (explode('.', $dotPath) as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return null;
            }
            $current = $current[$part];
        }

        return $current;
    }

    /**
     * @param array<string, mixed> $data
     * @param mixed                $value
     *
     * @return array<string, mixed>
     */
    private static function setNested(array $data, string $dotPath, mixed $value): array
    {
        $parts = explode('.', $dotPath);
        $target = &$data;
        foreach ($parts as $i => $part) {
            if ($i === count($parts) - 1) {
                $target[$part] = $value;
                break;
            }
            if (!isset($target[$part]) || !is_array($target[$part])) {
                $target[$part] = [];
            }
            $target = &$target[$part];
        }

        return $data;
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
