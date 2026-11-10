<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Plan\Plan;
use Waynestate\Booster\Plan\PlanStep;
use Waynestate\Booster\Plan\Planner;
use Waynestate\Booster\Project\ProjectDetector;
use Waynestate\Booster\Report\HopRecord;
use Waynestate\Booster\Report\TextWriter;
use Waynestate\Booster\Run\ComposerEditor;
use Waynestate\Booster\Run\HopExecutor;
use Waynestate\Booster\Run\NodeRunner;
use Waynestate\Booster\Run\ProcessResult;
use Waynestate\Booster\Run\RectorRunner;
use Waynestate\Booster\Run\RunOptions;
use Waynestate\Booster\Run\RunReport;
use Waynestate\Booster\Run\StepResult;
use Waynestate\Booster\Run\StoppedHop;
use Waynestate\Booster\Run\TargetImageRunner;

/**
 * Re-gating a stopped hop. A run stops at a red gate and leaves the hop's
 * edits in place; the person fixes the cause by hand, commits, and runs
 * again. Booster plans from the app as it is now, so the stopped hop is
 * either gone from the plan (its target is reached) or comes back with
 * nothing left to edit. Either way its gate never ran green, so the rerun
 * runs that gate again and carries on: it neither redoes the hop nor skips
 * it. The hop record the stopped run left (booster-hop.json) says which hop
 * it was.
 */
final class RegateTest extends TestCase
{
    public function test_a_stopped_front_end_hop_that_left_the_plan_gets_its_build_gate_again(): void
    {
        // vite-major stopped at its build gate; the person fixed vite.config by
        // hand and committed. package.json is at the Vite pins now, so the
        // planner leaves the hop out, and the PHP baseline never builds.
        $dir = $this->viteApp();
        $this->writeRecord($dir, 'fail', [[1, HopRegistry::VITE_MAJOR_ID, StepResult::GATE_FAILED]]);
        $fake = $this->fake();

        $report = $this->execute($dir, [], $fake);

        self::assertCount(1, $this->npm($fake, 'build'), 'the build gate ran again for the stopped hop');
        self::assertNotNull($report->regate);
        self::assertTrue($report->regate->passed);
        self::assertSame(HopRegistry::VITE_MAJOR_ID, $report->regate->hopId);
        self::assertFalse($report->failed());
        $text = implode("\n", $report->regate->lines);
        self::assertStringContainsString('vite-major stopped at its gate in the last run', $text);
        self::assertStringContainsString('Build gate passed', $text);
        self::assertStringContainsString('The baseline on PHP 8.3 ran the tests again', $text);
    }

    public function test_a_still_red_build_stops_the_rerun_before_the_first_step(): void
    {
        $dir = $this->viteApp();
        $this->writeRecord($dir, 'fail', [[1, HopRegistry::VITE_MAJOR_ID, StepResult::GATE_FAILED]]);
        $fake = $this->fake(buildExit: 1);

        $report = $this->execute($dir, [$this->pinStep()], $fake);

        self::assertNotNull($report->regate);
        self::assertFalse($report->regate->passed);
        self::assertTrue($report->failed());
        self::assertSame([StepResult::NOT_RUN], array_map(static fn(StepResult $r): string => $r->status, $report->results));
        self::assertStringContainsString('still fails its gate', implode(' ', $report->results[0]->messages));
        $text = (new TextWriter())->run($report, $dir, 'booster');
        self::assertStringContainsString('Re-gate: FAIL', $text);
        self::assertStringContainsString('vite build failed', $text);
        self::assertStringContainsString('The upgrade did not start: vite-major, the hop that stopped the last run, still fails its gate.', $text);

        // The record of this run keeps the stopped hop, so the run after it re-gates again.
        $record = HopRecord::build($report, '2026-11-09T00:00:00+00:00', '2026-11-09T00:01:00+00:00', []);
        self::assertSame(['hop' => HopRegistry::VITE_MAJOR_ID, 'status' => StepResult::GATE_FAILED], $record['regate']);
        HopRecord::write($dir, $record);
        self::assertSame(HopRegistry::VITE_MAJOR_ID, StoppedHop::read($dir)?->hopId);
    }

