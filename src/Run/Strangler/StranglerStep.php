<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run\Strangler;

use Closure;
use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Plan\PlanStep;
use Waynestate\Booster\Run\GateReport;
use Waynestate\Booster\Run\RunOptions;
use Waynestate\Booster\Run\StepResult;
use Waynestate\Booster\Strangler\AddsComposerPackages;
use Waynestate\Booster\Strangler\Ledger;
use Waynestate\Booster\Strangler\MoveResult;
use Waynestate\Booster\Strangler\SlimMover;
use Waynestate\Booster\Strangler\Unit;
use Waynestate\Booster\ToolError;

/**
 * HopExecutor's strangler branch (design section 6). One hop, many runs,
 * and the ledger (booster-strangler.json) is the only state between them:
 *
 *  - run 1 (no ledger): the stop signs, then Step 0 (the legacy half signs
 *    in through SESAME, gated on the replay as it is served today), then the
 *    mount (gated with --serve-changed against the untouched app's
 *    answers), then it stops;
 *  - a later run: --moves N units in move order (default 1), or one
 *    --route "<id>", each gated by the replay with the moved units answering
 *    from Laravel; --hand --route "<id>" flips a unit a person ported, once
 *    route:list shows it; --finish, when nothing is left in legacy.
 *
 * Booster never commits, so every run is one diff, and a failed gate leaves
 * its units marked failed with the changes in place, like every other hop.
 */
final class StranglerStep
{
    /**
     * @param Closure(list<string>): GateReport                            $legacyGate the step's normal gate (tests, then the replay as served now), for Step 0; takes the request paths whose sign-in hand-off Step 0 changed on purpose
     * @param Closure(bool, list<string>=): GateReport                     $mountedGate the gate on the mounted tree; true when this run changed how the app is served (the mount, the finish); then the paths whose sign-in Step 0 moved in this same run
     * @param Closure(): bool                                              $dirty       whether the working copy has uncommitted changes
     * @param Closure(): (list<array{methods: list<string>, uri: string}>|string) $routeList   Laravel's route:list, or why it could not be read
     * @param Closure(): string                                            $now         the time stamp the ledger records
     * @param Closure(): (list<\Waynestate\Booster\Replay\ReplayAnswer>|null) $baselineAnswers the answers this run recorded before the first step, or null
     * @param Closure(list<string>): ?string                              $composerUpdate runs composer update -W with these packages on the Laravel root; null when it passed, else Composer's first error line
     */
    public function __construct(
        private readonly StranglerParts $parts,
        private readonly Closure $legacyGate,
        private readonly Closure $mountedGate,
        private readonly Closure $dirty,
        private readonly Closure $routeList,
        private readonly Closure $now,
        private readonly ?Closure $baselineAnswers = null,
        private readonly ?Closure $composerUpdate = null,
    ) {}

    /** The app this run works on, so every report can list its Slim-hosted units. */
    private ?string $appPath = null;

    /**
     * @param string|null $line        the framework line the app is on now (from detect)
     * @param string      $newestMajor the latest major of that framework Booster moves to
     * @param string      $next        the start of the next command ("booster run --path=app --only slim-to-laravel --replay")
     */
    public function run(PlanStep $step, string $path, RunOptions $options, ?string $line, string $newestMajor, string $next): StepResult
    {
        $hop = $step->hop;
        $this->appPath = $path;
        $framework = HopRegistry::stranglerFramework($hop->id) ?? throw new ToolError(sprintf('%s is not a strangler hop.', $hop->id));
        $ledger = Ledger::load($path);
        if ($ledger !== null && $ledger->framework !== $framework) {
            return $this->refused($step, [sprintf('%s says this is a %s move, but the hop is %s.', Ledger::FILE, $ledger->framework, $hop->id)]);
        }
        $noReplay = StranglerStopSigns::noReplay($options->replay !== null);
        if ($ledger === null) {
            if ($options->moves !== null || $options->route !== null || $options->hand || $options->finish) {
                return $this->refused($step, [sprintf('The app is not mounted yet (no %s), so there is nothing to move or finish. Run 1 does Step 0 and the mount: %s', Ledger::FILE, $next)]);
            }

            return $this->mount($step, $path, $options, $framework, $line, $newestMajor, $next, $noReplay);
        }
        if ($noReplay !== null && !$options->dryRun) {
            return $this->refused($step, [$noReplay]);
        }
        if ($options->finish) {
            return $this->finish($step, $path, $options, $framework, $ledger, $next);
        }
        if ($options->hand) {
            return $this->hand($step, $path, $options, $framework, $ledger, $next);
        }

        return $this->moves($step, $path, $options, $framework, $ledger, $next);
    }

