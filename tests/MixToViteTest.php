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
 * The mix-to-vite hop: the planner offers it, and the executor converts the
 * simple shape of webpack.mix.js into a Vite front end.
 */
final class MixToViteTest extends TestCase
{
    public function test_the_planner_offers_mix_to_vite_after_node_pin(): void
    {
        $dir = $this->tempDir();
        $this->makeApp($dir);
        $plan = $this->planner()->plan((new ProjectDetector())->detect($dir), new PlanOptions(fromPhp: '8.3', toPhp: '8.3', toLaravel: '12'), $this->today());

        self::assertSame([HopRegistry::laravelHopId('11', '12'), HopRegistry::NODE_PIN_ID, HopRegistry::MIX_TO_VITE_ID], $this->ids($plan));
    }

    public function test_the_planner_skips_mix_to_vite_without_a_mix_file(): void
    {
        $dir = $this->tempDir();
        $this->makeApp($dir);
        unlink($dir . '/webpack.mix.js');
        $plan = $this->planner()->plan((new ProjectDetector())->detect($dir), new PlanOptions(fromPhp: '8.3', toPhp: '8.3', toLaravel: '12'), $this->today());

        self::assertSame([HopRegistry::laravelHopId('11', '12'), HopRegistry::NODE_PIN_ID], $this->ids($plan));
    }

    public function test_the_planner_waits_for_laravel_9_before_mix_to_vite(): void
    {
        // Laravel 8 still ships Mix, so the hop can only start once the plan
        // has climbed to Laravel 9 or newer; here it lands on 12.
        $dir = $this->tempDir();
        $this->makeApp($dir, '^8.1', '^8.0');
        $plan = $this->planner()->plan((new ProjectDetector())->detect($dir), new PlanOptions(fromPhp: '8.1', toPhp: '8.3', toLaravel: '12'), $this->today());

        self::assertSame([
            HopRegistry::laravelHopId('8', '9'),
            HopRegistry::laravelHopId('9', '10'),
            HopRegistry::phpHopId('8.1', '8.2'),
            HopRegistry::laravelHopId('10', '11'),
            HopRegistry::laravelHopId('11', '12'),
            HopRegistry::phpHopId('8.2', '8.3'),
            HopRegistry::NODE_PIN_ID,
            HopRegistry::MIX_TO_VITE_ID,
        ], $this->ids($plan));
    }

    public function test_mix_to_vite_never_appears_without_a_package_json(): void
    {
        // An app without a package.json has no front end to move, even if a
        // webpack.mix.js file is lying around.
        $dir = $this->tempDir();
        $this->makeApp($dir);
        unlink($dir . '/package.json');
        $plan = $this->planner()->plan((new ProjectDetector())->detect($dir), new PlanOptions(fromPhp: '8.3', toPhp: '8.3', toLaravel: '12'), $this->today());

        self::assertSame([HopRegistry::laravelHopId('11', '12')], $this->ids($plan));
    }