    public function test_a_stopped_hop_back_in_the_plan_with_nothing_to_edit_runs_its_gate(): void
    {
        // The hop's edits are already in the tree, so it has nothing to change.
        // Before, that read as "nothing to do, so the gate was skipped".
        $dir = $this->viteApp();
        $this->pin($dir);
        $this->writeRecord($dir, 'fail', [[1, HopRegistry::NODE_PIN_ID, StepResult::GATE_FAILED]]);
        $fake = $this->fake();

        $report = $this->execute($dir, [$this->pinStep()], $fake);

        self::assertNull($report->regate);
        self::assertSame(StepResult::NOTHING_TO_DO, $report->results[0]->status);
        self::assertCount(2, $this->gateRuns($fake), 'the baseline and the re-gate');
        $messages = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString('Re-gate: this hop stopped at its gate in the last run', $messages);
        // The fixture has no tests of its own, so its gate is the syntax check.
        self::assertStringContainsString('Gate passed: syntax check is clean on PHP 8.3.', $messages);
        self::assertStringNotContainsString('gate was skipped', $messages);
        self::assertFalse($report->failed());
    }

    public function test_a_red_re_gate_in_the_plan_fails_the_step(): void
    {
        $dir = $this->viteApp();
        $this->pin($dir);
        $this->writeRecord($dir, 'fail', [[1, HopRegistry::NODE_PIN_ID, StepResult::GATE_FAILED]]);
        $calls = 0;
        $fake = new FakeProcessRunner(static function (array $command) use (&$calls): ProcessResult {
            if ($command[0] === 'docker' && ($command[1] ?? '') === 'run' && in_array('/app', $command, true) && !in_array('npm', $command, true)) {
                // The baseline passes; the re-gate is red.
                return ++$calls === 1 ? new ProcessResult(0, "BOOSTER_GATE_PHASE=tests\n", '') : new ProcessResult(1, "FAILED: still red\nBOOSTER_GATE_PHASE=tests\nBOOSTER_GATE_EXIT=1\n", '');
            }

            return new ProcessResult(0, '', '');
        });

        $report = $this->execute($dir, [$this->pinStep()], $fake);

        self::assertSame(StepResult::GATE_FAILED, $report->results[0]->status);
        self::assertTrue($report->failed());
        self::assertStringContainsString('still red', implode("\n", $report->results[0]->messages));
    }

    public function test_without_a_stopped_hop_nothing_to_do_still_skips_the_gate(): void
    {
        foreach ([null, 'pass'] as $result) {
            $dir = $this->viteApp();
            $this->pin($dir);
            if ($result !== null) {
                $this->writeRecord($dir, $result, [[1, HopRegistry::NODE_PIN_ID, StepResult::APPLIED]]);
            }
            $fake = $this->fake();

            $report = $this->execute($dir, [$this->pinStep()], $fake);

            self::assertNull($report->regate);
            self::assertCount(1, $this->gateRuns($fake), 'the baseline only');
            self::assertStringContainsString('so the gate was skipped', implode(' ', $report->results[0]->messages));
        }
    }

    public function test_a_dry_run_and_a_skipped_gate_never_re_gate(): void
    {
        foreach ([new RunOptions(dryRun: true), new RunOptions(skipGate: true)] as $options) {
            $dir = $this->viteApp();
            $this->writeRecord($dir, 'fail', [[1, HopRegistry::VITE_MAJOR_ID, StepResult::GATE_FAILED]]);
            $fake = $this->fake();

            $report = $this->execute($dir, [], $fake, $options);

            self::assertNull($report->regate);
            self::assertSame([], $this->npm($fake, 'build'));
        }
    }

    public function test_the_stopped_hop_is_read_from_a_failed_real_run_only(): void
    {
        $dir = $this->tempDir();
        self::assertNull(StoppedHop::read($dir));

        $this->writeRecord($dir, 'fail', [[1, 'php-83-to-84', StepResult::APPLIED], [2, 'php-84-to-85', StepResult::GATE_FAILED], [3, 'node-pin-24', StepResult::NOT_RUN]]);
        $stopped = StoppedHop::read($dir);
        self::assertNotNull($stopped);
        self::assertSame('php-84-to-85', $stopped->hopId);
        self::assertSame(2, $stopped->step);

        // A Rector error changed nothing, so the rerun simply runs the hop again.
        $this->writeRecord($dir, 'fail', [[1, 'php-83-to-84', StepResult::ERROR]]);
        self::assertNull(StoppedHop::read($dir));

        $this->writeRecord($dir, 'pass', [[1, 'php-83-to-84', StepResult::APPLIED]]);
        self::assertNull(StoppedHop::read($dir));

        file_put_contents($dir . '/' . HopRecord::FILE_NAME, '{not json');
        self::assertNull(StoppedHop::read($dir));
    }

