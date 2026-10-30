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
 * The tailwind-3-to-4 hop: the planner offers it for apps whose tailwindcss
 * constraint floors below 4, and the executor runs the official upgrade tool
 * in the Node container, then pins the packages itself.
 */
final class TailwindUpgradeTest extends TestCase
{
    public function test_the_planner_offers_tailwind_after_node_pin(): void
    {
        $dir = $this->app();
        $plan = $this->planner()->plan((new ProjectDetector())->detect($dir), new PlanOptions(fromPhp: '8.3', toPhp: '8.3', toLaravel: '13', withNode: true), $this->today());

        self::assertSame([HopRegistry::laravelHopId('12', '13'), HopRegistry::NODE_PIN_ID, HopRegistry::TAILWIND_ID], $this->ids($plan));
    }

    public function test_the_planner_skips_tailwind_when_it_already_floors_at_4(): void
    {
        $dir = $this->app();
        $this->setTailwind($dir, '^4.0');
        $plan = $this->planner()->plan((new ProjectDetector())->detect($dir), new PlanOptions(fromPhp: '8.3', toPhp: '8.3', toLaravel: '13', withNode: true), $this->today());

        self::assertSame([HopRegistry::laravelHopId('12', '13'), HopRegistry::NODE_PIN_ID], $this->ids($plan));
    }

    public function test_the_planner_skips_tailwind_without_a_package_json(): void
    {
        $dir = $this->app();
        unlink($dir . '/package.json');
        $plan = $this->planner()->plan((new ProjectDetector())->detect($dir), new PlanOptions(fromPhp: '8.3', toPhp: '8.3', toLaravel: '13'), $this->today());

        // Without a package.json there is no node pin either.
        self::assertSame([HopRegistry::laravelHopId('12', '13')], $this->ids($plan));
    }

    public function test_the_hop_runs_the_upgrade_tool_and_pins_the_packages(): void
    {
        $dir = $this->app();
        $fake = $this->fake($dir);
        $report = $this->runTailwind($fake, new RunOptions(), $dir);

        self::assertSame([StepResult::APPLIED], $this->statuses($report));
        self::assertFalse($report->failed());
        self::assertSame(['package.json'], array_column($report->results[0]->files, 'file'));

        $registry = new HopRegistry();
        $package = json_decode((string) file_get_contents($dir . '/package.json'), true);
        self::assertIsArray($package);
        self::assertSame($registry->tailwindVersion(), $package['devDependencies']['tailwindcss']);
        self::assertSame($registry->tailwindVersion(), $package['devDependencies']['@tailwindcss/vite']);
        self::assertArrayNotHasKey('autoprefixer', $package['devDependencies']);
        self::assertArrayNotHasKey('postcss-import', $package['devDependencies']);

        $messages = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString('Gate passed: npm ci, npm run build and the tests are green.', $messages);
        self::assertStringContainsString('MANUAL:', $messages);

        $npx = array_values(array_filter($fake->calls, static fn(array $c): bool => in_array('npx', $c['command'], true)));
        self::assertCount(1, $npx);
        $command = $npx[0]['command'];
        $imageAt = array_search(NodeRunner::imageTag(24), $command, true);
        self::assertNotFalse($imageAt);
        self::assertSame(['npx', '-y', $registry->tailwindUpgradeVersion()], array_slice($command, $imageAt + 1));
    }