    private function mount(PlanStep $step, string $path, RunOptions $options, string $framework, ?string $line, string $newestMajor, string $next, ?string $noReplay): StepResult
    {
        $replayReason = $noReplay;
        if ($replayReason === null && $options->replay !== null) {
            $replayReason = $this->parts->replayUnusable($path, $this->baselineAnswers === null ? null : ($this->baselineAnswers)(), $options->replay->path);
            if ($replayReason !== null) {
                $replayReason = 'The replay cannot gate the strangler: ' . $replayReason;
            }
        }
        $dirty = !$options->dryRun && ($this->dirty)();
        $signs = StranglerStopSigns::beforeMount($path, $framework, $line, $newestMajor, $step->phpBefore ?? '0', $dirty, $options->dryRun ? null : $replayReason);
        if ($signs !== []) {
            return $this->refused($step, $signs);
        }

        $report = new StranglerReport('mount', $framework);
        $inspect = $this->parts->signInInspect($path, $framework);
        if ($inspect->refusedNow()) {
            return $this->refused($step, $inspect->refusals, $report);
        }
        $step0Done = $inspect->status === PartResult::ALREADY;
        if ($options->dryRun) {
            $lines = [
                $step0Done ? 'Step 0: nothing to move (the legacy half signs nobody in, or already signs in through SESAME with the shared sign-out store).' : 'Step 0: would move the legacy half\'s sign-in onto SESAME, then gate it on the replay as the app is served today.',
                ...$inspect->lines,
                'Mount: would lay Laravel ' . HopRegistry::STRANGLER_LARAVEL . ' at the root (sourdough new --laravel), move the old Composer root unchanged to legacy/, write the dispatcher in public/index.php and an empty route table, then gate it with the replay against the untouched app (--serve-changed).',
                ...($replayReason === null ? [] : ['Note: a real run refuses here: ' . $replayReason]),
                'Then it stops. Later runs move units: ' . $next . ' --moves 1',
            ];
            $report->next = $next;

            return $this->result($step, StepResult::PREVIEWED, [], [...$lines, ...$inspect->manual], $report);
        }

        $files = [];
        $messages = [];
        // The recording predates a Step 0 that runs now, so the mount's gate accepts the same sign-in changes.
        $step0Changes = [];
        if ($step0Done) {
            $messages[] = 'Step 0: nothing to change (the legacy half signs nobody in, or already signs in through SESAME with the shared sign-out store).';
            $messages = [...$messages, ...$inspect->lines];
        } else {
            $migrate = $this->parts->signInMigrate($path, $framework);
            if ($migrate->refusedNow()) {
                return $this->refused($step, $migrate->refusals, $report);
            }
            $files = [...$files, ...$migrate->changedFiles];
            $report->addManual('sign-in', $migrate->manual);
            $messages = [...$messages, 'Step 0: the legacy half now signs in through SESAME.', ...$migrate->lines];
            $gate = ($this->legacyGate)($migrate->replayChanges);
            if (!$gate->passed) {
                return $this->result($step, StepResult::GATE_FAILED, $files, [
                    ...$messages,
                    ...$gate->lines,
                    'Step 0 did not pass its gate, so the mount did not run. The sign-in change is in place for you to read; fix it, commit, and run again.',
                ], $report);
            }
            $messages = [...$messages, ...self::prefix('Step 0 gate: ', $gate->lines)];
            $step0Changes = $migrate->replayChanges;
        }
        $report->step0 = Ledger::STEP0_DONE;

        $mount = $this->parts->mount($path, $framework);
        if ($mount->refusedNow()) {
            $kept = $files === [] ? 'Nothing changed.' : 'Step 0\'s sign-in change is in place and passed its gate; commit it on its own if you like.';

            return $this->result($step, StepResult::ERROR, $files, [
                ...$messages,
                'Refused: the mount hit a stop sign. ' . $kept,
                ...array_map(static fn(string $r): string => 'Stop sign: ' . $r, $mount->refusals),
            ], $report);
        }
        $files = [...$files, ...$mount->changedFiles];
        $report->addManual('mount', $mount->manual);
        $messages = [...$messages, ...$mount->lines];

        // SignInStep::migrate again, now that legacy/ is there: it wires the Laravel half (SESAME under /_laravel, the shared revocation store).
        $wire = $this->parts->signInMigrate($path, $framework);
        if ($wire->refusedNow()) {
            return $this->result($step, StepResult::ERROR, $files, [
                ...$messages,
                sprintf('Refused: the mount is in place, but the Laravel half\'s sign-in could not be wired, so no %s was written. Undo the run (git checkout -- . && git clean -fd), fix the cause, and run again.', Ledger::FILE),
                ...array_map(static fn(string $r): string => 'Stop sign: ' . $r, $wire->refusals),
            ], $report);
        }
        $files = [...$files, ...$wire->changedFiles];
        $report->addManual('sign-in', $wire->manual);
        $messages = [...$messages, ...$wire->lines];

        try {
            $units = $this->parts->unitSource($framework)->units($path);
        } catch (ToolError $e) {
            return $this->result($step, StepResult::ERROR, $files, [...$messages, 'The mount is in place, but the legacy units could not be read: ' . $e->getMessage()], $report);
        }
        $gate = ($this->mountedGate)(true, $step0Changes);
        if (!$gate->passed) {
            return $this->result($step, StepResult::GATE_FAILED, $files, [
                ...$messages,
                ...$gate->lines,
                sprintf('The mount did not pass its gate, so no %s was written: this run is not a mount yet. Read the changes, then undo them (git checkout -- . && git clean -fd) and run again once the cause is fixed.', Ledger::FILE),
            ], $report);
        }
        $ledger = Ledger::start($framework, ($this->now)(), Ledger::STEP0_DONE, $units);
        $ledger->save($path);
        $files[] = Ledger::FILE;
        $report->ledger($ledger, $units);
        $report->next = $next . ' --moves 1';
        $messages = [
            ...$messages,
            ...$gate->lines,
            sprintf('Mounted: %d unit%s found in legacy/, all still answering from legacy. The ledger is %s.', count($units), count($units) === 1 ? '' : 's', Ledger::FILE),
            'This run stops here (run 1 is Step 0 and the mount). Deploy the mount and the Citadel public directory change together.',
        ];

        return $this->result($step, StepResult::APPLIED, $files, $messages, $report);
    }