    public function test_a_simple_mix_file_is_converted_to_vite(): void
    {
        $dir = $this->tempDir();
        $this->makeApp($dir);
        $report = $this->runMix($this->fake($dir), new RunOptions(), $dir);

        self::assertSame([StepResult::APPLIED], $this->statuses($report));
        self::assertFalse($report->failed());
        self::assertSame(['vite.config.js', 'package.json', '.env.example', 'resources/views/layouts/app.blade.php', 'webpack.mix.js'], array_column($report->results[0]->files, 'file'));
        self::assertFileDoesNotExist($dir . '/webpack.mix.js');

        $expectedConfig = <<<'JS'
        import { defineConfig } from 'vite';
        import laravel from 'laravel-vite-plugin';
        import vue from '@vitejs/plugin-vue';

        export default defineConfig({
            plugins: [
                laravel({,
                    input: [,
                        'resources/js/app.js',
                        'resources/sass/app.scss',
                    ],
                    refresh: true,
                }),
                vue()
            ],
        });
        JS;
        // PHP 8.5 heredocs drop the final newline, but the hop writes one.
        self::assertSame($expectedConfig . "\n", (string) file_get_contents($dir . '/vite.config.js'));

        $package = json_decode((string) file_get_contents($dir . '/package.json'), true);
        self::assertIsArray($package);
        self::assertArrayNotHasKey('laravel-mix', $package['devDependencies']);
        $registry = new HopRegistry();
        self::assertSame($registry->viteVersion(), $package['devDependencies']['vite']);
        self::assertSame($registry->laravelVitePluginVersion(), $package['devDependencies']['laravel-vite-plugin']);
        self::assertSame($registry->vuePluginVersion(), $package['devDependencies']['@vitejs/plugin-vue']);
        self::assertSame('^3.5.0', $package['dependencies']['vue']);
        self::assertSame('vite', $package['scripts']['dev']);
        self::assertSame('vite build', $package['scripts']['build']);
        self::assertSame('module', $package['type']);

        self::assertSame("APP_NAME=Example\nVITE_PUSHER_APP_KEY=secret\nVITE_PUSHER_APP_PORT=6001\n", (string) file_get_contents($dir . '/.env.example'));

        $expectedLayout = <<<'HTML'
        <!DOCTYPE html>
        <html>
        <head>
            @vite(['resources/sass/app.scss', 'resources/js/app.js'])
        </head>
        <body>
            <div id="app"></div>
        </body>
        </html>
        HTML;
        self::assertSame($expectedLayout, (string) file_get_contents($dir . '/resources/views/layouts/app.blade.php'));

        $messages = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString('Build gate passed', $messages);
        self::assertStringContainsString('Gate passed: tests are green on PHP 8.3.', $messages);
    }

    public function test_a_complex_mix_file_is_refused_and_changes_nothing(): void
    {
        $dir = $this->tempDir();
        $this->makeApp($dir);
        file_put_contents(
            $dir . '/webpack.mix.js',
            <<<'JS'
        const mix = require('laravel-mix');

        mix.js('resources/js/app.js', 'public/js')
            .extract(['lodash']);
        JS,
        );
        $names = ['composer.json', 'package.json', '.env.example', 'webpack.mix.js', 'resources/views/layouts/app.blade.php'];
        $before = $this->snapshot($dir, $names);
        $report = $this->runMix($this->fake($dir), new RunOptions(), $dir);

        self::assertSame([StepResult::ERROR], $this->statuses($report));
        self::assertTrue($report->failed());
        self::assertStringContainsString('unsupported Mix call "extract"', implode("\n", $report->results[0]->messages));
        self::assertSame($before, $this->snapshot($dir, $names));
        self::assertFileDoesNotExist($dir . '/vite.config.js');
    }

    public function test_vue_2_is_refused_with_a_pointer_at_the_vue_hop(): void
    {
        $dir = $this->tempDir();
        $this->makeApp($dir);
        $file = $dir . '/package.json';
        $data = json_decode((string) file_get_contents($file), true);
        $data['dependencies']['vue'] = '^2.7.16';
        file_put_contents($file, (string) json_encode($data));
        $report = $this->runMix($this->fake($dir), new RunOptions(), $dir);

        self::assertSame([StepResult::ERROR], $this->statuses($report));
        self::assertStringContainsString('vue-2-to-3', implode("\n", $report->results[0]->messages));
        self::assertFileExists($dir . '/webpack.mix.js');
        self::assertFileDoesNotExist($dir . '/vite.config.js');
    }

    public function test_an_unmapped_blade_reference_earns_a_manual_note(): void
    {
        $dir = $this->tempDir();
        $this->makeApp($dir);
        mkdir($dir . '/resources/views/partials', 0775, true);
        $partial = $dir . '/resources/views/partials/footer.blade.php';
        file_put_contents($partial, '<script src="{{ mix(\'js/vendor.js\') }}"></script>' . "\n");
        $report = $this->runMix($this->fake($dir), new RunOptions(), $dir);

        self::assertSame([StepResult::APPLIED], $this->statuses($report));
        self::assertStringContainsString('@vite(', (string) file_get_contents($dir . '/resources/views/layouts/app.blade.php'));
        self::assertSame('<script src="{{ mix(\'js/vendor.js\') }}"></script>' . "\n", (string) file_get_contents($partial));
        $messages = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString('resources/views/partials/footer.blade.php', $messages);
        self::assertStringContainsString('move it by hand', $messages);
    }

