<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Hop\HopKind;
use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Plan\Plan;
use Waynestate\Booster\Plan\PlanOptions;
use Waynestate\Booster\Plan\PlanStep;
use Waynestate\Booster\Plan\Planner;
use Waynestate\Booster\Project\ProjectDetector;
use Waynestate\Booster\Refresh\HopDataRefresher;
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
 * The vite-major hop: the planner offers it for apps whose vite constraint
 * floors below the Vite major in the hop data, and the executor pins vite and
 * the plugin packages in package.json, then runs npm install in the Node
 * container.
 */
final class ViteMajorTest extends TestCase
{
    public function test_the_planner_offers_vite_major_after_node_pin(): void
    {
        $dir = $this->app();
        $plan = $this->planner()->plan((new ProjectDetector())->detect($dir), new PlanOptions(fromPhp: '8.3', toPhp: '8.3', toLaravel: '13', withNode: true), $this->today());

        self::assertSame([HopRegistry::laravelHopId('12', '13'), HopRegistry::NODE_PIN_ID, HopRegistry::VITE_MAJOR_ID], $this->ids($plan));
    }

    public function test_the_planner_orders_vite_major_between_node_pin_and_tailwind(): void
    {
        $dir = $this->app();
        $this->setPackage($dir, static function (array $package): array {
            $package['devDependencies']['tailwindcss'] = '^3.4.0';

            return $package;
        });
        $plan = $this->planner()->plan((new ProjectDetector())->detect($dir), new PlanOptions(fromPhp: '8.3', toPhp: '8.3', toLaravel: '13', withNode: true), $this->today());

        self::assertSame([HopRegistry::laravelHopId('12', '13'), HopRegistry::NODE_PIN_ID, HopRegistry::VITE_MAJOR_ID, HopRegistry::TAILWIND_ID], $this->ids($plan));
    }

    public function test_the_planner_skips_vite_major_when_it_already_floors_at_the_target(): void
    {
        $dir = $this->app();
        $this->setPackage($dir, static fn(array $package): array => self::putVite($package, (new HopRegistry())->viteVersion()));
        $plan = $this->planner()->plan((new ProjectDetector())->detect($dir), new PlanOptions(fromPhp: '8.3', toPhp: '8.3', toLaravel: '13', withNode: true), $this->today());

        self::assertSame([HopRegistry::laravelHopId('12', '13'), HopRegistry::NODE_PIN_ID], $this->ids($plan));
    }

    public function test_the_planner_never_offers_vite_major_twice(): void
    {
        $dir = $this->app();
        $this->setPackage($dir, static function (array $package): array {
            $package['dependencies']['vite'] = '^6.0.0';

            return $package;
        });
        $plan = $this->planner()->plan((new ProjectDetector())->detect($dir), new PlanOptions(fromPhp: '8.3', toPhp: '8.3', toLaravel: '13', withNode: true), $this->today());

        self::assertSame(1, count(array_filter($this->ids($plan), static fn(string $id): bool => $id === HopRegistry::VITE_MAJOR_ID)));
    }

    public function test_the_planner_skips_vite_major_without_the_package(): void
    {
        $dir = $this->app();
        $this->setPackage($dir, static function (array $package): array {
            unset($package['devDependencies']['vite']);

            return $package;
        });
        $plan = $this->planner()->plan((new ProjectDetector())->detect($dir), new PlanOptions(fromPhp: '8.3', toPhp: '8.3', toLaravel: '13', withNode: true), $this->today());

        self::assertSame([HopRegistry::laravelHopId('12', '13'), HopRegistry::NODE_PIN_ID], $this->ids($plan));
    }

    public function test_the_planner_never_offers_vite_major_with_mix_to_vite(): void
    {
        $dir = $this->app();
        file_put_contents($dir . '/webpack.mix.js', "const mix = require('laravel-mix');\n");
        $plan = $this->planner()->plan((new ProjectDetector())->detect($dir), new PlanOptions(fromPhp: '8.3', toPhp: '8.3', toLaravel: '13', withNode: true), $this->today());

        self::assertContains(HopRegistry::MIX_TO_VITE_ID, $this->ids($plan));
        self::assertNotContains(HopRegistry::VITE_MAJOR_ID, $this->ids($plan));
    }

