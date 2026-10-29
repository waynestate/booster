<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Waynestate\Booster\Hop\Hop;
use Waynestate\Booster\Hop\HopKind;
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
use Waynestate\Booster\ToolError;

final class HopExecutorTest extends TestCase
{
    /**
     * @param int            $changedPerHop files Rector reports per run
     * @param string         $gitStatus     what `git status --porcelain` prints
     * @param int            $gateExit      exit code of each hop's gate docker run
     * @param list<string>   $rectorErrors
     * @param int            $baselineExit  exit code of the baseline docker run (the first one)
     */
    private function fake(int $changedPerHop = 1, string $gitStatus = '', int $gateExit = 0, array $rectorErrors = [], int $baselineExit = 0): FakeProcessRunner
    {
        $dockerRuns = 0;

        return new FakeProcessRunner(static function (array $command) use ($changedPerHop, $gitStatus, $gateExit, $rectorErrors, $baselineExit, &$dockerRuns): ProcessResult {
            if ($command[0] === 'git') {
                return new ProcessResult(0, $gitStatus, '');
            }
            if ($command[0] === 'docker') {
                $isRun = ($command[1] ?? '') === 'run';
                if (!$isRun) {
                    return new ProcessResult(0, '', '');
                }
                // The first docker run is the baseline; the rest are the per-hop gates.
                $exit = $dockerRuns === 0 ? $baselineExit : $gateExit;
                $dockerRuns++;

                return new ProcessResult($exit, $exit !== 0 ? 'FAILURES!' : '', '');
            }
            $files = [];
            for ($i = 0; $i < $changedPerHop; $i++) {
                $files[] = ['file' => "/tmp/x/src/F{$i}.php", 'diff' => "--- a\n+++ b"];
            }

            return new ProcessResult(2, (string) json_encode(['file_diffs' => $files, 'errors' => array_map(static fn(string $m): array => ['message' => $m], $rectorErrors)]), '');
        });
    }

    /**
     * @return list<list<string>> the docker run commands, in order
     */
    private function dockerRuns(FakeProcessRunner $fake): array
    {
        return array_values(array_filter($fake->calls, static fn(array $c): bool => $c['command'][0] === 'docker' && ($c['command'][1] ?? '') === 'run'));
    }

    private function execute(FakeProcessRunner $fake, RunOptions $options, string $example = 'raw-php', ?PlanOptions $plan = null): RunReport
    {
        $dir = $this->copyExample($example);
        $project = (new ProjectDetector())->detect($dir);
        $plan = (new Planner($this->policy(), new HopRegistry()))->plan($project, $plan ?? new PlanOptions(fromPhp: '8.1', toPhp: '8.3'), $this->today());
        $executor = new HopExecutor(
            new RectorRunner($fake, '/fake/rector'),
            new ComposerEditor(),
            new TargetImageRunner($fake),
            $fake,
        );

        return $executor->execute($plan, $options);
    }

    /**
     * @return list<string>
     */
    private function statuses(RunReport $report): array
    {
        return array_map(static fn(StepResult $r): string => $r->status, $report->results);
    }

    public function test_a_clean_run_applies_each_hop_and_passes_each_gate(): void
    {
        $report = $this->execute($this->fake(), new RunOptions());

        self::assertSame(['applied', 'applied'], $this->statuses($report));
        self::assertFalse($report->failed());
        self::assertStringContainsString('Gate passed', implode(' ', $report->results[0]->messages));
    }

    public function test_the_gate_uses_the_php_the_step_moves_to(): void
    {
        $fake = $this->fake();
        $this->execute($fake, new RunOptions());

        $runs = $this->dockerRuns($fake);
        self::assertCount(3, $runs);
        self::assertContains('booster-target:8.1', $runs[0]['command']);
        self::assertContains('booster-target:8.2', $runs[1]['command']);
        self::assertContains('booster-target:8.3', $runs[2]['command']);
    }

