<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Plan\Plan;
use Waynestate\Booster\Plan\PlanOptions;
use Waynestate\Booster\Plan\PlanStep;
use Waynestate\Booster\Plan\Planner;
use Waynestate\Booster\Project\ProjectDetector;
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
 * The inertia-2-to-3 hop: the planner offers it last for Inertia 2 apps that
 * land on Laravel 11 or newer, and the executor pins both Inertia packages at
 * 3, installs the new front end with npm install, and gates the composer side.
 */
final class InertiaThreeHopTest extends TestCase
{
    public function test_the_planner_offers_inertia_three_last_for_an_inertia_two_app(): void
    {
        $dir = $this->app();
        $plan = $this->planner()->plan((new ProjectDetector())->detect($dir), new PlanOptions(toPhp: '8.5', toLaravel: '13', withNode: true), $this->today());

        self::assertSame([
            HopRegistry::laravelHopId('12', '13'),
            HopRegistry::phpHopId('8.3', '8.4'),
            HopRegistry::phpHopId('8.4', '8.5'),
            HopRegistry::NODE_PIN_ID,
            HopRegistry::INERTIA_3_ID,
        ], $this->ids($plan));
    }

    public function test_the_planner_skips_inertia_three_when_the_constraints_already_floor_at_3(): void
    {
        $dir = $this->app('^3.0', '^3.0.0');
        $plan = $this->planner()->plan((new ProjectDetector())->detect($dir), new PlanOptions(toPhp: '8.5', toLaravel: '13', withNode: true), $this->today());

        self::assertSame([
            HopRegistry::laravelHopId('12', '13'),
            HopRegistry::phpHopId('8.3', '8.4'),
            HopRegistry::phpHopId('8.4', '8.5'),
            HopRegistry::NODE_PIN_ID,
        ], $this->ids($plan));
    }

    public function test_the_planner_skips_inertia_three_when_the_plan_lands_below_laravel_11(): void
    {
        $dir = $this->laravelTenApp();
        $plan = $this->planner()->plan((new ProjectDetector())->detect($dir), new PlanOptions(toPhp: '8.2', toLaravel: '10', withNode: true), $this->today());

        self::assertSame([HopRegistry::NODE_PIN_ID], $this->ids($plan));
    }

    public function test_the_planner_skips_inertia_three_without_an_inertia_package(): void
    {
        $dir = $this->plainLaravelApp();
        $plan = $this->planner()->plan((new ProjectDetector())->detect($dir), new PlanOptions(toPhp: '8.5', toLaravel: '13', withNode: true), $this->today());

        self::assertSame([
            HopRegistry::laravelHopId('12', '13'),
            HopRegistry::phpHopId('8.3', '8.4'),
            HopRegistry::phpHopId('8.4', '8.5'),
            HopRegistry::NODE_PIN_ID,
        ], $this->ids($plan));
    }

    public function test_the_hop_pins_both_packages_installs_and_gates(): void
    {
        $dir = $this->app();
        $fake = $this->fake();
        $report = $this->runInertia($fake, new RunOptions(), $dir);

        self::assertSame([StepResult::APPLIED], $this->statuses($report));
        self::assertFalse($report->failed());
        self::assertSame(['composer.json', 'package.json'], array_column($report->results[0]->files, 'file'));

        $registry = new HopRegistry();
        $composer = json_decode((string) file_get_contents($dir . '/composer.json'), true);
        self::assertIsArray($composer);
        self::assertSame($registry->inertiaLaravelNext(), $composer['require']['inertiajs/inertia-laravel']);
        $package = json_decode((string) file_get_contents($dir . '/package.json'), true);
        self::assertIsArray($package);
        self::assertSame($registry->inertiaVue3Next(), $package['devDependencies']['@inertiajs/vue3']);

        $messages = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString('Set inertiajs/inertia-laravel to ' . $registry->inertiaLaravelNext() . ' in require.', $messages);
        self::assertStringContainsString('npm install ran in the Node container: package-lock.json and node_modules now match the pins.', $messages);
        self::assertStringContainsString('Gate passed: syntax check is clean on PHP 8.3.', $messages);
        self::assertStringContainsString('MANUAL:', $messages);
        // A real app showed a blank page until the compiled Blade views were cleared (2026-11-06).
        self::assertStringContainsString('MANUAL: run php artisan view:clear on every machine that serves the app', $messages);

        // npm install (the lock still names Inertia 2, so npm ci would refuse it) runs in the Node container, and the gate refreshes only the
        // Inertia pin in the lock instead of a plain install.
        $all = $this->commandsBlob($fake);
        self::assertStringContainsString(NodeRunner::imageTag(24) . ' npm install', $all);
        self::assertStringContainsString('composer update -W --no-interaction --no-progress inertiajs/inertia-laravel', $all);
    }

