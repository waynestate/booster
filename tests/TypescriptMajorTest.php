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
 * The typescript-major hop: the planner offers it for apps whose typescript
 * constraint floors below the major in the hop data, and the executor pins
 * typescript (and vue-tsc, when listed) itself, then gates on the build.
 */
final class TypescriptMajorTest extends TestCase
{
    public function test_the_planner_offers_typescript_after_node_pin(): void
    {
        $dir = $this->app();
        $plan = $this->planner()->plan((new ProjectDetector())->detect($dir), new PlanOptions(fromPhp: '8.3', toPhp: '8.3', toLaravel: '13', withNode: true), $this->today());

        self::assertSame([HopRegistry::laravelHopId('12', '13'), HopRegistry::NODE_PIN_ID, HopRegistry::VITE_MAJOR_ID, HopRegistry::TYPESCRIPT_MAJOR_ID], $this->ids($plan));
    }

    public function test_the_planner_skips_typescript_when_it_already_floors_at_the_target_major(): void
    {
        $dir = $this->app();
        $this->setTypescript($dir, '^6.0');
        $plan = $this->planner()->plan((new ProjectDetector())->detect($dir), new PlanOptions(fromPhp: '8.3', toPhp: '8.3', toLaravel: '13', withNode: true), $this->today());

        self::assertSame([HopRegistry::laravelHopId('12', '13'), HopRegistry::NODE_PIN_ID, HopRegistry::VITE_MAJOR_ID], $this->ids($plan));
    }

    public function test_the_planner_skips_typescript_without_a_package_json(): void
    {
        $dir = $this->app();
        unlink($dir . '/package.json');
        $plan = $this->planner()->plan((new ProjectDetector())->detect($dir), new PlanOptions(fromPhp: '8.3', toPhp: '8.3', toLaravel: '13'), $this->today());

        // Without a package.json there is no node pin either.
        self::assertSame([HopRegistry::laravelHopId('12', '13')], $this->ids($plan));
    }

    public function test_the_registry_exposes_the_typescript_pins(): void
    {
        $registry = new HopRegistry();

        self::assertSame('^6.0', $registry->typescriptVersion());
        self::assertSame('^3.0', $registry->vueTscVersion());
    }

    public function test_the_hop_pins_typescript_and_vue_tsc_and_gates(): void
    {
        $dir = $this->app();
        $fake = $this->fake($dir);
        $report = $this->runTypescript($fake, new RunOptions(), $dir);

        self::assertSame([StepResult::APPLIED], $this->statuses($report));
        self::assertFalse($report->failed());
        self::assertSame(['package.json'], array_column($report->results[0]->files, 'file'));

        $registry = new HopRegistry();
        $package = json_decode((string) file_get_contents($dir . '/package.json'), true);
        self::assertIsArray($package);
        self::assertSame($registry->typescriptVersion(), $package['devDependencies']['typescript']);
        self::assertSame($registry->vueTscVersion(), $package['devDependencies']['vue-tsc']);

        $messages = implode("\n", $report->results[0]->messages);
        // Each pin is reported as its own change line, and the file diff names both.
        self::assertStringContainsString('package.json: devDependencies typescript to ' . $registry->typescriptVersion() . '.', $messages);
        self::assertStringContainsString('package.json: devDependencies vue-tsc to ' . $registry->vueTscVersion() . '.', $messages);
        self::assertSame('pinned typescript to ' . $registry->typescriptVersion() . ' and vue-tsc to ' . $registry->vueTscVersion(), $report->results[0]->files[0]['diff']);
        self::assertStringContainsString('Gate passed: npm ci, npm run build and the tests are green.', $messages);
        self::assertStringContainsString('MANUAL:', $messages);

        // No upgrade tool runs; the build gate still does npm ci and npm run build.
        $commands = array_map(static fn(array $c): array => $c['command'], $fake->calls);
        self::assertSame([], array_filter($commands, static fn(array $command): bool => in_array('npx', $command, true)));
        self::assertNotSame([], array_filter($commands, static fn(array $command): bool => in_array('npm', $command, true) && in_array('ci', $command, true)));
        self::assertNotSame([], array_filter($commands, static fn(array $command): bool => in_array('npm', $command, true) && in_array('build', $command, true)));
    }