    public function test_the_baseline_runs_on_the_current_php_before_any_hop(): void
    {
        $fake = $this->fake();
        $report = $this->execute($fake, new RunOptions());

        self::assertSame('passed', $report->baseline->status);
        self::assertSame('8.1', $report->baseline->php);
        self::assertSame(0, $report->baseline->exitCode);
        self::assertSame(['applied', 'applied'], $this->statuses($report));

        $dockerAt = $rectorAt = null;
        foreach ($fake->calls as $i => $call) {
            if ($dockerAt === null && $call['command'][0] === 'docker' && ($call['command'][1] ?? '') === 'run') {
                $dockerAt = $i;
            }
            if ($rectorAt === null && in_array('process', $call['command'], true)) {
                $rectorAt = $i;
            }
        }
        self::assertNotNull($dockerAt);
        self::assertNotNull($rectorAt);
        self::assertLessThan($rectorAt, $dockerAt);
    }

    public function test_a_failing_baseline_stops_before_the_first_hop(): void
    {
        $fake = $this->fake(baselineExit: 1);
        $report = $this->execute($fake, new RunOptions());

        self::assertSame('failed', $report->baseline->status);
        self::assertSame(1, $report->baseline->exitCode);
        self::assertSame(['not-run', 'not-run'], $this->statuses($report));
        self::assertTrue($report->failed());
        self::assertSame([], array_filter($fake->calls, static fn(array $c): bool => in_array('process', $c['command'], true)));
        self::assertStringContainsString('--allow-failing-baseline', implode(' ', $report->results[0]->messages));
    }

    public function test_allow_failing_baseline_continues_and_labels_gate_failures(): void
    {
        $report = $this->execute($this->fake(baselineExit: 1, gateExit: 1), new RunOptions(allowFailingBaseline: true));

        self::assertSame('failed', $report->baseline->status);
        self::assertSame(['gate-failed', 'not-run'], $this->statuses($report));
        self::assertStringContainsString('baseline was already failing', implode(' ', $report->results[0]->messages));
    }

    public function test_skip_gate_reports_a_skipped_baseline(): void
    {
        $fake = $this->fake();
        $report = $this->execute($fake, new RunOptions(skipGate: true));

        self::assertSame('skipped', $report->baseline->status);
        self::assertNull($report->baseline->exitCode);
        self::assertSame([], array_filter($fake->calls, static fn(array $c): bool => $c['command'][0] === 'docker'));
    }

    public function test_the_report_array_contains_the_baseline(): void
    {
        $array = $this->execute($this->fake(), new RunOptions())->toArray();

        self::assertArrayHasKey('baseline', $array);
        self::assertSame('passed', $array['baseline']['status']);
        self::assertSame('8.1', $array['baseline']['php']);
        self::assertSame(0, $array['baseline']['exit_code']);
    }

    public function test_the_baseline_keeps_only_the_last_twenty_lines(): void
    {
        $lines = '';
        for ($i = 1; $i <= 30; $i++) {
            $lines .= "line {$i}\n";
        }
        $fake = new FakeProcessRunner(static function (array $command) use ($lines): ProcessResult {
            if ($command[0] === 'git') {
                return new ProcessResult(0, '', '');
            }
            if ($command[0] === 'docker') {
                // Only the baseline `docker run` produces output; version/inspect succeed.
                if (($command[1] ?? '') !== 'run') {
                    return new ProcessResult(0, '', '');
                }

                return new ProcessResult(1, $lines, '');
            }

            return new ProcessResult(2, (string) json_encode(['file_diffs' => [], 'errors' => []]), '');
        });
        $report = $this->execute($fake, new RunOptions(allowFailingBaseline: true));

        self::assertCount(20, $report->baseline->output);
        self::assertSame('line 11', $report->baseline->output[0]);
        self::assertSame('line 30', $report->baseline->output[19]);
    }