    public function test_the_planner_skips_vite_major_for_an_app_without_a_package_json(): void
    {
        $dir = $this->copyExample('raw-php');
        $plan = $this->planner()->plan((new ProjectDetector())->detect($dir), new PlanOptions(fromPhp: '8.3', toPhp: '8.3'), $this->today());

        self::assertNotContains(HopRegistry::VITE_MAJOR_ID, $this->ids($plan));
    }

    public function test_the_hop_pins_vite_wherever_it_sits_and_reports_each_change(): void
    {
        $dir = $this->app();
        $this->setPackage($dir, static function (array $package): array {
            $package['dependencies']['vite'] = $package['devDependencies']['vite'];
            unset($package['devDependencies']['vite']);

            return $package;
        });
        $report = $this->runViteMajor($this->fake(), new RunOptions(), $dir);

        self::assertSame([StepResult::APPLIED], $this->statuses($report));
        self::assertFalse($report->failed());
        // One files entry per pinned package, all in package.json.
        self::assertSame(['package.json', 'package.json', 'package.json'], array_column($report->results[0]->files, 'file'));

        $registry = new HopRegistry();
        $package = json_decode((string) file_get_contents($dir . '/package.json'), true);
        self::assertIsArray($package);
        self::assertSame($registry->viteVersion(), $package['dependencies']['vite']);
        self::assertArrayNotHasKey('vite', $package['devDependencies']);
        self::assertSame($registry->laravelVitePluginVersion(), $package['devDependencies']['laravel-vite-plugin']);
        self::assertSame($registry->vuePluginVersion(), $package['devDependencies']['@vitejs/plugin-vue']);

        $messages = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString('package.json: dependencies vite to ' . $registry->viteVersion() . '.', $messages);
        self::assertStringContainsString('Gate passed: npm ci, npm run build and the tests are green.', $messages);
        self::assertStringContainsString('MANUAL:', $messages);
    }

    public function test_the_plugins_are_pinned_only_when_listed(): void
    {
        $dir = $this->app();
        $this->setPackage($dir, static function (array $package): array {
            unset($package['devDependencies']['laravel-vite-plugin'], $package['devDependencies']['@vitejs/plugin-vue']);

            return $package;
        });
        $report = $this->runViteMajor($this->fake(), new RunOptions(), $dir);

        self::assertSame([StepResult::APPLIED], $this->statuses($report));
        $package = json_decode((string) file_get_contents($dir . '/package.json'), true);
        self::assertIsArray($package);
        $registry = new HopRegistry();
        self::assertSame($registry->viteVersion(), $package['devDependencies']['vite']);
        self::assertArrayNotHasKey('laravel-vite-plugin', $package['devDependencies']);
        self::assertArrayNotHasKey('@vitejs/plugin-vue', $package['devDependencies']);
    }

    public function test_the_dry_run_changes_nothing(): void
    {
        $dir = $this->app();
        $names = ['composer.json', 'package.json'];
        $before = $this->snapshot($dir, $names);
        $fake = $this->fake();
        $report = $this->runViteMajor($fake, new RunOptions(dryRun: true), $dir);

        self::assertSame([StepResult::PREVIEWED], $this->statuses($report));
        $messages = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString('Dry run: would set package.json devDependencies vite to ' . (new HopRegistry())->viteVersion() . '.', $messages);
        self::assertSame($before, $this->snapshot($dir, $names));
        self::assertSame([], array_filter($fake->calls, static fn(array $c): bool => $c['command'][0] === 'docker'));
    }

    public function test_a_failing_install_fails_the_step_and_keeps_the_pins(): void
    {
        $dir = $this->app();
        $report = $this->runViteMajor($this->fake(installExit: 1), new RunOptions(), $dir);

        self::assertSame([StepResult::ERROR], $this->statuses($report));
        self::assertTrue($report->failed());
        $messages = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString('npm install failed', $messages);
        self::assertStringContainsString('git checkout .', $messages);

        $package = json_decode((string) file_get_contents($dir . '/package.json'), true);
        self::assertIsArray($package);
        self::assertSame((new HopRegistry())->viteVersion(), $package['devDependencies']['vite']);
    }

