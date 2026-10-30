<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Plan\Plan;
use Waynestate\Booster\Plan\PlanStep;
use Waynestate\Booster\Plan\Planner;
use Waynestate\Booster\Project\ProjectDetector;
use Waynestate\Booster\Run\ComposerEditor;
use Waynestate\Booster\Run\HopExecutor;
use Waynestate\Booster\Run\ProcessResult;
use Waynestate\Booster\Run\RectorRunner;
use Waynestate\Booster\Run\RunOptions;
use Waynestate\Booster\Run\RunReport;
use Waynestate\Booster\Run\StepResult;
use Waynestate\Booster\Run\TargetImageRunner;

/**
 * The Inertia constraint raise a Laravel hop performs for an Inertia/Vue app:
 * inertia-laravel and @inertiajs/vue3 move toward the constraints the new
 * Laravel major pairs with, only when the app already carries them.
 */
final class InertiaHopTest extends TestCase
{
    public function test_a_laravel_hop_raises_the_inertia_constraints_toward_the_new_major(): void
    {
        $dir = $this->tempDir();
        $this->makeApp($dir);
        $report = $this->runLaravel($this->fake(), new RunOptions(), $dir, '12', '13');

        self::assertSame([StepResult::APPLIED], $this->statuses($report));
        $composer = json_decode((string) file_get_contents($dir . '/composer.json'), true);
        self::assertIsArray($composer);
        self::assertSame('^13.0', $composer['require']['laravel/framework']);
        self::assertSame('^2.0', $composer['require']['inertiajs/inertia-laravel']);
        $package = json_decode((string) file_get_contents($dir . '/package.json'), true);
        self::assertIsArray($package);
        self::assertSame('^2.0', $package['dependencies']['@inertiajs/vue3']);

        $messages = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString('Set inertiajs/inertia-laravel to ^2.0 in require.', $messages);
        self::assertStringContainsString('package.json: dependencies @inertiajs/vue3 to ^2.0.', $messages);
        self::assertStringContainsString('Gate passed', $messages);
    }

    public function test_a_laravel_hop_leaves_inertia_alone_when_it_already_floors_at_the_target(): void
    {
        $dir = $this->tempDir();
        $this->makeApp($dir, '^12.0', '^2.0', '^2.0.0');
        $report = $this->runLaravel($this->fake(), new RunOptions(), $dir, '12', '13');

        self::assertSame([StepResult::APPLIED], $this->statuses($report));
        $composer = json_decode((string) file_get_contents($dir . '/composer.json'), true);
        self::assertIsArray($composer);
        self::assertSame('^2.0', $composer['require']['inertiajs/inertia-laravel']);
        $package = json_decode((string) file_get_contents($dir . '/package.json'), true);
        self::assertIsArray($package);
        self::assertSame('^2.0.0', $package['dependencies']['@inertiajs/vue3']);

        $messages = implode("\n", $report->results[0]->messages);
        self::assertStringNotContainsString('Set inertiajs/inertia-laravel', $messages);
        self::assertStringNotContainsString('@inertiajs/vue3 to', $messages);
    }

    public function test_the_dry_run_reports_the_inertia_raises_without_changing_any_file(): void
    {
        $dir = $this->tempDir();
        $this->makeApp($dir);
        $names = ['composer.json', 'package.json'];
        $before = $this->snapshot($dir, $names);
        $report = $this->runLaravel($this->fake(), new RunOptions(dryRun: true), $dir, '12', '13');

        self::assertSame([StepResult::PREVIEWED], $this->statuses($report));
        $messages = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString('Dry run: would set inertiajs/inertia-laravel to ^2.0 in require.', $messages);
        self::assertStringContainsString('Dry run: would set package.json dependencies @inertiajs/vue3 to ^2.0.', $messages);
        self::assertSame($before, $this->snapshot($dir, $names));
    }