    private function moves(PlanStep $step, string $path, RunOptions $options, string $framework, Ledger $ledger, string $next): StepResult
    {
        $report = new StranglerReport('moves', $framework);
        $report->step0 = $ledger->step0();
        try {
            $units = $this->parts->unitSource($framework)->units($path);
        } catch (ToolError $e) {
            return $this->refused($step, ['The legacy units could not be read: ' . $e->getMessage()], $report);
        }
        $sync = $ledger->sync($units);
        $byId = [];
        foreach ($units as $unit) {
            $byId[$unit->id] = $unit;
        }
        $messages = [];
        if ($sync['added'] !== []) {
            $messages[] = sprintf('New in legacy since the last run, added to the ledger: %s.', implode(', ', $sync['added']));
        }
        if ($sync['dropped'] !== []) {
            $messages[] = sprintf('Gone from legacy, dropped from the ledger: %s.', implode(', ', $sync['dropped']));
        }

        if ($options->route !== null) {
            $unit = $byId[$options->route] ?? null;
            if ($unit === null) {
                return $this->refused($step, [self::unknownRoute($options->route, $ledger)], $report);
            }
            $state = $ledger->state($unit->id);
            if ($state === Ledger::LARAVEL || $state === Ledger::HAND) {
                return $this->result($step, StepResult::NOTHING_TO_DO, [], [sprintf('%s already answers from Laravel (%s).', $unit->id, $state)], $report->withNext($next . ' --moves 1'));
            }
            $candidates = [$unit];
            $budget = 1;
        } else {
            $candidates = [];
            foreach ($units as $unit) {
                // A failed unit waits for a person: only --route retries it.
                if ($ledger->state($unit->id) === Ledger::LEGACY) {
                    $candidates[] = $unit;
                }
            }
            $budget = max(1, $options->moves ?? 1);
        }
        if ($candidates === []) {
            $left = $ledger->left();
            $report->ledger($ledger, $units);
            if ($left === []) {
                $report->next = $next . ' --finish';

                return $this->result($step, StepResult::NOTHING_TO_DO, [], [...$messages, 'Every unit answers from Laravel. Next: --finish deletes legacy/ and puts back the stock front controller.'], $report);
            }

            return $this->result($step, StepResult::NOTHING_TO_DO, [], [...$messages, sprintf('Nothing left to move automatically: %d unit%s failed a gate and wait%s for a person (%s). Fix one and retry it with --route, or port it and run --hand --route.', count($left), count($left) === 1 ? '' : 's', count($left) === 1 ? 's' : '', implode(', ', $left))], $report->withNext($next . ' --route "' . $left[0] . '"'));
        }

        $moved = [];
        $files = [];
        $composerFailure = null;
        // The unit source gives the units in move order already: never re-sort them.
        foreach ($candidates as $unit) {
            if (count($moved) >= $budget) {
                break;
            }
            if ($unit->needsSignIn && $ledger->step0() !== Ledger::STEP0_DONE) {
                $report->refuse($unit->id, '', 0, 'needs sign-in, and Step 0 (sign-in on SESAME) is not done');
                continue;
            }
            if ($options->dryRun) {
                $moved[] = $unit;
                continue;
            }
            $mover = null;
            try {
                $mover = $this->parts->mover($framework);
                $result = $mover->move($unit, $path);
            } catch (ToolError $e) {
                $result = new MoveResult(false, [], [['file' => $unit->sourceFile, 'line' => $unit->sourceLine, 'reason' => $e->getMessage()]], []);
            }
            $report->addManual('route moves', $result->manual);
            if (!$result->moved || $result->refusals !== []) {
                foreach ($result->refusals as $refusal) {
                    $report->refuse($unit->id, $refusal['file'], $refusal['line'], $refusal['reason']);
                }
                if ($result->refusals === []) {
                    $report->refuse($unit->id, $unit->sourceFile, $unit->sourceLine, 'the mover did not move it and gave no reason');
                }
                continue;
            }
            $files = [...$files, ...$result->changedFiles];
            $adds = $mover instanceof AddsComposerPackages && in_array('composer.json', $result->changedFiles, true) ? $mover->composerAdds() : [];
            if ($adds !== []) {
                // The moved code needs a package on the Laravel root: install it before the gate.
                $packages = array_keys($adds);
                $error = $this->composerUpdate === null ? 'this build has no Composer runner for the strangler' : ($this->composerUpdate)($packages);
                if ($error !== null) {
                    $composerFailure = ['unit' => $unit, 'packages' => $packages, 'error' => $error];
                    break;
                }
                $files[] = 'composer.lock';
                $messages[] = sprintf('composer update -W %s on the Laravel root for %s.', implode(' ', $packages), $unit->id);
            }
            $this->parts->routeAdd($path, $unit);
            $files[] = StranglerFinish::ROUTE_TABLE;
            $moved[] = $unit;
        }

        if ($options->dryRun) {
            $report->ledger($ledger, $units);
            $ids = array_map(static fn(Unit $u): string => $u->id, $moved);

            return $this->result($step, StepResult::PREVIEWED, [], [...$messages, $ids === [] ? 'Would move nothing.' : 'Would move, in this order: ' . implode(', ', $ids) . '. Each run is gated by the replay with the moved units answering from Laravel.'], $report->withNext($next . ' --moves ' . max(1, count($ids))));
        }

        if ($composerFailure !== null) {
            $failedUnit = $composerFailure['unit'];
            $reason = sprintf('composer update -W %s failed on the Laravel root: %s', implode(' ', $composerFailure['packages']), $composerFailure['error']);
            $ledger->mark($failedUnit->id, Ledger::FAILED);
            $report->fail($failedUnit->id, $reason);
            // The units this run moved before it never reached the gate.
            foreach ($moved as $unit) {
                $ledger->mark($unit->id, Ledger::FAILED);
                $report->fail($unit->id, sprintf('not gated: the run stopped at the composer update for %s', $failedUnit->id));
            }
            $ledger->save($path);
            $report->ledger($ledger, $units);

            return $this->result($step, StepResult::GATE_FAILED, self::unique([...$files, Ledger::FILE]), [
                ...$messages,
                sprintf('%s moved, but %s', $failedUnit->id, $reason),
                sprintf('The run stopped before the gate. %s marked failed in %s with the changes in place; fix composer.json and retry with --route, or undo this run (git checkout -- . && git clean -fd).', implode(', ', [$failedUnit->id, ...array_map(static fn(Unit $u): string => $u->id, $moved)]) . ($moved === [] ? ' is' : ' are'), Ledger::FILE),
                ...$report->refusalLines(),
            ], $report);
        }

        if ($moved === []) {
            $report->ledger($ledger, $units);
            $first = $report->refusals[0]['unit'] ?? null;

            return $this->result($step, StepResult::ERROR, [], [
                ...$messages,
                'Refused: no unit could move, so nothing changed. Port a refused unit by hand, then flip it with --hand --route.',
                ...$report->refusalLines(),
            ], $report->withNext($first === null ? null : $next . ' --hand --route "' . $first . '"'));
        }

        $at = ($this->now)();
        foreach ($moved as $unit) {
            $ledger->mark($unit->id, Ledger::LARAVEL, $at);
        }
        $gate = ($this->mountedGate)(false);
        if (!$gate->passed) {
            foreach ($moved as $unit) {
                $ledger->mark($unit->id, Ledger::FAILED);
            }
            $ledger->save($path);
            $report->ledger($ledger, $units);

            return $this->result($step, StepResult::GATE_FAILED, self::unique([...$files, Ledger::FILE]), [
                ...$messages,
                ...$gate->lines,
                sprintf('The gate failed with %s answering from Laravel. They are marked failed in %s with the changes in place; fix one and retry it with --route, or undo this run (git checkout -- . && git clean -fd).', implode(', ', array_map(static fn(Unit $u): string => $u->id, $moved)), Ledger::FILE),
                ...$report->refusalLines(),
            ], $report);
        }
        $ledger->save($path);
        $replayLine = self::replayLine($gate);
        foreach ($moved as $unit) {
            $report->moved[] = ['id' => $unit->id, 'state' => Ledger::LARAVEL, 'replay' => $replayLine];
        }
        $report->ledger($ledger, $units);
        $report->next = $ledger->left() === [] ? $next . ' --finish' : $next . ' --moves 1';

        return $this->result($step, StepResult::APPLIED, self::unique([...$files, Ledger::FILE]), [
            ...$messages,
            ...$gate->lines,
            sprintf('Moved %d unit%s to Laravel: %s.', count($moved), count($moved) === 1 ? '' : 's', implode(', ', array_map(static fn(Unit $u): string => $u->id, $moved))),
            ...$report->refusalLines(),
        ], $report);
    }

