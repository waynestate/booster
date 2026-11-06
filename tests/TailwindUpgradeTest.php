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

        // The fixture carries vite ^6.0.0, so the plan also pins Vite before Tailwind runs.
        self::assertSame([HopRegistry::laravelHopId('12', '13'), HopRegistry::NODE_PIN_ID, HopRegistry::VITE_MAJOR_ID, HopRegistry::TAILWIND_ID], $this->ids($plan));
    }

    public function test_the_planner_skips_tailwind_when_it_already_floors_at_4(): void
    {
        $dir = $this->app();
        $this->setTailwind($dir, '^4.0');
        $plan = $this->planner()->plan((new ProjectDetector())->detect($dir), new PlanOptions(fromPhp: '8.3', toPhp: '8.3', toLaravel: '13', withNode: true), $this->today());

        self::assertSame([HopRegistry::laravelHopId('12', '13'), HopRegistry::NODE_PIN_ID, HopRegistry::VITE_MAJOR_ID], $this->ids($plan));
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
        // --force: the tool's clean-tree check needs git, which the slim Node image lacks.
        self::assertSame(['npx', '-y', '@tailwindcss/upgrade@' . $registry->tailwindUpgradeVersion(), '--force'], array_slice($command, $imageAt + 1));
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
        self::assertStringContainsString('Dry run: would run npx -y @tailwindcss/upgrade@' . (new HopRegistry())->tailwindUpgradeVersion() . ' --force', $messages);
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

    public function test_a_tool_that_never_installed_says_so_instead_of_printing_only_npm_notices(): void
    {
        $dir = $this->app();
        $fake = new FakeProcessRunner(static function (array $command): ProcessResult {
            if ($command[0] === 'docker' && in_array('npx', $command, true)) {
                return new ProcessResult(1, '', "npm notice\nnpm notice New major version of npm available!\nnpm notice\n");
            }

            return new ProcessResult(0, '', '');
        });
        $report = $this->runTailwind($fake, new RunOptions(), $dir);

        self::assertSame([StepResult::ERROR], $this->statuses($report));
        $messages = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString('The tool printed nothing, which usually means npx could not install it in the Node container.', $messages);
        self::assertStringContainsString('npx -y @tailwindcss/upgrade@' . (new HopRegistry())->tailwindUpgradeVersion() . ' --force', $messages);
        self::assertStringContainsString('git checkout .', $messages);
    }

    public function test_a_tool_that_explains_its_failure_gets_no_install_hint(): void
    {
        $dir = $this->app();
        $report = $this->runTailwind($this->fake($dir, toolExit: 1), new RunOptions(), $dir);

        $messages = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString('@tailwindcss/upgrade exploded', $messages);
        self::assertStringNotContainsString('The tool printed nothing', $messages);
    }

    public function test_the_upgrade_tool_is_pinned_to_one_exact_release(): void
    {
        // A range let npx pick 4.3.4, which cannot install in the slim Node image.
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', (new HopRegistry())->tailwindUpgradeVersion());
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
        // Only the upgrade tool and the lock sync still run in the Node container.
        $docker = array_values(array_filter($fake->calls, static fn(array $c): bool => $c['command'][0] === 'docker'));
        self::assertCount(2, $docker);
        self::assertContains('npx', $docker[0]['command']);
        self::assertSame(['npm', 'install'], array_slice($docker[1]['command'], -2));
    }

    public function test_npm_install_syncs_the_lock_after_the_pins_and_before_npm_ci(): void
    {
        // Found on a real app: the tool installs its own pins, BOOSTER's edits
        // come after, and npm ci refused the stale lock.
        $dir = $this->app();
        $fake = $this->fake($dir);
        $report = $this->runTailwind($fake, new RunOptions(), $dir);

        self::assertSame([StepResult::APPLIED], $this->statuses($report));
        self::assertStringContainsString('npm install ran in the Node container: package-lock.json and node_modules now match the pins.', implode("\n", $report->results[0]->messages));
        $npm = array_values(array_map(
            static fn(array $c): string => implode(' ', array_slice($c['command'], (int) array_search('npm', $c['command'], true))),
            array_filter($fake->calls, static fn(array $c): bool => $c['command'][0] === 'docker' && in_array('npm', $c['command'], true)),
        ));
        self::assertSame(['npm install', 'npm ci', 'npm run build'], $npm);
    }

    public function test_an_inline_postcss_block_in_the_vite_config_becomes_the_vite_plugin(): void
    {
        // The Laravel Vue starter kit of early 2025 fed Tailwind 3 this way, and
        // the upgrade tool leaves vite.config alone (found on a real app, 2026-11-06).
        $dir = $this->app();
        file_put_contents($dir . '/vite.config.ts', <<<'TS'
            import vue from '@vitejs/plugin-vue';
            import autoprefixer from 'autoprefixer';
            import laravel from 'laravel-vite-plugin';
            import tailwindcss from 'tailwindcss';
            import { defineConfig } from 'vite';

            export default defineConfig({
                plugins: [
                    laravel({
                        input: ['resources/js/app.ts'],
                    }),
                    vue(),
                ],
                css: {
                    postcss: {
                        plugins: [tailwindcss, autoprefixer],
                    },
                },
            });

            TS);
        $report = $this->runTailwind($this->fake($dir), new RunOptions(), $dir);

        self::assertSame([StepResult::APPLIED], $this->statuses($report));
        self::assertSame(['package.json', 'vite.config.ts'], array_column($report->results[0]->files, 'file'));
        self::assertSame(<<<'TS'
            import vue from '@vitejs/plugin-vue';
            import laravel from 'laravel-vite-plugin';
            import tailwindcss from '@tailwindcss/vite';
            import { defineConfig } from 'vite';

            export default defineConfig({
                plugins: [
                    tailwindcss(),
                    laravel({
                        input: ['resources/js/app.ts'],
                    }),
                    vue(),
                ],
            });

            TS, file_get_contents($dir . '/vite.config.ts'));
        self::assertStringContainsString('vite.config.ts: the Tailwind PostCSS block became the tailwindcss() plugin from @tailwindcss/vite.', implode("\n", $report->results[0]->messages));
    }

    public function test_a_vite_config_with_other_postcss_plugins_is_left_alone_with_a_manual_line(): void
    {
        $dir = $this->app();
        $config = <<<'JS'
            import autoprefixer from 'autoprefixer';
            import nesting from 'postcss-nesting';
            import tailwindcss from 'tailwindcss';
            import { defineConfig } from 'vite';

            export default defineConfig({
                plugins: [],
                css: { postcss: { plugins: [nesting, tailwindcss, autoprefixer] } },
            });

            JS;
        file_put_contents($dir . '/vite.config.js', $config);
        $report = $this->runTailwind($this->fake($dir), new RunOptions(), $dir);

        self::assertSame($config, file_get_contents($dir . '/vite.config.js'));
        self::assertSame(['package.json'], array_column($report->results[0]->files, 'file'));
        self::assertStringContainsString('MANUAL: vite.config.js still imports tailwindcss or autoprefixer as PostCSS plugins', implode("\n", $report->results[0]->messages));
    }

    public function test_a_vite_config_that_already_has_the_plugin_is_untouched(): void
    {
        $dir = $this->app();
        $config = "import tailwindcss from '@tailwindcss/vite';\nimport { defineConfig } from 'vite';\n\nexport default defineConfig({ plugins: [tailwindcss()] });\n";
        file_put_contents($dir . '/vite.config.ts', $config);
        $report = $this->runTailwind($this->fake($dir), new RunOptions(), $dir);

        self::assertSame($config, file_get_contents($dir . '/vite.config.ts'));
        self::assertStringNotContainsString('vite.config', implode("\n", $report->results[0]->messages));
    }

    public function test_a_failing_lock_sync_stops_the_step_before_the_build_gate(): void
    {
        $dir = $this->app();
        $fake = new FakeProcessRunner(static function (array $command): ProcessResult {
            if ($command[0] === 'docker' && in_array('npm', $command, true) && in_array('install', $command, true)) {
                return new ProcessResult(1, '', 'ERESOLVE could not resolve');
            }

            return new ProcessResult(0, '', '');
        });
        $report = $this->runTailwind($fake, new RunOptions(), $dir);

        self::assertSame([StepResult::ERROR], $this->statuses($report));
        $messages = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString('npm install failed: it exited 1 in the Node container.', $messages);
        self::assertStringContainsString('ERESOLVE could not resolve', $messages);
        self::assertSame([], array_filter($fake->calls, static fn(array $c): bool => in_array('ci', $c['command'], true)));
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