    public function test_a_laravel_hop_below_eleven_notes_that_boosters_pins_nothing(): void
    {
        $dir = $this->tempDir();
        $this->makeApp($dir, '^9.0', '^1.0', '^1.0.0');
        $report = $this->runLaravel($this->fake(), new RunOptions(), $dir, '9', '10');

        self::assertSame([StepResult::APPLIED], $this->statuses($report));
        $composer = json_decode((string) file_get_contents($dir . '/composer.json'), true);
        self::assertIsArray($composer);
        // Nothing was pinned for Laravel 10, so the constraints stay put.
        self::assertSame('^1.0', $composer['require']['inertiajs/inertia-laravel']);

        $messages = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString('Inertia: BOOSTER pins no inertia-laravel or @inertiajs/vue3 constraint for Laravel 10', $messages);
    }

    public function test_a_non_inertia_laravel_app_never_sees_inertia_changes(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/composer.json', (string) json_encode([
            'name' => 'example/plain-app',
            'require' => [
                'php' => '^8.3',
                'laravel/framework' => '^12.0',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        file_put_contents($dir . '/package.json', (string) json_encode([
            'name' => 'example-plain-app',
            'private' => true,
            'dependencies' => ['vue' => '^3.5.0'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $report = $this->runLaravel($this->fake(), new RunOptions(), $dir, '12', '13');

        self::assertSame([StepResult::APPLIED], $this->statuses($report));
        $messages = implode("\n", $report->results[0]->messages);
        self::assertStringNotContainsString('Set inertiajs/inertia-laravel', $messages);
        self::assertStringNotContainsString('@inertiajs/vue3 to', $messages);
    }

    /**
     * A small Laravel 12 Inertia/Vue app. vendor/autoload.php exists so a
     * dry run is not flagged incomplete.
     */
    private function makeApp(string $dir, string $laravel = '^12.0', string $inertiaComposer = '^1.0', string $inertiaNpm = '^1.0.0'): void
    {
        file_put_contents($dir . '/composer.json', (string) json_encode([
            'name' => 'example/inertia-app',
            'require' => [
                'php' => '^8.3',
                'laravel/framework' => $laravel,
                'inertiajs/inertia-laravel' => $inertiaComposer,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        file_put_contents($dir . '/package.json', (string) json_encode([
            'name' => 'example-inertia-app',
            'private' => true,
            'dependencies' => [
                '@inertiajs/vue3' => $inertiaNpm,
                'vue' => '^3.5.0',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        mkdir($dir . '/vendor', 0775, true);
        file_put_contents($dir . '/vendor/autoload.php', "<?php\n");
    }

    /**
     * git answers clean, the first docker run (the baseline) and every gate
     * answer from $gateExit, and Rector reports $changedPerHop changed files.
     */
    private function fake(int $changedPerHop = 1, int $gateExit = 0): FakeProcessRunner
    {
        $dockerRuns = 0;

        return new FakeProcessRunner(static function (array $command) use ($changedPerHop, $gateExit, &$dockerRuns): ProcessResult {
            if ($command[0] === 'git') {
                return new ProcessResult(0, '', '');
            }
            if ($command[0] === 'docker') {
                $exit = $dockerRuns === 0 ? 0 : $gateExit;
                $dockerRuns++;

                return new ProcessResult($exit, $exit !== 0 ? 'FAILURES!' : '', '');
            }
            $files = [];
            for ($i = 0; $i < $changedPerHop; $i++) {
                $files[] = ['file' => "/tmp/x/src/F{$i}.php", 'diff' => "--- a\n+++ b"];
            }

            return new ProcessResult(2, (string) json_encode(['file_diffs' => $files, 'errors' => []]), '');
        });
    }

    private function runLaravel(FakeProcessRunner $fake, RunOptions $options, string $dir, string $from, string $to): RunReport
    {
        $step = new PlanStep(1, (new HopRegistry())->get(HopRegistry::laravelHopId($from, $to)), '8.3', '8.3', $from, $to, null, null, [Planner::GATE_TESTS]);
        $plan = new Plan((new ProjectDetector())->detect($dir), self::TODAY, 'test', '8.3', $to, null, [$step], []);
        $executor = new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake), $fake);

        return $executor->execute($plan, $options);
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
}
