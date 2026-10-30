<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Waynestate\Booster\Hop\Hop;
use Waynestate\Booster\Hop\HopKind;
use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Node\MixBuild;
use Waynestate\Booster\Node\MixFile;
use Waynestate\Booster\Plan\Plan;
use Waynestate\Booster\Plan\PlanStep;
use Waynestate\Booster\Prepass\NewByReferenceFixer;
use Waynestate\Booster\Prepass\PrepassResult;
use Waynestate\Booster\Project\AppType;
use Waynestate\Booster\Refresh\HopDataRefresher;
use Waynestate\Booster\Report\TextWriter;
use Waynestate\Booster\Scan\RemovalsScan;
use Waynestate\Booster\ToolError;

/**
 * Walks a plan. For each step: preview with Rector, ask or stop when the diff
 * is over the review threshold (or the --max-changes hard limit), apply, move
 * the CAS config and model traits on the SESAME hop, edit composer.json, then
 * run the gate before the next step. It edits the working copy only: no
 * commits, no branches, no pushes.
 */
final class HopExecutor
{
    public function __construct(
        private readonly RectorRunner $rector,
        private readonly ComposerEditor $composer,
        private readonly TargetImageRunner $gate,
        private readonly ProcessRunner $processes,
        private readonly ?NodeRunner $node = null,
        private readonly ?HopRegistry $registry = null,
        private readonly ?ProctorRunner $proctor = null,
        private readonly CasMigration $casMigration = new CasMigration(),
    ) {}

    private ?RemovalsScan $removals = null;

    /**
     * Untracked paths the app held before the run, so undo hints name only
     * this run's creations.
     *
     * @var list<string>
     */
    private array $startingUntracked = [];

    private function registry(): HopRegistry
    {
        return $this->registry ?? new HopRegistry();
    }

    private ?NewByReferenceFixer $fixer = null;

    private function fixer(): NewByReferenceFixer
    {
        return $this->fixer ??= new NewByReferenceFixer();
    }

    private function removalsScanner(): RemovalsScan
    {
        return $this->removals ??= new RemovalsScan();
    }

    public function execute(Plan $plan, RunOptions $options): RunReport
    {
        $path = realpath($plan->project->path);
        if ($path === false) {
            throw new ToolError(sprintf('Path "%s" does not exist.', $plan->project->path));
        }
        if (!$options->dryRun && !$options->allowDirty) {
            $this->assertCleanGitTree($path);
        }
        // Files the user already held untracked, so the undo hint never tells
        // them to delete their own work. A checked clean tree holds none, and a
        // dry run creates nothing, so only an --allow-dirty real run needs the
        // status read.
        $this->startingUntracked = !$options->dryRun && $options->allowDirty ? $this->untrackedPaths($path) : [];

        $baseline = $this->runBaseline($plan, $path, $options);
        if ($baseline->failed() && !$options->allowFailingBaseline) {
            // The suite is red (or never ran) before Booster touches anything, so a later red
            // gate would be unprovable. Stop before the first hop and say so plainly.
            $reason = $baseline->status === BaselineResult::INSTALL_FAILED
                ? sprintf('composer install failed (exit %d) before the app\'s tests ran. Fix the app\'s composer.json and composer.lock, or pass --allow-failing-baseline.', $baseline->exitCode ?? -1)
                : "the app's tests fail before any change. Fix them or pass --allow-failing-baseline.";
            $results = [];
            foreach ($plan->steps as $step) {
                $results[] = new StepResult($step->number, $step->hop->id, StepResult::NOT_RUN, [], [
                    'Not run because the baseline failed: ' . $reason,
                ]);
            }

            return new RunReport($plan, $options->dryRun, $baseline, $results);
        }

        $results = [];
        // Measure the app against the CREED policy on the PHP it is on now, so
        // the final gate can tell pre-existing findings from ones the upgrade
        // introduces.
        $proctorBaseline = $this->runProctorBaseline($plan, $path, $options);
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

        $proctor = $this->runProctorGate($plan, $path, $options, $results);
        $comparison = $proctor === null ? null : ProctorComparison::compare($proctorBaseline, $proctor);

        return new RunReport($plan, $options->dryRun, $baseline, $results, $proctor, $options->proctorAdvisory, $comparison);
    }

    /**
     * The PROCTOR baseline: measure the app against the CREED policy on the
     * PHP it is on now, before the first hop, so the final gate can tell
     * pre-existing findings from ones the upgrade introduces. Skipped for dry
     * runs, when the gate is skipped, or when the plan has no steps, like the
     * final run. A tool error is recorded as a tripped baseline so the final
     * gate falls back to blocking on any failure and says why.
     */
    private function runProctorBaseline(Plan $plan, string $path, RunOptions $options): ?ProctorResult
    {
        if ($options->dryRun || $options->skipGate || $plan->steps === [] || $this->proctor === null) {
            return null;
        }
        $php = $this->startingPhp($plan);
        if ($php === null) {
            return null;
        }

        try {
            return $this->proctor->run($path, $php, $plan->policyOrigin, $plan->today);
        } catch (ToolError $e) {
            return new ProctorResult(ProctorResult::TRIPPED, 2, $php, [], $e->getMessage());
        }
    }

    /**
     * The PROCTOR gate: once every step finished without failing, measure the
     * upgraded app against the CREED policy on the final PHP. Skipped for dry
     * runs, when the gate is skipped, when the plan has no steps, or when a
     * step left the run unfinished.
     *
     * @param list<StepResult> $results
     */
    private function runProctorGate(Plan $plan, string $path, RunOptions $options, array $results): ?ProctorResult
    {
        if ($options->dryRun || $options->skipGate || $plan->steps === [] || $this->proctor === null) {
            return null;
        }
        foreach ($results as $result) {
            if ($result->failed() || $result->status === StepResult::NOT_RUN) {
                return null;
            }
        }
        $php = $plan->targetPhp ?? $this->startingPhp($plan);
        if ($php === null) {
            return null;
        }

        return $this->proctor->run($path, $php, $plan->policyOrigin, $plan->today);
    }