    public function test_a_stopped_hop_with_no_build_is_re_gated_by_the_baseline_and_says_so(): void
    {
        // A PHP hop that stopped: the rerun starts from the PHP composer.json
        // names now, so the baseline on that PHP is the stopped hop's test gate.
        $dir = $this->viteApp();
        $this->writeRecord($dir, 'fail', [[3, 'php-84-to-85', StepResult::GATE_FAILED]]);
        $fake = $this->fake();

        $report = $this->execute($dir, [], $fake);

        self::assertNotNull($report->regate);
        self::assertTrue($report->regate->passed);
        self::assertSame([], $this->npm($fake, 'build'));
        self::assertStringContainsString('The baseline on PHP 8.3 ran the tests again', implode(' ', $report->regate->lines));
        self::assertSame('passed', $report->toArray()['regate']['status']);
    }

    private function viteApp(): string
    {
        $dir = $this->tempDir();
        foreach (['composer.json', 'package.json'] as $file) {
            copy(__DIR__ . '/fixtures/vite6/' . $file, $dir . '/' . $file);
        }
        // The person kept the hop's pins: the app is at the Vite major now.
        $package = json_decode((string) file_get_contents($dir . '/package.json'), true);
        self::assertIsArray($package);
        $package['devDependencies']['vite'] = (new HopRegistry())->viteVersion();
        file_put_contents($dir . '/package.json', (string) json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        file_put_contents($dir . '/package-lock.json', "{}\n");

        return $dir;
    }

    private function pin(string $dir): void
    {
        $target = (new HopRegistry())->nodePinTarget();
        $package = json_decode((string) file_get_contents($dir . '/package.json'), true);
        self::assertIsArray($package);
        $package['engines']['node'] = '>=' . $target;
        file_put_contents($dir . '/package.json', (string) json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        file_put_contents($dir . '/.nvmrc', $target . "\n");
    }

    private function pinStep(): PlanStep
    {
        return new PlanStep(1, (new HopRegistry())->get(HopRegistry::NODE_PIN_ID), '8.3', '8.3', null, null, null, null, [Planner::GATE_TESTS]);
    }

    /**
     * @param list<array{0: int, 1: string, 2: string}> $hops step, hop id, status
     */
    private function writeRecord(string $dir, string $result, array $hops): void
    {
        $record = [
            'tool' => 'booster',
            'version' => '1.16.0',
            'dry_run' => false,
            'result' => $result,
            'hops' => array_map(static fn(array $h): array => ['step' => $h[0], 'hop' => $h[1], 'status' => $h[2], 'rules' => new \stdClass()], $hops),
        ];
        file_put_contents($dir . '/' . HopRecord::FILE_NAME, (string) json_encode($record, JSON_PRETTY_PRINT));
    }

    /** git answers clean; npm run build answers $buildExit; everything else in Docker is green. */
    private function fake(int $buildExit = 0): FakeProcessRunner
    {
        return new FakeProcessRunner(static function (array $command) use ($buildExit): ProcessResult {
            if ($command[0] === 'docker' && in_array('npm', $command, true) && in_array('build', $command, true)) {
                return new ProcessResult($buildExit, $buildExit === 0 ? '' : 'vite build failed', '');
            }

            return new ProcessResult(0, '', '');
        });
    }

    /**
     * @param list<PlanStep> $steps
     */
    private function execute(string $dir, array $steps, FakeProcessRunner $fake, ?RunOptions $options = null): RunReport
    {
        $plan = new Plan((new ProjectDetector())->detect($dir), self::TODAY, 'test', '8.3', null, null, $steps, []);
        $executor = new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake), $fake, new NodeRunner($fake));

        return $executor->execute($plan, $options ?? new RunOptions());
    }

    /** @return list<list<string>> the npm commands run in the Node container with $word in them */
    private function npm(FakeProcessRunner $fake, string $word): array
    {
        return array_values(array_filter(
            array_column($fake->calls, 'command'),
            static fn(array $c): bool => ($c[0] ?? '') === 'docker' && in_array('npm', $c, true) && in_array($word, $c, true),
        ));
    }

    /** @return list<list<string>> the test gate runs (the baseline included) in the target image */
    private function gateRuns(FakeProcessRunner $fake): array
    {
        return array_values(array_filter(
            array_column($fake->calls, 'command'),
            static fn(array $c): bool => ($c[0] ?? '') === 'docker' && ($c[1] ?? '') === 'run' && !in_array('npm', $c, true) && str_contains(implode(' ', $c), 'BOOSTER_GATE_PHASE'),
        ));
    }
}