    private function hand(PlanStep $step, string $path, RunOptions $options, string $framework, Ledger $ledger, string $next): StepResult
    {
        $report = new StranglerReport('hand', $framework);
        $report->step0 = $ledger->step0();
        if ($options->route === null) {
            return $this->refused($step, ['--hand needs --route "<id>": the unit a person ported. The ids are in ' . Ledger::FILE . '.'], $report);
        }
        $units = $this->parts->unitSource($framework)->units($path);
        $ledger->sync($units);
        $unit = null;
        foreach ($units as $candidate) {
            if ($candidate->id === $options->route) {
                $unit = $candidate;
            }
        }
        if ($unit === null) {
            return $this->refused($step, [self::unknownRoute($options->route, $ledger)], $report);
        }
        if ($ledger->state($unit->id) === Ledger::LARAVEL || $ledger->state($unit->id) === Ledger::HAND) {
            return $this->result($step, StepResult::NOTHING_TO_DO, [], [sprintf('%s already answers from Laravel.', $unit->id)], $report->withNext($next . ' --moves 1'));
        }
        if ($options->dryRun) {
            return $this->result($step, StepResult::PREVIEWED, [], [sprintf('Would check that route:list has %s %s, flip %s in the route table, and gate it with the replay.', implode('|', $unit->methods), $unit->uri, $unit->id)], $report->withNext($next . ' --hand --route "' . $unit->id . '"'));
        }
        $missing = $this->missingFromRouteList($unit);
        if ($missing !== null) {
            return $this->refused($step, [$missing], $report);
        }
        $this->parts->routeAdd($path, $unit);
        $ledger->mark($unit->id, Ledger::HAND, ($this->now)());
        $gate = ($this->mountedGate)(false);
        if (!$gate->passed) {
            $ledger->mark($unit->id, Ledger::FAILED);
            $ledger->save($path);
            $report->ledger($ledger, $units);

            return $this->result($step, StepResult::GATE_FAILED, [StranglerFinish::ROUTE_TABLE, Ledger::FILE], [
                ...$gate->lines,
                sprintf('The gate failed with the hand-ported %s answering from Laravel. It is marked failed in %s; the route table keeps the entry so you can read the answer. Fix the port and run --hand --route again.', $unit->id, Ledger::FILE),
            ], $report->withNext($next . ' --hand --route "' . $unit->id . '"'));
        }
        $ledger->save($path);
        $report->moved[] = ['id' => $unit->id, 'state' => Ledger::HAND, 'replay' => self::replayLine($gate)];
        $report->ledger($ledger, $units);
        $report->next = $ledger->left() === [] ? $next . ' --finish' : $next . ' --moves 1';

        return $this->result($step, StepResult::APPLIED, [StranglerFinish::ROUTE_TABLE, Ledger::FILE], [
            sprintf('route:list has %s %s, so the route table now sends %s to Laravel (hand-ported).', implode('|', $unit->methods), $unit->uri, $unit->id),
            ...$gate->lines,
        ], $report);
    }

