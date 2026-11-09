<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Waynestate\Booster\Hop\Hop;
use Waynestate\Booster\Hop\HopKind;
use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Hop\TrustProxiesRewriter;
use Waynestate\Booster\Node\MixBuild;
use Waynestate\Booster\Node\MixFile;
use Waynestate\Booster\Plan\Plan;
use Waynestate\Booster\Plan\PlanStep;
use Waynestate\Booster\Prepass\NewByReferenceFixer;
use Waynestate\Booster\Prepass\PrepassResult;
use Waynestate\Booster\Project\AppType;
use Waynestate\Booster\Refresh\HopDataRefresher;
use Waynestate\Booster\Replay\ReplayGate;
use Waynestate\Booster\Replay\ReplayOutcome;
use Waynestate\Booster\Replay\ReplayRunner;
use Waynestate\Booster\Replay\ReplaySuggestion;
use Waynestate\Booster\Report\TextWriter;
use Waynestate\Booster\Scan\BehaviourScan;
use Waynestate\Booster\Scan\ComposerAuditScan;
use Waynestate\Booster\Scan\RemovalsScan;
use Waynestate\Booster\ToolError;

/**
 * Walks a plan. For each step: preview with Rector, ask or stop when the diff
 * is over the review threshold (or the --max-changes hard limit), apply, move
 * the CAS config and model traits on the SESAME hop, run the WayneUI
 * migration on the WayneUI hop, edit composer.json, then run the gate before
 * the next step. It edits the working copy only: no commits, no branches, no
 * pushes.
 */
final class HopExecutor
{
    /**
     * The gate for an app with no tests of its own: every PHP file outside
     * vendor/ and node_modules/ must still parse on the target PHP. It proves
     * the code loads, not that it works.
     */
    public const SYNTAX_CHECK_COMMAND = "find . -name '*.php' -not -path './vendor/*' -not -path './node_modules/*' -print0 | xargs -0 -r -n1 php -l > /dev/null";

    /** What a front-end hop reports once npm install has brought the lock up to its pins. */
    private const NPM_LOCK_SYNCED = 'npm install ran in the Node container: package-lock.json and node_modules now match the pins.';

    public function __construct(
        private readonly RectorRunner $rector,
        private readonly ComposerEditor $composer,
        private readonly TargetImageRunner $gate,
        private readonly ProcessRunner $processes,
        private readonly ?NodeRunner $node = null,
        private readonly ?HopRegistry $registry = null,
        private readonly ?ProctorRunner $proctor = null,
        private readonly CasMigration $casMigration = new CasMigration(),
        private readonly WayneUiMigration $wayneUiMigration = new WayneUiMigration(),
        private readonly TrustProxiesRewriter $trustProxies = new TrustProxiesRewriter(),
        private readonly ?ReplayRunner $replayRunner = null,
        private readonly VendoredMigration $vendoredMigration = new VendoredMigration(),
        private readonly StrictSession $strictSession = new StrictSession(),
    ) {}

    /** The replay gate for this run, once its baseline is recorded (--replay). */
    private ?ReplayGate $replayGate = null;

    /**
     * The step number of the plan's vendored-to-composer hop. The replay
     * waits for it: until the hand copies leave, the app may load a 2012
     * library that crashes on the new PHP (Doctrine 2.2's class loader did,
     * on a real app), and that is the very thing the hop fixes.
     */
    private ?int $replayFrom = null;

    private ?RemovalsScan $removals = null;

    private ?BehaviourScan $behaviour = null;

    private ?ComposerAuditScan $composerAudit = null;

    /**
     * The locked package versions read before the first step, so the package
     * rows on the last step compare against the lock the app started with,
     * not one an earlier step already bumped past the row's major.
     *
     * @var array<string, string>
     */
    private array $startingLockedVersions = [];

    /**
     * Untracked paths the app held before the run, so undo hints name only
     * this run's creations.
     *
     * @var list<string>
     */
    private array $startingUntracked = [];

    /**
     * True once the run has said which nested vendored directories Rector
     * left alone, so the note goes out once per run, not once per hop.
     */
    private bool $reportedVendored = false;

    /**
     * True once a Rector hop actually ran, so the run report records the
     * rector/rector version only when Rector did the work.
     */
    private bool $ranRector = false;

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

    private function behaviourScanner(): BehaviourScan
    {
        return $this->behaviour ??= new BehaviourScan();
    }

    /**
     * The advisory audit of the app's packages, built on this run's process
     * seam so tests can feed it a canned report.
     */
    private function composerAuditScanner(): ComposerAuditScan
    {
        return $this->composerAudit ??= new ComposerAuditScan($this->processes);
    }