    public function test_a_failing_build_gate_fails_the_step_but_keeps_the_changes(): void
    {
        $dir = $this->app();
        $report = $this->runViteMajor($this->fake(buildExit: 1), new RunOptions(), $dir);

        self::assertSame([StepResult::GATE_FAILED], $this->statuses($report));
        self::assertTrue($report->failed());
        $messages = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString('Build gate failed', $messages);
        self::assertStringContainsString('npm run build', $messages);

        $package = json_decode((string) file_get_contents($dir . '/package.json'), true);
        self::assertIsArray($package);
        self::assertSame((new HopRegistry())->viteVersion(), $package['devDependencies']['vite']);
    }

    public function test_skip_gate_applies_the_pins_without_the_gates(): void
    {
        $dir = $this->app();
        $fake = $this->fake();
        $report = $this->runViteMajor($fake, new RunOptions(skipGate: true), $dir);

        self::assertSame([StepResult::APPLIED], $this->statuses($report));
        self::assertStringContainsString('Gate skipped', implode("\n", $report->results[0]->messages));
        // Only npm install still runs in the Node container.
        $docker = array_values(array_filter($fake->calls, static fn(array $c): bool => $c['command'][0] === 'docker'));
        self::assertCount(1, $docker);
        self::assertContains('install', $docker[0]['command']);
    }

    public function test_the_registry_exposes_the_vite_major_hop(): void
    {
        $registry = new HopRegistry();
        self::assertSame('^8.3', $registry->viteVersion());
        self::assertSame('^3.2', $registry->laravelVitePluginVersion());
        self::assertSame('^6.0', $registry->vuePluginVersion());

        $hop = $registry->get(HopRegistry::VITE_MAJOR_ID);
        self::assertSame(HopKind::Node, $hop->kind);
        self::assertSame('any', $hop->from);
        self::assertSame(HopDataRefresher::lowestMajor($registry->viteVersion()), $hop->to);
        self::assertTrue($hop->buildGate);
        self::assertFalse($hop->isManual());
        self::assertContains('https://vite.dev/guide/migration', $hop->links);
        self::assertCount(2, $hop->notes);
        self::assertStringStartsWith('MANUAL:', $hop->notes[0]);
        self::assertStringStartsWith('MANUAL:', $hop->notes[1]);
    }

    private function app(): string
    {
        $dir = $this->tempDir();
        $this->copyTree(__DIR__ . '/fixtures/vite6', $dir);

        return $dir;
    }

    /**
     * @param callable(array): array $mutate
     */
    private function setPackage(string $dir, callable $mutate): void
    {
        $file = $dir . '/package.json';
        $package = json_decode((string) file_get_contents($file), true);
        self::assertIsArray($package);
        $package = $mutate($package);
        file_put_contents($file, (string) json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /**
     * @param array<string, mixed> $package
     *
     * @return array<string, mixed>
     */
    private static function putVite(array $package, string $constraint): array
    {
        $package['devDependencies']['vite'] = $constraint;

        return $package;
    }

    /**
     * git answers clean; docker answers from $installExit for npm install, from
     * $buildExit for npm run build, and with exit 0 for everything else (npm
     * ci, the baseline, the per-hop gates).
     */
    private function fake(int $installExit = 0, int $buildExit = 0): FakeProcessRunner
    {
        return new FakeProcessRunner(static function (array $command) use ($installExit, $buildExit): ProcessResult {
            if ($command[0] === 'git') {
                return new ProcessResult(0, '', '');
            }
            if ($command[0] === 'docker' && in_array('npm', $command, true)) {
                if (in_array('install', $command, true)) {
                    return new ProcessResult($installExit, $installExit !== 0 ? 'npm install exploded' : '', '');
                }
                $isBuild = in_array('build', $command, true);

                return new ProcessResult($isBuild ? $buildExit : 0, $isBuild && $buildExit !== 0 ? 'vite build failed' : '', '');
            }
            if ($command[0] === 'docker') {
                return new ProcessResult(0, '', '');
            }

            return new ProcessResult(0, '', '');
        });
    }

    private function runViteMajor(FakeProcessRunner $fake, RunOptions $options, string $dir): RunReport
    {
        $step = new PlanStep(1, (new HopRegistry())->get(HopRegistry::VITE_MAJOR_ID), '8.3', '8.3', '12', '12', null, null, [Planner::GATE_TESTS]);
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