    public function test_the_dry_run_changes_nothing(): void
    {
        $dir = $this->app();
        $names = ['composer.json', 'package.json'];
        $before = $this->snapshot($dir, $names);
        $fake = $this->fake();
        $registry = new HopRegistry();
        $report = $this->runInertia($fake, new RunOptions(dryRun: true), $dir);

        self::assertSame([StepResult::PREVIEWED], $this->statuses($report));
        $messages = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString('Dry run: would set inertiajs/inertia-laravel to ' . $registry->inertiaLaravelNext() . ' in require.', $messages);
        self::assertStringContainsString('Dry run: would set package.json devDependencies @inertiajs/vue3 to ' . $registry->inertiaVue3Next() . '.', $messages);
        self::assertStringContainsString('Dry run: would install the new Inertia versions (npm install, composer update -W inertiajs/inertia-laravel).', $messages);
        self::assertSame($before, $this->snapshot($dir, $names));
        self::assertSame([], array_filter($fake->calls, static fn(array $c): bool => $c['command'][0] === 'docker'));
    }

    public function test_the_hop_is_a_no_op_when_the_constraints_already_floor_at_3(): void
    {
        $dir = $this->app('^3.0', '^3.0.0');
        $names = ['composer.json', 'package.json'];
        $before = $this->snapshot($dir, $names);
        $report = $this->runInertia($this->fake(), new RunOptions(), $dir);

        self::assertSame([StepResult::NOTHING_TO_DO], $this->statuses($report));
        self::assertFalse($report->failed());
        self::assertStringContainsString('Nothing to do: the Inertia constraints already floor at 3, so the gate was skipped.', implode("\n", $report->results[0]->messages));
        self::assertSame($before, $this->snapshot($dir, $names));
    }

    public function test_a_failing_npm_install_fails_the_step_but_keeps_the_changes(): void
    {
        $dir = $this->app();
        $report = $this->runInertia($this->fake(npmExit: 1), new RunOptions(), $dir);

        self::assertSame([StepResult::GATE_FAILED], $this->statuses($report));
        self::assertTrue($report->failed());
        $messages = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString('npm install failed: it exited 1 in the Node container. The working copy keeps the pins, so you can read them.', $messages);

        $composer = json_decode((string) file_get_contents($dir . '/composer.json'), true);
        self::assertIsArray($composer);
        self::assertSame((new HopRegistry())->inertiaLaravelNext(), $composer['require']['inertiajs/inertia-laravel']);
    }

    public function test_a_failing_gate_fails_the_step_but_keeps_the_changes(): void
    {
        $dir = $this->app();
        $report = $this->runInertia($this->fake(gateExit: 1), new RunOptions(), $dir);

        self::assertSame([StepResult::GATE_FAILED], $this->statuses($report));
        self::assertTrue($report->failed());
        $messages = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString('Gate failed:', $messages);
        self::assertStringContainsString('on PHP 8.3', $messages);

        $package = json_decode((string) file_get_contents($dir . '/package.json'), true);
        self::assertIsArray($package);
        self::assertSame((new HopRegistry())->inertiaVue3Next(), $package['devDependencies']['@inertiajs/vue3']);
    }

    public function test_skip_gate_skips_the_composer_gate_but_not_npm_install(): void
    {
        $dir = $this->app();
        $fake = $this->fake();
        $report = $this->runInertia($fake, new RunOptions(skipGate: true), $dir);

        self::assertSame([StepResult::APPLIED], $this->statuses($report));
        self::assertStringContainsString('Gate skipped. Run the tests on PHP 8.3 before the next step.', implode("\n", $report->results[0]->messages));
        // npm install still proves the front end installs; no gate container runs.
        $all = $this->commandsBlob($fake);
        self::assertStringContainsString(NodeRunner::imageTag(24) . ' npm install', $all);
        self::assertSame([], array_filter($fake->calls, static fn(array $c): bool => $c['command'][0] === 'docker' && in_array('sh', $c['command'], true)));
    }

    public function test_a_composer_only_app_skips_npm_install(): void
    {
        $dir = $this->composerOnlyApp();
        $fake = $this->fake();
        $report = $this->runInertia($fake, new RunOptions(), $dir);

        self::assertSame([StepResult::APPLIED], $this->statuses($report));
        self::assertSame(['composer.json'], array_column($report->results[0]->files, 'file'));
        $composer = json_decode((string) file_get_contents($dir . '/composer.json'), true);
        self::assertIsArray($composer);
        self::assertSame((new HopRegistry())->inertiaLaravelNext(), $composer['require']['inertiajs/inertia-laravel']);
        self::assertStringNotContainsString(' npm install', $this->commandsBlob($fake));
    }