    private function finish(PlanStep $step, string $path, RunOptions $options, string $framework, Ledger $ledger, string $next): StepResult
    {
        $report = new StranglerReport('finish', $framework);
        $report->step0 = $ledger->step0();
        $left = $ledger->left();
        if ($left !== []) {
            $report->ledger($ledger, []);

            return $this->refused($step, [sprintf('%d unit%s still answer%s from legacy (%s). Move them first; --finish deletes legacy/.', count($left), count($left) === 1 ? '' : 's', count($left) === 1 ? 's' : '', implode(', ', array_slice($left, 0, 12)) . (count($left) > 12 ? ', ...' : ''))], $report->withNext($next . ' --moves ' . count($left)));
        }
        $hosted = $framework === 'slim' ? SlimMover::hostedUnits($path) : [];
        if ($hosted !== []) {
            return $this->refused($step, [sprintf('%d unit%s on Laravel still run%s through Slim inside the Laravel route (%s), and --finish deletes the legacy code %s. Port each one\'s check to Laravel middleware first (the SLIM-HOSTED: lines say what to port) and replace its route, then run --finish again.', count($hosted), count($hosted) === 1 ? '' : 's', count($hosted) === 1 ? 's' : '', self::idList($hosted), count($hosted) === 1 ? 'it runs' : 'they run')], $report);
        }
        $booted = $framework === 'slim' ? self::slimAppBootstrapUnits($path) : [];
        if ($booted !== []) {
            $report->addManual('finish', ['Port the legacy services the moved Slim code reads (legacy.container) to a Laravel service provider and the app-wide middleware to Laravel middleware, then point the moved routes at them, so no unit needs the legacy front controller.']);

            return $this->refused($step, [sprintf('%d moved unit%s still boot%s the legacy app\'s own setup per request (container "app" in %s: the copied legacy front controller up to run(), for its services and app-wide middleware), and --finish deletes legacy/ (%s). Port those services and that middleware to Laravel first, then run --finish again.', count($booted), count($booted) === 1 ? '' : 's', count($booted) === 1 ? 's' : '', SlimMover::MANIFEST, self::idList($booted))], $report);
        }
        if ($options->dryRun) {
            return $this->result($step, StepResult::PREVIEWED, [], ['Would delete legacy/, the route table and the install hook, put back Laravel\'s stock public/index.php, move /login to Laravel, and gate it with the replay (--serve-changed).'], $report);
        }
        $signIn = $this->parts->signInFinish($path, $framework);
        if ($signIn->refusedNow()) {
            return $this->refused($step, $signIn->refusals, $report);
        }
        $done = (new StranglerFinish())->run($path);
        $report->addManual('finish', [...$signIn->manual, ...$done['manual']]);
        $files = [...$signIn->changedFiles, ...$done['files']];
        $gate = ($this->mountedGate)(true);
        if (!$gate->passed) {
            return $this->result($step, StepResult::GATE_FAILED, $files, [
                ...$signIn->lines,
                ...$done['lines'],
                ...$gate->lines,
                'With every unit on Laravel, an answer that still differs is usually the legacy app\'s own fallback: what it answered for paths no unit owns (its not-found or method-not-allowed page) and for a trailing-slash path Laravel matches as the route without it. Port that to Laravel (Route::fallback(), the exception handler in bootstrap/app.php), then run --finish again.',
                sprintf('The finish did not pass its gate. %s stays, so the next run offers --finish again; the changes are in place for you to read.', Ledger::FILE),
            ], $report);
        }
        StranglerFinish::dropLedger($path);
        $files[] = Ledger::FILE;
        $report->next = null;

        return $this->result($step, StepResult::APPLIED, $files, [
            ...$signIn->lines,
            ...$done['lines'],
            ...$gate->lines,
            sprintf('Finished: the app is a plain Laravel app. %s is gone, so the next `booster plan` plans it like any Laravel app.', Ledger::FILE),
        ], $report);
    }