    public function test_a_bare_mix_call_earns_a_manual_note(): void
    {
        $dir = $this->tempDir();
        $this->makeApp($dir);
        $layout = $dir . '/resources/views/layouts/app.blade.php';
        $modified = str_replace('</body>', "<img src=\"{{ mix('css/logo.png') }}\">\n</body>", (string) file_get_contents($layout));
        file_put_contents($layout, $modified);
        $report = $this->runMix($this->fake($dir), new RunOptions(), $dir);

        self::assertSame([StepResult::APPLIED], $this->statuses($report));
        self::assertSame($modified, (string) file_get_contents($layout));
        $messages = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString('not a plain script or link tag', $messages);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $dir = $this->tempDir();
        $this->makeApp($dir);
        $names = ['composer.json', 'package.json', '.env.example', 'webpack.mix.js', 'resources/views/layouts/app.blade.php'];
        $before = $this->snapshot($dir, $names);
        $report = $this->runMix($this->fake($dir), new RunOptions(dryRun: true), $dir);

        self::assertSame([StepResult::PREVIEWED], $this->statuses($report));
        self::assertStringContainsString('Dry run: would convert webpack.mix.js (2 entries plus Vue) to Vite', implode("\n", $report->results[0]->messages));
        self::assertSame($before, $this->snapshot($dir, $names));
        self::assertFileDoesNotExist($dir . '/vite.config.js');
    }

    public function test_a_failing_build_gate_fails_the_step_but_keeps_the_changes(): void
    {
        $dir = $this->tempDir();
        $this->makeApp($dir);
        $report = $this->runMix($this->fake($dir, buildExit: 1), new RunOptions(), $dir);

        self::assertSame([StepResult::GATE_FAILED], $this->statuses($report));
        self::assertTrue($report->failed());
        $messages = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString('npm run build', $messages);
        self::assertStringContainsString('vite build failed', $messages);
        self::assertFileExists($dir . '/vite.config.js');
        self::assertFileDoesNotExist($dir . '/webpack.mix.js');
    }

    public function test_a_missing_manifest_fails_the_build_gate(): void
    {
        $dir = $this->tempDir();
        $this->makeApp($dir);
        $report = $this->runMix($this->fake($dir, writeManifest: false), new RunOptions(), $dir);

        self::assertSame([StepResult::GATE_FAILED], $this->statuses($report));
        self::assertStringContainsString('manifest.json is missing', implode("\n", $report->results[0]->messages));
    }

    public function test_without_mix_earns_a_manual_note(): void
    {
        $dir = $this->tempDir();
        $this->makeApp($dir);
        mkdir($dir . '/app/Http/Middleware', 0775, true);
        file_put_contents(
            $dir . '/app/Http/Middleware/NoVite.php',
            <<<'PHP'
        <?php

        namespace App\Http\Middleware;

        use Closure;
        use Illuminate\Http\Request;

        final class NoVite
        {
            public function handle(Request $request, Closure $next)
            {
                $request->withoutMix();

                return $next($request);
            }
        }
        PHP,
        );
        $report = $this->runMix($this->fake($dir), new RunOptions(), $dir);

        self::assertSame([StepResult::APPLIED], $this->statuses($report));
        self::assertStringContainsString('app/Http/Middleware/NoVite.php: replace withoutMix() with withoutVite() by hand.', implode("\n", $report->results[0]->messages));
    }

