<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Plan\Plan;
use Waynestate\Booster\Plan\PlanStep;
use Waynestate\Booster\Plan\Planner;
use Waynestate\Booster\Project\ProjectDetector;
use Waynestate\Booster\Run\CacheMount;
use Waynestate\Booster\Run\ComposerEditor;
use Waynestate\Booster\Run\HopExecutor;
use Waynestate\Booster\Run\NodeRunner;
use Waynestate\Booster\Run\ProcessResult;
use Waynestate\Booster\Run\RectorRunner;
use Waynestate\Booster\Run\RunOptions;
use Waynestate\Booster\Run\RunReport;
use Waynestate\Booster\Run\StepResult;
use Waynestate\Booster\Run\TargetImageRunner;

/**
 * The Node pin hop builds the front end. The pin only edits package.json
 * and .nvmrc, so on its own it proves nothing about the Node every later
 * front-end step runs on. When the app has a build script and a lock, the
 * hop runs npm ci and npm run build in the node:<pinned> container as part
 * of its gate; with no build script it says so plainly and carries on.
 */
final class NodePinBuildTest extends TestCase
{
    public function test_an_app_with_a_build_script_is_built_on_the_pinned_node(): void
    {
        $dir = $this->app();
        $fake = $this->fake();

        $report = $this->execute($dir, $fake);

        $result = $report->results[0];
        self::assertSame(StepResult::APPLIED, $result->status);
        $npm = $this->npm($fake);
        self::assertSame([['npm', 'ci'], ['npm', 'run', 'build']], array_map(static fn(array $c): array => array_slice($c, array_search('npm', $c, true)), $npm));
        $image = NodeRunner::imageTag((int) $this->target());
        foreach ($npm as $command) {
            self::assertContains($image, $command);
        }
        $messages = implode("\n", $result->messages);
        self::assertStringContainsString(sprintf('Build gate passed: npm ci and npm run build are green in the node:%s container.', $this->target()), $messages);
        // The PHP test gate still runs after the build (the baseline plus this hop).
        self::assertCount(2, $this->gateRuns($fake));
        self::assertGreaterThan($this->callIndex($fake, 'build'), $this->lastGateIndex($fake), 'the build runs before the test gate');
        self::assertFalse($report->failed());
    }

    public function test_no_build_script_is_a_plain_note_and_no_node_container(): void
    {
        $dir = $this->app(build: false);
        $fake = $this->fake();

        $report = $this->execute($dir, $fake);

        self::assertSame(StepResult::APPLIED, $report->results[0]->status);
        self::assertSame([], $this->npm($fake));
        self::assertContains('No build script in package.json, so the front-end build was not checked.', $report->results[0]->messages);
        self::assertCount(2, $this->gateRuns($fake), 'the test gate still runs');
    }

    public function test_a_build_script_without_a_lock_is_a_plain_note(): void
    {
        $dir = $this->app(lock: false);
        $fake = $this->fake();

        $report = $this->execute($dir, $fake);

        self::assertSame(StepResult::APPLIED, $report->results[0]->status);
        self::assertSame([], $this->npm($fake));
        self::assertStringContainsString('no package-lock.json, so the front-end build was not checked', implode("\n", $report->results[0]->messages));
    }

    public function test_a_red_build_fails_the_hop_and_keeps_the_pin(): void
    {
        $dir = $this->app();
        $fake = $this->fake(buildExit: 1);

        $report = $this->execute($dir, $fake);

        $result = $report->results[0];
        self::assertSame(StepResult::GATE_FAILED, $result->status);
        self::assertTrue($report->failed());
        $messages = implode("\n", $result->messages);
        self::assertStringContainsString('Build gate failed: "npm run build" exited 1', $messages);
        self::assertStringContainsString('vite build failed', $messages);
        self::assertStringContainsString(sprintf('The front end does not build on Node %s.', $this->target()), $messages);
        self::assertCount(1, $this->gateRuns($fake), 'the baseline only: a red build stops before the test gate');
        self::assertSame($this->target() . "\n", file_get_contents($dir . '/.nvmrc'), 'the pin stays so the person can read it');
    }