    /** @param list<string> $ids */
    private static function idList(array $ids): string
    {
        return implode(', ', array_slice($ids, 0, 12)) . (count($ids) > 12 ? ', ...' : '');
    }

    /**
     * The moved Slim units that run behind the adapter in container "app"
     * mode (SLIM round 3): the manifest says so for the whole app, and every
     * adapter route in routes/web.php after a `// booster:strangler` line
     * then boots the copied legacy front controller per request. Read as
     * text; Booster never runs the app's files.
     *
     * @return list<string>
     */
    private static function slimAppBootstrapUnits(string $path): array
    {
        $manifest = rtrim($path, '/\\') . '/' . SlimMover::MANIFEST;
        $web = rtrim($path, '/\\') . '/routes/web.php';
        if (!is_file($manifest) || !is_file($web) || preg_match('/\'container\'\s*=>\s*\'app\'/', (string) file_get_contents($manifest)) !== 1) {
            return [];
        }
        $parts = preg_split('#^// booster:strangler (.+?) from .*$#m', (string) file_get_contents($web), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $out = [];
        for ($i = 1; $i + 1 < count($parts); $i += 2) {
            if (str_contains($parts[$i + 1], 'SlimAdapter::class')) {
                $out[] = $parts[$i];
            }
        }

        return $out;
    }

    /** Why route:list does not show the unit, or null when it shows every method at the unit's URI. */
    private function missingFromRouteList(Unit $unit): ?string
    {
        $routes = ($this->routeList)();
        if (is_string($routes)) {
            return 'route:list could not be read, so the hand move cannot be checked: ' . $routes;
        }
        $uri = trim($unit->uri, '/');
        $want = array_values(array_diff($unit->methods, ['HEAD']));
        foreach ($routes as $route) {
            if (trim($route['uri'], '/') !== $uri) {
                continue;
            }
            $want = array_values(array_diff($want, $route['methods']));
        }
        if ($want === []) {
            return null;
        }

        return sprintf('route:list has no %s %s yet. Add the Laravel route for %s (routes/web.php) and run --hand --route again.', implode('|', $want), $uri === '' ? '/' : $uri, $unit->id);
    }

    private static function unknownRoute(string $id, Ledger $ledger): string
    {
        $ids = array_map(static fn(array $u): string => $u['id'], $ledger->units());

        return sprintf('--route "%s" is not a unit in %s. The units are: %s.', $id, Ledger::FILE, $ids === [] ? '(none)' : implode(', ', array_slice($ids, 0, 20)) . (count($ids) > 20 ? ', ...' : ''));
    }

    /** The one replay line a moved unit carries in the report: the gate's "Replay passed" line. */
    private static function replayLine(GateReport $gate): string
    {
        foreach ($gate->lines as $line) {
            if (str_starts_with($line, 'Replay ')) {
                return $line;
            }
        }

        return $gate->lines[0] ?? 'Gate passed.';
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function prefix(string $prefix, array $lines): array
    {
        return array_map(static fn(string $line): string => $prefix . $line, $lines);
    }

    /**
     * @param list<string> $files
     *
     * @return list<string>
     */
    private static function unique(array $files): array
    {
        return array_values(array_unique($files));
    }

    /** @param list<string> $refusals */
    private function refused(PlanStep $step, array $refusals, ?StranglerReport $report = null): StepResult
    {
        $report ??= new StranglerReport('refused', (string) HopRegistry::stranglerFramework($step->hop->id));
        foreach ($refusals as $refusal) {
            $report->stopSigns[] = $refusal;
        }

        return $this->result($step, StepResult::ERROR, [], [
            'Refused: Booster will not guess past a stop sign, so nothing changed.',
            ...array_map(static fn(string $r): string => 'Stop sign: ' . $r, $refusals),
        ], $report);
    }

    /**
     * @param list<string> $files   app-relative paths
     * @param list<string> $messages
     */
    private function result(PlanStep $step, string $status, array $files, array $messages, StranglerReport $report): StepResult
    {
        $report->status = $status;
        if ($report->framework === 'slim' && $this->appPath !== null) {
            $report->slimHosted = SlimMover::hostedUnits($this->appPath);
        }
        $tail = [...$report->hostedLines(), ...$report->manualLines()];
        if ($report->next !== null && $status !== StepResult::ERROR && $status !== StepResult::GATE_FAILED) {
            $tail[] = 'Next: ' . $report->next;
        } elseif ($report->next !== null) {
            $tail[] = 'Then: ' . $report->next;
        }

        return new StepResult(
            $step->number,
            $step->hop->id,
            $status,
            array_map(static fn(string $file): array => ['file' => $file, 'diff' => ''], self::unique($files)),
            [...$messages, ...$report->leftLines(), ...$tail],
            strangler: $report->toArray(),
        );
    }
}