    public function test_rector_is_asked_for_a_preview_before_it_applies(): void
    {
        $fake = $this->fake();
        $this->execute($fake, new RunOptions(), plan: new PlanOptions(fromPhp: '8.2', toPhp: '8.3'));

        $rector = array_values(array_filter($fake->calls, static fn(array $c): bool => in_array('process', $c['command'], true)));
        self::assertContains('--dry-run', $rector[0]['command']);
        self::assertNotContains('--dry-run', $rector[1]['command']);
        self::assertSame('8.3', $rector[0]['env']['BOOSTER_PHP_TARGET']);
    }

    public function test_dry_run_changes_nothing_and_skips_git_and_gate(): void
    {
        $fake = $this->fake(gitStatus: ' M dirty.php');
        $report = $this->execute($fake, new RunOptions(dryRun: true));

        self::assertSame(['previewed', 'previewed'], $this->statuses($report));
        foreach ($fake->calls as $call) {
            self::assertNotSame('git', $call['command'][0]);
            self::assertNotSame('docker', $call['command'][0]);
            self::assertContains('--dry-run', $call['command']);
        }
    }

    public function test_dry_run_leaves_composer_json_alone(): void
    {
        $dir = $this->copyExample('raw-php');
        $before = file_get_contents($dir . '/composer.json');
        $fake = $this->fake();
        $plan = (new Planner($this->policy(), new HopRegistry()))->plan((new ProjectDetector())->detect($dir), new PlanOptions(fromPhp: '8.1', toPhp: '8.3'), $this->today());
        (new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake), $fake))
            ->execute($plan, new RunOptions(dryRun: true));

        self::assertSame($before, file_get_contents($dir . '/composer.json'));
    }

    public function test_a_real_run_edits_composer_json(): void
    {
        $dir = $this->copyExample('raw-php');
        $fake = $this->fake();
        $plan = (new Planner($this->policy(), new HopRegistry()))->plan((new ProjectDetector())->detect($dir), new PlanOptions(fromPhp: '8.1', toPhp: '8.3'), $this->today());
        (new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake), $fake))
            ->execute($plan, new RunOptions());

        self::assertStringContainsString('"php": "^8.3"', (string) file_get_contents($dir . '/composer.json'));
    }

    public function test_a_dirty_working_copy_is_refused(): void
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('uncommitted changes');
        $this->execute($this->fake(gitStatus: " M src/Legacy.php\n"), new RunOptions());
    }

    public function test_a_directory_that_is_not_a_git_repo_is_refused(): void
    {
        $fake = new FakeProcessRunner(static fn(array $c): ProcessResult => new ProcessResult($c[0] === 'git' ? 128 : 0, '', ''));

        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('not a git working copy');
        $this->execute($fake, new RunOptions());
    }

    public function test_allow_dirty_skips_the_git_check(): void
    {
        $fake = $this->fake(gitStatus: ' M x');
        $report = $this->execute($fake, new RunOptions(allowDirty: true, skipGate: true));

        self::assertFalse($report->failed());
        self::assertSame([], array_filter($fake->calls, static fn(array $c): bool => $c['command'][0] === 'git'));
    }

    public function test_a_diff_over_the_cap_stops_the_run_before_anything_is_applied(): void
    {
        $fake = $this->fake(changedPerHop: 4);
        $report = $this->execute($fake, new RunOptions(maxChanges: 3));

        self::assertSame(['capped', 'not-run'], $this->statuses($report));
        self::assertTrue($report->failed());
        foreach ($fake->calls as $call) {
            if (in_array('process', $call['command'], true)) {
                self::assertContains('--dry-run', $call['command']);
            }
        }
    }

    public function test_a_failing_gate_stops_the_run_and_keeps_the_changes(): void
    {
        $report = $this->execute($this->fake(gateExit: 1), new RunOptions());

        self::assertSame(['gate-failed', 'not-run'], $this->statuses($report));
        self::assertStringContainsString('git checkout', implode(' ', $report->results[0]->messages));
        self::assertStringContainsString('FAILURES!', implode(' ', $report->results[0]->messages));
    }

    public function test_rector_errors_stop_the_run_without_applying(): void
    {
        $fake = $this->fake(rectorErrors: ['Parse error in x.php']);
        $report = $this->execute($fake, new RunOptions());

        self::assertSame(['error', 'not-run'], $this->statuses($report));
        foreach ($fake->calls as $call) {
            if (in_array('process', $call['command'], true)) {
                self::assertContains('--dry-run', $call['command']);
            }
        }
    }

    public function test_skip_gate_says_so(): void
    {
        $fake = $this->fake();
        $report = $this->execute($fake, new RunOptions(skipGate: true));

        self::assertStringContainsString('Gate skipped', implode(' ', $report->results[0]->messages));
        self::assertSame([], array_filter($fake->calls, static fn(array $c): bool => $c['command'][0] === 'docker'));
    }

    public function test_manual_hops_are_reported_and_never_fail_the_run(): void
    {
        $report = $this->execute($this->fake(), new RunOptions(skipGate: true), 'vue-spa', new PlanOptions(fromPhp: '8.3', toPhp: '8.3'));

        self::assertSame('manual', $this->statuses($report)[count($report->results) - 1]);
        self::assertFalse($report->failed());
    }

    public function test_a_node_hop_pins_package_json_and_writes_nvmrc(): void
    {
        $dir = $this->copyExample('vue-spa');
        $project = (new ProjectDetector())->detect($dir);
        $plan = (new Planner($this->policy(), new HopRegistry()))->plan($project, new PlanOptions(fromPhp: '8.3', toPhp: '8.3'), $this->today());
        $fake = $this->fake();
        $report = (new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake), $fake, new NodeRunner($fake)))
            ->execute($plan, new RunOptions(skipGate: true));

        self::assertSame(['applied', 'manual'], $this->statuses($report));
        $target = (new HopRegistry())->nodePinTarget();
        $package = json_decode((string) file_get_contents($dir . '/package.json'), true);
        self::assertSame('>=' . $target, $package['engines']['node']);
        self::assertSame('example-vue-spa', $package['name']);
        self::assertSame($target . "\n", (string) file_get_contents($dir . '/.nvmrc'));
        self::assertStringContainsString('engines.node to >=' . $target, implode(' ', $report->results[0]->messages));
    }

    public function test_a_node_hop_dry_run_changes_nothing(): void
    {
        $dir = $this->copyExample('vue-spa');
        $before = file_get_contents($dir . '/package.json');
        $project = (new ProjectDetector())->detect($dir);
        $plan = (new Planner($this->policy(), new HopRegistry()))->plan($project, new PlanOptions(fromPhp: '8.3', toPhp: '8.3'), $this->today());
        $fake = $this->fake();
        $report = (new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake), $fake, new NodeRunner($fake)))
            ->execute($plan, new RunOptions(dryRun: true));

        self::assertSame(['previewed', 'manual'], $this->statuses($report));
        self::assertSame($before, file_get_contents($dir . '/package.json'));
        self::assertFileDoesNotExist($dir . '/.nvmrc');
        $target = (new HopRegistry())->nodePinTarget();
        self::assertStringContainsString(sprintf('Dry run: would set engines.node to >=%s in package.json and write .nvmrc with %s.', $target, $target), implode(' ', $report->results[0]->messages));
    }

    public function test_a_node_hop_that_is_already_pinned_is_nothing_to_do(): void
    {
        // The planner would not offer the hop to an already-pinned app, so the step is built by hand.
        $dir = $this->copyExample('vue-spa');
        $file = $dir . '/package.json';
        $data = json_decode((string) file_get_contents($file), true);
        self::assertIsArray($data);
        $target = (new HopRegistry())->nodePinTarget();
        $data['engines']['node'] = '>=' . $target;
        file_put_contents($file, (string) json_encode($data));
        file_put_contents($dir . '/.nvmrc', $target . "\n");
        $step = new PlanStep(1, (new HopRegistry())->get(HopRegistry::NODE_PIN_ID), '8.3', '8.3', null, null, null, null, [Planner::GATE_TESTS]);
        $plan = new Plan((new ProjectDetector())->detect($dir), '2026-10-28', 'test', '8.3', null, null, [$step], []);
        $fake = $this->fake();
        $report = (new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake), $fake, new NodeRunner($fake)))
            ->execute($plan, new RunOptions(skipGate: true));

        self::assertSame('nothing-to-do', $this->statuses($report)[0]);
        self::assertFalse($report->failed());
    }

    public function test_an_app_without_a_package_json_never_runs_node(): void
    {
        $fake = $this->fake();
        $this->execute($fake, new RunOptions());

        self::assertSame([], array_filter($fake->calls, static fn(array $c): bool => in_array('bookworm-slim', $c['command'], true)));
    }

    public function test_a_passing_build_gate_applies_the_pin(): void
    {
        $dir = $this->copyExample('vue-spa');
        $fake = new FakeProcessRunner(static function (array $command): ProcessResult {
            if ($command[0] === 'git') {
                return new ProcessResult(0, '', '');
            }

            return new ProcessResult(0, '', '');
        });
        $report = $this->runBuildGateHop($dir, $fake);

        self::assertSame([StepResult::APPLIED], $this->statuses($report));
        self::assertStringContainsString('Build gate passed', implode(' ', $report->results[0]->messages));
        $package = json_decode((string) file_get_contents($dir . '/package.json'), true);
        self::assertSame('>=' . (new HopRegistry())->nodePinTarget(), $package['engines']['node']);
    }

    public function test_a_failing_build_gate_fails_the_node_hop(): void
    {
        $dir = $this->copyExample('vue-spa');
        $fake = new FakeProcessRunner(static function (array $command): ProcessResult {
            if ($command[0] === 'git') {
                return new ProcessResult(0, '', '');
            }
            if ($command[0] === 'docker') {
                return in_array('build', $command, true) ? new ProcessResult(1, '', 'vite build failed') : new ProcessResult(0, '', '');
            }

            return new ProcessResult(0, '', '');
        });
        $report = $this->runBuildGateHop($dir, $fake);

        self::assertSame([StepResult::GATE_FAILED], $this->statuses($report));
        self::assertTrue($report->failed());
        self::assertStringContainsString('npm run build', implode(' ', $report->results[0]->messages));
        self::assertStringContainsString('vite build failed', implode(' ', $report->results[0]->messages));
    }

    private function runBuildGateHop(string $dir, FakeProcessRunner $fake): RunReport
    {
        $target = (new HopRegistry())->nodePinTarget();
        $hop = new Hop(
            id: HopRegistry::NODE_PIN_ID,
            kind: HopKind::Node,
            from: 'any',
            to: $target,
            summary: 'A pin hop that asks for a build gate.',
            npmSet: ['engines.node' => '>=' . $target],
            buildGate: true,
        );
        $step = new PlanStep(1, $hop, '8.3', '8.3', null, null, null, null, [Planner::GATE_TESTS]);
        $plan = new Plan((new ProjectDetector())->detect($dir), '2026-10-28', 'test', '8.3', null, null, [$step], []);
        $executor = new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake), $fake, new NodeRunner($fake));

        return $executor->execute($plan, new RunOptions());
    }

    public function test_the_default_test_command_follows_the_composer_scripts(): void
    {
        $dir = $this->copyExample('raw-php');
        $composer = json_decode((string) file_get_contents($dir . '/composer.json'), true);
        $composer['scripts']['test'] = 'phpunit';
        file_put_contents($dir . '/composer.json', json_encode($composer));

        $fake = $this->fake();
        $plan = (new Planner($this->policy(), new HopRegistry()))->plan((new ProjectDetector())->detect($dir), new PlanOptions(fromPhp: '8.2', toPhp: '8.3'), $this->today());
        (new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake), $fake))
            ->execute($plan, new RunOptions());

        $run = array_values(array_filter($fake->calls, static fn(array $c): bool => ($c['command'][1] ?? '') === 'run'))[0]['command'];
        self::assertStringContainsString('&& composer test', $run[count($run) - 1]);
    }

    public function test_rector_json_paths_become_relative_to_the_app(): void
    {
        $fake = new FakeProcessRunner(static function (array $command, array $env): ProcessResult {
            $app = $env['BOOSTER_APP_PATH'];

            return new ProcessResult(2, (string) json_encode(['file_diffs' => [['file' => $app . '/src/A.php', 'diff' => 'd']]]), '');
        });

        $result = (new RectorRunner($fake, '/fake/rector'))->run((new HopRegistry())->get('php-74-to-80'), '/srv/app', true, '8.0');

        self::assertSame('src/A.php', $result->files[0]['file']);
    }

    public function test_rector_paths_relative_to_the_scratch_directory_are_resolved(): void
    {
        $fake = new FakeProcessRunner(static function (array $c, array $e, ?string $cwd): ProcessResult {
            $depth = substr_count(trim(str_replace('\\', '/', (string) $cwd), '/'), '/') + 1;

            return new ProcessResult(2, (string) json_encode(['file_diffs' => [['file' => str_repeat('../', $depth) . 'srv/app/src/A.php', 'diff' => 'd']]]), '');
        });

        $result = (new RectorRunner($fake, '/fake/rector'))->run((new HopRegistry())->get('php-74-to-80'), '/srv/app', true, '8.0');

        self::assertSame('src/A.php', $result->files[0]['file']);
    }

    public function test_rector_with_no_json_is_a_tool_error(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(255, 'PHP Fatal error', 'boom'));

        $this->expectException(ToolError::class);
        (new RectorRunner($fake, '/fake/rector'))->run((new HopRegistry())->get('php-74-to-80'), '/srv/app', true, '8.0');
    }

    public function test_a_laravel_dry_run_without_vendor_is_incomplete(): void
    {
        $fake = $this->fake();
        $report = $this->execute($fake, new RunOptions(dryRun: true), 'laravel-blade');

        $incomplete = array_filter($report->results, static fn(StepResult $r): bool => $r->incomplete);
        self::assertNotEmpty($incomplete);
        foreach ($incomplete as $r) {
            self::assertStringStartsWith('laravel-', $r->hopId);
            self::assertStringContainsString('Preview incomplete', implode(' ', $r->messages));
        }
    }

    public function test_a_laravel_dry_run_with_vendor_is_complete(): void
    {
        $dir = $this->copyExample('laravel-blade');
        mkdir($dir . '/vendor', 0775, true);
        file_put_contents($dir . '/vendor/autoload.php', "<?php\n");
        $project = (new ProjectDetector())->detect($dir);
        $plan = (new Planner($this->policy(), new HopRegistry()))->plan($project, new PlanOptions(fromPhp: '8.1', toPhp: '8.3'), $this->today());
        $fake = $this->fake();
        $report = (new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake), $fake))
            ->execute($plan, new RunOptions(dryRun: true));

        foreach ($report->results as $r) {
            self::assertFalse($r->incomplete);
        }
    }

    public function test_a_hop_that_changes_nothing_is_nothing_to_do_and_skips_the_gate(): void
    {
        $dir = $this->copyExample('raw-php');
        $composer = json_decode((string) file_get_contents($dir . '/composer.json'), true);
        $composer['require']['php'] = '^8.3';
        file_put_contents($dir . '/composer.json', (string) json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $project = (new ProjectDetector())->detect($dir);
        $plan = (new Planner($this->policy(), new HopRegistry()))->plan($project, new PlanOptions(fromPhp: '8.1', toPhp: '8.3'), $this->today());
        $fake = $this->fake(changedPerHop: 0);
        $report = (new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake), $fake))
            ->execute($plan, new RunOptions());

        self::assertSame(['nothing-to-do', 'nothing-to-do'], $this->statuses($report));
        self::assertFalse($report->failed());
        self::assertCount(1, $this->dockerRuns($fake));
        self::assertStringContainsString('Nothing to do', implode(' ', $report->results[0]->messages));
    }

    /**
     * Plans only the cas-to-sesame hop from the wayne-cas example and runs it.
     *
     * @return array{0: string, 1: RunReport} the temp dir and the report
     */
    private function executeSesameHop(FakeProcessRunner $fake, RunOptions $options): array
    {
        $dir = $this->copyExample('laravel-wayne-cas');
        $project = (new ProjectDetector())->detect($dir);
        // PHP and Laravel stay where the example has them, so the plan is the
        // cas-to-sesame hop alone.
        $plan = (new Planner($this->policy(), new HopRegistry()))->plan($project, new PlanOptions(fromPhp: '8.3', toPhp: '8.3', toLaravel: '12', sesame: true), $this->today());
        $executor = new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake), $fake);

        return [$dir, $executor->execute($plan, $options)];
    }

    public function test_the_sesame_hop_moves_config_and_rewrites_the_model(): void
    {
        $fake = $this->fake(changedPerHop: 0);
        [$dir, $report] = $this->executeSesameHop($fake, new RunOptions(skipGate: true));

        self::assertSame(['applied'], $this->statuses($report));
        self::assertFileExists($dir . '/config/sesame.php');
        self::assertFileExists($dir . '/config/wayne-cas.php');
        self::assertStringContainsString("'base_url' => 'https://cas.example.edu/cas'", (string) file_get_contents($dir . '/config/sesame.php'));
        self::assertStringContainsString('class User implements WayneCasable', (string) file_get_contents($dir . '/app/User.php'));
        $composer = json_decode((string) file_get_contents($dir . '/composer.json'), true);
        self::assertSame('^0.2', $composer['require']['waynestate/sesame']);
        self::assertArrayNotHasKey('cit-web-infra/wayne-cas', $composer['require']);
        self::assertSame(['config/sesame.php', 'app/User.php'], array_map(static fn(array $f): string => $f['file'], $report->results[0]->files));
        $messages = implode(' ', $report->results[0]->messages);
        foreach (['DUO_HOSTNAME', 'DUO_IKEY', 'DUO_SKEY', 'DUO_AKEY'] as $needle) {
            self::assertStringContainsString($needle, $messages);
        }
        self::assertStringContainsString('Gate skipped', $messages);
    }

    public function test_the_sesame_hop_dry_run_reports_but_changes_nothing(): void
    {
        $dir = $this->copyExample('laravel-wayne-cas');
        $before = $this->snapshot($dir);
        $fake = $this->fake(changedPerHop: 0);
        [, $report] = $this->executeSesameHop($fake, new RunOptions(dryRun: true));

        self::assertSame(['previewed'], $this->statuses($report));
        self::assertSame($before, $this->snapshot($dir));
        self::assertFileDoesNotExist($dir . '/config/sesame.php');
        self::assertCount(2, $report->results[0]->files);
        $messages = implode(' ', $report->results[0]->messages);
        self::assertStringContainsString('Dry run: would write config/sesame.php', $messages);
        self::assertStringContainsString('Dry run: would rewrite app/User.php', $messages);
    }

    /**
     * Every file in the tree, by relative path and content.
     *
     * @return array<string, string>
     */
    private function snapshot(string $dir): array
    {
        $out = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
                $out[$relative] = (string) file_get_contents($file->getPathname());
            }
        }
        ksort($out);

        return $out;
    }
}