    public function test_a_dry_run_says_the_real_run_builds_and_runs_nothing(): void
    {
        foreach ([true, false] as $build) {
            $dir = $this->app(build: $build);
            $fake = $this->fake();

            $report = $this->execute($dir, $fake, new RunOptions(dryRun: true));

            self::assertSame(StepResult::PREVIEWED, $report->results[0]->status);
            self::assertSame([], $this->npm($fake));
            $messages = implode("\n", $report->results[0]->messages);
            self::assertStringContainsString(
                $build ? sprintf('A real run then builds the front end (npm ci, npm run build) in the node:%s container', $this->target()) : 'No build script in package.json',
                $messages,
            );
        }
    }

    public function test_a_skipped_gate_names_the_build_to_run_by_hand(): void
    {
        $dir = $this->app();
        $fake = $this->fake();

        $report = $this->execute($dir, $fake, new RunOptions(skipGate: true));

        self::assertSame([], $this->npm($fake));
        self::assertStringContainsString(
            sprintf('Gate skipped. Run the tests on PHP 8.3, and npm ci and npm run build on Node %s, before the next step.', $this->target()),
            implode("\n", $report->results[0]->messages),
        );
    }

    public function test_the_build_reads_the_opt_in_npm_cache(): void
    {
        $dir = $this->app();
        $fake = $this->fake();
        $node = new NodeRunner($fake, (int) $this->target(), npmCache: CacheMount::parse(CacheMount::NPM, 'stack-npm-cache'));

        $this->execute($dir, $fake, null, $node);

        $npm = $this->npm($fake);
        self::assertCount(2, $npm);
        foreach ($npm as $command) {
            self::assertContains('stack-npm-cache:/root/.npm', $command);
        }
    }

    private function target(): string
    {
        return (new HopRegistry())->nodePinTarget();
    }

    private function app(bool $build = true, bool $lock = true): string
    {
        $dir = $this->tempDir();
        foreach (['composer.json', 'package.json'] as $file) {
            copy(__DIR__ . '/fixtures/vite6/' . $file, $dir . '/' . $file);
        }
        if (!$build) {
            $package = json_decode((string) file_get_contents($dir . '/package.json'), true);
            self::assertIsArray($package);
            unset($package['scripts']['build']);
            file_put_contents($dir . '/package.json', (string) json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        }
        if ($lock) {
            file_put_contents($dir . '/package-lock.json', "{}\n");
        }

        return $dir;
    }

    /** npm run build answers $buildExit; everything else is green. */
    private function fake(int $buildExit = 0): FakeProcessRunner
    {
        return new FakeProcessRunner(static function (array $command) use ($buildExit): ProcessResult {
            if ($command[0] === 'docker' && in_array('npm', $command, true) && in_array('build', $command, true)) {
                return new ProcessResult($buildExit, $buildExit === 0 ? '' : 'vite build failed', '');
            }

            return new ProcessResult(0, '', '');
        });
    }

    private function execute(string $dir, FakeProcessRunner $fake, ?RunOptions $options = null, ?NodeRunner $node = null): RunReport
    {
        $step = new PlanStep(1, (new HopRegistry())->get(HopRegistry::NODE_PIN_ID), '8.3', '8.3', null, null, null, null, [Planner::GATE_TESTS]);
        $plan = new Plan((new ProjectDetector())->detect($dir), self::TODAY, 'test', '8.3', null, null, [$step], []);
        $executor = new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake), $fake, $node ?? new NodeRunner($fake, (int) $this->target()));

        return $executor->execute($plan, $options ?? new RunOptions());
    }

    /** @return list<list<string>> the npm commands run in the Node container */
    private function npm(FakeProcessRunner $fake): array
    {
        return array_values(array_filter(
            array_column($fake->calls, 'command'),
            static fn(array $c): bool => ($c[0] ?? '') === 'docker' && in_array('npm', $c, true),
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

    private function callIndex(FakeProcessRunner $fake, string $word): int
    {
        foreach ($fake->calls as $i => $call) {
            if (in_array('npm', $call['command'], true) && in_array($word, $call['command'], true)) {
                return $i;
            }
        }
        self::fail('no npm ' . $word . ' call');
    }

    private function lastGateIndex(FakeProcessRunner $fake): int
    {
        $last = -1;
        foreach ($fake->calls as $i => $call) {
            if (($call['command'][0] ?? '') === 'docker' && !in_array('npm', $call['command'], true) && str_contains(implode(' ', $call['command']), 'BOOSTER_GATE_PHASE')) {
                $last = $i;
            }
        }

        return $last;
    }
}
