<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Plan\PlanOptions;
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
use Waynestate\Booster\ToolError;

final class HopExecutorTest extends TestCase
{
    /**
     * @param int            $changedPerHop files Rector reports per run
     * @param string         $gitStatus     what `git status --porcelain` prints
     * @param int            $gateExit      exit code of the docker run
     * @param list<string>   $rectorErrors
     */
    private function fake(int $changedPerHop = 1, string $gitStatus = '', int $gateExit = 0, array $rectorErrors = []): FakeProcessRunner
    {
        return new FakeProcessRunner(static function (array $command) use ($changedPerHop, $gitStatus, $gateExit, $rectorErrors): ProcessResult {
            if ($command[0] === 'git') {
                return new ProcessResult(0, $gitStatus, '');
            }
            if ($command[0] === 'docker') {
                $isRun = ($command[1] ?? '') === 'run';

                return new ProcessResult($isRun ? $gateExit : 0, $isRun && $gateExit !== 0 ? 'FAILURES!' : '', '');
            }
            $files = [];
            for ($i = 0; $i < $changedPerHop; $i++) {
                $files[] = ['file' => "/tmp/x/src/F{$i}.php", 'diff' => "--- a\n+++ b"];
            }

            return new ProcessResult(2, (string) json_encode(['file_diffs' => $files, 'errors' => array_map(static fn(string $m): array => ['message' => $m], $rectorErrors)]), '');
        });
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

        $runs = array_values(array_filter($fake->calls, static fn(array $c): bool => $c['command'][0] === 'docker' && ($c['command'][1] ?? '') === 'run'));
        self::assertCount(2, $runs);
        self::assertContains('booster-target:8.2', $runs[0]['command']);
        self::assertContains('booster-target:8.3', $runs[1]['command']);
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
}