    public function test_the_postcss_plugin_is_pinned_when_the_app_has_no_vite(): void
    {
        $dir = $this->app();
        $package = json_decode((string) file_get_contents($dir . '/package.json'), true);
        unset($package['devDependencies']['vite']);
        file_put_contents($dir . '/package.json', (string) json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $report = $this->runTailwind($this->fake($dir), new RunOptions(), $dir);

        self::assertSame([StepResult::APPLIED], $this->statuses($report));
        $package = json_decode((string) file_get_contents($dir . '/package.json'), true);
        self::assertIsArray($package);
        $registry = new HopRegistry();
        self::assertSame($registry->tailwindVersion(), $package['devDependencies']['@tailwindcss/postcss']);
        self::assertArrayNotHasKey('@tailwindcss/vite', $package['devDependencies']);
    }

    public function test_the_dry_run_changes_nothing(): void
    {
        $dir = $this->app();
        $names = ['composer.json', 'package.json', 'tailwind.config.js', 'resources/css/app.css'];
        $before = $this->snapshot($dir, $names);
        $fake = $this->fake($dir);
        $report = $this->runTailwind($fake, new RunOptions(dryRun: true), $dir);

        self::assertSame([StepResult::PREVIEWED], $this->statuses($report));
        $messages = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString('Dry run: would run npx -y ' . (new HopRegistry())->tailwindUpgradeVersion(), $messages);
        self::assertSame($before, $this->snapshot($dir, $names));
        self::assertSame([], array_filter($fake->calls, static fn(array $c): bool => $c['command'][0] === 'docker'));
    }

    public function test_a_failing_upgrade_tool_fails_the_step_and_keeps_the_copy(): void
    {
        $dir = $this->app();
        $names = ['package.json', 'tailwind.config.js', 'resources/css/app.css'];
        $before = $this->snapshot($dir, $names);
        $report = $this->runTailwind($this->fake($dir, toolExit: 1), new RunOptions(), $dir);

        self::assertSame([StepResult::ERROR], $this->statuses($report));
        self::assertTrue($report->failed());
        $messages = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString('The Tailwind upgrade tool failed', $messages);
        self::assertStringContainsString('git checkout .', $messages);
        self::assertSame($before, $this->snapshot($dir, $names));
    }

    public function test_a_failing_build_gate_fails_the_step_but_keeps_the_changes(): void
    {
        $dir = $this->app();
        $report = $this->runTailwind($this->fake($dir, buildExit: 1), new RunOptions(), $dir);

        self::assertSame([StepResult::GATE_FAILED], $this->statuses($report));
        self::assertTrue($report->failed());
        $messages = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString('Build gate failed', $messages);
        self::assertStringContainsString('npm run build', $messages);

        $package = json_decode((string) file_get_contents($dir . '/package.json'), true);
        self::assertIsArray($package);
        self::assertSame((new HopRegistry())->tailwindVersion(), $package['devDependencies']['tailwindcss']);
    }

    public function test_skip_gate_skips_npm_and_php_gates_but_not_the_tool(): void
    {
        $dir = $this->app();
        $fake = $this->fake($dir);
        $report = $this->runTailwind($fake, new RunOptions(skipGate: true), $dir);

        self::assertSame([StepResult::APPLIED], $this->statuses($report));
        self::assertStringContainsString('Gate skipped', implode("\n", $report->results[0]->messages));
        // Only the upgrade tool itself still runs in the Node container.
        $docker = array_values(array_filter($fake->calls, static fn(array $c): bool => $c['command'][0] === 'docker'));
        self::assertCount(1, $docker);
        self::assertContains('npx', $docker[0]['command']);
    }

    private function app(): string
    {
        $dir = $this->tempDir();
        $this->copyTree(__DIR__ . '/fixtures/tailwind3', $dir);

        return $dir;
    }

    private function setTailwind(string $dir, string $constraint): void
    {
        $file = $dir . '/package.json';
        $package = json_decode((string) file_get_contents($file), true);
        self::assertIsArray($package);
        $package['devDependencies']['tailwindcss'] = $constraint;
        file_put_contents($file, (string) json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /**
     * git answers clean; docker answers from $toolExit for the npx call, from
     * $buildExit for npm run build, and with exit 0 for everything else (npm
     * ci, the baseline, the per-hop gates).
     */
    private function fake(string $dir, int $toolExit = 0, int $buildExit = 0): FakeProcessRunner
    {
        return new FakeProcessRunner(static function (array $command) use ($dir, $toolExit, $buildExit): ProcessResult {
            if ($command[0] === 'git') {
                return new ProcessResult(0, '', '');
            }
            if ($command[0] === 'docker' && in_array('npx', $command, true)) {
                return new ProcessResult($toolExit, $toolExit !== 0 ? '@tailwindcss/upgrade exploded' : '', '');
            }
            if ($command[0] === 'docker' && in_array('npm', $command, true)) {
                $isBuild = in_array('build', $command, true);

                return new ProcessResult($isBuild ? $buildExit : 0, $isBuild && $buildExit !== 0 ? 'vite build failed' : '', '');
            }
            if ($command[0] === 'docker') {
                return new ProcessResult(0, '', '');
            }

            return new ProcessResult(0, '', '');
        });
    }

    private function runTailwind(FakeProcessRunner $fake, RunOptions $options, string $dir): RunReport
    {
        $step = new PlanStep(1, (new HopRegistry())->get(HopRegistry::TAILWIND_ID), '8.3', '8.3', '12', '12', null, null, [Planner::GATE_TESTS]);
        $plan = new Plan((new ProjectDetector())->detect($dir), self::TODAY, 'test', '8.3', null, null, [$step], []);
        $executor = new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake), $fake, new NodeRunner($fake));

        return $executor->execute($plan, $options);
    }

    private function planner(): Planner
    {
        return new Planner($this->policy(), new HopRegistry());
    }

    private function copyTree(string $from, string $to): void
    {
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir($from . '/' . $entry)) {
                mkdir($to . '/' . $entry, 0775, true);
                $this->copyTree($from . '/' . $entry, $to . '/' . $entry);
            } else {
                copy($from . '/' . $entry, $to . '/' . $entry);
            }
        }
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