    public function test_vue_tsc_is_not_added_when_the_app_does_not_list_it(): void
    {
        $dir = $this->app();
        $package = json_decode((string) file_get_contents($dir . '/package.json'), true);
        unset($package['devDependencies']['vue-tsc']);
        file_put_contents($dir . '/package.json', (string) json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $report = $this->runTypescript($this->fake($dir), new RunOptions(), $dir);

        self::assertSame([StepResult::APPLIED], $this->statuses($report));
        $package = json_decode((string) file_get_contents($dir . '/package.json'), true);
        self::assertIsArray($package);
        self::assertSame((new HopRegistry())->typescriptVersion(), $package['devDependencies']['typescript']);
        self::assertArrayNotHasKey('vue-tsc', $package['devDependencies']);
    }

    public function test_the_dry_run_changes_nothing(): void
    {
        $dir = $this->app();
        $names = ['composer.json', 'package.json'];
        $before = $this->snapshot($dir, $names);
        $fake = $this->fake($dir);
        $report = $this->runTypescript($fake, new RunOptions(dryRun: true), $dir);

        self::assertSame([StepResult::PREVIEWED], $this->statuses($report));
        $messages = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString('Dry run: would pin typescript to ' . (new HopRegistry())->typescriptVersion(), $messages);
        self::assertSame($before, $this->snapshot($dir, $names));
        self::assertSame([], array_filter($fake->calls, static fn(array $c): bool => $c['command'][0] === 'docker'));
    }

    public function test_a_failing_build_gate_fails_the_step_but_keeps_the_changes(): void
    {
        $dir = $this->app();
        $report = $this->runTypescript($this->fake($dir, buildExit: 1), new RunOptions(), $dir);

        self::assertSame([StepResult::GATE_FAILED], $this->statuses($report));
        self::assertTrue($report->failed());
        $messages = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString('Build gate failed', $messages);
        self::assertStringContainsString('npm run build', $messages);

        $package = json_decode((string) file_get_contents($dir . '/package.json'), true);
        self::assertIsArray($package);
        self::assertSame((new HopRegistry())->typescriptVersion(), $package['devDependencies']['typescript']);
    }

    public function test_skip_gate_skips_npm_and_php_gates_but_still_pins(): void
    {
        $dir = $this->app();
        $fake = $this->fake($dir);
        $report = $this->runTypescript($fake, new RunOptions(skipGate: true), $dir);

        self::assertSame([StepResult::APPLIED], $this->statuses($report));
        self::assertStringContainsString('Gate skipped', implode("\n", $report->results[0]->messages));
        // Nothing runs in the Node container at all.
        self::assertSame([], array_filter($fake->calls, static fn(array $c): bool => $c['command'][0] === 'docker'));
    }

    private function app(): string
    {
        $dir = $this->tempDir();
        $this->copyTree(__DIR__ . '/fixtures/typescript-major', $dir);

        return $dir;
    }

    private function setTypescript(string $dir, string $constraint): void
    {
        $file = $dir . '/package.json';
        $package = json_decode((string) file_get_contents($file), true);
        self::assertIsArray($package);
        $package['devDependencies']['typescript'] = $constraint;
        file_put_contents($file, (string) json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /**
     * git answers clean; docker answers from $buildExit for npm run build and
     * with exit 0 for everything else (npm ci, the baseline, the per-hop gates).
     */
    private function fake(string $dir, int $buildExit = 0): FakeProcessRunner
    {
        return new FakeProcessRunner(static function (array $command) use ($dir, $buildExit): ProcessResult {
            if ($command[0] === 'git') {
                return new ProcessResult(0, '', '');
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

    private function runTypescript(FakeProcessRunner $fake, RunOptions $options, string $dir): RunReport
    {
        $step = new PlanStep(1, (new HopRegistry())->get(HopRegistry::TYPESCRIPT_MAJOR_ID), '8.3', '8.3', '12', '12', null, null, [Planner::GATE_TESTS]);
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