    /**
     * Runs the app's own tests on the PHP the app is on now, before the first
     * hop, so a later red gate can be told apart from a suite that was already
     * red. Skipped (not run) for a dry run or when the gate is skipped.
     */
    private function runBaseline(Plan $plan, string $path, RunOptions $options): BaselineResult
    {
        $php = $this->startingPhp($plan);
        // The scan is cheap and must happen even when the baseline itself is
        // skipped, so a dry run still names a suite that proves nothing.
        $warning = StockTests::onlyStockExamples($path)
            ? "The app's tests are only Laravel's stock examples, so the gate cannot prove anything. See \"A smoke test for WSU apps\" in the README."
            : null;
        if ($options->dryRun || $options->skipGate || $php === null) {
            return new BaselineResult(BaselineResult::SKIPPED, $php, null, [], $warning);
        }

        $test = $options->testCommand ?? $this->defaultTestCommand($path);
        $result = $this->gate->run($path, $php, $test, $options->proctorCommand, $options->gateImage);
        $outcome = TargetImageRunner::gateOutcome($result);
        $status = match (true) {
            $outcome->successful() => BaselineResult::PASSED,
            $outcome->phase === GateOutcome::PHASE_INSTALL => BaselineResult::INSTALL_FAILED,
            default => BaselineResult::FAILED,
        };

        return new BaselineResult($status, $php, $outcome->exitCode, $outcome->output, $warning);
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
     * Runs one step. For PHP hops it first lists what the language removes
     * between the hop's two versions: an advisory in the step's messages,
     * never a failure and never a reason to stop.
     */
    private function runStep(PlanStep $step, string $path, Plan $plan, RunOptions $options, BaselineResult $baseline): StepResult
    {
        // Scan first: the advisory must describe the app as it was before the hop rewrites it.
        $removals = $this->removalsLines($step, $path);
        $result = $this->runStepCore($step, $path, $plan, $options, $baseline);
        if ($removals === '') {
            return $result;
        }

        return new StepResult($step->number, $step->hop->id, $result->status, $result->files, [$removals, ...$result->messages], $result->incomplete, $result->prepass);
    }

    /**
     * The advisory removals block for a PHP hop, or '' when the hop is not a
     * PHP hop or the app has no uses of removed functions or constructs.
     */
    private function removalsLines(PlanStep $step, string $path): string
    {
        if ($step->hop->kind !== HopKind::Php || $step->phpBefore === null || $step->phpAfter === null) {
            return '';
        }
        $result = $this->removalsScanner()->scan($path, $step->phpBefore, $step->phpAfter);
        if ($result->total === 0) {
            return '';
        }

        return implode("\n", (new TextWriter())->removals($result));
    }

    private function runStepCore(PlanStep $step, string $path, Plan $plan, RunOptions $options, BaselineResult $baseline): StepResult
    {
        $hop = $step->hop;
        if ($hop->isManual()) {
            return new StepResult($step->number, $hop->id, StepResult::MANUAL, [], ['Follow the playbook; BOOSTER has no automatic change for this hop.', ...$hop->notes]);
        }

        try {
            if ($hop->kind === HopKind::Node) {
                return match ($hop->id) {
                    HopRegistry::MIX_TO_VITE_ID => $this->runMixHop($step, $path, $plan, $options, $baseline),
                    HopRegistry::TAILWIND_ID => $this->runTailwindHop($step, $path, $options, $baseline),
                    HopRegistry::VUE_COMPAT_CODEMOD_ID => $this->runVueCompatCodemodHop($step, $path, $options),
                    default => $this->runNodeHop($step, $path, $options, $baseline),
                };
            }

            // PHP hops get the new-by-reference pre-pass first: modern PHP refuses to compile
            // "$a = &new Foo();", so without the rewrite Rector never gets a parse tree and the
            // hop stops with nothing changed. The rewrite lands on the working copy, and a dry
            // run puts every original byte back.
            $prepass = $hop->kind === HopKind::Php ? $this->fixer()->fixTree($path, true) : null;
            $keepPrepass = false;
            try {
                $phpunitTarget = $hop->kind === HopKind::Phpunit ? $hop->to : null;
                $preview = $this->rector->run($hop, $path, true, $step->phpAfter ?? '', $step->laravelAfter, $phpunitTarget, $options->memoryLimit);
                [$stopErrors, $notes] = self::splitRepairedNotes($preview->errors);
                if ($stopErrors !== []) {
                    if ($options->dryRun && $hop->kind === HopKind::Laravel && self::staleVendorErrors($stopErrors)) {
                        // The app's vendor/ still holds an older Laravel, so Rector cannot see the
                        // classes this hop's rules work on. Name that and point at the earlier hops
                        // instead of a wall of "class not found" errors; a real run must still stop.
                        return new StepResult($step->number, $hop->id, StepResult::PREVIEWED, [...self::prepassFiles($prepass), ...$preview->files], [sprintf(
                            "Can't preview %s yet: the app's vendor/ is from an older Laravel, so Rector cannot see the classes this hop uses. Apply the earlier Laravel hops (and composer update), then dry-run again.",
                            $hop->id,
                        ), ...$notes], true, $prepass?->files() ?? []);
                    }

                    return new StepResult($step->number, $hop->id, StepResult::ERROR, [...self::prepassFiles($prepass), ...$preview->files], ['Rector reported errors; nothing was changed.', ...self::refusals($stopErrors), ...$notes], false, $prepass?->files() ?? []);
                }
                $wanted = $preview->changedCount() + ($prepass?->changedCount() ?? 0);
                // The per-rule breakdown only makes sense for the hops whose changes are all Rector's.
                $rules = $hop->kind === HopKind::Php || $hop->kind === HopKind::Phpunit ? $preview->rules() : [];
                // A --max-changes of 0 lifts the limit entirely; only a positive number caps.
                $overExplicitCap = $options->maxChanges !== null && $options->maxChanges > 0 && $wanted > $options->maxChanges;
                $overThreshold = $options->maxChanges === null && $wanted > $options->reviewThreshold;
                if (!$options->dryRun && $overExplicitCap) {
                    return new StepResult($step->number, $hop->id, StepResult::CAPPED, [...self::prepassFiles($prepass), ...$preview->files], [sprintf(
                        'This hop wants to change %d files and --max-changes is %d. Nothing was changed.',
                        $wanted,
                        $options->maxChanges,
                    ), ...$notes], false, $prepass?->files() ?? [], $rules);
                }
                if (!$options->dryRun && $overThreshold) {
                    $summary = self::summaryLines($prepass, $preview);
                    $apply = $options->confirm !== null && ($options->confirm)($wanted, $summary);
                    if (!$apply) {
                        // When the prompt already showed the summary, do not print it twice.
                        return new StepResult($step->number, $hop->id, StepResult::CAPPED, [...self::prepassFiles($prepass), ...$preview->files], [sprintf(
                            'This hop wants to change %d files, over the review threshold of %d. Nothing was changed. Read the per-rule summary, then run again with --max-changes=%d to apply it, or --max-changes=0 for no limit.',
                            $wanted,
                            $options->reviewThreshold,
                            $wanted,
                        ), ...($options->confirm === null ? $summary : []), ...$notes], false, $prepass?->files() ?? [], $rules);
                    }
                }
                if ($options->dryRun) {
                    // A Laravel hop previews through the app's own autoloader; without vendor/ Rector
                    // cannot see the app's classes, so the diff is a lower bound, not the whole change.
                    $incomplete = $hop->kind === HopKind::Laravel && !is_file($path . '/vendor/autoload.php');
                    $migration = $hop->kind === HopKind::Sesame ? $this->casMigration->migrate($path, true) : null;
                    [$inertiaChanges, $inertiaFiles] = $this->inertiaChangesFor($hop, $step, $plan, true);
                    $files = [...self::prepassFiles($prepass), ...$preview->files, ...($migration->files ?? []), ...$inertiaFiles];
                    $threshold = $overThreshold ? [sprintf(
                        'Over the review threshold: this hop changes %d files (threshold %d). Read the per-rule summary below.',
                        $wanted,
                        $options->reviewThreshold,
                    ), ...self::summaryLines($prepass, $preview)] : [];
                    // A real run's gate refreshes the lock for these hops; say so now.
                    $update = $this->lockUpdatePackages($step);
                    $wouldRun = $update === [] ? [] : [sprintf('would run: composer update -W %s', implode(' ', $update))];
                    if ($incomplete) {
                        $messages = [...$threshold, ...$wouldRun, sprintf('Preview incomplete: %s needs the app\'s vendor/ to recognise Laravel classes, and it is missing. Run composer install in the app, then dry-run again.', $hop->id), ...$inertiaChanges, ...$hop->notes, ...$notes];
                    } elseif ($files === []) {
                        $messages = [...$threshold, ...$wouldRun, 'Dry run: nothing was changed.', ...($migration->manual ?? []), ...$hop->notes, ...$notes];
                    } else {
                        $messages = [...$threshold, ...$wouldRun, ...($migration->changes ?? []), ...$inertiaChanges, ...($migration->manual ?? []), ...$hop->notes, ...$notes];
                    }

                    return new StepResult($step->number, $hop->id, StepResult::PREVIEWED, $files, $messages, $incomplete, $prepass?->files() ?? [], $rules);
                }

                $keepPrepass = true;
                $applied = $this->rector->run($hop, $path, false, $step->phpAfter ?? '', $step->laravelAfter, $phpunitTarget, $options->memoryLimit);
                $messages = $this->composer->apply($hop, $path);
                [$appliedErrors, $appliedNotes] = self::splitRepairedNotes($applied->errors);
                foreach ($appliedNotes as $note) {
                    if (!in_array($note, $notes, true)) {
                        $notes[] = $note;
                    }
                }
                if ($appliedErrors !== []) {
                    return new StepResult($step->number, $hop->id, StepResult::ERROR, [...self::prepassFiles($prepass), ...$applied->files], ['Rector reported errors while applying.', ...self::refusals($appliedErrors), ...$notes], false, $prepass?->files() ?? []);
                }
                if ($hop->kind === HopKind::Laravel) {
                    // Rector rewrites code but does not tidy imports, so a rename
                    // leaves a dead `use` behind. Clean the files this hop changed.
                    $importsRemoved = 0;
                    $importFiles = 0;
                    foreach ($applied->files as $entry) {
                        $file = $entry['file'];
                        if (!str_ends_with($file, '.php')) {
                            continue;
                        }
                        $absolute = str_starts_with($file, '/') || preg_match('#^[A-Za-z]:/#', $file) === 1 ? $file : $path . '/' . $file;
                        $removed = UnusedImports::cleanFile($absolute);
                        if ($removed > 0) {
                            $importsRemoved += $removed;
                            $importFiles++;
                        }
                    }
                    if ($importsRemoved > 0) {
                        $messages[] = sprintf(
                            'Removed %d unused import%s from %d file%s after the Rector pass.',
                            $importsRemoved,
                            $importsRemoved === 1 ? '' : 's',
                            $importFiles,
                            $importFiles === 1 ? '' : 's',
                        );
                    }
                }
                // The CAS config and model traits move only after Rector succeeded,
                // because they assume the class renames are in place.
                $migration = $hop->kind === HopKind::Sesame ? $this->casMigration->migrate($path, false) : null;
                [$inertiaChanges, $inertiaFiles] = $this->inertiaChangesFor($hop, $step, $plan, false);
                $files = [...self::prepassFiles($prepass), ...$applied->files, ...($migration->files ?? []), ...$inertiaFiles];
                if (($prepass?->changedCount() ?? 0) === 0 && $applied->changedCount() === 0 && $messages === [] && ($migration->changes ?? []) === [] && $inertiaChanges === []) {
                    // Nothing changed, so there is nothing for the gate to prove; skip it.
                    return new StepResult($step->number, $hop->id, StepResult::NOTHING_TO_DO, [], [
                        'Nothing to do: Rector changed no files and composer.json already allows the target, so the gate was skipped.',
                        ...($migration->manual ?? []),
                        ...$hop->notes,
                        ...$notes,
                    ]);
                }

                if (!$options->skipGate) {
                    $gate = $this->runGate($step, $path, $options, $baseline);
                    if ($gate !== null) {
                        return new StepResult($step->number, $hop->id, StepResult::GATE_FAILED, $files, [...$messages, ...($migration->changes ?? []), ...$inertiaChanges, ...($migration->manual ?? []), ...$gate, ...$notes], false, $prepass?->files() ?? []);
                    }
                    $messages[] = 'Gate passed: tests are green on PHP ' . ($step->phpAfter ?? '?') . '.';
                    $update = $this->lockUpdatePackages($step);
                    if ($update !== []) {
                        $messages[] = sprintf('The gate refreshed composer.lock (composer update -W %s).', implode(' ', $update));
                    }
                } else {
                    $messages[] = 'Gate skipped. Run the tests on PHP ' . ($step->phpAfter ?? '?') . ' before the next step.';
                }

                return new StepResult($step->number, $hop->id, StepResult::APPLIED, $files, [...$messages, ...($migration->changes ?? []), ...$inertiaChanges, ...($migration->manual ?? []), ...$hop->notes, ...$notes], false, $prepass?->files() ?? []);
            } finally {
                if ($prepass !== null && !$keepPrepass) {
                    $prepass->restore();
                }
            }
        } catch (ToolError $e) {
            return new StepResult($step->number, $hop->id, StepResult::ERROR, [], [$e->getMessage()]);
        }
    }

    /**
     * Pre-pass fixes as StepResult file entries, so they show in the per-step
     * file list and count as changed files.
     *
     * @return list<array{file: string, diff: string}>
     */
    private static function prepassFiles(?PrepassResult $prepass): array
    {
        if ($prepass === null) {
            return [];
        }

        return array_map(static fn(array $fix): array => [
            'file' => $fix['file'],
            'diff' => sprintf('pre-pass: %d new-by-reference rewrite%s', $fix['rewrites'], $fix['rewrites'] === 1 ? '' : 's'),
        ], $prepass->files());
    }

    /**
     * The per-rule summary for an over-threshold hop: the pre-pass total first
     * when it changed files, then one line per rule, biggest first with ties
     * broken by name. At most 15 rules are listed; the rest collapse into one
     * line, so a hop with hundreds of rules still fits on the screen.
     *
     * @return list<string>
     */
    private static function summaryLines(?PrepassResult $prepass, RectorResult $preview): array
    {
        $lines = [];
        $prepassCount = $prepass?->changedCount() ?? 0;
        if ($prepassCount > 0) {
            $lines[] = sprintf('  new-by-reference pre-pass: %d files', $prepassCount);
        }
        $items = [];
        foreach ($preview->rules() as $name => $count) {
            $items[] = [$count, $name];
        }
        usort($items, static fn(array $a, array $b): int => $b[0] <=> $a[0] ?: strcmp($a[1], $b[1]));
        $shown = 0;
        foreach ($items as [$count, $name]) {
            if ($shown === 15) {
                $lines[] = sprintf('  and %d more rules', count($items) - 15);

                break;
            }
            $lines[] = sprintf('  %s: %d files', $name, $count);
            $shown++;
        }

        return $lines;
    }

    /**
     * Turns Rector parse errors into named refusals: the file (and the line,
     * when the message carries one) is named, and the reader is told that a
     * person is needed. Every other error passes through untouched.
     *
     * @param list<string> $errors
     *
     * @return list<string>
     */
    private static function refusals(array $errors): array
    {
        $out = [];
        foreach ($errors as $error) {
            $out[] = self::refusal($error) ?? $error;
        }

        return $out;
    }

    /**
     * One refusal for a parse error Rector reports as "Syntax error ..." or
     * "Invalid numeric literal": BOOSTER cannot repair those by tooling, so
     * the file needs a person.
     */
    private static function refusal(string $error): ?string
    {
        foreach (['Syntax error', 'Invalid numeric literal'] as $phrase) {
            $at = strpos($error, $phrase);
            if ($at === false) {
                continue;
            }
            $message = substr($error, $at);
            $file = $at >= 2 && substr($error, $at - 2, 2) === ': ' ? substr($error, 0, $at - 2) : null;
            $where = '';
            if ($file !== null) {
                $where = preg_match('/line (\d+)/i', $message, $m) === 1 ? $file . ' line ' . $m[1] : $file;
            }

            return ($where !== '' ? $where . ': ' : '') . '"' . $message . '" cannot be repaired by tooling, so BOOSTER stops here and the file needs a person.';
        }

        return null;
    }

    /**
     * Splits Rector errors into the ones that must stop the run and the
     * informational notes (the UTF-8 repair note). A note never stops a run;
     * it is shown in the step's messages wherever the step ends.
     *
     * @param list<string> $errors
     *
     * @return array{0: list<string>, 1: list<string>} stopping errors, then notes
     */
    private static function splitRepairedNotes(array $errors): array
    {
        $stopping = [];
        $notes = [];
        foreach ($errors as $error) {
            if (str_starts_with($error, RectorResult::REPAIRED_UTF8_NOTE)) {
                $notes[] = $error;
            } else {
                $stopping[] = $error;
            }
        }

        return [$stopping, $notes];
    }

    /**
     * True when at least 90% of the Rector errors are PHPStan failing to find
     * an Illuminate class ("Class Illuminate\... was not found"). That wall is
     * the signature of a Laravel hop previewing against a vendor/ that still
     * holds an older Laravel, not a problem in the app's own code.
     *
     * @param list<string> $errors
     */
    private static function staleVendorErrors(array $errors): bool
    {
        if ($errors === []) {
            return false;
        }
        $matched = 0;
        foreach ($errors as $error) {
            if (preg_match('/Class\s+Illuminate(?:\\\\[A-Za-z0-9_]+)+\s+was not found/', $error) === 1) {
                $matched++;
            }
        }

        return $matched * 10 >= count($errors) * 9;
    }

    /**
     * The packages the gate must refresh in the lock for this step. Laravel
     * and PHPUnit hops raise a package requirement in composer.json, so a
     * plain `composer install` would hold the old lock (or fail on its stale
     * content hash); the gate instead runs `composer update -W` for those
     * packages, and because the app is mounted at /app the refreshed lock
     * lands in the app itself. PHP hops only move the platform requirement,
     * which Composer resolves from the lock, so they keep the plain install.
     *
     * @return list<string>
     */
    private function lockUpdatePackages(PlanStep $step): array
    {
        $hop = $step->hop;
        if ($hop->kind !== HopKind::Laravel && $hop->kind !== HopKind::Phpunit) {
            return [];
        }

        return array_keys($hop->composerSet);
    }

    /**
     * @return list<string>|null failure messages, or null when the gate passed
     */
    private function runGate(PlanStep $step, string $path, RunOptions $options, BaselineResult $baseline): ?array
    {
        $php = $step->phpAfter ?? '';
        $test = $options->testCommand ?? $this->defaultTestCommand($path);
        $update = $this->lockUpdatePackages($step);
        $result = $this->gate->run($path, $php, $test, $options->proctorCommand, $options->gateImage, $update);
        $outcome = TargetImageRunner::gateOutcome($result);
        if ($outcome->successful()) {
            return null;
        }

        // A Composer-phase failure is never reported as the test command
        // exiting N: the tests never ran, so only the Composer phase is named.
        $what = match (true) {
            $outcome->phase !== GateOutcome::PHASE_INSTALL => sprintf('"%s" exited %d', $test, $outcome->exitCode),
            $update === [] => sprintf('composer install failed (exit %d)', $outcome->exitCode),
            default => sprintf('composer update -W %s failed (exit %d)', implode(' ', $update), $outcome->exitCode),
        };
        $messages = [
            sprintf('Gate failed: %s on PHP %s. The working copy keeps the changes so you can read them.', $what, $php),
            ...$outcome->output,
        ];
        if ($update !== []) {
            // Both files are named so the undo line below reads as covering both.
            $messages[] = 'This step changed composer.json and composer.lock.';
        }
        $messages[] = $this->undoHint($path);
        if ($baseline->status === BaselineResult::FAILED) {
            $messages[] = 'The baseline was already failing, so this gate failure may predate the upgrade.';
        } elseif ($baseline->status === BaselineResult::INSTALL_FAILED) {
            $messages[] = 'No baseline to compare against: the baseline\'s composer install failed, so this gate failure cannot be attributed.';
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
            $phrases = self::nodeChangePhrases($hop);

            return new StepResult($step->number, $hop->id, StepResult::PREVIEWED, [], [
                $phrases === [] ? 'Dry run: would edit package.json.' : 'Dry run: would ' . self::joinPhrases($phrases) . '.',
                ...$hop->notes,
            ]);
        }

        [$changes, $files] = $this->applyNodeChanges($hop, $path);
        if ($changes === []) {
            $phrases = self::nodeAlreadyPhrases($hop);

            return new StepResult($step->number, $hop->id, StepResult::NOTHING_TO_DO, [], [
                $phrases === [] ? 'Nothing to do: package.json already matches the hop, so the gate was skipped.' : 'Nothing to do: ' . self::joinPhrases($phrases) . ', so the gate was skipped.',
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
     * The Mix to Vite hop. It converts the simple shape of webpack.mix.js:
     * write vite.config.js, swap laravel-mix for vite and laravel-vite-plugin
     * in package.json, rename MIX_ keys to VITE_ in .env.example, rewrite
     * Blade mix() tags where the mapping is unambiguous, delete
     * webpack.mix.js. A production-only mix.version() block is dropped with a
     * note, because Vite hashes production assets by default. Anything else
     * outside the simple shape is refused with a message that names the
     * offending call.
     */
    private function runMixHop(PlanStep $step, string $path, Plan $plan, RunOptions $options, BaselineResult $baseline): StepResult
    {
        $hop = $step->hop;
        $mixFile = $path . '/' . MixFile::FILE;
        if (!is_file($mixFile)) {
            throw new ToolError(sprintf('%s is missing, so there is nothing to convert.', MixFile::FILE));
        }

        try {
            $build = MixFile::parse((string) file_get_contents($mixFile));
        } catch (ToolError $e) {
            return $options->dryRun
                ? new StepResult($step->number, $hop->id, StepResult::PREVIEWED, [], ['Dry run: ' . $e->getMessage(), ...$hop->notes])
                : new StepResult($step->number, $hop->id, StepResult::ERROR, [], [$e->getMessage(), ...$hop->notes]);
        }

        if ($build->vue && $plan->project->vueMajor === '2') {
            $message = sprintf(
                'Refusing to convert %s: the front end uses Vue 2, but @vitejs/plugin-vue is Vue 3 only. Move to Vue 3 first with the vue-2-to-3 hop, then run this hop again.',
                MixFile::FILE,
            );

            return $options->dryRun
                ? new StepResult($step->number, $hop->id, StepResult::PREVIEWED, [], ['Dry run: ' . $message, ...$hop->notes])
                : new StepResult($step->number, $hop->id, StepResult::ERROR, [], [$message, ...$hop->notes]);
        }

        if ($options->dryRun) {
            return new StepResult($step->number, $hop->id, StepResult::PREVIEWED, [], [
                sprintf(
                    'Dry run: would convert %s (%d entries%s) to Vite: write vite.config.js, swap laravel-mix for vite and laravel-vite-plugin in package.json, rename MIX_ keys in .env.example, rewrite Blade mix() tags where the mapping is unambiguous, delete %s.',
                    MixFile::FILE,
                    count($build->entries),
                    $build->vue ? ' plus Vue' : '',
                    MixFile::FILE,
                ),
                ...$build->notes,
                ...$this->bladeNotes($path, $build),
                ...$hop->notes,
            ]);
        }

        [$changes, $files] = $this->applyMixToVite($path, $build);
        $changes = [...$changes, ...$build->notes, ...$this->bladeNotes($path, $build), ...$this->withoutMixNotes($path)];

        if (!$options->skipGate) {
            $failure = $this->runBuildGate($path);
            if ($failure === null && !is_file($path . '/public/build/manifest.json')) {
                $failure = ['Build gate failed: npm run build finished but public/build/manifest.json is missing. Vite writes it when the build succeeds, so check the build output.'];
            }
            if ($failure !== null) {
                return new StepResult($step->number, $hop->id, StepResult::GATE_FAILED, $files, [...$changes, ...$failure]);
            }
            $changes[] = 'Build gate passed: npm ci and npm run build are green in the Node container, and public/build/manifest.json exists.';
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
     * The Vue compat codemod hop. It runs vue-upgrade-tool over the app's
     * front end in the Node container, keeps every manual item the tool lists
     * as a hop message, and gates on the app's build (plus its tests, when the
     * app defines an npm test script). The tool expects @vue/compat in
     * package.json, so the hop refuses to run before the vue-27-to-compat hop
     * has landed.
     */
    private function runVueCompatCodemodHop(PlanStep $step, string $path, RunOptions $options): StepResult
    {
        $hop = $step->hop;
        $version = $this->registry()->vueUpgradeToolVersion();

        if (!$this->packageHasCompat($path)) {
            $message = 'Refusing to run the codemod: package.json does not list @vue/compat, so the app needs vue-27-to-compat first. Run that hop, then run this hop again.';

            return $options->dryRun
                ? new StepResult($step->number, $hop->id, StepResult::PREVIEWED, [], ['Dry run: ' . $message, ...$hop->notes])
                : new StepResult($step->number, $hop->id, StepResult::ERROR, [], [$message, ...$hop->notes]);
        }

        if ($options->dryRun) {
            return new StepResult($step->number, $hop->id, StepResult::PREVIEWED, [], [
                sprintf('Dry run: would run npx --yes vue-upgrade-tool@%s --files src/**/* in the Node container and gate on the build.', $version),
                ...$hop->notes,
            ]);
        }

        if ($this->node === null) {
            return new StepResult($step->number, $hop->id, StepResult::ERROR, [], ['The codemod needs a Node runner, but this BOOSTER has none configured.', ...$hop->notes]);
        }

        $result = $this->node->run($path, ['npx', '--yes', 'vue-upgrade-tool@' . $version, '--files', 'src/**/*']);
        if (!$result->successful()) {
            return new StepResult($step->number, $hop->id, StepResult::ERROR, [], [
                sprintf('vue-upgrade-tool exited %d in the Node container. The working copy keeps any changes it made, so read them before continuing.', $result->exitCode),
                trim($result->stdout . "\n" . $result->stderr),
                $this->undoHint($path),
                ...$hop->notes,
            ]);
        }

        [$items, $total, $recognized] = self::parseCodemodOutput($result);
        $changes = $recognized
            ? [sprintf('vue-upgrade-tool@%s ran in the Node container; %d manual item(s) remain.', $version, $total)]
            : ['codemod ran; manual items could not be counted, see the tool output'];

        if (!$options->skipGate) {
            $failure = $this->runFrontEndGate($path);
            if ($failure !== null) {
                return new StepResult($step->number, $hop->id, StepResult::GATE_FAILED, [], [...$changes, ...$items, ...$failure]);
            }
            $changes[] = $recognized
                ? sprintf('Build gate passed: npm ci and npm run build are green in the Node container, and %d manual item(s) remain from the codemod.', $total)
                : 'Build gate passed: npm ci and npm run build are green in the Node container.';
        } else {
            $changes[] = 'Gate skipped. Run the tests on PHP ' . ($step->phpAfter ?? '?') . ' before the next step.';
        }

        return new StepResult($step->number, $hop->id, StepResult::APPLIED, [], [...$changes, ...$items, ...$hop->notes]);
    }

    /** Whether the app's package.json lists @vue/compat, the precondition the codemod needs. */
    private function packageHasCompat(string $path): bool
    {
        $packageFile = $path . '/package.json';
        if (!is_file($packageFile)) {
            return false;
        }
        $package = json_decode((string) file_get_contents($packageFile), true);
        if (!is_array($package)) {
            return false;
        }
        foreach (['dependencies', 'devDependencies'] as $section) {
            $list = $package[$section] ?? null;
            if (is_array($list) && array_key_exists('@vue/compat', $list)) {
                return true;
            }
        }

        return false;
    }

    /** The raw codemod output kept in hop messages is trimmed to this many bytes. */
    private const CODEMOD_OUTPUT_CAP = 4000;

    /**
     * The codemod output, turned into hop messages. A line that starts with a
     * file path and a colon is one of the tool's manual items and gets a
     * "Manual item" prefix; anything else (banners, summaries) is kept
     * verbatim so nothing the tool says is lost, but only the file lines
     * count as manual items. The messages are capped at 20 lines, the rest
     * counted.
     *
     * When the output is non-empty but holds no manual item, its shape is not
     * recognised: the hop must not fail on that, so the whole output is kept
     * as one message (trimmed to CODEMOD_OUTPUT_CAP) and flagged uncountable.
     *
     * @return array{0: list<string>, 1: int, 2: bool} the capped messages, the manual item count, and whether the output was recognised
     */
    private static function parseCodemodOutput(ProcessResult $result): array
    {
        $raw = trim($result->stdout . "\n" . $result->stderr);
        if ($raw === '') {
            return [[], 0, true];
        }
        $lines = preg_split('/\R/', $raw) ?: [];
        $items = [];
        $manual = 0;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^[\w.@\-\/]+\.\w{1,8}:\s/i', $line) === 1) {
                $manual++;
                $items[] = 'Manual item: ' . $line;
            } else {
                $items[] = $line;
            }
        }
        if ($manual === 0) {
            if (strlen($raw) > self::CODEMOD_OUTPUT_CAP) {
                $raw = mb_strcut($raw, 0, self::CODEMOD_OUTPUT_CAP, 'UTF-8') . '...';
            }

            return [[$raw], 0, false];
        }
        if (count($items) > 20) {
            $items = [...array_slice($items, 0, 20), sprintf('and %d more', count($items) - 20)];
        }

        return [$items, $manual, true];
    }

    /**
     * The front-end gate for the codemod hop: npm ci, npm run build, and npm
     * test when the app defines that script.
     *
     * @return list<string>|null failure messages, or null when the gate passed
     */
    private function runFrontEndGate(string $path): ?array
    {
        if ($this->node === null) {
            return ['The build gate needs a Node runner, but this BOOSTER has none configured.'];
        }
        $argvs = [['npm', 'ci'], ['npm', 'run', 'build']];
        if ($this->hasNpmTestScript($path)) {
            $argvs[] = ['npm', 'test'];
        }
        foreach ($argvs as $argv) {
            $result = $this->node->run($path, $argv);
            if (!$result->successful()) {
                return [
                    sprintf('Build gate failed: "%s" exited %d in the Node container. The working copy keeps the changes so you can read them.', implode(' ', $argv), $result->exitCode),
                    trim($result->stdout . "\n" . $result->stderr),
                    $this->undoHint($path),
                ];
            }
        }

        return null;
    }

    /** Whether the app's package.json defines an npm test script. */
    private function hasNpmTestScript(string $path): bool
    {
        $packageFile = $path . '/package.json';
        if (!is_file($packageFile)) {
            return false;
        }
        $package = json_decode((string) file_get_contents($packageFile), true);
        $scripts = is_array($package) ? ($package['scripts'] ?? null) : null;

        return is_array($scripts) && array_key_exists('test', $scripts);
    }

    /**
     * The Tailwind 3 to 4 hop. It runs the official @tailwindcss/upgrade tool
     * in the Node container, then normalizes package.json itself: the tool
     * rewrites the CSS and the config, BOOSTER owns the dependency pins.
     */
    private function runTailwindHop(PlanStep $step, string $path, RunOptions $options, BaselineResult $baseline): StepResult
    {
        $hop = $step->hop;
        if ($this->node === null) {
            throw new ToolError('The Tailwind hop needs a Node runner, but this BOOSTER has none configured.');
        }
        $toolVersion = $this->registry()->tailwindUpgradeVersion();
        if ($options->dryRun) {
            return new StepResult($step->number, $hop->id, StepResult::PREVIEWED, [], [
                sprintf('Dry run: would run npx -y %s in the Node container, then normalize package.json (pin tailwindcss to %s, add the matching Vite or PostCSS plugin, remove autoprefixer and postcss-import).', $toolVersion, $this->registry()->tailwindVersion()),
                ...$hop->notes,
            ]);
        }

        $tool = $this->node->run($path, ['npx', '-y', $toolVersion]);
        if (!$tool->successful()) {
            return new StepResult($step->number, $hop->id, StepResult::ERROR, [], [
                sprintf('The Tailwind upgrade tool failed: "npx -y %s" exited %d. The working copy keeps whatever it wrote, so you can read it.', $toolVersion, $tool->exitCode),
                trim($tool->stdout . "\n" . $tool->stderr),
                $this->undoHint($path),
            ]);
        }

        [$changes, $files] = $this->applyTailwindPackages($path);
        if ($changes === []) {
            $changes[] = 'The upgrade tool ran, but package.json already matched the Tailwind 4 pins.';
        }

        if (!$options->skipGate) {
            $failure = $this->runBuildGate($path);
            if ($failure === null) {
                $failure = $this->runGate($step, $path, $options, $baseline);
            }
            if ($failure !== null) {
                return new StepResult($step->number, $hop->id, StepResult::GATE_FAILED, $files, [...$changes, ...$failure]);
            }
            $changes[] = 'Gate passed: npm ci, npm run build and the tests are green.';
        } else {
            $changes[] = 'Gate skipped. Run npm ci, npm run build and the tests before the next step.';
        }

        return new StepResult($step->number, $hop->id, StepResult::APPLIED, $files, [...$changes, ...$hop->notes]);
    }

    /**
     * Writes the Vite shape into the app.
     *
     * @return array{0: list<string>, 1: list<array{file: string, diff: string}>} what changed and which files
     */
    private function applyMixToVite(string $path, MixBuild $build): array
    {
        $changes = [];
        $files = [];
        $mixFile = $path . '/' . MixFile::FILE;

        $config = $this->viteConfig($build);
        $configFile = $path . '/vite.config.js';
        if (!is_file($configFile) || (string) file_get_contents($configFile) !== $config) {
            file_put_contents($configFile, $config);
            $changes[] = 'vite.config.js: written with the laravel-vite-plugin inputs from ' . MixFile::FILE . ($build->vue ? ' plus @vitejs/plugin-vue' : '') . '.';
            $files[] = ['file' => 'vite.config.js', 'diff' => 'written'];
        }

        $packageFile = $path . '/package.json';
        if (is_file($packageFile)) {
            $package = json_decode((string) file_get_contents($packageFile), true);
            if (!is_array($package)) {
                throw new ToolError(sprintf('package.json is not valid JSON: %s', $packageFile));
            }
            $edited = false;
            foreach (['dependencies', 'devDependencies'] as $section) {
                if (is_array($package[$section] ?? null) && array_key_exists('laravel-mix', $package[$section])) {
                    unset($package[$section]['laravel-mix']);
                    $changes[] = 'package.json: removed laravel-mix from ' . $section . '.';
                    $edited = true;
                }
            }
            $registry = $this->registry();
            foreach ([
                'vite' => $registry->viteVersion(),
                'laravel-vite-plugin' => $registry->laravelVitePluginVersion(),
            ] as $name => $version) {
                if (($package['devDependencies'][$name] ?? null) !== $version) {
                    $package['devDependencies'][$name] = $version;
                    $changes[] = 'package.json: devDependencies ' . $name . ' to ' . $version . '.';
                    $edited = true;
                }
            }
            if ($build->vue && ($package['devDependencies']['@vitejs/plugin-vue'] ?? null) !== $registry->vuePluginVersion()) {
                $package['devDependencies']['@vitejs/plugin-vue'] = $registry->vuePluginVersion();
                $changes[] = 'package.json: devDependencies @vitejs/plugin-vue to ' . $registry->vuePluginVersion() . '.';
                $edited = true;
            }
            foreach (['dev' => 'vite', 'build' => 'vite build'] as $script => $command) {
                if (($package['scripts'][$script] ?? null) !== $command) {
                    $package['scripts'][$script] = $command;
                    $changes[] = 'package.json: scripts.' . $script . ' to ' . $command . '.';
                    $edited = true;
                }
            }
            if (($package['type'] ?? null) !== 'module') {
                $package['type'] = 'module';
                $changes[] = 'package.json: type to module.';
                $edited = true;
            }
            if ($edited) {
                file_put_contents($packageFile, json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
                $files[] = ['file' => 'package.json', 'diff' => 'swapped laravel-mix for vite and laravel-vite-plugin'];
            }
        }

        $envFile = $path . '/.env.example';
        if (is_file($envFile)) {
            $lines = explode("\n", (string) file_get_contents($envFile));
            $renamed = 0;
            foreach ($lines as $i => $line) {
                if (str_starts_with($line, 'MIX_')) {
                    $lines[$i] = 'VITE_' . substr($line, 4);
                    $renamed++;
                }
            }
            if ($renamed > 0) {
                file_put_contents($envFile, implode("\n", $lines));
                $changes[] = '.env.example: renamed ' . $renamed . ' MIX_ key' . ($renamed === 1 ? '' : 's') . ' to VITE_.';
                $files[] = ['file' => '.env.example', 'diff' => 'MIX_ keys to VITE_'];
            }
        }

        $this->rewriteBlade($path, $build, $changes, $files);

        unlink($mixFile);
        $changes[] = MixFile::FILE . ': deleted.';
        $files[] = ['file' => MixFile::FILE, 'diff' => 'deleted'];

        return [$changes, $files];
    }

    /**
     * Rewrites Blade mix() script and link tags into one @vite([...]) line per
     * layout, but only when every referenced asset maps to a Mix entry. A file
     * that cannot be moved cleanly is left untouched and earns a manual note.
     *
     * @param list<string>                           $changes
     * @param list<array{file: string, diff: string}> $files
     */
    private function rewriteBlade(string $path, MixBuild $build, array &$changes, array &$files): void
    {
        $map = $build->outputMap();
        foreach ($this->bladeFiles($path . '/resources/views') as $file) {
            $content = (string) file_get_contents($file);
            $tagRefs = self::mixTagRefs($content);
            if ($tagRefs === []) {
                continue;
            }
            // Files that cannot be moved cleanly are left untouched here;
            // bladeNotes() reports them once, in the step result.
            $bareRefs = self::bareMixRefs($content);
            $unmapped = array_values(array_diff($tagRefs, array_keys($map)));
            if ($unmapped !== [] || $bareRefs !== []) {
                continue;
            }
            $inputs = [];
            foreach ($tagRefs as $ref) {
                $input = $map[$ref];
                if (!in_array($input, $inputs, true)) {
                    $inputs[] = $input;
                }
            }
            $directive = '@vite([' . implode(', ', array_map(static fn(string $input): string => "'" . $input . "'", $inputs)) . '])';
            $first = true;
            $rewritten = preg_replace_callback(self::MIX_TAG, static function (array $m) use (&$first, $directive): string {
                if (!$first) {
                    return '';
                }
                $first = false;
                // Groups 1 to 8 are guaranteed by MIX_TAG; PHPStan cannot see
                // through the pattern, so the lookups default to empty.
                $isScript = ($m[2] ?? '') !== '';

                return ($isScript ? ($m[1] ?? '') : ($m[5] ?? '')) . $directive . ($isScript ? ($m[4] ?? '') : ($m[8] ?? ''));
            }, $content);
            if ($rewritten === null || $rewritten === $content) {
                $changes[] = sprintf('%s: BOOSTER could not rewrite the mix() tags; move them by hand.', $this->relative($path, $file));

                continue;
            }
            file_put_contents($file, $rewritten);
            $changes[] = $this->relative($path, $file) . ': mix() tags replaced with ' . $directive . '.';
            $files[] = ['file' => $this->relative($path, $file), 'diff' => 'mix() tags to @vite'];
        }
    }

    /**
     * Manual notes about Blade files Booster will not touch: references with no
     * matching Mix entry, or mix() calls that are not plain script or link tags.
     *
     * @return list<string>
     */
    private function bladeNotes(string $path, MixBuild $build): array
    {
        $map = $build->outputMap();
        $notes = [];
        foreach ($this->bladeFiles($path . '/resources/views') as $file) {
            $content = (string) file_get_contents($file);
            $tagRefs = self::mixTagRefs($content);
            if ($tagRefs === []) {
                continue;
            }
            $bareRefs = self::bareMixRefs($content);
            $unmapped = array_values(array_diff($tagRefs, array_keys($map)));
            if ($unmapped === [] && $bareRefs === []) {
                continue;
            }
            $reason = $unmapped !== [] ? 'references an asset with no matching entry in ' . MixFile::FILE : 'has a mix() call that is not a plain script or link tag';
            $notes[] = sprintf('%s: mix(%s) %s; move it by hand.', $this->relative($path, $file), implode("', '", $tagRefs), $reason);
        }

        return $notes;
    }

    /**
     * Files that still call withoutMix(); the helper becomes withoutVite() and
     * Booster does not rename it on your behalf.
     *
     * @return list<string>
     */
    private function withoutMixNotes(string $path): array
    {
        $notes = [];
        foreach ($this->withoutMixFiles($path) as $file) {
            $notes[] = $file . ': replace withoutMix() with withoutVite() by hand.';
        }

        return $notes;
    }

    /**
     * @return list<string>
     */
    private function withoutMixFiles(string $path): array
    {
        $hits = [];
        foreach ($this->phpFiles($path) as $relative => $absolute) {
            if (str_contains((string) file_get_contents($absolute), 'withoutMix(')) {
                $hits[] = $relative;
            }
        }
        sort($hits);

        return $hits;
    }

    /**
     * The vite.config.js the hop writes: the Mix entries as laravel-vite-plugin
     * inputs, plus the Vue plugin when the Mix file compiled components.
     */
    private function viteConfig(MixBuild $build): string
    {
        $imports = "import { defineConfig } from 'vite';\nimport laravel from 'laravel-vite-plugin';\n";
        $plugins = ['        laravel({', '            input: [', ...array_map(static fn(string $input): string => "                '" . $input . "'", $build->inputs()), '            ]', '            refresh: true', '        })'];
        if ($build->vue) {
            $imports .= "import vue from '@vitejs/plugin-vue';\n";
            $plugins[] = '        vue()';
        }
        $body = [];
        foreach ($plugins as $i => $line) {
            $body[] = $line . ($i < count($plugins) - 1 ? ',' : '');
        }

        return $imports
            . "\n"
            . "export default defineConfig({\n    plugins: [\n"
            . implode("\n", $body)
            . "\n    ],\n});\n";
    }

    /**
     * Every mix() reference inside a script or link tag, in document order.
     *
     * @return list<string>
     */
    private static function mixTagRefs(string $content): array
    {
        $refs = [];
        if (preg_match_all(self::MIX_TAG, $content, $matches, PREG_OFFSET_CAPTURE) !== false) {
            foreach ($matches[0] as $i => $match) {
                $refs[] = [$match[1], $matches[2][$i][1] === -1 ? $matches[7][$i][0] : $matches[3][$i][0]];
            }
        }
        sort($refs);

        return array_map(static fn(array $ref): string => $ref[1], $refs);
    }

    /**
     * mix() references that sit outside any script or link tag.
     *
     * @return list<string>
     */
    private static function bareMixRefs(string $content): array
    {
        $ranges = [];
        if (preg_match_all(self::MIX_TAG, $content, $matches, PREG_OFFSET_CAPTURE) !== false) {
            foreach ($matches[0] as $match) {
                $ranges[] = [$match[1], $match[1] + strlen($match[0])];
            }
        }
        $bare = [];
        if (preg_match_all('/\{\{\s*mix\(\s*([\'"])([^\'"]+)\1\s*\)\s*\}\}/', $content, $calls, PREG_OFFSET_CAPTURE) !== false) {
            foreach ($calls[0] as $i => $call) {
                $inside = false;
                foreach ($ranges as [$start, $end]) {
                    if ($call[1] >= $start && $call[1] < $end) {
                        $inside = true;

                        break;
                    }
                }
                if (!$inside) {
                    $bare[] = $calls[2][$i][0];
                }
            }
        }

        return $bare;
    }

    /**
     * @return list<string> absolute paths, sorted
     */
    private function bladeFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * PHP files outside vendor/, node_modules/, public/ and storage/.
     *
     * @return array<string, string> relative path to absolute path
     */
    private function phpFiles(string $path): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                continue;
            }
            $relative = $this->relative($path, $file->getPathname());
            foreach (['.git/', 'vendor/', 'node_modules/', 'public/', 'storage/', 'bootstrap/cache/'] as $skip) {
                if (str_starts_with($relative, $skip)) {
                    continue 2;
                }
            }
            $files[$relative] = $file->getPathname();
        }

        return $files;
    }

    private function relative(string $base, string $file): string
    {
        $base = str_replace('\\', '/', rtrim($base, '/\\'));
        $file = str_replace('\\', '/', $file);

        return substr($file, strlen($base) + 1);
    }

    /**
     * A script tag whose src is a Blade mix() call, or a link tag whose href
     * is one. Groups 1 to 4 belong to the script branch (indent, quote, asset
     * reference, trailing newline); groups 5 to 8 are the same for the link
     * branch. The quote group can never be empty when its branch matched, so
     * it doubles as the branch discriminator. The lookbehind keeps data-src
     * and data-href out.
     */
    private const MIX_TAG = '/(^[ \t]*)<script\b[^>]*(?<![\w-])src="\{\{\s*mix\(\s*(\x27|\x22)([^\x27\x22]+)\2\s*\)\s*\}\}"[^>]*>\s*<\/script>[ \t]*(\r?\n)?|(^[ \t]*)<link\b[^>]*(?<![\w-])href="\{\{\s*mix\(\s*(\x27|\x22)([^\x27\x22]+)\6\s*\)\s*\}\}"[^>]*>[ \t]*(\r?\n)?/m';

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
            foreach ($hop->npmSetIfPresent as $key => $value) {
                [$section, $name] = self::splitDependencyKey($key);
                if ($section === null || !array_key_exists($name, $package[$section] ?? [])) {
                    continue;
                }
                if (self::getNested($package, $key) !== $value) {
                    $package = self::setNested($package, $key, $value);
                    $changes[] = 'package.json: ' . $key . ' to ' . $value;
                    $files[] = ['file' => 'package.json', 'diff' => $key . ' to ' . $value];
                    $edited = true;
                }
            }
            foreach ($hop->npmRemove as $name) {
                foreach (['dependencies', 'devDependencies'] as $section) {
                    if (is_array($package[$section] ?? null) && array_key_exists($name, $package[$section])) {
                        unset($package[$section][$name]);
                        $changes[] = 'package.json: removed ' . $name . ' from ' . $section;
                        $files[] = ['file' => 'package.json', 'diff' => 'removed ' . $name . ' from ' . $section];
                        $edited = true;
                    }
                }
            }
            if ($edited) {
                file_put_contents($packageFile, json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
            }
        }

        if ($hop->pinNode) {
            $nvmrc = $path . '/.nvmrc';
            if (!is_file($nvmrc) || trim((string) file_get_contents($nvmrc)) !== $hop->to) {
                file_put_contents($nvmrc, $hop->to . "\n");
                $changes[] = '.nvmrc: ' . $hop->to;
                $files[] = ['file' => '.nvmrc', 'diff' => 'pins ' . $hop->to];
            }
        }

        return [$changes, $files];
    }

    /**
     * Normalizes package.json for Tailwind 4 after the upgrade tool ran: pin
     * tailwindcss wherever it sits, add the Vite or PostCSS plugin, remove
     * autoprefixer and postcss-import, which Tailwind 4 folds in.
     *
     * @return array{0: list<string>, 1: list<array{file: string, diff: string}>} what changed and which files
     */
    private function applyTailwindPackages(string $path): array
    {
        $changes = [];
        $files = [];
        $packageFile = $path . '/package.json';
        $package = json_decode((string) file_get_contents($packageFile), true);
        if (!is_array($package)) {
            throw new ToolError(sprintf('package.json is not valid JSON: %s', $packageFile));
        }
        $pin = $this->registry()->tailwindVersion();
        $edited = false;
        foreach (['dependencies', 'devDependencies'] as $section) {
            if (!is_array($package[$section] ?? null)) {
                continue;
            }
            if (array_key_exists('tailwindcss', $package[$section]) && $package[$section]['tailwindcss'] !== $pin) {
                $package[$section]['tailwindcss'] = $pin;
                $changes[] = 'package.json: ' . $section . ' tailwindcss to ' . $pin . '.';
                $edited = true;
            }
            foreach (['autoprefixer', 'postcss-import'] as $obsolete) {
                if (array_key_exists($obsolete, $package[$section])) {
                    unset($package[$section][$obsolete]);
                    $changes[] = 'package.json: removed ' . $obsolete . ' from ' . $section . ', which Tailwind 4 folds in.';
                    $edited = true;
                }
            }
        }
        if (!array_key_exists('tailwindcss', $package['dependencies'] ?? []) && !array_key_exists('tailwindcss', $package['devDependencies'] ?? [])) {
            $package['devDependencies']['tailwindcss'] = $pin;
            $changes[] = 'package.json: devDependencies tailwindcss to ' . $pin . '.';
            $edited = true;
        }
        $plugin = $this->usesVite($path) ? '@tailwindcss/vite' : '@tailwindcss/postcss';
        if (($package['devDependencies'][$plugin] ?? null) !== $pin) {
            $package['devDependencies'][$plugin] = $pin;
            $changes[] = 'package.json: devDependencies ' . $plugin . ' to ' . $pin . '.';
            $edited = true;
        }
        if ($edited) {
            file_put_contents($packageFile, json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
            $files[] = ['file' => 'package.json', 'diff' => 'pinned tailwindcss to ' . $pin . ' and added ' . $plugin];
        }

        return [$changes, $files];
    }

    /**
     * The app builds with Vite: a vite.config.js or vite.config.ts exists, or
     * package.json depends on vite. Decides which Tailwind 4 plugin to pin.
     */
    private function usesVite(string $path): bool
    {
        if (is_file($path . '/vite.config.js') || is_file($path . '/vite.config.ts')) {
            return true;
        }
        $package = json_decode((string) file_get_contents($path . '/package.json'), true);
        if (!is_array($package)) {
            return false;
        }
        foreach (['dependencies', 'devDependencies'] as $section) {
            if (array_key_exists('vite', $package[$section] ?? [])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The Inertia constraint raises a Laravel hop performs for an Inertia/Vue
     * app, or empty when the hop or the app does not qualify.
     *
     * @return array{0: list<string>, 1: list<array{file: string, diff: string}>}
     */
    private function inertiaChangesFor(Hop $hop, PlanStep $step, Plan $plan, bool $dryRun): array
    {
        if ($hop->kind !== HopKind::Laravel || $plan->project->type !== AppType::LaravelInertiaVue || $step->laravelAfter === null) {
            return [[], []];
        }

        return $this->applyInertiaChanges($plan->project->path, $step->laravelAfter, $dryRun);
    }

    /**
     * Raises the Inertia constraints an app already carries toward the ones
     * its new Laravel major pairs with. Only packages the app has are touched:
     * a Vue 2 app never gains @inertiajs/vue3, and a fresh add is a human
     * decision.
     *
     * @return array{0: list<string>, 1: list<array{file: string, diff: string}>}
     */
    private function applyInertiaChanges(string $path, string $laravelTo, bool $dryRun): array
    {
        $wanted = $this->registry()->inertiaChanges($laravelTo);
        $changes = [];
        $files = [];

        $composerFile = $path . '/composer.json';
        if (is_file($composerFile)) {
            $composer = json_decode((string) file_get_contents($composerFile), true);
            if (is_array($composer)) {
                $edited = false;
                foreach ($wanted['composer'] as $package => $target) {
                    $current = $composer['require'][$package] ?? null;
                    if (!is_string($current) || self::atOrAboveFloor($current, $target)) {
                        continue;
                    }
                    if ($dryRun) {
                        $changes[] = sprintf('Dry run: would set %s to %s in require.', $package, $target);
                    } else {
                        $composer['require'][$package] = $target;
                        $changes[] = sprintf('Set %s to %s in require.', $package, $target);
                        $edited = true;
                    }
                    $files[] = ['file' => 'composer.json', 'diff' => $package . ' to ' . $target];
                }
                if ($edited) {
                    file_put_contents($composerFile, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
                }
            }
        }

        $packageFile = $path . '/package.json';
        if (is_file($packageFile)) {
            $package = json_decode((string) file_get_contents($packageFile), true);
            if (is_array($package)) {
                $edited = false;
                foreach ($wanted['npm'] as $name => $target) {
                    $section = array_key_exists($name, $package['dependencies'] ?? []) ? 'dependencies' : 'devDependencies';
                    $current = $package[$section][$name] ?? null;
                    if (!is_string($current) || self::atOrAboveFloor($current, $target)) {
                        continue;
                    }
                    if ($dryRun) {
                        $changes[] = sprintf('Dry run: would set package.json %s %s to %s.', $section, $name, $target);
                    } else {
                        $package[$section][$name] = $target;
                        $changes[] = sprintf('package.json: %s %s to %s.', $section, $name, $target);
                        $edited = true;
                    }
                    $files[] = ['file' => 'package.json', 'diff' => $name . ' to ' . $target];
                }
                if ($edited) {
                    file_put_contents($packageFile, json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
                }
            }
        }

        return [$changes, $files];
    }

    /**
     * True when $constraint already floors at or above the floor of $target,
     * so raising it would only narrow the range. A constraint with no usable
     * floor ("*" or unparseable) is raised, the way ComposerEditor treats it.
     */
    private static function atOrAboveFloor(string $current, string $target): bool
    {
        $floor = HopDataRefresher::lowestMajor($current);
        $targetFloor = HopDataRefresher::lowestMajor($target);
        if ($floor === null || $targetFloor === null) {
            return false;
        }

        return version_compare($floor, $targetFloor, '>=');
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
                    $this->undoHint($path),
                ];
            }
        }

        return null;
    }

    /**
     * What a node hop will change, in the order it changes it: set values,
     * set-if-present values, removals, then the .nvmrc pin.
     *
     * @return list<string>
     */
    private static function nodeChangePhrases(Hop $hop): array
    {
        $phrases = [];
        foreach ($hop->npmSet as $key => $value) {
            $phrases[] = 'set ' . self::npmName($key) . ' to ' . $value . ' in package.json';
        }
        foreach ($hop->npmSetIfPresent as $key => $value) {
            $phrases[] = 'set ' . self::npmName($key) . ' to ' . $value . ' in package.json if it is listed';
        }
        foreach ($hop->npmRemove as $name) {
            $phrases[] = 'remove ' . $name . ' from package.json';
        }
        if ($hop->pinNode) {
            $phrases[] = 'write .nvmrc with ' . $hop->to;
        }

        return $phrases;
    }

    /**
     * Why a node hop has nothing left to do: the set values already match,
     * the removed packages are absent, and the .nvmrc already pins.
     *
     * @return list<string>
     */
    private static function nodeAlreadyPhrases(Hop $hop): array
    {
        $phrases = [];
        if ($hop->npmSet !== []) {
            $parts = [];
            foreach ($hop->npmSet as $key => $value) {
                $parts[] = self::npmName($key) . ' to ' . $value;
            }
            $phrases[] = 'package.json already sets ' . implode(', ', $parts);
        }
        if ($hop->npmRemove !== []) {
            $phrases[] = 'package.json does not list ' . implode(', ', $hop->npmRemove);
        }
        if ($hop->pinNode) {
            $phrases[] = '.nvmrc already pins ' . $hop->to;
        }

        return $phrases;
    }

    /**
     * Join human phrases with commas and a final "and", the way a person
     * would read them out loud.
     *
     * @param list<string> $phrases
     */
    private static function joinPhrases(array $phrases): string
    {
        $count = count($phrases);
        if ($count === 0) {
            return '';
        }
        if ($count === 1) {
            return $phrases[0];
        }
        if ($count === 2) {
            return $phrases[0] . ' and ' . $phrases[1];
        }

        return implode(', ', array_slice($phrases, 0, -1)) . ' and ' . $phrases[$count - 1];
    }

    /**
     * The package name behind a dot-path: "dependencies.vue" is "vue", while
     * "engines.node" stays "engines.node".
     */
    private static function npmName(string $key): string
    {
        [, $name] = self::splitDependencyKey($key);

        return $name;
    }

    /**
     * Split a dependency dot-path into its section and package name. A path
     * that is not a dependencies or devDependencies entry comes back with a
     * null section and the whole key as the name.
     *
     * @return array{0: ?string, 1: string}
     */
    private static function splitDependencyKey(string $key): array
    {
        foreach (['dependencies', 'devDependencies'] as $section) {
            $prefix = $section . '.';
            if (str_starts_with($key, $prefix)) {
                return [$section, substr($key, strlen($prefix))];
            }
        }

        return [null, $key];
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

    /**
     * The undo line for a failed step. "git checkout ." restores changed
     * tracked files; files this run created are untracked and survive that, so
     * when the working tree holds any they are named for "git clean" too. Only
     * paths absent from the start-of-run status are named, so a user's own
     * untracked files (--allow-dirty) stay out of the clean list. A directory
     * untracked at start keeps its single "dir/" entry when it gains files, so
     * it stays out too: name nothing you cannot prove this run created.
     */
    private function undoHint(string $path): string
    {
        $created = array_values(array_diff($this->untrackedPaths($path), $this->startingUntracked));
        if ($created === []) {
            return 'Undo with: git checkout . (inside the app).';
        }

        return 'Undo with: git checkout . && git clean -fd -- ' . implode(' ', $created) . ' (inside the app).';
    }

    /** @return list<string> the app's untracked paths from one git status */
    private function untrackedPaths(string $path): array
    {
        $status = $this->processes->run(['git', '-C', $path, 'status', '--porcelain'], null, [], 60);
        $paths = [];
        foreach (explode("\n", trim($status->stdout)) as $line) {
            if (str_starts_with($line, '?? ') && strlen($line) > 3) {
                $paths[] = trim(substr($line, 3), '"');
            }
        }

        return $paths;
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