    public function test_skip_gate_skips_npm_and_php_gates(): void
    {
        $dir = $this->tempDir();
        $this->makeApp($dir);
        $fake = $this->fake($dir);
        $report = $this->runMix($fake, new RunOptions(skipGate: true), $dir);

        self::assertSame([StepResult::APPLIED], $this->statuses($report));
        self::assertStringContainsString('Gate skipped', implode("\n", $report->results[0]->messages));
        self::assertSame([], array_filter($fake->calls, static fn(array $call): bool => $call['command'][0] === 'docker'));
    }

    private function makeApp(string $dir, string $php = '^8.3', string $laravel = '^11.0'): void
    {
        // A small Laravel app with the simple Mix shape: one JS bundle that
        // compiles Vue components and one SASS bundle, both written to
        // directories, a layout that references both, and MIX_ keys in
        // .env.example.
        file_put_contents($dir . '/composer.json', (string) json_encode([
            'name' => 'example/mix-app',
            'require' => [
                'php' => $php,
                'laravel/framework' => $laravel,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        file_put_contents($dir . '/package.json', (string) json_encode([
            'name' => 'example-mix-app',
            'private' => true,
            'dependencies' => ['vue' => '^3.5.0'],
            'devDependencies' => ['laravel-mix' => '^6.0.49'],
            'scripts' => [
                'dev' => 'npm run development',
                'prod' => 'npm run production',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        file_put_contents(
            $dir . '/webpack.mix.js',
            <<<'JS'
        const mix = require('laravel-mix');

        mix.js('resources/js/app.js', 'public/js')
            .vue()
            .sass('resources/sass/app.scss', 'public/css');
        JS,
        );
        file_put_contents($dir . '/.env.example', "APP_NAME=Example\nMIX_PUSHER_APP_KEY=secret\nMIX_PUSHER_APP_PORT=6001\n");
        mkdir($dir . '/resources/views/layouts', 0775, true);
        file_put_contents(
            $dir . '/resources/views/layouts/app.blade.php',
            <<<'HTML'
        <!DOCTYPE html>
        <html>
        <head>
            <link href="{{ mix('css/app.css') }}" rel="stylesheet">
        </head>
        <body>
            <div id="app"></div>
            <script src="{{ mix('js/app.js') }}"></script>
        </body>
        </html>
        HTML,
        );
    }

    /**
     * git answers clean, npm answers from $buildExit and writes the manifest
     * a real Vite build would, and every other Docker command (the PHP test
     * gates) answers from $gateExit.
     */
    private function fake(string $dir, int $buildExit = 0, bool $writeManifest = true, int $gateExit = 0): FakeProcessRunner
    {
        return new FakeProcessRunner(static function (array $command) use ($dir, $buildExit, $writeManifest, $gateExit): ProcessResult {
            if ($command[0] === 'git') {
                return new ProcessResult(0, '', '');
            }
            if ($command[0] === 'docker' && in_array('npm', $command, true)) {
                $isBuild = in_array('build', $command, true);
                if ($isBuild && $buildExit === 0 && $writeManifest) {
                    @mkdir($dir . '/public/build', 0775, true);
                    file_put_contents($dir . '/public/build/manifest.json', '{}');
                }

                return new ProcessResult($isBuild ? $buildExit : 0, $isBuild && $buildExit !== 0 ? 'vite build failed' : '', '');
            }
            if ($command[0] === 'docker') {
                return new ProcessResult($gateExit, $gateExit !== 0 ? 'FAILURES!' : '', '');
            }

            return new ProcessResult(0, '', '');
        });
    }

    private function runMix(FakeProcessRunner $fake, RunOptions $options, string $dir): RunReport
    {
        $step = new PlanStep(1, (new HopRegistry())->get(HopRegistry::MIX_TO_VITE_ID), '8.3', '8.3', '11', '11', null, null, [Planner::GATE_TESTS]);
        $plan = new Plan((new ProjectDetector())->detect($dir), self::TODAY, 'test', '8.3', null, null, [$step], []);
        $executor = new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake), $fake, new NodeRunner($fake));

        return $executor->execute($plan, $options);
    }

    private function planner(): Planner
    {
        return new Planner($this->policy(), new HopRegistry());
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