    public function test_without_a_node_runner_the_hop_warns_and_still_gates(): void
    {
        $dir = $this->app();
        $fake = $this->fake();
        $executor = new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake), $fake);
        $step = new PlanStep(1, (new HopRegistry())->get(HopRegistry::INERTIA_3_ID), '8.3', '8.3', '13', '13', null, null, [Planner::GATE_TESTS]);
        $plan = new Plan((new ProjectDetector())->detect($dir), self::TODAY, 'test', '8.3', null, null, [$step], []);
        $report = $executor->execute($plan, new RunOptions());

        self::assertSame([StepResult::APPLIED], $this->statuses($report));
        $messages = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString('No Node runner configured, so npm install did not run; run it yourself before relying on the front end.', $messages);
        self::assertStringContainsString('Gate passed: syntax check is clean on PHP 8.3.', $messages);
        self::assertStringNotContainsString(' npm install', $this->commandsBlob($fake));
    }

    /** An Inertia 2 app: Laravel 12 on PHP 8.3 with both Inertia packages below 3. */
    private function app(string $inertia = '^2.0', string $vue3 = '^2.0.0'): string
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/composer.json', (string) json_encode([
            'name' => 'example/inertia-app',
            'require' => [
                'php' => '^8.3',
                'laravel/framework' => '^12.0',
                'inertiajs/inertia-laravel' => $inertia,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        file_put_contents($dir . '/package.json', (string) json_encode([
            'private' => true,
            'devDependencies' => [
                'vue' => '^3.5.0',
                '@inertiajs/vue3' => $vue3,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        return $dir;
    }

    /** The same app with no package.json, so only the composer side moves. */
    private function composerOnlyApp(): string
    {
        $dir = $this->app();
        unlink($dir . '/package.json');

        return $dir;
    }

    /** An Inertia 2 app whose plan lands on Laravel 10, where Inertia 3 does not run. */
    private function laravelTenApp(): string
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/composer.json', (string) json_encode([
            'name' => 'example/inertia-app',
            'require' => [
                'php' => '^8.2',
                'laravel/framework' => '^10.0',
                'inertiajs/inertia-laravel' => '^2.0',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        file_put_contents($dir . '/package.json', (string) json_encode([
            'private' => true,
            'devDependencies' => [
                'vue' => '^3.5.0',
                '@inertiajs/vue3' => '^2.0.0',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        return $dir;
    }

    /** A plain Laravel 12 app with a Vue front end but no Inertia. */
    private function plainLaravelApp(): string
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/composer.json', (string) json_encode([
            'name' => 'example/laravel-app',
            'require' => [
                'php' => '^8.3',
                'laravel/framework' => '^12.0',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        file_put_contents($dir . '/package.json', (string) json_encode([
            'private' => true,
            'devDependencies' => [
                'vue' => '^3.5.0',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        return $dir;
    }

    /**
     * git answers clean; docker answers from $npmExit for the npm install call, from
     * $gateExit for every gate container after the baseline, and with exit 0
     * for everything else (docker version, image inspect, the baseline itself).
     */
    private function fake(int $npmExit = 0, int $gateExit = 0): FakeProcessRunner
    {
        $gateRuns = 0;

        return new FakeProcessRunner(static function (array $command) use ($npmExit, $gateExit, &$gateRuns): ProcessResult {
            if ($command[0] === 'git') {
                return new ProcessResult(0, '', '');
            }
            if ($command[0] !== 'docker') {
                return new ProcessResult(0, '', '');
            }
            if (($command[1] ?? '') !== 'run') {
                // docker version and docker image inspect always succeed.
                return new ProcessResult(0, '', '');
            }
            if (in_array('npm', $command, true)) {
                return new ProcessResult($npmExit, $npmExit !== 0 ? 'npm install exploded' : '', '');
            }
            // A gate container: the first one is the baseline, later ones gates.
            $gateRuns++;
            $exit = $gateRuns === 1 ? 0 : $gateExit;

            return new ProcessResult($exit, $exit !== 0 ? "FAILURES!\nBOOSTER_GATE_PHASE=tests\nBOOSTER_GATE_EXIT={$exit}\n" : '', '');
        });
    }

    private function runInertia(FakeProcessRunner $fake, RunOptions $options, string $dir): RunReport
    {
        $step = new PlanStep(1, (new HopRegistry())->get(HopRegistry::INERTIA_3_ID), '8.3', '8.3', '13', '13', null, null, [Planner::GATE_TESTS]);
        $plan = new Plan((new ProjectDetector())->detect($dir), self::TODAY, 'test', '8.3', null, null, [$step], []);
        $executor = new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake), $fake, new NodeRunner($fake));

        return $executor->execute($plan, $options);
    }

    private function planner(): Planner
    {
        return new Planner($this->policy(), new HopRegistry());
    }

    /** Every recorded command joined into one blob, so assertions can span arguments. */
    private function commandsBlob(FakeProcessRunner $fake): string
    {
        return implode("\n", array_map(static fn(array $c): string => implode(' ', $c['command']), $fake->calls));
    }

    /**
     * @param list<string> $names
     *
     * @return array<string, string>
     */
    private function snapshot(string $dir, array $names): array
    {
        $out = [];
        foreach ($names as $name) {
            $out[$name] = (string) file_get_contents($dir . '/' . $name);
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function statuses(RunReport $report): array
    {
        return array_map(static fn(StepResult $result): string => $result->status, $report->results);
    }

    /**
     * @return list<string>
     */
    private function ids(Plan $plan): array
    {
        return array_map(static fn(PlanStep $step): string => $step->hop->id, $plan->steps);
    }
}