    public function execute(Plan $plan, RunOptions $options): RunReport
    {
        $this->reportedVendored = false;
        $this->ranRector = false;
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
        // The report names the --test-env variables by key only; the values may
        // hold credentials and must never reach a report or a log.
        $testEnvKeys = array_map(
            static fn(string $pair): string => substr($pair, 0, (int) strpos($pair, '=')),
            $options->testEnv,
        );
        // The composer.lock fingerprint before any hop, so the run can tell
        // whether the upgrade changed it and write the Citadel install script.
        $lockBefore = $options->dryRun ? null : $this->lockFingerprint($path);
        // The locked versions before any hop, for the package rows above.
        $this->startingLockedVersions = BehaviourScan::lockedVersions($path);

        $baseline = $this->runBaseline($plan, $path, $options);
        if ($baseline->failed() && !$options->allowFailingBaseline) {
            // The suite is red (or never ran) before Booster touches anything, so a later red
            // gate would be unprovable. Stop before the first hop and say so plainly.
            $reason = $baseline->status === BaselineResult::INSTALL_FAILED
                ? sprintf('composer install failed (exit %d) before the gate ran. Fix the app\'s composer.json and composer.lock, or pass --allow-failing-baseline.', $baseline->exitCode ?? -1)
                : $this->baselineFailureReason($baseline);
            $results = [];
            foreach ($plan->steps as $step) {
                $results[] = new StepResult($step->number, $step->hop->id, StepResult::NOT_RUN, [], [
                    'Not run because the baseline failed: ' . $reason,
                ]);
            }

            return new RunReport($plan, $options->dryRun, $baseline, $results, testEnvKeys: $testEnvKeys);
        }

        // With --replay, record how the app answers now, before the first hop
        // changes anything. A baseline that cannot be recorded stops the run
        // the way a red test baseline does: nothing could be proven later.
        $replay = $this->recordReplay($plan, $path, $options);
        $this->replayFrom = null;
        foreach ($plan->steps as $step) {
            if ($step->hop->id === HopRegistry::VENDORED_TO_COMPOSER_ID) {
                $this->replayFrom = $step->number;
                break;
            }
        }
        if ($replay !== null && $replay->failed()) {
            $results = [];
            foreach ($plan->steps as $step) {
                $results[] = new StepResult($step->number, $step->hop->id, StepResult::NOT_RUN, [], [
                    'Not run because the replay baseline could not be recorded (see Replay above).',
                ]);
            }

            return new RunReport($plan, $options->dryRun, $baseline, $results, testEnvKeys: $testEnvKeys, replay: $replay);
        }
        // A gate that only parses the code (or only runs Laravel's stock
        // examples) proves little, so a run without --replay names the
        // characterization answers it could be recording instead. Advisory.
        $replaySuggestion = ReplaySuggestion::for($plan, $baseline, $path, $options->replay !== null, $options->skipGate);

        $results = [];
        // Measure the app against the CREED policy on the PHP it is on now, so
        // the final gate can tell pre-existing findings from ones the upgrade
        // introduces.
        $proctorBaseline = $this->runProctorBaseline($plan, $path, $options);
        // One line per hop to whoever passed a progress callback, so a long run
        // shows which hop it is in without touching the report on stdout.
        $started = microtime(true);
        $progress = $options->progress;
        foreach ($plan->steps as $step) {
            $hopStarted = microtime(true);
            $result = $this->runStep($step, $path, $plan, $options, $baseline);
            $results[] = $result;
            if ($progress !== null) {
                $files = count($result->files);
                $progress(sprintf(
                    'hop %d/%d %s: %s, %d file%s, %d s',
                    $step->number,
                    count($plan->steps),
                    $result->hopId,
                    $result->status,
                    $files,
                    $files === 1 ? '' : 's',
                    (int) floor(microtime(true) - $hopStarted),
                ));
            }
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
        if ($progress !== null) {
            $ran = 0;
            $stopped = 0;
            foreach ($results as $result) {
                if ($result->status !== StepResult::NOT_RUN) {
                    $ran++;
                }
                if ($result->failed()) {
                    $stopped = 1;
                }
            }
            $progress(sprintf(
                'run finished: %d of %d hops run, %d stopped, total %d s',
                $ran,
                count($plan->steps),
                $stopped,
                (int) floor(microtime(true) - $started),
            ));
        }

        $proctor = $this->runProctorGate($plan, $path, $options, $results);
        // With steps the final gate reads against the pre-upgrade baseline; with
        // no steps there is nothing to compare, so the single result stands alone.
        $comparison = $proctor === null || $plan->steps === [] ? null : ProctorComparison::compare($proctorBaseline, $proctor);
        // A run that stops before the final target (an EOL stop, --until or
        // --only) cannot prove the app at that target, so the PROCTOR gate
        // reports instead of blocking.
        $advisory = $options->proctorAdvisory || $plan->stopsEarly;
        // When the upgrade changed composer.lock and the app deploys on Citadel,
        // write the once-only install script so existing deployments pick up the
        // new vendor/ first. A dry run changes no lock, so it writes none.
        $deployScript = $options->dryRun
            ? null
            : (new CitadelDeployScript())->writeIfLockChanged($path, $lockBefore, (new \DateTimeImmutable('now'))->format('YmdHi'));

        return new RunReport($plan, $options->dryRun, $baseline, $results, $proctor, $advisory, $comparison, testEnvKeys: $testEnvKeys, deployScript: $deployScript, replay: $replay, rectorVersion: $this->ranRector ? $this->rector->installedVersion() : null, replaySuggestion: $replaySuggestion);
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
     * app against the CREED policy on the final PHP. With no steps there is
     * nothing to upgrade, so it runs once on the app's current PHP and stands
     * on its own. Skipped for dry runs, when the gate is skipped, or when a
     * step left the run unfinished.
     *
     * @param list<StepResult> $results
     */
    private function runProctorGate(Plan $plan, string $path, RunOptions $options, array $results): ?ProctorResult
    {
        if ($options->dryRun || $options->skipGate || $this->proctor === null) {
            return null;
        }
        foreach ($results as $result) {
            if ($result->failed() || $result->status === StepResult::NOT_RUN) {
                return null;
            }
        }
        // With no steps there is nothing to land on, so the single check runs
        // on the app's current PHP; otherwise it lands where the last step did.
        $php = $plan->steps === [] ? $this->startingPhp($plan) : ($plan->targetPhp ?? $this->startingPhp($plan));
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
        $kind = $this->gateKind($options, $path);
        if ($options->dryRun || $options->skipGate || $php === null) {
            return new BaselineResult(BaselineResult::SKIPPED, $php, null, [], $warning, $kind);
        }

        $test = $options->testCommand ?? $this->defaultTestCommand($path);
        $result = $this->gate->run($path, $php, $test, $options->proctorCommand, $options->gateImage, [], $options->testEnv);
        $outcome = TargetImageRunner::gateOutcome($result);
        $status = match (true) {
            $outcome->successful() => BaselineResult::PASSED,
            $outcome->phase === GateOutcome::PHASE_INSTALL => BaselineResult::INSTALL_FAILED,
            default => BaselineResult::FAILED,
        };

        return new BaselineResult($status, $php, $outcome->exitCode, $outcome->output, $warning, $kind);
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
     * between the hop's two versions, and for PHP and Laravel hops (plus the
     * plan's last step) the curated behaviour changes: advisories in the
     * step's messages, never a failure and never a reason to stop. When the
     * hop changed the app's composer files it then audits the packages the
     * app now declares, again advisory only: the audit never fails the step
     * and a clean audit stays silent.
     */
    private function runStep(PlanStep $step, string $path, Plan $plan, RunOptions $options, BaselineResult $baseline): StepResult
    {
        // Scan first: the advisory must describe the app as it was before the hop rewrites it.
        $removals = $this->removalsLines($step, $path);
        $behaviour = $this->behaviourLines($step, $path, $plan);
        // A dry run changes no file, so there is nothing to fingerprint and
        // nothing to audit afterwards.
        $composerBefore = $options->dryRun ? null : $this->composerFingerprint($path);
        $result = $this->runStepCore($step, $path, $plan, $options, $baseline);

        // After the hop: did it change what the app declares? Then audit the
        // packages, again advisory only. A clean audit stays silent. A dry
        // run neither audits nor says it skipped.
        $audit = null;
        $skipAudit = false;
        if (!$options->dryRun) {
            if ($this->composerFingerprint($path) !== $composerBefore) {
                $audit = $this->composerAuditScanner()->scan($path);
                if ($audit->ok && !$audit->hasFindings()) {
                    $audit = null;
                }
            } else {
                $skipAudit = true;
            }
        }

        $messages = [...$result->messages];
        if ($removals !== '') {
            array_unshift($messages, $removals);
        }
        if ($behaviour !== '') {
            $messages[] = $behaviour;
        }
        if ($skipAudit) {
            $messages[] = 'Composer audit: skipped (this hop changed no composer file).';
        }

        return new StepResult($step->number, $step->hop->id, $result->status, $result->files, $messages, $result->incomplete, $result->prepass, $result->rules, $audit);
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

    /**
     * The advisory behaviour-changes block for a PHP or Laravel hop, and for
     * the plan's last step where the package rows land, or '' when nothing
     * curated applies or the app touches none of it.
     */
    private function behaviourLines(PlanStep $step, string $path, Plan $plan): string
    {
        $isLast = $step->number === count($plan->steps);
        if ($step->hop->kind !== HopKind::Php && $step->hop->kind !== HopKind::Laravel && !$isLast) {
            return '';
        }
        $result = $this->behaviourScanner()->scan($path, $step->hop->id, BehaviourScan::composerRequire($path), $this->startingLockedVersions);
        if (!$isLast) {
            $result = $result->withoutPackages();
        }
        if ($result->entries === []) {
            return '';
        }

        return implode("\n", (new TextWriter())->behaviour($result));
    }

    private function runStepCore(PlanStep $step, string $path, Plan $plan, RunOptions $options, BaselineResult $baseline): StepResult
    {
        $hop = $step->hop;
        if ($hop->isManual()) {
            return new StepResult($step->number, $hop->id, StepResult::MANUAL, [], ['Follow the playbook; Booster has no automatic change for this hop.', ...$hop->notes]);
        }

        try {
            if ($hop->kind === HopKind::Node) {
                return match ($hop->id) {
                    HopRegistry::MIX_TO_VITE_ID => $this->runMixHop($step, $path, $plan, $options, $baseline),
                    HopRegistry::VITE_MAJOR_ID => $this->runViteMajorHop($step, $path, $options, $baseline),
                    HopRegistry::TAILWIND_ID => $this->runTailwindHop($step, $path, $options, $baseline),
                    HopRegistry::INERTIA_3_ID => $this->runInertiaHop($step, $path, $options, $baseline),
                    HopRegistry::TYPESCRIPT_MAJOR_ID => $this->runTypescriptMajorHop($step, $path, $options, $baseline),
                    HopRegistry::VUE_COMPAT_CODEMOD_ID => $this->runVueCompatCodemodHop($step, $path, $options),
                    default => $this->runNodeHop($step, $path, $options, $baseline),
                };
            }

            if ($hop->kind === HopKind::WayneUi) {
                return $this->runWayneUiHop($step, $path, $options, $baseline);
            }

            if ($hop->kind === HopKind::Framework) {
                return $this->runFrameworkHop($step, $path, $options, $baseline);
            }

            if ($hop->kind === HopKind::Session) {
                return $this->runSessionHop($step, $path, $options, $baseline);
            }

            // PHP hops get the new-by-reference pre-pass first: modern PHP refuses to compile
            // "$a = &new Foo();", so without the rewrite Rector never gets a parse tree and the
            // hop stops with nothing changed. The rewrite lands on the working copy, and a dry
            // run puts every original byte back.
            $prepass = $hop->kind === HopKind::Php ? $this->fixer()->fixTree($path, true) : null;
            $keepPrepass = false;
            try {
                $phpunitTarget = $hop->kind === HopKind::Phpunit ? $hop->to : null;
                $this->ranRector = true;
                $preview = $this->rector->run($hop, $path, true, $step->phpAfter ?? '', $step->laravelAfter, $phpunitTarget, $options->memoryLimit);
                [$stopErrors, $notes] = self::splitRepairedNotes($preview->errors);
                // Say once per run which nested vendored libraries Rector left alone.
                $this->noteVendoredDirs($preview, $notes);
                // Name the files Rector ran without. Not once per run: every hop
                // skipped them, so every step says so.
                $this->noteExcludedFiles($preview, $notes);
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
                    $proxies = $hop->id === HopRegistry::laravelHopId('8', '9') ? $this->trustProxies->rewrite($path, true) : null;
                    [$inertiaChanges, $inertiaFiles] = $this->inertiaChangesFor($hop, $step, $plan, true);
                    $files = [...self::prepassFiles($prepass), ...$preview->files, ...($migration->files ?? []), ...($proxies->files ?? []), ...$inertiaFiles];
                    $threshold = $overThreshold ? [sprintf(
                        'Over the review threshold: this hop changes %d files (threshold %d). Read the per-rule summary below.',
                        $wanted,
                        $options->reviewThreshold,
                    ), ...self::summaryLines($prepass, $preview)] : [];
                    // A real run's gate refreshes the lock for these hops; say so now.
                    $update = $this->lockUpdatePackages($step, $path);
                    $wouldRun = $update === [] ? [] : ['would run: ' . self::lockCommand($update)];
                    if ($incomplete) {
                        $messages = [...$threshold, ...$wouldRun, sprintf('Preview incomplete: %s needs the app\'s vendor/ to recognize Laravel classes, and it is missing. Run composer install in the app, then dry-run again.', $hop->id), ...$inertiaChanges, ...$hop->notes, ...$notes];
                    } elseif ($files === []) {
                        // With no edits the hop's notes would read like findings in the app, so
                        // they get their own label line before the first note.
                        $general = $hop->notes === [] ? [] : ['General notes for this step (nothing found in your app, just good to know):'];
                        $messages = [...$threshold, ...$wouldRun, 'No edits needed for this step (dry run).', ...($migration->manual ?? []), ...($proxies->manual ?? []), ...$general, ...$hop->notes, ...$notes];
                    } else {
                        $messages = [...$threshold, ...$wouldRun, ...($migration->changes ?? []), ...($proxies->changes ?? []), ...$inertiaChanges, ...($migration->manual ?? []), ...($proxies->manual ?? []), ...$hop->notes, ...$notes];
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
                if ($hop->kind === HopKind::Laravel || $hop->kind === HopKind::Sesame) {
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
                $proxies = $hop->id === HopRegistry::laravelHopId('8', '9') ? $this->trustProxies->rewrite($path, false) : null;
                [$inertiaChanges, $inertiaFiles] = $this->inertiaChangesFor($hop, $step, $plan, false);
                $files = [...self::prepassFiles($prepass), ...$applied->files, ...($migration->files ?? []), ...($proxies->files ?? []), ...$inertiaFiles];
                if (($prepass?->changedCount() ?? 0) === 0 && $applied->changedCount() === 0 && $messages === [] && ($migration->changes ?? []) === [] && ($proxies->changes ?? []) === [] && $inertiaChanges === []) {
                    // Nothing changed, so there is nothing for the gate to prove; skip it.
                    return new StepResult($step->number, $hop->id, StepResult::NOTHING_TO_DO, [], [
                        'Nothing to do: Rector changed no files and composer.json already allows the target, so the gate was skipped.',
                        ...($migration->manual ?? []),
                        ...($proxies->manual ?? []),
                        ...$hop->notes,
                        ...$notes,
                    ]);
                }

                if (!$options->skipGate) {
                    $gate = $this->runGate($step, $path, $options, $baseline);
                    if (!$gate->passed) {
                        return new StepResult($step->number, $hop->id, StepResult::GATE_FAILED, $files, [...$messages, ...($migration->changes ?? []), ...($proxies->changes ?? []), ...$inertiaChanges, ...($migration->manual ?? []), ...($proxies->manual ?? []), ...$gate->lines, ...$notes], false, $prepass?->files() ?? []);
                    }
                    $messages = [...$messages, ...$gate->lines];
                    $messages[] = $this->gatePassedLine($step->phpAfter, $baseline->gateKind);
                    $update = $this->lockUpdatePackages($step, $path);
                    if ($update !== []) {
                        $messages[] = sprintf('The gate refreshed composer.lock (%s).', self::lockCommand($update));
                    }
                    $unblocked = $this->composer->temporaryUnblocks($path);
                    if ($unblocked !== []) {
                        // The unblock is temporary by name: the final target
                        // ships without it, so the last step of the full plan
                        // fails until it is out of composer.json. A plan that
                        // stops early never reaches that target, so its last
                        // step only warns.
                        $finding = sprintf(
                            'composer.json still has Booster\'s temporary advisory unblock for %s (config.policy.advisories.ignore).',
                            implode(', ', $unblocked),
                        );
                        if ($step->number === count($plan->steps) && !$plan->stopsEarly) {
                            return new StepResult($step->number, $hop->id, StepResult::GATE_FAILED, $files, [...$messages, $finding . ' Remove it before release; Booster fails the final target while it is there.', ...($migration->changes ?? []), ...($proxies->changes ?? []), ...$inertiaChanges, ...($migration->manual ?? []), ...($proxies->manual ?? []), ...$notes], false, $prepass?->files() ?? []);
                        }
                        $messages[] = $finding;
                    }
                } else {
                    $messages[] = 'Gate skipped. Run the tests on PHP ' . ($step->phpAfter ?? '?') . ' before the next step.';
                }

                return new StepResult($step->number, $hop->id, StepResult::APPLIED, $files, [...$messages, ...($migration->changes ?? []), ...($proxies->changes ?? []), ...$inertiaChanges, ...($migration->manual ?? []), ...($proxies->manual ?? []), ...$hop->notes, ...$notes], false, $prepass?->files() ?? []);
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
     * "Invalid numeric literal": Booster cannot repair those by tooling, so
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

            return ($where !== '' ? $where . ': ' : '') . '"' . $message . '" cannot be repaired by tooling, so Booster stops here and the file needs a person.';
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
     * Appends the once-per-run note naming the nested vendored directories
     * Rector was told to leave alone, so the report says which ones were
     * skipped. It lands in $notes, so whichever way the step ends (previewed,
     * applied, or an error after the Rector run) the message goes out exactly
     * once per run.
     *
     * @param list<string> $notes
     */
    private function noteVendoredDirs(RectorResult $result, array &$notes): void
    {
        if ($result->skippedDirs === [] || $this->reportedVendored) {
            return;
        }
        $this->reportedVendored = true;
        $dirs = $result->skippedDirs;
        $notes[] = 'Left alone (vendored libraries, never rewritten): ' . implode(', ', array_slice($dirs, 0, 10)) . (count($dirs) > 10 ? ' and ' . (count($dirs) - 10) . ' more' : '') . '.';
    }

    /**
     * Appends one manual-work line per file Rector was run without (not valid
     * UTF-8, or a class it could not resolve). Unlike the vendored note this is
     * not once per run: every hop skipped the files, so every step names them.
     * At most 20 files are named; the rest collapse into one line.
     *
     * @param list<string> $notes
     */
    private function noteExcludedFiles(RectorResult $result, array &$notes): void
    {
        if ($result->excluded === []) {
            return;
        }
        $allUtf8 = true;
        foreach ($result->excluded as $entry) {
            if ($entry['reason'] !== 'not valid UTF-8') {
                $allUtf8 = false;

                break;
            }
        }
        foreach (array_slice($result->excluded, 0, 20) as $entry) {
            if ($entry['reason'] === 'not valid UTF-8') {
                $notes[] = 'MANUAL: ' . $entry['file'] . ' is not valid UTF-8, so Rector was run without it. Convert it to UTF-8, then upgrade it by hand.';

                continue;
            }
            $notes[] = 'MANUAL: ' . $entry['file'] . ': ' . $entry['reason'] . '. Rector was run without it, so upgrade it by hand.';
        }
        if (count($result->excluded) > 20) {
            $notes[] = $allUtf8
                ? 'MANUAL: and ' . (count($result->excluded) - 20) . ' more files that are not valid UTF-8.'
                : 'MANUAL: and ' . (count($result->excluded) - 20) . ' more files that Rector was run without.';
        }
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
     * lands in the app itself. A Laravel hop also refreshes each companion
     * package the app actually lists, so the lock moves with composer.json.
     * PHP hops only move the platform requirement, so they keep the plain
     * install, except when the app pins config.platform.php: the lock keeps
     * the old pin in its platform-overrides and `composer install` checks
     * against that, so it refuses the raised require.php (found on WELD,
     * 2026-11-06; every Sourdough app pins it). Those get `composer update
     * --lock`, which moves no package but rewrites the hash and overrides.
     *
     * @return list<string>
     */
    private function lockUpdatePackages(PlanStep $step, string $path): array
    {
        $hop = $step->hop;
        if ($hop->kind === HopKind::Php) {
            return self::pinsPlatformPhp($path) && is_file(rtrim($path, '/\\') . '/composer.lock')
                ? [TargetImageRunner::LOCK_REFRESH]
                : [];
        }
        if ($hop->kind !== HopKind::Laravel && $hop->kind !== HopKind::Phpunit) {
            return [];
        }
        $packages = array_keys($hop->composerSet);
        foreach (array_keys($hop->composerSetIfPresent) as $package) {
            if (self::appListsPackage($path, $package)) {
                $packages[] = $package;
            }
        }

        return $packages;
    }

    /**
     * The Composer command the gate runs for a non-empty update list, as the
     * person would type it: `composer update --lock` for a lock refresh,
     * `composer update -W <packages>` otherwise.
     *
     * @param list<string> $update
     */
    private static function lockCommand(array $update): string
    {
        return $update === [TargetImageRunner::LOCK_REFRESH]
            ? 'composer update --lock'
            : 'composer update -W ' . implode(' ', $update);
    }

    /** True when a step of the plan runs the build gate (npm ci, npm run build). */
    private static function planBuildsFrontEnd(Plan $plan): bool
    {
        foreach ($plan->steps as $step) {
            if ($step->hop->buildGate) {
                return true;
            }
        }

        return false;
    }

    /** True when the app's composer.json sets config.platform.php. */
    private static function pinsPlatformPhp(string $path): bool
    {
        $file = rtrim($path, '/\\') . '/composer.json';
        $raw = is_file($file) ? file_get_contents($file) : false;
        $data = $raw === false ? null : json_decode($raw, true);

        return is_array($data) && is_string($data['config']['platform']['php'] ?? null);
    }

    /**
     * True when the app's composer.json lists $package in require or
     * require-dev. A missing or unreadable manifest means no, so the hop
     * falls back to refreshing only its own set.
     */
    private static function appListsPackage(string $path, string $package): bool
    {
        $file = rtrim($path, '/\\') . '/composer.json';
        if (!is_file($file)) {
            return false;
        }
        $raw = file_get_contents($file);
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data)) {
            return false;
        }

        return isset($data['require'][$package]) || isset($data['require-dev'][$package]);
    }

    /**
     * The step's whole gate: the test gate first, then, when the run records
     * a replay baseline (--replay), the replay on the hop's PHP. A changed
     * answer fails the hop like a red test and leaves the changes in place.
     *
     * @param list<string>|null $update packages for composer update -W; the
     *        hop's composer set by default, or the hop's own list when it
     *        changes packages outside that set
     */
    private function runGate(PlanStep $step, string $path, RunOptions $options, BaselineResult $baseline, ?array $update = null): GateReport
    {
        $gate = $this->runTestGate($step, $path, $options, $baseline, $update);
        if (!$gate->passed || $this->replayGate === null || $this->replayGate->recording() === null) {
            return $gate;
        }
        $php = $step->phpAfter ?? '';
        if ($this->replayFrom !== null && $step->number < $this->replayFrom) {
            return new GateReport(true, [...$gate->lines, sprintf(
                'Replay deferred to step %d (%s): the app still loads its hand-copied libraries, which may not run on PHP %s. The answers are compared once Composer\'s releases are in.',
                $this->replayFrom,
                HopRegistry::VENDORED_TO_COMPOSER_ID,
                $php,
            )]);
        }
        try {
            $outcome = $this->replayGate->check($php, sprintf('step-%d-%s-php%s', $step->number, $step->hop->id, $php));
        } catch (ToolError $e) {
            return new GateReport(false, [...$gate->lines, 'Replay could not run: ' . $e->getMessage(), $this->undoHint($path)]);
        }
        if ($outcome->failed()) {
            return new GateReport(false, [
                ...$gate->lines,
                ...$outcome->lines,
                'The tests (or syntax check) passed, but an answer changed. The working copy keeps the changes so you can read them.',
                $this->undoHint($path),
            ]);
        }

        return new GateReport(true, [...$gate->lines, ...$outcome->lines]);
    }

    /**
     * Records the replay baseline on the PHP the app starts from, right after
     * the test baseline (which installed vendor/). Null without --replay; a
     * dry run records nothing and says so.
     */
    private function recordReplay(Plan $plan, string $path, RunOptions $options): ?ReplayOutcome
    {
        $this->replayGate = null;
        $file = $options->replay;
        if ($file === null) {
            return null;
        }
        $name = basename($file->path);
        if ($options->dryRun || $options->skipGate) {
            return new ReplayOutcome(ReplayOutcome::SKIPPED, null, [sprintf('Replay: SKIPPED (no requests sent in a dry run). A real run records how the app answers the requests in %s before the first hop, and replays them after each one.', $name)], $name);
        }
        $php = $this->startingPhp($plan);
        if ($php === null) {
            return new ReplayOutcome(ReplayOutcome::FAILED, null, ['Replay baseline could not be recorded: Booster could not tell which PHP the app runs on. Pass --from-php.'], $name);
        }
        // A hop with a build gate rebuilds public/build, so the pages it serves
        // afterwards name the built assets. Record the baseline on a built front
        // end too, or every page differs from a baseline that served no build
        // or a test stub manifest (found on WELD, 2026-11-06).
        $built = null;
        if ($this->node !== null && self::planBuildsFrontEnd($plan)) {
            $buildFailure = $this->runBuildGate($path);
            if ($buildFailure !== null) {
                return new ReplayOutcome(ReplayOutcome::FAILED, $php, [
                    'Replay baseline could not be recorded: the front end does not build before any change, and a later hop rebuilds it.',
                    ...array_slice($buildFailure, 0, 2),
                ], $name);
            }
            $built = 'Replay: built the front end first (npm ci, npm run build), because a later hop rebuilds it.';
        }
        $runner = $this->replayRunner ?? new ReplayRunner($this->gate, $this->processes);
        $this->replayGate = new ReplayGate($runner, $file, $path, $options->replayOut ?? ReplayGate::defaultOutDir($path), $options->gateImage);

        try {
            $outcome = $this->replayGate->record($php);
            if ($built !== null) {
                $outcome = new ReplayOutcome($outcome->status, $outcome->php, [$built, ...$outcome->lines], $outcome->file, $outcome->outDir, $outcome->transcript, $outcome->requests, $outcome->differences);
            }
        } catch (ToolError $e) {
            $outcome = new ReplayOutcome(ReplayOutcome::FAILED, $php, ['Replay baseline could not be recorded: ' . $e->getMessage()], $name);
        }
        if ($outcome->failed()) {
            $this->replayGate = null;
        }

        return $outcome;
    }

    /**
     * Runs the step's test gate. When Composer 2.10's policy blocking refuses the
     * tagged releases the lock update needs, it writes the temporary advisory
     * unblock into composer.json and runs the same gate once more: one retry,
     * never a loop. The same unblock answers the quiet variant of the same
     * refusal: with dev stability allowed, Composer does not fail the install
     * phase at all, it locks the branch instead, so the refreshed lock is read
     * back and a branch version behind a tagged range is treated like the
     * block.
     *
     * @param list<string>|null $update packages for composer update -W; the
     *        hop's composer set by default, or the hop's own list when it
     *        changes packages outside that set
     */
    private function runTestGate(PlanStep $step, string $path, RunOptions $options, BaselineResult $baseline, ?array $update = null): GateReport
    {
        $php = $step->phpAfter ?? '';
        $test = $options->testCommand ?? $this->defaultTestCommand($path);
        $update = $update ?? $this->lockUpdatePackages($step, $path);
        $unblock = [];
        $result = $this->gate->run($path, $php, $test, $options->proctorCommand, $options->gateImage, $update, $options->testEnv);
        $outcome = TargetImageRunner::gateOutcome($result);
        if (!$outcome->successful() && $outcome->phase === GateOutcome::PHASE_INSTALL && $update !== []) {
            $blocked = self::advisoryBlock($outcome->output);
            if ($blocked !== null) {
                $unblock = [sprintf(
                    'Composer refused tagged %s %s for known security advisories; Booster added a temporary, scoped unblock (config.policy.advisories.ignore). Remove it before release; Booster fails the final target while it is there.',
                    $blocked['package'],
                    $blocked['constraint'],
                )];
                try {
                    $unblock = [...$unblock, ...$this->composer->advisoryUnblock($path, $blocked['package'], $blocked['constraint'], $blocked['ids'])];
                } catch (ToolError $e) {
                    $unblock[] = 'Could not write the advisory unblock: ' . $e->getMessage();
                }
                // The ignore is in composer.json now, so the same command may
                // load the blocked releases.
                $result = $this->gate->run($path, $php, $test, $options->proctorCommand, $options->gateImage, $update, $options->testEnv);
                $outcome = TargetImageRunner::gateOutcome($result);
            }
        }
        if ($update !== [] && $outcome->phase === GateOutcome::PHASE_TESTS) {
            $dev = self::devFallback($step, $path);
            if ($dev !== null) {
                $unblock[] = sprintf(
                    'Composer locked %s to %s although the hop asks for %s: the tagged releases were refused for known security advisories; Booster added a temporary, scoped unblock (config.policy.advisories.ignore). Remove it before release; Booster fails the final target while it is there.',
                    $dev['package'],
                    $dev['version'],
                    $dev['constraint'],
                );
                try {
                    $unblock = [...$unblock, ...$this->composer->advisoryUnblock($path, $dev['package'], $dev['constraint'], [])];
                } catch (ToolError $e) {
                    $unblock[] = 'Could not write the advisory unblock: ' . $e->getMessage();
                }
                // The ignore is in composer.json now, so the same command may
                // load the tagged releases.
                $result = $this->gate->run($path, $php, $test, $options->proctorCommand, $options->gateImage, $update, $options->testEnv);
                $outcome = TargetImageRunner::gateOutcome($result);
                $still = self::devFallback($step, $path);
                if ($still !== null) {
                    // The unblock did not bring the tags back: fail where the
                    // lock was written, naming what the lock still carries.
                    $messages = [sprintf(
                        'Gate failed: composer update -W %s still resolves %s to %s after the unblock; the tagged releases are not loadable.',
                        implode(' ', $update),
                        $still['package'],
                        $still['version'],
                    )];
                    $messages[] = 'This step changed composer.json and composer.lock.';
                    $messages[] = $this->undoHint($path);

                    return new GateReport(false, [...$unblock, ...$messages]);
                }
                $unblock[] = sprintf(
                    '%s now resolves to the tagged release %s.',
                    $dev['package'],
                    self::lockedVersions($path)[$dev['package']] ?? 'a tag',
                );
            }
        }
        if ($outcome->successful()) {
            return new GateReport(true, $unblock);
        }

        // A Composer-phase failure is never reported as the test command
        // exiting N: the tests never ran, so only the Composer phase is named.
        $what = match (true) {
            $outcome->phase !== GateOutcome::PHASE_INSTALL => sprintf('"%s" exited %d', $test, $outcome->exitCode),
            $update === [] => sprintf('composer install failed (exit %d)', $outcome->exitCode),
            $update === [TargetImageRunner::LOCK_REFRESH] => sprintf('%s failed (exit %d)', self::lockCommand($update), $outcome->exitCode),
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

        return new GateReport(false, [...$unblock, ...$messages]);
    }

    /**
     * Reads Composer 2.10's advisory block out of a failed install phase: the
     * package whose tagged releases were not loaded, the constraint the root
     * requires, and the advisory IDs Composer named. Null when the failure is
     * something else (a stale lock, a platform mismatch, ...).
     *
     * @param list<string> $output
     *
     * @return array{package: string, constraint: string, ids: list<string>}|null
     */
    private static function advisoryBlock(array $output): ?array
    {
        $text = implode("\n", $output);
        if (preg_match('#Root composer\.json requires ([A-Za-z0-9_./~-]+) (\S+), found .*?affected by security advisories \(([^)]*)\)#', $text, $m) !== 1) {
            return null;
        }
        if (preg_match_all('/"([^"]+)"/', $m[3], $ids) === 0 || $ids[1] === []) {
            return null;
        }

        return ['package' => $m[1], 'constraint' => $m[2], 'ids' => $ids[1]];
    }

    /**
     * The package-to-version map of the app's lock file, across packages and
     * packages-dev (PHPUnit lives in the dev section). Empty when the lock is
     * missing or unreadable.
     *
     * @return array<string, string>
     */
    private static function lockedVersions(string $path): array
    {
        $lockFile = rtrim($path, '/\\') . '/composer.lock';
        if (!is_file($lockFile)) {
            return [];
        }
        $lock = json_decode((string) file_get_contents($lockFile), true);
        if (!is_array($lock)) {
            return [];
        }
        $locked = [];
        foreach (['packages', 'packages-dev'] as $section) {
            foreach ((array) ($lock[$section] ?? []) as $package) {
                if (is_array($package) && isset($package['name'], $package['version'])) {
                    $locked[(string) $package['name']] = (string) $package['version'];
                }
            }
        }

        return $locked;
    }

    /**
     * A silent dev fallback out of the refreshed lock: a package the hop set
     * to a tagged range that Composer locked to a branch version anyway,
     * because every tagged release matching the range was refused for security
     * advisories and dev stability was allowed. Null when every hop-set
     * package resolved to a tag (or is absent from the lock).
     *
     * @return array{package: string, constraint: string, version: string}|null
     */
    private static function devFallback(PlanStep $step, string $path): ?array
    {
        $locked = self::lockedVersions($path);
        foreach ($step->hop->composerSet as $package => $constraint) {
            if (str_contains($constraint, '-dev') || str_starts_with($constraint, 'dev-')) {
                continue; // The hop asked for a branch; a dev lock is what it wanted.
            }
            $version = $locked[$package] ?? null;
            if ($version !== null && (str_ends_with($version, '-dev') || str_starts_with($version, 'dev-'))) {
                return ['package' => $package, 'constraint' => $constraint, 'version' => $version];
            }
        }

        return null;
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
            if (!$gate->passed) {
                return new StepResult($step->number, $hop->id, StepResult::GATE_FAILED, $files, [...$changes, ...$gate->lines]);
            }
            $changes = [...$changes, ...$gate->lines, $this->gatePassedLine($step->phpAfter, $baseline->gateKind)];
        } else {
            $changes[] = 'Gate skipped. Run the tests on PHP ' . ($step->phpAfter ?? '?') . ' before the next step.';
        }

        return new StepResult($step->number, $hop->id, StepResult::APPLIED, $files, [...$changes, ...$hop->notes]);
    }

    /**
     * The WayneUI 1.x to 2.x hop. It has no Rector sets, so the migration
     * engine does the work instead: the composer swap lands first, then the
     * engine rewrites the one-to-one items and lists the context dependent
     * rest as manual lines. The test gate still runs after a real run, like
     * every other hop.
     */
    private function runWayneUiHop(PlanStep $step, string $path, RunOptions $options, BaselineResult $baseline): StepResult
    {
        $hop = $step->hop;
        if ($options->dryRun) {
            $migration = $this->wayneUiMigration->migrate($path, true);
            $general = $hop->notes === [] ? [] : ['General notes for this step (nothing found in your app, just good to know):'];
            $messages = $migration->files === []
                ? ['No edits needed for this step (dry run).', ...$migration->manual, ...$general, ...$hop->notes]
                : [...$migration->changes, $migration->summary(), ...$migration->manual, ...$hop->notes];

            return new StepResult($step->number, $hop->id, StepResult::PREVIEWED, $migration->files, $messages);
        }

        $messages = $this->composer->apply($hop, $path);
        $migration = $this->wayneUiMigration->migrate($path, false);
        if ($messages === [] && $migration->changedCount() === 0) {
            // Nothing changed, so there is nothing for the gate to prove; skip it.
            return new StepResult($step->number, $hop->id, StepResult::NOTHING_TO_DO, [], [
                'Nothing to do: the migration changed no files and composer.json already allows the target, so the gate was skipped.',
                ...$migration->manual,
                ...$hop->notes,
            ]);
        }

        if (!$options->skipGate) {
            $gate = $this->runGate($step, $path, $options, $baseline);
            if (!$gate->passed) {
                return new StepResult($step->number, $hop->id, StepResult::GATE_FAILED, $migration->files, [...$messages, ...$migration->changes, $migration->summary(), ...$migration->manual, ...$gate->lines]);
            }
            $messages = [...$messages, ...$gate->lines, $this->gatePassedLine($step->phpAfter, $baseline->gateKind)];
        } else {
            $messages[] = 'Gate skipped. Run the tests on PHP ' . ($step->phpAfter ?? '?') . ' before the next step.';
        }

        return new StepResult($step->number, $hop->id, StepResult::APPLIED, $migration->files, [...$messages, ...$migration->changes, $migration->summary(), ...$migration->manual, ...$hop->notes]);
    }

    /**
     * The vendored-to-composer hop. VendoredMigration does the work: the
     * hand copies leave vendor/, composer.json asks for the maintained
     * releases, and the require lines load Composer's autoloader. The gate
     * then installs them on the hop's PHP (an app with no lock gets its first
     * composer.lock from that install) and runs the app's tests or the syntax
     * check; with --replay the replay proves the pages answer the same.
     */
    private function runFrameworkHop(PlanStep $step, string $path, RunOptions $options, BaselineResult $baseline): StepResult
    {
        $hop = $step->hop;
        $php = $step->phpAfter ?? $step->phpBefore ?? '';
        $hadLock = is_file($path . '/composer.lock');
        $migration = $this->vendoredMigration->migrate($path, $options->dryRun, $php);
        if ($migration->refused()) {
            return new StepResult($step->number, $hop->id, StepResult::ERROR, [], [
                'Refused: Booster will not guess past a stop sign, so nothing changed.',
                ...array_map(static fn(string $reason): string => 'Stop sign: ' . $reason, $migration->refusals),
            ]);
        }
        if ($options->dryRun) {
            return new StepResult($step->number, $hop->id, StepResult::PREVIEWED, $migration->files, [...$migration->changes, ...$migration->manual]);
        }

        $messages = $migration->changes;
        if (!$options->skipGate) {
            // An app that already had a lock moves it for the new packages
            // only; a brand-new composer.json has no lock, so the plain
            // install resolves the packages on this PHP and writes one.
            $update = $hadLock && $migration->packages !== [] ? $migration->packages : [];
            $gate = $this->runGate($step, $path, $options, $baseline, $update);
            if (!$gate->passed) {
                return new StepResult($step->number, $hop->id, StepResult::GATE_FAILED, $migration->files, [...$messages, ...$migration->manual, ...$gate->lines]);
            }
            $messages = [...$messages, ...$gate->lines, $this->gatePassedLine($step->phpAfter, $baseline->gateKind)];
        } else {
            $messages[] = sprintf('Gate skipped. Run composer install and the tests on PHP %s before the next step.', $php);
        }

        return new StepResult($step->number, $hop->id, StepResult::APPLIED, $migration->files, [...$messages, ...$migration->manual]);
    }

    /**
     * The session-strict-mode hop. StrictSession does the work: a guarded
     * ini_set() line before each session_start() statement. No Composer file
     * changes, so the gate is a plain install and the app's tests (or the
     * syntax check); with --replay, the replay shows the pages still answer
     * the same. An app with nothing left to cover skips the gate.
     */
    private function runSessionHop(PlanStep $step, string $path, RunOptions $options, BaselineResult $baseline): StepResult
    {
        $hop = $step->hop;
        $migration = $this->strictSession->migrate($path, $options->dryRun);
        if ($migration->refused()) {
            return new StepResult($step->number, $hop->id, StepResult::ERROR, [], [
                'Refused: strict mode would change how this app hands out session IDs, so nothing changed.',
                ...array_map(static fn(string $reason): string => 'Stop sign: ' . $reason, $migration->refusals),
            ]);
        }
        if ($migration->files === []) {
            return new StepResult($step->number, $hop->id, StepResult::NOTHING_TO_DO, [], [...$migration->notes, 'The gate was skipped: nothing changed.']);
        }
        if ($options->dryRun) {
            return new StepResult($step->number, $hop->id, StepResult::PREVIEWED, $migration->files, [...$migration->changes, ...$migration->notes, ...$migration->manual]);
        }

        $messages = [...$migration->changes, ...$migration->notes];
        if (!$options->skipGate) {
            $gate = $this->runGate($step, $path, $options, $baseline);
            if (!$gate->passed) {
                return new StepResult($step->number, $hop->id, StepResult::GATE_FAILED, $migration->files, [...$messages, ...$migration->manual, ...$gate->lines]);
            }
            $messages = [...$messages, ...$gate->lines, $this->gatePassedLine($step->phpAfter, $baseline->gateKind)];
        } else {
            $messages[] = 'Gate skipped. Run the tests on PHP ' . ($step->phpAfter ?? '?') . ' before the next step.';
        }

        return new StepResult($step->number, $hop->id, StepResult::APPLIED, $migration->files, [...$messages, ...$migration->manual]);
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
            if (!$gate->passed) {
                return new StepResult($step->number, $hop->id, StepResult::GATE_FAILED, $files, [...$changes, ...$gate->lines]);
            }
            $changes = [...$changes, ...$gate->lines, $this->gatePassedLine($step->phpAfter, $baseline->gateKind)];
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
            return new StepResult($step->number, $hop->id, StepResult::ERROR, [], ['The codemod needs a Node runner, but this Booster has none configured.', ...$hop->notes]);
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
            return ['The build gate needs a Node runner, but this Booster has none configured.'];
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
     * rewrites the CSS and the config, Booster owns the dependency pins.
     */
    private function runTailwindHop(PlanStep $step, string $path, RunOptions $options, BaselineResult $baseline): StepResult
    {
        $hop = $step->hop;
        if ($this->node === null) {
            throw new ToolError('The Tailwind hop needs a Node runner, but this Booster has none configured.');
        }
        $toolVersion = $this->registry()->tailwindUpgradeVersion();
        $package = '@tailwindcss/upgrade@' . $toolVersion;
        if ($options->dryRun) {
            return new StepResult($step->number, $hop->id, StepResult::PREVIEWED, [], [
                sprintf('Dry run: would run npx -y %s --force in the Node container, then normalize package.json (pin tailwindcss to %s, add the matching Vite or PostCSS plugin, remove autoprefixer and postcss-import).', $package, $this->registry()->tailwindVersion()),
                ...$hop->notes,
            ]);
        }

        // --force: the tool refuses a dirty tree, and its check needs git, which the
        // slim Node image lacks. Booster started from a clean tree and never commits,
        // so earlier hops' edits are expected here and the undo line covers them all.
        $tool = $this->node->run($path, ['npx', '-y', $package, '--force']);
        if (!$tool->successful()) {
            $output = trim($tool->stdout . "\n" . $tool->stderr);
            $messages = [sprintf('The Tailwind upgrade tool failed: "npx -y %s --force" exited %d. The working copy keeps whatever it wrote, so you can read it.', $package, $tool->exitCode)];
            if (self::onlyNpmNotices($output)) {
                $messages[] = sprintf(
                    'The tool printed nothing, which usually means npx could not install it in the Node container. Try it by hand: docker run --rm -v "<app>:/app" -w /app %s npx -y %s --force',
                    NodeRunner::imageTag((int) $this->registry()->nodePinTarget()),
                    $package,
                );
            }
            if ($output !== '') {
                $messages[] = $output;
            }
            $messages[] = $this->undoHint($path);

            return new StepResult($step->number, $hop->id, StepResult::ERROR, [], $messages);
        }

        [$changes, $files] = $this->applyTailwindPackages($path);
        if ($this->usesVite($path)) {
            [$viteChanges, $viteFiles] = $this->wireTailwindVitePlugin($path);
            $changes = [...$changes, ...$viteChanges];
            $files = [...$files, ...$viteFiles];
        }
        if ($changes === []) {
            $changes[] = 'The upgrade tool ran, but package.json already matched the Tailwind 4 pins.';
        } else {
            // The tool installed its own pins; Booster's edits came after, so the
            // lock must catch up before the build gate's npm ci can pass.
            $syncFailure = $this->syncNpmLock($this->node, $path, $peerNotes);
            if ($syncFailure !== null) {
                return new StepResult($step->number, $hop->id, StepResult::ERROR, $files, [...$changes, ...$syncFailure]);
            }
            $changes = [...$changes, self::NPM_LOCK_SYNCED, ...$peerNotes];
        }

        if (!$options->skipGate) {
            $buildFailure = $this->runBuildGate($path);
            if ($buildFailure !== null) {
                return new StepResult($step->number, $hop->id, StepResult::GATE_FAILED, $files, [...$changes, ...$buildFailure]);
            }
            $gate = $this->runGate($step, $path, $options, $baseline);
            if (!$gate->passed) {
                return new StepResult($step->number, $hop->id, StepResult::GATE_FAILED, $files, [...$changes, ...$gate->lines]);
            }
            $changes = [...$changes, ...$gate->lines, 'Gate passed: npm ci, npm run build and the tests are green.'];
        } else {
            $changes[] = 'Gate skipped. Run npm ci, npm run build and the tests before the next step.';
        }

        return new StepResult($step->number, $hop->id, StepResult::APPLIED, $files, [...$changes, ...$hop->notes]);
    }

    /**
     * The Vite major hop. It pins vite wherever it sits and raises the
     * Laravel and Vue plugin pins the app already carries to the majors in
     * the data file, then runs npm install in the Node container so the lock
     * and node_modules catch up.
     */
    private function runViteMajorHop(PlanStep $step, string $path, RunOptions $options, BaselineResult $baseline): StepResult
    {
        $hop = $step->hop;
        if ($this->node === null) {
            throw new ToolError('The Vite major hop needs a Node runner, but this Booster has none configured.');
        }
        [$changes, $files] = $this->applyViteMajorPackages($path, $options->dryRun);
        if ($options->dryRun) {
            if ($changes === []) {
                $changes[] = 'Dry run: nothing to pin: package.json already matches the Vite pins.';
            }
            return new StepResult($step->number, $hop->id, StepResult::PREVIEWED, $files, [...$changes, ...$hop->notes]);
        }

        if ($changes === []) {
            return new StepResult($step->number, $hop->id, StepResult::NOTHING_TO_DO, [], [
                'Nothing to do: package.json already matches the Vite pins, so the gate was skipped.',
                ...$hop->notes,
            ]);
        }

        $syncFailure = $this->syncNpmLock($this->node, $path, $peerNotes);
        if ($syncFailure !== null) {
            return new StepResult($step->number, $hop->id, StepResult::ERROR, $files, $syncFailure);
        }
        $changes = [...$changes, self::NPM_LOCK_SYNCED, ...$peerNotes];

        if (!$options->skipGate) {
            $buildFailure = $this->runBuildGate($path);
            if ($buildFailure !== null) {
                return new StepResult($step->number, $hop->id, StepResult::GATE_FAILED, $files, [...$changes, ...$buildFailure]);
            }
            $gate = $this->runGate($step, $path, $options, $baseline);
            if (!$gate->passed) {
                return new StepResult($step->number, $hop->id, StepResult::GATE_FAILED, $files, [...$changes, ...$gate->lines]);
            }
            $changes = [...$changes, ...$gate->lines, 'Gate passed: npm ci, npm run build and the tests are green.'];
        } else {
            $changes[] = 'Gate skipped. Run npm ci, npm run build and the tests before the next step.';
        }

        return new StepResult($step->number, $hop->id, StepResult::APPLIED, $files, [...$changes, ...$hop->notes]);
    }

    /**
     * The TypeScript major hop. It pins typescript (and vue-tsc, when the app
     * has it) to the majors in the hop data, then gates on the build and the
     * tests like the Tailwind hop; the type check is a person's job.
     */
    private function runTypescriptMajorHop(PlanStep $step, string $path, RunOptions $options, BaselineResult $baseline): StepResult
    {
        $hop = $step->hop;
        if ($this->node === null) {
            throw new ToolError('The TypeScript hop needs a Node runner, but this Booster has none configured.');
        }
        $typescript = $this->registry()->typescriptVersion();
        if ($options->dryRun) {
            return new StepResult($step->number, $hop->id, StepResult::PREVIEWED, [], [
                sprintf('Dry run: would pin typescript to %s in package.json (and vue-tsc to %s when the app has it), raise %s when the app lists an older one, then gate on the build and the tests.', $typescript, $this->registry()->vueTscVersion(), implode(', ', array_map(static fn(string $name, string $pin): string => $name . ' to ' . $pin, array_keys($this->registry()->typescriptCompanionPins()), $this->registry()->typescriptCompanionPins()))),
                ...$hop->notes,
            ]);
        }

        [$changes, $files] = $this->applyTypescriptPackages($path);
        if ($changes === []) {
            $changes[] = 'package.json already matched the TypeScript pins.';
        } else {
            $syncFailure = $this->syncNpmLock($this->node, $path, $peerNotes);
            if ($syncFailure !== null) {
                return new StepResult($step->number, $hop->id, StepResult::ERROR, $files, [...$changes, ...$syncFailure]);
            }
            $changes = [...$changes, self::NPM_LOCK_SYNCED, ...$peerNotes];
        }

        if (!$options->skipGate) {
            $buildFailure = $this->runBuildGate($path);
            if ($buildFailure !== null) {
                return new StepResult($step->number, $hop->id, StepResult::GATE_FAILED, $files, [...$changes, ...$buildFailure]);
            }
            $gate = $this->runGate($step, $path, $options, $baseline);
            if (!$gate->passed) {
                return new StepResult($step->number, $hop->id, StepResult::GATE_FAILED, $files, [...$changes, ...$gate->lines]);
            }
            $changes = [...$changes, ...$gate->lines, 'Gate passed: npm ci, npm run build and the tests are green.'];
        } else {
            $changes[] = 'Gate skipped. Run npm ci, npm run build and the tests before the next step.';
        }

        return new StepResult($step->number, $hop->id, StepResult::APPLIED, $files, [...$changes, ...$hop->notes]);
    }

    /**
     * Pins vite wherever it sits and raises the Laravel and Vue plugin pins
     * the app already carries, reporting each change. A constraint that
     * already floors at or above the target stays; in a dry run nothing is
     * written and each pin is reported as what it would be.
     *
     * @return array{0: list<string>, 1: list<array{file: string, diff: string}>} what changed and which files
     */
    private function applyViteMajorPackages(string $path, bool $dryRun): array
    {
        $changes = [];
        $files = [];
        $packageFile = $path . '/package.json';
        if (!is_file($packageFile)) {
            return [$changes, $files];
        }
        $package = json_decode((string) file_get_contents($packageFile), true);
        if (!is_array($package)) {
            throw new ToolError(sprintf('package.json is not valid JSON: %s', $packageFile));
        }
        $registry = $this->registry();
        $pins = [
            'vite' => $registry->viteVersion(),
            'laravel-vite-plugin' => $registry->laravelVitePluginVersion(),
            '@vitejs/plugin-vue' => $registry->vuePluginVersion(),
        ];
        $edited = false;
        foreach (['dependencies', 'devDependencies'] as $section) {
            if (!is_array($package[$section] ?? null)) {
                continue;
            }
            foreach ($pins as $name => $pin) {
                $current = $package[$section][$name] ?? null;
                if (!is_string($current) || self::atOrAboveFloor($current, $pin)) {
                    continue;
                }
                if ($dryRun) {
                    $changes[] = sprintf('Dry run: would set package.json %s %s to %s.', $section, $name, $pin);
                } else {
                    $package[$section][$name] = $pin;
                    $changes[] = sprintf('package.json: %s %s to %s.', $section, $name, $pin);
                    $edited = true;
                }
                $files[] = ['file' => 'package.json', 'diff' => $name . ' to ' . $pin];
            }
        }
        if (!$dryRun && $edited) {
            file_put_contents($packageFile, json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        }

        return [$changes, $files];
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
                $changes[] = sprintf('%s: Booster could not rewrite the mix() tags; move them by hand.', $this->relative($path, $file));

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
     * Normalizes package.json for the TypeScript major: pin typescript
     * wherever it sits, and vue-tsc to its pin when the app lists it. A fresh
     * add is a human decision, so nothing is installed that was not there.
     *
     * @return array{0: list<string>, 1: list<array{file: string, diff: string}>} what changed and which files
     */
    private function applyTypescriptPackages(string $path): array
    {
        $changes = [];
        $files = [];
        $packageFile = $path . '/package.json';
        $package = json_decode((string) file_get_contents($packageFile), true);
        if (!is_array($package)) {
            throw new ToolError(sprintf('package.json is not valid JSON: %s', $packageFile));
        }
        $typescript = $this->registry()->typescriptVersion();
        $vueTsc = $this->registry()->vueTscVersion();
        $edited = false;
        $vueTscPinned = false;
        $companions = [];
        foreach (['dependencies', 'devDependencies'] as $section) {
            if (!is_array($package[$section] ?? null)) {
                continue;
            }
            if (array_key_exists('typescript', $package[$section]) && $package[$section]['typescript'] !== $typescript) {
                $package[$section]['typescript'] = $typescript;
                $changes[] = 'package.json: ' . $section . ' typescript to ' . $typescript . '.';
                $edited = true;
            }
            if (array_key_exists('vue-tsc', $package[$section]) && $package[$section]['vue-tsc'] !== $vueTsc) {
                $package[$section]['vue-tsc'] = $vueTsc;
                $changes[] = 'package.json: ' . $section . ' vue-tsc to ' . $vueTsc . '.';
                $edited = true;
                $vueTscPinned = true;
            }
            // Companions whose older releases refuse the new vue-tsc as a peer:
            // npm install only warns once, and the next hop's install fails.
            foreach ($this->registry()->typescriptCompanionPins() as $name => $pin) {
                $current = $package[$section][$name] ?? null;
                if (is_string($current) && HopDataRefresher::floorsBelow($current, $pin)) {
                    $package[$section][$name] = $pin;
                    $changes[] = 'package.json: ' . $section . ' ' . $name . ' to ' . $pin . ' (older releases refuse the new vue-tsc as a peer).';
                    $edited = true;
                    $companions[] = $name . ' to ' . $pin;
                }
            }
        }
        if ($edited) {
            file_put_contents($packageFile, json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
            $diff = 'pinned typescript to ' . $typescript;
            if ($vueTscPinned) {
                $diff .= ' and vue-tsc to ' . $vueTsc;
            }
            if ($companions !== []) {
                $diff .= ', raised ' . implode(', ', $companions);
            }
            $files[] = ['file' => 'package.json', 'diff' => $diff];
        }

        return [$changes, $files];
    }

    /**
     * Wires the @tailwindcss/vite plugin into the Vite config when Tailwind 3
     * was fed through an inline css.postcss block there, the way the Laravel
     * starter kits of early 2025 did it; the upgrade tool only migrates
     * postcss.config files, so the build would still import tailwindcss and
     * autoprefixer as PostCSS plugins (found on a real app, 2026-11-06).
     *
     * Only that exact shape is rewritten: the imports of tailwindcss (and
     * autoprefixer), and a css block holding nothing but those PostCSS
     * plugins. Anything else that still imports them gets a MANUAL line.
     *
     * @return array{0: list<string>, 1: list<array{file: string, diff: string}>} what changed and which files
     */
    private function wireTailwindVitePlugin(string $path): array
    {
        $name = null;
        foreach (['vite.config.ts', 'vite.config.js', 'vite.config.mts', 'vite.config.mjs'] as $candidate) {
            if (is_file($path . '/' . $candidate)) {
                $name = $candidate;
                break;
            }
        }
        if ($name === null) {
            return [[], []];
        }
        $source = (string) file_get_contents($path . '/' . $name);
        if (str_contains($source, '@tailwindcss/vite')) {
            return [[], []];
        }
        $importsTailwind = preg_match('/^import\s+(\w+)\s+from\s+([\'"])tailwindcss\2;?[ \t]*\r?\n/m', $source, $tw) === 1;
        $importsPrefixer = preg_match('/^import\s+(\w+)\s+from\s+([\'"])autoprefixer\2;?[ \t]*\r?\n/m', $source, $ap) === 1;
        if (!$importsTailwind && !$importsPrefixer) {
            return [[], []];
        }
        $manual = [sprintf('MANUAL: %s still imports tailwindcss or autoprefixer as PostCSS plugins; replace them with the tailwindcss() plugin from @tailwindcss/vite in its plugins list.', $name)];
        if (!$importsTailwind) {
            return [$manual, []];
        }
        $plugins = preg_quote($tw[1], '/') . ($importsPrefixer ? '(?:\s*,\s*' . preg_quote($ap[1], '/') . ')?' : '');
        $cssBlock = '/\r?\n[ \t]*css:\s*\{\s*postcss:\s*\{\s*plugins:\s*\[\s*' . $plugins . '\s*,?\s*\]\s*,?\s*\}\s*,?\s*\}\s*,?(?=\s*\r?\n)/';
        if (preg_match_all($cssBlock, $source) !== 1) {
            return [$manual, []];
        }
        $rewritten = (string) preg_replace($cssBlock, '', $source, 1);
        if (preg_match('/\bplugins:\s*\[([ \t]*\r?\n([ \t]*))?/', $rewritten, $open, PREG_OFFSET_CAPTURE) !== 1) {
            return [$manual, []];
        }
        $indent = isset($open[2]) ? $open[2][0] : '';
        $at = $open[0][1] + strlen($open[0][0]);
        $insert = isset($open[2]) ? $tw[1] . "(),\n" . $indent : $tw[1] . '(), ';
        $rewritten = substr($rewritten, 0, $at) . $insert . substr($rewritten, $at);
        $rewritten = str_replace($tw[0], 'import ' . $tw[1] . ' from ' . $tw[2] . '@tailwindcss/vite' . $tw[2] . ";\n", $rewritten);
        if ($importsPrefixer) {
            $rewritten = str_replace($ap[0], '', $rewritten);
        }
        file_put_contents($path . '/' . $name, $rewritten);

        return [
            [sprintf('%s: the Tailwind PostCSS block became the %s() plugin from @tailwindcss/vite.', $name, $tw[1])],
            [['file' => $name, 'diff' => 'moved Tailwind from css.postcss to the @tailwindcss/vite plugin']],
        ];
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

        return $this->applyInertiaChanges($plan->project->path, $this->registry()->inertiaChanges($step->laravelAfter), $dryRun);
    }

    /**
     * Raises the Inertia constraints an app already carries toward $wanted.
     * Only packages the app has are touched: a Vue 2 app never gains
     * @inertiajs/vue3, and a fresh add is a human decision.
     *
     * @param array{composer: array<string, string>, npm: array<string, string>} $wanted
     * @return array{0: list<string>, 1: list<array{file: string, diff: string}>}
     */
    private function applyInertiaChanges(string $path, array $wanted, bool $dryRun): array
    {
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
     * The Inertia 2 to 3 hop. It pins both Inertia packages at 3, installs
     * the new front end with npm ci, and lets the composer gate prove the app
     * still loads; the upgrade guide's breaking changes stay manual lines in
     * the hop's notes.
     */
    private function runInertiaHop(PlanStep $step, string $path, RunOptions $options, BaselineResult $baseline): StepResult
    {
        $hop = $step->hop;
        [$changes, $files] = $this->applyInertiaChanges($path, $this->registry()->inertiaChangesNext(), $options->dryRun);

        if ($options->dryRun) {
            if ($files === []) {
                $changes[] = 'Dry run: nothing to change; the Inertia constraints already floor at 3.';
            } else {
                $installs = [];
                if (self::fileTouched($files, 'package.json')) {
                    $installs[] = 'npm install';
                }
                if (self::fileTouched($files, 'composer.json')) {
                    $installs[] = 'composer update -W inertiajs/inertia-laravel';
                }
                $changes[] = 'Dry run: would install the new Inertia versions (' . implode(', ', $installs) . ').';
            }

            return new StepResult($step->number, $hop->id, StepResult::PREVIEWED, $files, [...$changes, ...$hop->notes]);
        }

        if ($files === []) {
            return new StepResult($step->number, $hop->id, StepResult::NOTHING_TO_DO, [], [
                'Nothing to do: the Inertia constraints already floor at 3, so the gate was skipped.',
                ...$hop->notes,
            ]);
        }

        if (self::fileTouched($files, 'package.json')) {
            if ($this->node === null) {
                $changes[] = 'No Node runner configured, so npm install did not run; run it yourself before relying on the front end.';
            } else {
                // npm install, not npm ci: the lock still names Inertia 2, and
                // npm ci refuses a lock that disagrees with package.json.
                $syncFailure = $this->syncNpmLock($this->node, $path, $peerNotes);
                if ($syncFailure !== null) {
                    return new StepResult($step->number, $hop->id, StepResult::GATE_FAILED, $files, [...$changes, ...$syncFailure]);
                }
                $changes = [...$changes, self::NPM_LOCK_SYNCED, ...$peerNotes];
            }
        }

        if (!$options->skipGate) {
            // The client moved a major (ESM only, removed APIs, a new page
            // payload), so the front end must build before the tests count;
            // otherwise public/build keeps the Inertia 2 client while the
            // server prints the Inertia 3 page (found on WELD, 2026-11-06).
            if ($this->node !== null && self::fileTouched($files, 'package.json')) {
                $buildFailure = $this->runBuildGate($path);
                if ($buildFailure !== null) {
                    return new StepResult($step->number, $hop->id, StepResult::GATE_FAILED, $files, [...$changes, ...$buildFailure]);
                }
                $changes[] = 'Build gate passed: npm ci and npm run build are green with the Inertia 3 client.';
            }
            $gate = $this->runGate($step, $path, $options, $baseline, $this->inertiaUpdatePackages($path));
            if (!$gate->passed) {
                return new StepResult($step->number, $hop->id, StepResult::GATE_FAILED, $files, [...$changes, ...$gate->lines]);
            }
            $changes = [...$changes, ...$gate->lines, $this->gatePassedLine($step->phpAfter, $baseline->gateKind)];
        } else {
            $changes[] = 'Gate skipped. Run the tests on PHP ' . ($step->phpAfter ?? '?') . ' before the next step.';
        }

        return new StepResult($step->number, $hop->id, StepResult::APPLIED, $files, [...$changes, ...$hop->notes]);
    }

    /**
     * The packages the Inertia 3 hop must refresh in the lock: the
     * inertia-laravel pin when the app requires it, empty otherwise so a
     * plain install keeps the unchanged lock honest.
     *
     * @return list<string>
     */
    private function inertiaUpdatePackages(string $path): array
    {
        $composerFile = $path . '/composer.json';
        if (!is_file($composerFile)) {
            return [];
        }
        $composer = json_decode((string) file_get_contents($composerFile), true);
        if (is_array($composer) && array_key_exists('inertiajs/inertia-laravel', $composer['require'] ?? [])) {
            return ['inertiajs/inertia-laravel'];
        }

        return [];
    }

    /**
     * Whether $files names $file among the files a change touched.
     *
     * @param list<array{file: string, diff: string}> $files
     */
    private static function fileTouched(array $files, string $name): bool
    {
        foreach ($files as $entry) {
            if ($entry['file'] === $name) {
                return true;
            }
        }

        return false;
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
            return ['The build gate needs a Node runner, but this Booster has none configured.'];
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

    /**
     * What runs in the gate when the user passed no --test-command. The app's
     * own suite wins: a composer "test" script, then PHPUnit or Pest when the
     * app declares one (a config file or a require line). An app with neither
     * gets the built-in syntax check instead of a missing vendor/bin/phpunit.
     */
    private function defaultTestCommand(string $path): string
    {
        $composer = is_file($path . '/composer.json') ? json_decode((string) file_get_contents($path . '/composer.json'), true) : null;
        $scripts = is_array($composer) && is_array($composer['scripts'] ?? null) ? $composer['scripts'] : [];
        if (array_key_exists('test', $scripts)) {
            return 'composer test';
        }
        foreach (['phpunit.xml', 'phpunit.xml.dist', 'phpunit.dist.xml'] as $config) {
            if (is_file($path . '/' . $config)) {
                return 'vendor/bin/phpunit';
            }
        }
        $packages = [];
        if (is_array($composer)) {
            foreach (['require-dev', 'require'] as $section) {
                if (is_array($composer[$section] ?? null)) {
                    $packages = [...$packages, ...array_keys($composer[$section])];
                }
            }
        }
        foreach (['phpunit/phpunit', 'pestphp/pest'] as $package) {
            if (in_array($package, $packages, true)) {
                return 'vendor/bin/phpunit';
            }
        }

        return self::SYNTAX_CHECK_COMMAND;
    }

    /**
     * Which gate this run uses, so the report can name it: the user's
     * --test-command, the app's own suite, or the built-in syntax check for
     * an app with no tests.
     */
    private function gateKind(RunOptions $options, string $path): string
    {
        if ($options->testCommand !== null) {
            return BaselineResult::GATE_CUSTOM;
        }

        return $this->defaultTestCommand($path) === self::SYNTAX_CHECK_COMMAND
            ? BaselineResult::GATE_SYNTAX
            : BaselineResult::GATE_TESTS;
    }

    /**
     * The step's closing line for a green gate, named by what the gate ran.
     */
    private function gatePassedLine(?string $phpAfter, string $gateKind): string
    {
        $php = $phpAfter ?? '?';

        return match ($gateKind) {
            BaselineResult::GATE_SYNTAX => 'Gate passed: syntax check is clean on PHP ' . $php . '.',
            BaselineResult::GATE_CUSTOM => 'Gate passed: your test command passed on PHP ' . $php . '.',
            default => 'Gate passed: tests are green on PHP ' . $php . '.',
        };
    }

    /**
     * Why a red baseline stops the run, named by what the baseline ran.
     */
    private function baselineFailureReason(BaselineResult $baseline): string
    {
        if ($baseline->gateKind === BaselineResult::GATE_SYNTAX) {
            return sprintf('a PHP file does not parse on PHP %s before any change. Fix it or pass --allow-failing-baseline.', $baseline->php ?? '?');
        }

        return "the app's tests fail before any change. Fix them or pass --allow-failing-baseline.";
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

    /**
     * Runs npm install in the Node container after Booster edited package.json,
     * so package-lock.json catches up before any npm ci (found on a real app,
     * 2026-11-06: npm ci refuses a lock that disagrees with package.json).
     *
     * When npm had to override a peer dependency to get there, $peerNotes gets
     * a MANUAL line per conflict: npm only warns this once, and the next plain
     * npm install refuses the same tree with ERESOLVE (also found 2026-11-06).
     *
     * @param list<string>|null $peerNotes set to the peer-override lines (empty when there were none)
     *
     * @param-out list<string> $peerNotes
     *
     * @return list<string>|null failure lines, or null when the install passed
     */
    private function syncNpmLock(NodeRunner $node, string $path, ?array &$peerNotes = null): ?array
    {
        $peerNotes = [];
        $install = $node->run($path, ['npm', 'install']);
        if ($install->successful()) {
            $peerNotes = self::peerOverrideNotes($install->stdout . "\n" . $install->stderr);

            return null;
        }

        return [
            sprintf('npm install failed: it exited %d in the Node container. The working copy keeps the pins, so you can read them.', $install->exitCode),
            trim($install->stdout . "\n" . $install->stderr),
            $this->undoHint($path),
        ];
    }

    /**
     * One MANUAL line per distinct peer conflict npm overrode during an
     * install ("npm warn ERESOLVE overriding peer dependency", then a
     * "peer[Optional] <wanted> from <package>" line).
     *
     * @return list<string>
     */
    private static function peerOverrideNotes(string $output): array
    {
        if (!str_contains($output, 'ERESOLVE overriding peer dependency')) {
            return [];
        }
        preg_match_all('/^npm warn\s+peer(?:Optional)?\s+(\S+)\s+from\s+(\S+)\s*$/m', $output, $matches, PREG_SET_ORDER);
        $notes = [];
        foreach ($matches as $match) {
            $notes[$match[2] . ' ' . $match[1]] = sprintf(
                'MANUAL: npm install had to override a peer dependency: %s asks for %s. The build passed, but the next plain npm install refuses this tree (ERESOLVE); raise %s to a release that accepts the new version.',
                $match[2],
                $match[1],
                self::npmPackageName($match[2]),
            );
        }
        if ($notes === []) {
            $notes[] = 'MANUAL: npm install had to override a peer dependency (ERESOLVE). The build passed, but the next plain npm install may refuse this tree; run it and raise the package that asks for the old version.';
        }

        return array_values($notes);
    }

    /** "prettier-plugin-organize-imports@4.1.0" or "@scope/name@1.2.3" to the bare package name. */
    private static function npmPackageName(string $spec): string
    {
        $at = strrpos($spec, '@');

        return $at > 0 ? substr($spec, 0, $at) : $spec;
    }

    /**
     * True when a tool's output is empty or only npm's own notices, which is how
     * npx looks when it never managed to install the package it was asked for.
     */
    private static function onlyNpmNotices(string $output): bool
    {
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, 'npm notice')) {
                return false;
            }
        }

        return true;
    }

    /**
     * The composer.lock fingerprint (its md5, or null when the file is absent),
     * so the run can tell whether the upgrade changed the lock at all.
     */
    private function lockFingerprint(string $path): ?string
    {
        // A lock we cannot read counts as absent, so no change is reported.
        return self::fileFingerprint($path . '/composer.lock');
    }

    /**
     * The fingerprint of the app's composer files, so a step can tell whether
     * it changed what the app declares. Each entry is the file's md5, or null
     * when the file is absent or unreadable, mirroring lockFingerprint.
     *
     * @return array{json: ?string, lock: ?string}
     */
    private function composerFingerprint(string $path): array
    {
        return [
            'json' => self::fileFingerprint($path . '/composer.json'),
            'lock' => self::fileFingerprint($path . '/composer.lock'),
        ];
    }

    /** The md5 of one file, or null when it is absent or unreadable. */
    private static function fileFingerprint(string $file): ?string
    {
        if (!is_file($file)) {
            return null;
        }
        $hash = md5_file($file);

        return $hash === false ? null : $hash;
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
            throw new ToolError('The app is not a git working copy (or git is missing), so Booster cannot make the change reversible. Put it under git, or pass --allow-dirty.');
        }
        if (trim($status->stdout) !== '') {
            throw new ToolError('The working copy has uncommitted changes. Commit or stash them so the upgrade diff stands alone, or pass --allow-dirty.');
        }
    }
}
