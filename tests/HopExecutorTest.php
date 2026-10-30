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
use Waynestate\Booster\Report\TextWriter;
use Waynestate\Booster\Run\BaselineResult;
use Waynestate\Booster\Run\ComposerEditor;
use Waynestate\Booster\Run\HopExecutor;
use Waynestate\Booster\Run\NodeRunner;
use Waynestate\Booster\Run\ProcessResult;
use Waynestate\Booster\Run\RectorResult;
use Waynestate\Booster\Run\ProctorResult;
use Waynestate\Booster\Run\ProctorRunner;
use Waynestate\Booster\Run\RectorRunner;
use Waynestate\Booster\Run\RunOptions;
use Waynestate\Booster\Run\RunReport;
use Waynestate\Booster\Run\StepResult;
use Waynestate\Booster\Run\TargetImageRunner;
use Waynestate\Booster\ToolError;

final class HopExecutorTest extends TestCase
{
    /**
     * @param int            $changedPerHop        files Rector reports per run
     * @param string         $gitStatus            what `git status --porcelain` prints
     * @param int            $gateExit             exit code of each hop's gate docker run
     * @param list<string>   $rectorErrors
     * @param int            $baselineExit         exit code of the baseline docker run (the first one)
     * @param int            $proctorExit          PROCTOR's exit code (0 pass, 1 fail, 2 tripped); -1 leaves PROCTOR unanswered
     * @param int            $proctorBaselineExit  exit code of the first PROCTOR call (the baseline); -1 answers it like the rest
     * @param string|null    $gitStatusStart       what the first git status prints (the start-of-run read); null answers it like the rest
     * @param string|null    $baselineStdout       stdout of a failing baseline run; null answers 'FAILURES!' (the tests phase)
     * @param string|null    $gateStdout           stdout of a failing gate run; null answers 'FAILURES!' (the tests phase)
     */
    private function fake(int $changedPerHop = 1, string $gitStatus = '', int $gateExit = 0, array $rectorErrors = [], int $baselineExit = 0, int $proctorExit = -1, int $proctorBaselineExit = -1, ?string $gitStatusStart = null, ?string $baselineStdout = null, ?string $gateStdout = null): FakeProcessRunner
    {
        $dockerRuns = 0;
        $proctorRuns = 0;
        $gitRuns = 0;

        return new FakeProcessRunner(static function (array $command) use ($changedPerHop, $gitStatus, $gateExit, $rectorErrors, $baselineExit, $proctorExit, $proctorBaselineExit, $gitStatusStart, $baselineStdout, $gateStdout, &$dockerRuns, &$proctorRuns, &$gitRuns): ProcessResult {
            if ($command[0] === 'git') {
                $first = $gitRuns === 0;
                $gitRuns++;

                return new ProcessResult(0, $first && $gitStatusStart !== null ? $gitStatusStart : $gitStatus, '');
            }
            if ($command[0] === 'docker') {
                $isRun = ($command[1] ?? '') === 'run';
                if (!$isRun) {
                    return new ProcessResult(0, '', '');
                }
                // The first docker run is the baseline; the rest are the per-hop gates.
                $exit = $dockerRuns === 0 ? $baselineExit : $gateExit;
                $stdout = $exit !== 0 ? ($dockerRuns === 0 ? ($baselineStdout ?? 'FAILURES!') : ($gateStdout ?? 'FAILURES!')) : '';
                $dockerRuns++;

                return new ProcessResult($exit, $stdout, '');
            }
            if (self::isProctorCommand($command)) {
                // The first PROCTOR call is the baseline on the starting PHP; the last is the final gate.
                $exit = $proctorRuns === 0 && $proctorBaselineExit >= 0 ? $proctorBaselineExit : $proctorExit;
                $proctorRuns++;
                if ($exit >= 0) {
                    if ($exit === 2) {
                        return new ProcessResult(2, '', 'PROCTOR could not read composer.json');
                    }
                    $fixture = $exit === 0 ? 'pass-report.json' : 'fail-report.json';

                    return new ProcessResult($exit, self::proctorFixture($fixture), '');
                }
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

    /** Like execute(), but with a PROCTOR gate wired in and answering from $fake. */
    private function executeWithProctor(FakeProcessRunner $fake, RunOptions $options, string $example = 'raw-php', ?PlanOptions $plan = null): RunReport
    {
        $dir = $this->copyExample($example);
        $project = (new ProjectDetector())->detect($dir);
        $plan = (new Planner($this->policy(), new HopRegistry()))->plan($project, $plan ?? new PlanOptions(fromPhp: '8.1', toPhp: '8.3'), $this->today());
        $executor = new HopExecutor(
            new RectorRunner($fake, '/fake/rector'),
            new ComposerEditor(),
            new TargetImageRunner($fake),
            $fake,
            null,
            null,
            new ProctorRunner($fake, '/fake/proctor', 'php'),
        );

        return $executor->execute($plan, $options);
    }

    /**
     * @return list<list<string>> the PROCTOR gate commands, in order
     */
    private function proctorRuns(FakeProcessRunner $fake): array
    {
        return array_map(
            static fn(array $c): array => $c['command'],
            array_values(array_filter($fake->calls, static fn(array $c): bool => self::isProctorCommand($c['command']))),
        );
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
        self::assertContains($this->gateTag('8.1'), $runs[0]['command']);
        self::assertContains($this->gateTag('8.2'), $runs[1]['command']);
        self::assertContains($this->gateTag('8.3'), $runs[2]['command']);
    }

    /**
     * The stamped image tag the gate builds for a PHP version.
     */
    private function gateTag(string $php): string
    {
        return 'booster-target:' . $php . '-' . substr(hash('sha256', file_get_contents(dirname(__DIR__) . '/docker/Dockerfile')), 0, 12);
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

    public function test_a_baseline_whose_install_failed_is_not_reported_as_test_failures(): void
    {
        $fake = $this->fake(baselineExit: 4, baselineStdout: "Problem 1 of 1\n- The lock file is not up to date with the latest changes in composer.json.\nBOOSTER_GATE_PHASE=install\nBOOSTER_GATE_EXIT=4");
        $report = $this->execute($fake, new RunOptions());

        self::assertSame('install-failed', $report->baseline->status);
        self::assertSame(4, $report->baseline->exitCode);
        self::assertSame(['not-run', 'not-run'], $this->statuses($report));
        self::assertTrue($report->failed());
        $all = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString('composer install failed (exit 4)', $all);
        self::assertStringNotContainsString("the app's tests fail", $all);
        $text = (new TextWriter())->run($report);
        self::assertStringContainsString('Baseline: composer install failed (exit 4)', $text);
        self::assertStringContainsString('The lock file is not up to date', $text);
        self::assertStringNotContainsString("The app's tests fail before any change", $text);
    }

    public function test_an_install_failed_baseline_gives_the_gate_no_baseline_to_compare(): void
    {
        $fake = $this->fake(
            baselineExit: 4,
            gateExit: 1,
            baselineStdout: "Your composer.json requires php ^8.3 but your system has 8.1.\nBOOSTER_GATE_PHASE=install\nBOOSTER_GATE_EXIT=4",
            gateStdout: 'FAILURES!',
        );
        $report = $this->execute($fake, new RunOptions(allowFailingBaseline: true));

        self::assertSame('install-failed', $report->baseline->status);
        self::assertSame(['gate-failed', 'not-run'], $this->statuses($report));
        $all = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString('No baseline to compare against', $all);
        self::assertStringNotContainsString('baseline was already failing', $all);
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

    public function test_the_baseline_keeps_the_tail_the_container_reports(): void
    {
        // Tailing happens inside the container (see TargetImageRunnerTest); the host
        // keeps whatever tail the container printed, with the marker lines removed.
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

                return new ProcessResult(1, $lines . "BOOSTER_GATE_PHASE=tests\nBOOSTER_GATE_EXIT=1\n", '');
            }

            return new ProcessResult(2, (string) json_encode(['file_diffs' => [], 'errors' => []]), '');
        });
        $report = $this->execute($fake, new RunOptions(allowFailingBaseline: true));

        self::assertCount(30, $report->baseline->output);
        self::assertSame('line 1', $report->baseline->output[0]);
        self::assertSame('line 30', $report->baseline->output[29]);
        foreach ($report->baseline->output as $line) {
            self::assertStringStartsNotWith('BOOSTER_GATE_', $line);
        }
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
        // The refusal check is skipped; the one status read is the undo hint's
        // record of what the user already had untracked.
        $gitCalls = array_values(array_filter($fake->calls, static fn(array $c): bool => $c['command'][0] === 'git'));
        self::assertCount(1, $gitCalls);
        self::assertSame(['status', '--porcelain'], array_slice($gitCalls[0]['command'], 3));
    }

    public function test_a_diff_over_an_explicit_max_changes_stops_the_run_before_anything_is_applied(): void
    {
        $fake = $this->fake(changedPerHop: 4);
        $report = $this->execute($fake, new RunOptions(maxChanges: 3));

        self::assertSame(['capped', 'not-run'], $this->statuses($report));
        self::assertStringContainsString('This hop wants to change 4 files and --max-changes is 3. Nothing was changed.', implode(' ', $report->results[0]->messages));
        self::assertTrue($report->failed());
        foreach ($fake->calls as $call) {
            if (in_array('process', $call['command'], true)) {
                self::assertContains('--dry-run', $call['command']);
            }
        }
    }

    public function test_the_review_threshold_defaults_to_two_hundred_files(): void
    {
        $options = new RunOptions();

        self::assertSame(200, $options->reviewThreshold);
        self::assertNull($options->maxChanges);
        self::assertNull($options->confirm);
    }

    public function test_an_explicit_max_changes_of_zero_is_no_limit_and_no_prompt(): void
    {
        $fake = $this->fake(changedPerHop: 4);
        $report = $this->execute($fake, new RunOptions(maxChanges: 0));

        self::assertSame(['applied', 'applied'], $this->statuses($report));
    }

    public function test_a_hop_over_the_threshold_stops_when_not_interactive(): void
    {
        $fake = $this->fake(changedPerHop: 4);
        $report = $this->execute($fake, new RunOptions(reviewThreshold: 3));

        self::assertSame(['capped', 'not-run'], $this->statuses($report));
        self::assertStringContainsString('This hop wants to change 4 files, over the review threshold of 3. Nothing was changed. Read the per-rule summary, then run again with --max-changes=4 to apply it, or --max-changes=0 for no limit.', implode(' ', $report->results[0]->messages));
        self::assertTrue($report->failed());
        foreach ($fake->calls as $call) {
            if (in_array('process', $call['command'], true)) {
                self::assertContains('--dry-run', $call['command']);
            }
        }
    }

    public function test_a_hop_over_the_threshold_applies_when_the_person_confirms(): void
    {
        $fake = $this->fake(changedPerHop: 4);
        $asked = [];
        $report = $this->execute($fake, new RunOptions(reviewThreshold: 3, confirm: static function (int $count, array $summary) use (&$asked): bool {
            $asked[] = [$count, $summary];

            return true;
        }));

        self::assertSame(['applied', 'applied'], $this->statuses($report));
        // Both hops are over the threshold, so both ask.
        self::assertCount(2, $asked);
        self::assertSame(4, $asked[0][0]);
    }

    public function test_a_hop_over_the_threshold_stops_when_the_person_declines(): void
    {
        $fake = $this->fake(changedPerHop: 4);
        $asked = 0;
        $report = $this->execute($fake, new RunOptions(reviewThreshold: 3, confirm: static function () use (&$asked): bool {
            $asked++;

            return false;
        }));

        self::assertSame(['capped', 'not-run'], $this->statuses($report));
        self::assertSame(1, $asked);
        self::assertStringContainsString('This hop wants to change 4 files, over the review threshold of 3.', implode(' ', $report->results[0]->messages));
    }

    public function test_a_dry_run_over_the_threshold_is_previewed_with_the_note_and_summary(): void
    {
        $fake = $this->fakeWithRules(4);
        $report = $this->execute($fake, new RunOptions(dryRun: true, reviewThreshold: 3));

        self::assertSame(['previewed', 'previewed'], $this->statuses($report));
        self::assertFalse($report->failed());
        $messages = $report->results[0]->messages;
        $noteIndex = array_search('Over the review threshold: this hop changes 4 files (threshold 3). Read the per-rule summary below.', $messages, true);
        self::assertIsInt($noteIndex);
        self::assertSame([
            '  RuleAlpha: 4 files',
            '  RuleBeta: 4 files',
            '  RuleDelta: 2 files',
            '  RuleGamma: 2 files',
        ], array_slice($messages, $noteIndex + 1, 4));
    }

    public function test_the_per_rule_summary_lists_fifteen_rules_and_collapses_the_rest(): void
    {
        $rules = [];
        for ($i = 0; $i < 17; $i++) {
            $rules[] = sprintf('Rector\\Demo\\Rule%02d', $i);
        }
        $fake = new FakeProcessRunner(static function (array $command) use ($rules): ProcessResult {
            if ($command[0] === 'git') {
                return new ProcessResult(0, '', '');
            }
            if ($command[0] === 'docker') {
                return new ProcessResult(0, '', '');
            }
            $files = [];
            foreach ($rules as $i => $rule) {
                $files[] = ['file' => "/tmp/x/src/F{$i}.php", 'diff' => '+x', 'applied_rectors' => [$rule]];
            }

            return new ProcessResult(2, (string) json_encode(['file_diffs' => $files, 'errors' => []]), '');
        });
        $report = $this->execute($fake, new RunOptions(dryRun: true, reviewThreshold: 3));

        $messages = $report->results[0]->messages;
        $noteIndex = array_search('Over the review threshold: this hop changes 17 files (threshold 3). Read the per-rule summary below.', $messages, true);
        self::assertIsInt($noteIndex);
        $summary = array_slice($messages, $noteIndex + 1, 16);
        self::assertSame('  Rule00: 1 files', $summary[0]);
        self::assertSame('  Rule14: 1 files', $summary[14]);
        self::assertSame('  and 2 more rules', $summary[15]);
    }

    public function test_the_per_rule_summary_starts_with_the_pre_pass_total(): void
    {
        $dir = $this->copyExample('raw-php');
        $legacy = $dir . '/src/Legacy.php';
        $original = "<?php\n\nclass Legacy\n{\n    public function make()\n    {\n        \$a = &new Self();\n\n        return \$a;\n    }\n}\n";
        file_put_contents($legacy, $original);
        $fake = $this->fakeWithRules(4);
        $project = (new ProjectDetector())->detect($dir);
        $plan = (new Planner($this->policy(), new HopRegistry()))->plan($project, new PlanOptions(fromPhp: '8.1', toPhp: '8.3'), $this->today());
        $report = (new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake), $fake))
            ->execute($plan, new RunOptions(dryRun: true, reviewThreshold: 3));

        $messages = $report->results[0]->messages;
        $noteIndex = array_search('Over the review threshold: this hop changes 5 files (threshold 3). Read the per-rule summary below.', $messages, true);
        self::assertIsInt($noteIndex);
        self::assertSame('  new-by-reference pre-pass: 1 files', $messages[$noteIndex + 1]);
    }

    public function test_a_capped_php_step_carries_its_rule_counts_in_the_json_report(): void
    {
        $fake = $this->fakeWithRules(4);
        $report = $this->execute($fake, new RunOptions(reviewThreshold: 3));

        self::assertSame(['capped', 'not-run'], $this->statuses($report));
        $array = $report->toArray();
        self::assertSame([
            'RuleAlpha' => 4,
            'RuleBeta' => 4,
            'RuleGamma' => 2,
            'RuleDelta' => 2,
        ], $array['results'][0]['rules']);
    }

    public function test_the_json_report_omits_rules_when_there_are_none(): void
    {
        $report = $this->execute($this->fake(), new RunOptions(dryRun: true));

        $array = $report->toArray();
        self::assertArrayNotHasKey('rules', $array['results'][0]);
    }

    /**
     * Like fake(), but every file diff names the rules that touched it, so the
     * per-rule summary has something to count. Four files give Alpha 4, Beta 4,
     * Gamma 2 and Delta 2.
     */
    private function fakeWithRules(int $changedPerHop = 4): FakeProcessRunner
    {
        return new FakeProcessRunner(static function (array $command) use ($changedPerHop): ProcessResult {
            if ($command[0] === 'git') {
                return new ProcessResult(0, '', '');
            }
            if ($command[0] === 'docker') {
                return new ProcessResult(0, '', '');
            }
            $files = [];
            for ($i = 0; $i < $changedPerHop; $i++) {
                $files[] = [
                    'file' => "/tmp/x/src/F{$i}.php",
                    'diff' => "--- a\n+++ b",
                    'applied_rectors' => [
                        'Rector\Demo\RuleAlpha',
                        'Rector\Demo\RuleBeta',
                        $i % 2 === 0 ? 'Rector\Demo\RuleGamma' : 'Rector\Demo\RuleDelta',
                    ],
                ];
            }

            return new ProcessResult(2, (string) json_encode(['file_diffs' => $files, 'errors' => []]), '');
        });
    }

    public function test_a_failing_gate_stops_the_run_and_keeps_the_changes(): void
    {
        $report = $this->execute($this->fake(gateExit: 1), new RunOptions());

        self::assertSame(['gate-failed', 'not-run'], $this->statuses($report));
        self::assertStringContainsString('git checkout', implode(' ', $report->results[0]->messages));
        self::assertStringContainsString('FAILURES!', implode(' ', $report->results[0]->messages));
    }

    public function test_the_undo_hint_names_files_the_run_created(): void
    {
        $report = $this->execute($this->fake(gitStatusStart: '', gitStatus: "?? src/NewThing.php\n?? generated/\n", gateExit: 1), new RunOptions(allowDirty: true));

        self::assertSame(['gate-failed', 'not-run'], $this->statuses($report));
        self::assertStringContainsString('Undo with: git checkout . && git clean -fd -- src/NewThing.php generated/ (inside the app).', implode(' ', $report->results[0]->messages));
    }

    public function test_the_undo_hint_stays_plain_when_nothing_was_created(): void
    {
        $report = $this->execute($this->fake(gateExit: 1), new RunOptions());
        $messages = implode(' ', $report->results[0]->messages);

        self::assertStringContainsString('Undo with: git checkout . (inside the app).', $messages);
        self::assertStringNotContainsString('git clean', $messages);
    }

    public function test_the_undo_hint_names_the_file_a_hop_created_from_a_clean_start(): void
    {
        $report = $this->execute($this->fake(gitStatusStart: '', gitStatus: "?? config/sesame.php\n", gateExit: 1), new RunOptions());

        self::assertSame(['gate-failed', 'not-run'], $this->statuses($report));
        self::assertStringContainsString('Undo with: git checkout . && git clean -fd -- config/sesame.php (inside the app).', implode(' ', $report->results[0]->messages));
    }

    public function test_the_undo_hint_leaves_the_users_own_untracked_files_alone(): void
    {
        // notes.txt and generated/ were the user's before the run; only
        // config/sesame.php is this run's. generated/ gained a file inside but
        // keeps its one porcelain entry, so it stays out of the clean list.
        $report = $this->execute(
            $this->fake(gitStatusStart: "?? notes.txt\n?? generated/\n", gitStatus: "?? notes.txt\n?? generated/\n?? config/sesame.php\n", gateExit: 1),
            new RunOptions(allowDirty: true),
        );

        self::assertSame(['gate-failed', 'not-run'], $this->statuses($report));
        $messages = implode(' ', $report->results[0]->messages);
        self::assertStringContainsString('Undo with: git checkout . && git clean -fd -- config/sesame.php (inside the app).', $messages);
        self::assertStringNotContainsString('notes.txt', $messages);
        self::assertStringNotContainsString('generated/', $messages);
    }

    public function test_the_undo_hint_stays_plain_when_the_run_created_nothing_new(): void
    {
        $report = $this->execute(
            $this->fake(gitStatusStart: "?? notes.txt\n", gitStatus: "?? notes.txt\n", gateExit: 1),
            new RunOptions(allowDirty: true),
        );
        $messages = implode(' ', $report->results[0]->messages);

        self::assertStringContainsString('Undo with: git checkout . (inside the app).', $messages);
        self::assertStringNotContainsString('git clean', $messages);
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

    public function test_a_repaired_utf8_note_does_not_stop_the_run(): void
    {
        $note = RectorResult::REPAIRED_UTF8_NOTE . ' (src/Bad.php)';
        $report = $this->execute($this->fake(rectorErrors: [$note]), new RunOptions(dryRun: true));

        self::assertSame(['previewed', 'previewed'], $this->statuses($report));
        self::assertFalse($report->failed());
        foreach ($report->results as $result) {
            self::assertContains($note, $result->messages);
        }
    }

    public function test_a_repaired_utf8_note_is_shown_once_on_an_applied_hop(): void
    {
        $note = RectorResult::REPAIRED_UTF8_NOTE . ' (src/Bad.php)';
        $report = $this->execute($this->fake(rectorErrors: [$note]), new RunOptions());

        self::assertSame(['applied', 'applied'], $this->statuses($report));
        foreach ($report->results as $result) {
            $notes = array_values(array_filter($result->messages, static fn(string $m): bool => str_starts_with($m, RectorResult::REPAIRED_UTF8_NOTE)));
            self::assertSame([$note], $notes);
        }
    }

    public function test_a_real_error_still_stops_the_run_next_to_a_note(): void
    {
        $note = RectorResult::REPAIRED_UTF8_NOTE . ' (src/Bad.php)';
        $report = $this->execute($this->fake(rectorErrors: ['Parse error in x.php', $note]), new RunOptions());

        self::assertSame(['error', 'not-run'], $this->statuses($report));
        self::assertStringContainsString('Parse error in x.php', implode(' ', $report->results[0]->messages));
        self::assertStringContainsString($note, implode(' ', $report->results[0]->messages));
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
        // The staged Vue hops are automated Node hops, so the manual playbook is built by hand.
        $dir = $this->copyExample('vue-spa');
        $fake = $this->fake();
        $step = new PlanStep(1, (new HopRegistry())->get(HopRegistry::VUE_2_TO_3_ID), '8.3', '8.3', null, null, null, null, [Planner::GATE_MANUAL]);
        $plan = new Plan((new ProjectDetector())->detect($dir), '2026-10-28', 'test', '8.3', null, null, [$step], []);
        $report = (new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake), $fake))
            ->execute($plan, new RunOptions(skipGate: true));

        self::assertSame([StepResult::MANUAL], $this->statuses($report));
        self::assertFalse($report->failed());
    }

    public function test_a_node_hop_pins_package_json_and_writes_nvmrc(): void
    {
        $dir = $this->copyExample('vue-spa');
        $project = (new ProjectDetector())->detect($dir);
        $plan = (new Planner($this->policy(), new HopRegistry()))->plan($project, new PlanOptions(fromPhp: '8.3', toPhp: '8.3', withNode: true), $this->today());
        $fake = $this->fake();
        $report = (new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake), $fake, new NodeRunner($fake)))
            ->execute($plan, new RunOptions(skipGate: true));

        self::assertSame(['applied', 'applied', 'applied'], $this->statuses($report));
        $target = (new HopRegistry())->nodePinTarget();
        $package = json_decode((string) file_get_contents($dir . '/package.json'), true);
        self::assertSame('>=' . $target, $package['engines']['node']);
        self::assertSame('example-vue-spa', $package['name']);
        self::assertSame($target . "\n", (string) file_get_contents($dir . '/.nvmrc'));
        self::assertStringContainsString('engines.node to >=' . $target, implode(' ', $report->results[0]->messages));
    }

    public function test_the_staged_vue_hops_apply_in_order(): void
    {
        $dir = $this->copyExample('vue-spa');
        $registry = new HopRegistry();
        $plan = (new Planner($this->policy(), $registry))->plan((new ProjectDetector())->detect($dir), new PlanOptions(fromPhp: '8.3', toPhp: '8.3', withNode: true), $this->today());
        $fake = $this->fake();
        $report = (new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake), $fake, new NodeRunner($fake)))
            ->execute($plan, new RunOptions(skipGate: true));

        self::assertSame(['applied', 'applied', 'applied'], $this->statuses($report));
        self::assertSame([HopRegistry::NODE_PIN_ID, HopRegistry::VUE_27_TO_COMPAT_ID, HopRegistry::VUE_COMPAT_CODEMOD_ID], array_map(static fn(StepResult $r): string => $r->hopId, $report->results));
        self::assertStringContainsString('manual item(s) remain', implode(' ', $report->results[2]->messages));
        $package = json_decode((string) file_get_contents($dir . '/package.json'), true);
        self::assertSame($registry->vueCompatVersion(), $package['dependencies']['vue']);
        self::assertSame($registry->vueCompatVersion(), $package['dependencies']['@vue/compat']);
        self::assertSame($registry->vueCompatVersion(), $package['devDependencies']['@vue/compiler-sfc']);
        // vue-loader is not listed in the example, so the set-if-present value must not add it.
        self::assertArrayNotHasKey('vue-loader', $package['devDependencies']);
        self::assertArrayNotHasKey('vue-template-compiler', $package['dependencies']);
        self::assertSame($registry->nodePinTarget() . "\n", (string) file_get_contents($dir . '/.nvmrc'));
    }

    public function test_a_node_hop_dry_run_changes_nothing(): void
    {
        $dir = $this->copyExample('vue-spa');
        $before = file_get_contents($dir . '/package.json');
        $project = (new ProjectDetector())->detect($dir);
        $plan = (new Planner($this->policy(), new HopRegistry()))->plan($project, new PlanOptions(fromPhp: '8.3', toPhp: '8.3', withNode: true), $this->today());
        $fake = $this->fake();
        $report = (new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake), $fake, new NodeRunner($fake)))
            ->execute($plan, new RunOptions(dryRun: true));

        self::assertSame(['previewed', 'previewed', 'previewed'], $this->statuses($report));
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
        self::assertStringContainsString('composer test >"$tests_log" 2>&1', $run[count($run) - 1]);
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

    /**
     * Like fake(), but only the Laravel hops' Rector calls report errors; the
     * PHP and PHPUnit hops come back clean. RectorRunner tells them apart with
     * BOOSTER_RECTOR_SETS (RectorLaravel constants vs Rector set lists).
     *
     * @param list<string> $laravelErrors
     */
    private function staleVendorFake(array $laravelErrors): FakeProcessRunner
    {
        return new FakeProcessRunner(static function (array $command, array $env) use ($laravelErrors): ProcessResult {
            if ($command[0] === 'git') {
                return new ProcessResult(0, '', '');
            }
            if ($command[0] === 'docker') {
                return new ProcessResult(0, '', '');
            }
            $isLaravel = str_contains($env['BOOSTER_RECTOR_SETS'] ?? '', 'RectorLaravel');
            $files = [['file' => '/tmp/x/src/F0.php', 'diff' => "--- a\n+++ b"]];
            $errors = $isLaravel ? array_map(static fn(string $m): array => ['message' => $m], $laravelErrors) : [];

            return new ProcessResult(2, (string) json_encode(['file_diffs' => $files, 'errors' => $errors]), '');
        });
    }

    public function test_a_laravel_dry_run_against_an_older_vendor_is_named_not_failed(): void
    {
        $dir = $this->copyExample('laravel-blade');
        mkdir($dir . '/vendor', 0775, true);
        file_put_contents($dir . '/vendor/autoload.php', "<?php\n");
        $project = (new ProjectDetector())->detect($dir);
        $plan = (new Planner($this->policy(), new HopRegistry()))->plan($project, new PlanOptions(fromPhp: '8.1', toPhp: '8.3'), $this->today());
        $fake = $this->staleVendorFake([
            'app/Http/Controllers/A.php: Class Illuminate\\Http\\Request was not found while trying to analyse it - discovering symbols is probably not configured properly. (line 12)',
            'app/Models/B.php: Class Illuminate\\Support\\Facades\\Request was not found while trying to analyse it - discovering symbols is probably not configured properly. (line 7)',
        ]);
        $report = (new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake), $fake))
            ->execute($plan, new RunOptions(dryRun: true));

        self::assertFalse($report->failed());
        $laravel = array_filter($report->results, static fn(StepResult $r): bool => str_starts_with($r->hopId, 'laravel-'));
        self::assertNotEmpty($laravel);
        foreach ($laravel as $r) {
            self::assertSame(StepResult::PREVIEWED, $r->status);
            self::assertTrue($r->incomplete);
            self::assertStringContainsString("Can't preview " . $r->hopId . " yet: the app's vendor/ is from an older Laravel", implode(' ', $r->messages));
        }
        foreach ($report->results as $r) {
            if (!str_starts_with($r->hopId, 'laravel-')) {
                self::assertFalse($r->incomplete, $r->hopId);
            }
        }
    }

    public function test_a_laravel_real_run_against_an_older_vendor_still_errors(): void
    {
        $dir = $this->copyExample('laravel-blade');
        mkdir($dir . '/vendor', 0775, true);
        file_put_contents($dir . '/vendor/autoload.php', "<?php\n");
        $project = (new ProjectDetector())->detect($dir);
        $plan = (new Planner($this->policy(), new HopRegistry()))->plan($project, new PlanOptions(fromPhp: '8.1', toPhp: '8.3'), $this->today());
        $fake = $this->staleVendorFake([
            'app/Http/Controllers/A.php: Class Illuminate\\Http\\Request was not found while trying to analyse it - discovering symbols is probably not configured properly. (line 12)',
        ]);
        $report = (new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake), $fake))
            ->execute($plan, new RunOptions());

        self::assertTrue($report->failed());
        $first = null;
        foreach ($report->results as $r) {
            if (str_starts_with($r->hopId, 'laravel-') && $r->status !== StepResult::NOT_RUN) {
                $first = $r;

                break;
            }
        }
        self::assertNotNull($first);
        self::assertSame(StepResult::ERROR, $first->status);
        self::assertStringContainsString('Rector reported errors; nothing was changed.', implode(' ', $first->messages));
        self::assertStringContainsString('Class Illuminate\\Http\\Request was not found', implode(' ', $first->messages));
    }

    public function test_a_laravel_dry_run_below_the_stale_vendor_share_still_errors(): void
    {
        $dir = $this->copyExample('laravel-blade');
        mkdir($dir . '/vendor', 0775, true);
        file_put_contents($dir . '/vendor/autoload.php', "<?php\n");
        $project = (new ProjectDetector())->detect($dir);
        $plan = (new Planner($this->policy(), new HopRegistry()))->plan($project, new PlanOptions(fromPhp: '8.1', toPhp: '8.3'), $this->today());
        $fake = $this->staleVendorFake([
            'app/Http/Controllers/A.php: Class Illuminate\\Http\\Request was not found while trying to analyse it - discovering symbols is probably not configured properly. (line 12)',
            'app/Http/Controllers/C.php: Syntax error, unexpected token "endif" (line 30)',
            'app/Models/D.php: Something else broke entirely (line 5)',
        ]);
        $report = (new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake), $fake))
            ->execute($plan, new RunOptions(dryRun: true));

        self::assertTrue($report->failed());
        $first = null;
        foreach ($report->results as $r) {
            if (str_starts_with($r->hopId, 'laravel-') && $r->status !== StepResult::NOT_RUN) {
                $first = $r;

                break;
            }
        }
        self::assertNotNull($first);
        self::assertSame(StepResult::ERROR, $first->status);
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

    public function test_the_prepass_rewrites_new_by_reference_before_rector_runs(): void
    {
        $dir = $this->copyExample('raw-php');
        $legacy = $dir . '/src/Legacy.php';
        $original = "<?php\n\nclass Legacy\n{\n    public function make()\n    {\n        \$a = &new Self();\n\n        return \$a;\n    }\n}\n";
        file_put_contents($legacy, $original);
        $seenDuringRector = [];
        $fake = new FakeProcessRunner(static function (array $command, array $env) use ($dir, &$seenDuringRector): ProcessResult {
            if ($command[0] === 'git') {
                return new ProcessResult(0, '', '');
            }
            if ($command[0] === 'docker') {
                return new ProcessResult(0, '', '');
            }
            $seenDuringRector[] = (string) file_get_contents($dir . '/src/Legacy.php');

            return new ProcessResult(2, (string) json_encode(['file_diffs' => [['file' => $env['BOOSTER_APP_PATH'] . '/src/A.php', 'diff' => 'd']], 'errors' => []]), '');
        });
        $project = (new ProjectDetector())->detect($dir);
        $plan = (new Planner($this->policy(), new HopRegistry()))->plan($project, new PlanOptions(fromPhp: '8.1', toPhp: '8.3'), $this->today());
        $report = (new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake), $fake))
            ->execute($plan, new RunOptions());

        self::assertSame(['applied', 'applied'], $this->statuses($report));
        self::assertNotEmpty($seenDuringRector);
        foreach ($seenDuringRector as $seen) {
            self::assertStringContainsString('$a = new Self();', $seen);
            self::assertStringNotContainsString('&new', $seen);
        }
        self::assertStringContainsString('$a = new Self();', (string) file_get_contents($legacy));
        self::assertSame([['file' => 'src/Legacy.php', 'rewrites' => 1]], $report->results[0]->prepass);
        self::assertSame([], $report->results[1]->prepass);
        self::assertSame([['file' => 'src/Legacy.php', 'rewrites' => 1]], $report->toArray()['results'][0]['prepass_fixes']);
        self::assertArrayNotHasKey('prepass_fixes', $report->toArray()['results'][1]);
    }

    public function test_the_removals_advisory_describes_the_app_before_the_hop(): void
    {
        // The simulated Rector apply deletes the legacy file, like a rule that
        // drops dead code. An advisory that still names the removed function
        // must therefore have been scanned before the rewrite touched the tree.
        $dir = $this->copyExample('raw-php');
        $removed = $dir . '/src/Removed.php';
        file_put_contents($removed, "<?php\n\n\$f = create_function('\$x', 'return \$x * 2;');\n\nreturn \$f(2);\n");
        $fake = new FakeProcessRunner(static function (array $command, array $env) use ($dir): ProcessResult {
            if ($command[0] === 'git') {
                return new ProcessResult(0, '', '');
            }
            if ($command[0] === 'docker') {
                return new ProcessResult(0, '', '');
            }
            if (!in_array('--dry-run', $command, true)) {
                unlink($dir . '/src/Removed.php');
            }

            return new ProcessResult(2, (string) json_encode(['file_diffs' => [['file' => $env['BOOSTER_APP_PATH'] . '/src/Removed.php', 'diff' => 'd']], 'errors' => []]), '');
        });
        // The planner would refuse 8.0 as a target (past security support),
        // but the hop is exactly what a PHP 7.4 app needs, so the step is
        // built by hand, like the manual-hop tests do.
        $step = new PlanStep(1, (new HopRegistry())->get('php-74-to-80'), '7.4', '8.0', null, null, null, null, [Planner::GATE_TESTS]);
        $plan = new Plan((new ProjectDetector())->detect($dir), self::TODAY, 'test', '8.0', null, null, [$step], []);
        $report = (new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake), $fake))
            ->execute($plan, new RunOptions(skipGate: true));

        self::assertSame([StepResult::APPLIED], $this->statuses($report));
        self::assertFileDoesNotExist($removed);
        $messages = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString('Removed between PHP 7.4 and PHP 8.0: 1 hit (advisory).', $messages);
        self::assertStringContainsString('src/Removed.php:3 create_function() function -> anonymous functions', $messages);
    }

    public function test_a_dry_run_restores_the_prepass_bytes_and_lists_the_fixes(): void
    {
        $dir = $this->copyExample('raw-php');
        $legacy = $dir . '/src/Legacy.php';
        $original = "<?php\n\nclass Legacy\n{\n    public function make()\n    {\n        \$a = &new Self();\n\n        return \$a;\n    }\n}\n";
        file_put_contents($legacy, $original);
        $fake = $this->fake();
        $project = (new ProjectDetector())->detect($dir);
        $plan = (new Planner($this->policy(), new HopRegistry()))->plan($project, new PlanOptions(fromPhp: '8.1', toPhp: '8.3'), $this->today());
        $report = (new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake), $fake))
            ->execute($plan, new RunOptions(dryRun: true));

        self::assertSame(['previewed', 'previewed'], $this->statuses($report));
        self::assertSame($original, (string) file_get_contents($legacy));
        self::assertSame([['file' => 'src/Legacy.php', 'rewrites' => 1]], $report->results[0]->prepass);
        $text = (new TextWriter())->run($report);
        self::assertStringContainsString('Pre-pass fixes:', $text);
        self::assertStringContainsString('src/Legacy.php: 1 rewrite', $text);
    }

    public function test_prepass_changes_count_toward_the_cap(): void
    {
        $dir = $this->copyExample('raw-php');
        $legacy = $dir . '/src/Legacy.php';
        $original = "<?php\n\nclass Legacy\n{\n    public function make()\n    {\n        \$a = &new Self();\n\n        return \$a;\n    }\n}\n";
        file_put_contents($legacy, $original);
        $fake = $this->fake(changedPerHop: 1);
        $project = (new ProjectDetector())->detect($dir);
        $plan = (new Planner($this->policy(), new HopRegistry()))->plan($project, new PlanOptions(fromPhp: '8.1', toPhp: '8.3'), $this->today());
        $report = (new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake), $fake))
            ->execute($plan, new RunOptions(maxChanges: 1));

        self::assertSame(['capped', 'not-run'], $this->statuses($report));
        self::assertStringContainsString('This hop wants to change 2 files and --max-changes is 1. Nothing was changed.', implode(' ', $report->results[0]->messages));
        self::assertSame($original, (string) file_get_contents($legacy));
    }

    public function test_parse_errors_become_named_refusals(): void
    {
        $fake = new FakeProcessRunner(static function (array $command): ProcessResult {
            if ($command[0] === 'git') {
                return new ProcessResult(0, '', '');
            }
            if ($command[0] === 'docker') {
                return new ProcessResult(0, '', '');
            }

            return new ProcessResult(2, (string) json_encode(['file_diffs' => [], 'errors' => [
                ['file' => 'src/Broken.php', 'message' => "Syntax error, unexpected ';'"],
                ['file' => 'src/Numeric.php', 'message' => 'Invalid numeric literal'],
            ]]), '');
        });
        $report = $this->execute($fake, new RunOptions());

        self::assertSame(['error', 'not-run'], $this->statuses($report));
        $messages = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString("src/Broken.php: \"Syntax error, unexpected ';'\" cannot be repaired by tooling, so BOOSTER stops here and the file needs a person.", $messages);
        self::assertStringContainsString('src/Numeric.php: "Invalid numeric literal" cannot be repaired by tooling, so BOOSTER stops here and the file needs a person.', $messages);
        self::assertStringNotContainsString('src/Broken.php: Syntax error', $messages);
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

    public function test_the_proctor_gate_passes_after_the_last_hop(): void
    {
        $fake = $this->fake(proctorExit: 0);
        $report = $this->executeWithProctor($fake, new RunOptions());

        self::assertSame(['applied', 'applied'], $this->statuses($report));
        self::assertFalse($report->failed());
        self::assertNotNull($report->proctor);
        self::assertSame(ProctorResult::PASSED, $report->proctor->status);
        self::assertSame('8.3', $report->proctor->php);
        // The baseline runs on the starting PHP before the first hop; the final gate on the target.
        self::assertCount(2, $this->proctorRuns($fake));
        [$baselineCommand, $finalCommand] = $this->proctorRuns($fake);
        self::assertContains('--format=json', $baselineCommand);
        self::assertContains('--today=2026-10-28', $baselineCommand);
        self::assertContains('--php-runtime=8.1.0', $baselineCommand);
        self::assertContains('--policy=' . self::POLICY_FIXTURE, $baselineCommand);
        self::assertContains('--php-runtime=8.3.0', $finalCommand);
    }

    public function test_preexisting_findings_do_not_fail_the_run(): void
    {
        $fake = $this->fake(proctorExit: 1);
        $report = $this->executeWithProctor($fake, new RunOptions());

        self::assertSame(['applied', 'applied'], $this->statuses($report));
        self::assertFalse($report->failed());
        self::assertNotNull($report->proctor);
        self::assertSame(ProctorResult::FAILED, $report->proctor->status);
        self::assertCount(2, $report->proctor->failures);
        self::assertSame('php-floor', $report->proctor->failures[0]['check']);
        self::assertNotNull($report->proctorComparison);
        self::assertTrue($report->proctorComparison->baselineTaken);
        self::assertSame([], $report->proctorComparison->new);
        self::assertCount(2, $report->proctorComparison->alreadyPresent);
        self::assertFalse($report->proctorComparison->blocking);
    }

    public function test_new_findings_after_the_hops_fail_the_run(): void
    {
        $fake = $this->fake(proctorBaselineExit: 0, proctorExit: 1);
        $report = $this->executeWithProctor($fake, new RunOptions());

        self::assertTrue($report->failed());
        self::assertNotNull($report->proctor);
        self::assertSame(ProctorResult::FAILED, $report->proctor->status);
        self::assertNotNull($report->proctorComparison);
        self::assertTrue($report->proctorComparison->baselineTaken);
        self::assertCount(2, $report->proctorComparison->new);
        self::assertSame([], $report->proctorComparison->alreadyPresent);
        self::assertTrue($report->proctorComparison->blocking);
    }

    public function test_findings_fixed_by_the_upgrade_are_reported_as_fixed(): void
    {
        $fake = $this->fake(proctorBaselineExit: 1, proctorExit: 0);
        $report = $this->executeWithProctor($fake, new RunOptions());

        self::assertFalse($report->failed());
        self::assertNotNull($report->proctor);
        self::assertSame(ProctorResult::PASSED, $report->proctor->status);
        self::assertNotNull($report->proctorComparison);
        self::assertSame([], $report->proctorComparison->new);
        self::assertCount(2, $report->proctorComparison->fixed);
        self::assertTrue($report->proctorComparison->phpFloorFixed());
    }

    public function test_a_tripped_baseline_falls_back_to_blocking_on_any_fail(): void
    {
        $fake = $this->fake(proctorBaselineExit: 2, proctorExit: 1);
        $report = $this->executeWithProctor($fake, new RunOptions());

        self::assertTrue($report->failed());
        self::assertNotNull($report->proctorComparison);
        self::assertFalse($report->proctorComparison->baselineTaken);
        self::assertStringContainsString('could not read composer.json', (string) $report->proctorComparison->baselineError);
        self::assertCount(2, $report->proctorComparison->new);
        self::assertTrue($report->proctorComparison->blocking);
    }

    public function test_a_tripped_baseline_with_a_clean_final_run_does_not_block(): void
    {
        $fake = $this->fake(proctorBaselineExit: 2, proctorExit: 0);
        $report = $this->executeWithProctor($fake, new RunOptions());

        self::assertFalse($report->failed());
        self::assertNotNull($report->proctorComparison);
        self::assertFalse($report->proctorComparison->baselineTaken);
        self::assertSame([], $report->proctorComparison->new);
        self::assertFalse($report->proctorComparison->blocking);
    }

    public function test_advisory_proctor_does_not_fail_the_run(): void
    {
        $fake = $this->fake(proctorBaselineExit: 0, proctorExit: 1);
        $report = $this->executeWithProctor($fake, new RunOptions(proctorAdvisory: true));

        self::assertFalse($report->failed());
        self::assertNotNull($report->proctor);
        self::assertSame(ProctorResult::FAILED, $report->proctor->status);
        self::assertTrue($report->proctorAdvisory);
        // The findings are new against the baseline, so advisory is what keeps the run green.
        self::assertCount(2, $report->proctorComparison?->new ?? []);
        self::assertTrue($report->proctorComparison?->blocking ?? false);
    }

    public function test_a_tripped_proctor_gate_fails_the_run(): void
    {
        $fake = $this->fake(proctorExit: 2);
        $report = $this->executeWithProctor($fake, new RunOptions());

        self::assertTrue($report->failed());
        self::assertNotNull($report->proctor);
        self::assertSame(ProctorResult::TRIPPED, $report->proctor->status);
        self::assertStringContainsString('could not read composer.json', (string) $report->proctor->error);
    }

    public function test_advisory_proctor_covers_a_tripped_gate_too(): void
    {
        $fake = $this->fake(proctorExit: 2);
        $report = $this->executeWithProctor($fake, new RunOptions(proctorAdvisory: true));

        self::assertFalse($report->failed());
        self::assertNotNull($report->proctor);
        self::assertSame(ProctorResult::TRIPPED, $report->proctor->status);
    }

    public function test_the_proctor_gate_is_skipped_for_dry_runs_and_skip_gate(): void
    {
        $dry = $this->fake(proctorExit: 0);
        $this->executeWithProctor($dry, new RunOptions(dryRun: true));
        self::assertSame([], $this->proctorRuns($dry));

        $skipped = $this->fake(proctorExit: 0);
        $report = $this->executeWithProctor($skipped, new RunOptions(skipGate: true));
        self::assertSame([], $this->proctorRuns($skipped));
        self::assertNull($report->proctor);
    }

    public function test_the_proctor_gate_is_skipped_when_a_step_failed(): void
    {
        $fake = $this->fake(gateExit: 1, proctorExit: 0);
        $report = $this->executeWithProctor($fake, new RunOptions());

        self::assertSame(['gate-failed', 'not-run'], $this->statuses($report));
        self::assertTrue($report->failed());
        // Only the baseline ran; the final gate is skipped when a step failed.
        self::assertCount(1, $this->proctorRuns($fake));
        self::assertContains('--php-runtime=8.1.0', $this->proctorRuns($fake)[0]);
        self::assertNull($report->proctor);
        self::assertNull($report->proctorComparison);
    }

    public function test_the_report_array_carries_the_proctor_section(): void
    {
        $array = $this->executeWithProctor($this->fake(proctorExit: 1), new RunOptions())->toArray();

        self::assertArrayHasKey('proctor', $array);
        self::assertSame('failed', $array['proctor']['status']);
        self::assertSame(1, $array['proctor']['exit_code']);
        self::assertSame('8.3', $array['proctor']['php']);
        self::assertCount(2, $array['proctor']['failures']);
        self::assertFalse($array['proctor']['blocking']);
        self::assertSame([], $array['proctor']['new']);
        self::assertCount(2, $array['proctor']['already_present']);
        self::assertSame([], $array['proctor']['fixed']);
        self::assertTrue($array['proctor']['baseline']['taken']);
        self::assertNull($array['proctor']['baseline']['error']);
        self::assertFalse($array['proctor_advisory']);
    }

    public function test_the_text_report_lists_new_findings_under_new(): void
    {
        $report = $this->executeWithProctor($this->fake(proctorBaselineExit: 0, proctorExit: 1), new RunOptions());
        $text = (new TextWriter())->run($report);

        self::assertStringContainsString('PROCTOR: FAIL on PHP 8.3 (2 new, 0 already present).', $text);
        self::assertStringContainsString('New:', $text);
        self::assertStringContainsString('[php-floor]', $text);
        self::assertStringNotContainsString('Already present (not blocking):', $text);
        self::assertStringContainsString('Fix the findings above, then run the upgrade again.', $text);
        self::assertStringContainsString('RESULT: FAIL', $text);
    }

    public function test_the_text_report_lists_preexisting_findings_as_not_blocking(): void
    {
        $report = $this->executeWithProctor($this->fake(proctorExit: 1), new RunOptions());
        $text = (new TextWriter())->run($report);

        self::assertStringContainsString('PROCTOR: FAIL on PHP 8.3 (0 new, 2 already present).', $text);
        self::assertStringContainsString('Already present (not blocking):', $text);
        self::assertStringNotContainsString('New:', $text);
        self::assertStringNotContainsString('Fix the findings above', $text);
        self::assertStringContainsString('RESULT: PASS', $text);
    }

    public function test_the_text_report_says_php_floor_was_fixed_by_the_upgrade(): void
    {
        $report = $this->executeWithProctor($this->fake(proctorBaselineExit: 1, proctorExit: 0), new RunOptions());
        $text = (new TextWriter())->run($report);

        self::assertStringContainsString('PROCTOR: PASS on PHP 8.3 (the upgraded app meets the policy).', $text);
        self::assertStringContainsString('Fixed by the upgrade:', $text);
        self::assertStringContainsString('php-floor: fixed by the upgrade.', $text);
        self::assertStringContainsString('RESULT: PASS', $text);
    }

    public function test_the_text_report_says_when_the_baseline_could_not_be_taken(): void
    {
        $report = $this->executeWithProctor($this->fake(proctorBaselineExit: 2, proctorExit: 1), new RunOptions());
        $text = (new TextWriter())->run($report);

        self::assertStringContainsString('PROCTOR baseline could not be taken: the baseline run tripped: PROCTOR could not read composer.json.', $text);
        self::assertStringContainsString('New:', $text);
        self::assertStringContainsString('RESULT: FAIL', $text);
    }

    /**
     * Copies laravel-blade and gives it Laravel's two stock example tests. With
     * $keepRealTest the app's own PostTest.php stays beside them.
     */
    private function stockExample(bool $keepRealTest = false): string
    {
        $dir = $this->copyExample('laravel-blade');
        if (!$keepRealTest) {
            unlink($dir . '/tests/PostTest.php');
        }
        $feature = $dir . '/tests/Feature';
        $unit = $dir . '/tests/Unit';
        if (!is_dir($feature)) {
            mkdir($feature, 0775, true);
        }
        if (!is_dir($unit)) {
            mkdir($unit, 0775, true);
        }
        file_put_contents(
            $feature . '/ExampleTest.php',
            <<<'PHP'
<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
PHP,
        );
        file_put_contents(
            $unit . '/ExampleTest.php',
            <<<'PHP'
<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ExampleTest extends TestCase
{
    public function test_that_true_is_true(): void
    {
        $this->assertTrue(true);
    }
}
PHP,
        );

        return $dir;
    }

    /** Like execute(), but for an app directory that is already prepared. */
    private function executePrepared(string $dir, RunOptions $options): RunReport
    {
        $project = (new ProjectDetector())->detect($dir);
        $plan = (new Planner($this->policy(), new HopRegistry()))->plan($project, new PlanOptions(fromPhp: '8.1', toPhp: '8.3'), $this->today());
        $fake = $this->fake();
        $executor = new HopExecutor(
            new RectorRunner($fake, '/fake/rector'),
            new ComposerEditor(),
            new TargetImageRunner($fake),
            $fake,
        );

        return $executor->execute($plan, $options);
    }

    public function test_a_suite_of_only_stock_examples_is_named_next_to_the_baseline(): void
    {
        $report = $this->executePrepared($this->stockExample(), new RunOptions());

        self::assertSame("The app's tests are only Laravel's stock examples, so the gate cannot prove anything. See \"A smoke test for WSU apps\" in the README.", $report->baseline->warning);
        $text = (new TextWriter())->run($report);
        self::assertStringContainsString("The app's tests are only Laravel's stock examples", $text);
    }

    public function test_the_stock_test_warning_shows_on_a_dry_run_too(): void
    {
        $report = $this->executePrepared($this->stockExample(), new RunOptions(dryRun: true));

        self::assertSame(BaselineResult::SKIPPED, $report->baseline->status);
        self::assertNotNull($report->baseline->warning);
    }

    public function test_a_real_test_beside_the_stock_examples_silences_the_warning(): void
    {
        $report = $this->executePrepared($this->stockExample(true), new RunOptions());

        self::assertNull($report->baseline->warning);
    }

    public function test_a_laravel_hop_removes_the_imports_its_changes_left_unused(): void
    {
        $dir = $this->copyExample('laravel-blade');
        $controller = $dir . '/app/Http/Controllers/ThingController.php';
        if (!is_dir(dirname($controller))) {
            mkdir(dirname($controller), 0775, true);
        }
        // The post-Rector state of the file: Gone was renamed away, leaving its import dead.
        file_put_contents($controller, implode("\n", [
            '<?php',
            '',
            'namespace App\Http\Controllers;',
            '',
            'use App\Models\Gone;',
            'use App\Models\Post;',
            'use Illuminate\Routing\Controller;',
            '',
            'class ThingController extends Controller',
            '{',
            '    public function index(): Post',
            '    {',
            '        return Post::first();',
            '    }',
            '}',
            '',
        ]));

        $fake = new FakeProcessRunner(static function (array $command, array $env, ?string $cwd) use ($dir): ProcessResult {
            if ($command[0] === 'git') {
                return new ProcessResult(0, '', '');
            }
            if ($command[0] === 'docker') {
                return new ProcessResult(0, '', '');
            }

            return new ProcessResult(2, (string) json_encode(['file_diffs' => [['file' => $dir . '/app/Http/Controllers/ThingController.php', 'diff' => "--- a\n+++ b"]], 'errors' => []]), '');
        });
        $project = (new ProjectDetector())->detect($dir);
        $plan = (new Planner($this->policy(), new HopRegistry()))->plan($project, new PlanOptions(fromPhp: '8.1', toPhp: '8.3'), $this->today());
        $executor = new HopExecutor(
            new RectorRunner($fake, '/fake/rector'),
            new ComposerEditor(),
            new TargetImageRunner($fake),
            $fake,
        );
        $report = $executor->execute($plan, new RunOptions());

        $content = (string) file_get_contents($controller);
        self::assertStringNotContainsString('use App\Models\Gone;', $content);
        self::assertStringContainsString('use App\Models\Post;', $content);
        self::assertStringContainsString('use Illuminate\Routing\Controller;', $content);

        $laravelSteps = array_values(array_filter($report->results, static fn(StepResult $r): bool => $r->hopId === 'laravel-10-to-11'));
        self::assertCount(1, $laravelSteps);
        self::assertContains('Removed 1 unused import from 1 file after the Rector pass.', $laravelSteps[0]->messages);
    }

    /**
     * Plans the laravel-blade example (Laravel 10 to 13, PHP 8.2 to 8.3) and
     * skips every step but the final Laravel hop, so a run exercises exactly
     * one gate. The intermediate hops are skipped by id read from the full
     * plan, so the test does not hard-code hop id spellings.
     */
    private function laravelPlan(string $dir): array
    {
        $project = (new ProjectDetector())->detect($dir);
        $planner = new Planner($this->policy(), new HopRegistry());
        $options = new PlanOptions(fromPhp: '8.2', toPhp: '8.3', fromLaravel: '10', toLaravel: '13');
        $full = $planner->plan($project, $options, $this->today());
        $laravelIds = array_values(array_map(
            static fn(PlanStep $s): string => $s->hop->id,
            array_filter($full->steps, static fn(PlanStep $s): bool => $s->hop->kind === HopKind::Laravel),
        ));
        self::assertSame(['laravel-10-to-11', 'laravel-11-to-12', 'laravel-12-to-13'], $laravelIds);
        $skip = array_values(array_diff(array_map(static fn(PlanStep $s): string => $s->hop->id, $full->steps), [end($laravelIds)]));
        $plan = $planner->plan($project, new PlanOptions(fromPhp: '8.2', toPhp: '8.3', fromLaravel: '10', toLaravel: '13', skip: $skip), $this->today());

        return [$dir, $plan];
    }

    /**
     * Plans the example as a Laravel 8 app (the test rewrites its composer.json
     * to ^8.0) and skips every step but the first Laravel hop, so a run
     * exercises exactly one gate whose constraint is ^9.0: the same constraint
     * Packagist refused in the live evidence behind the dev-fallback tests.
     */
    private function laravelEightPlan(string $dir): array
    {
        $project = (new ProjectDetector())->detect($dir);
        self::assertSame('8', $project->laravelMajor);
        $planner = new Planner($this->policy(), new HopRegistry());
        $options = new PlanOptions(fromPhp: '8.0', toPhp: '8.5', fromLaravel: '8', toLaravel: '13');
        $full = $planner->plan($project, $options, $this->today());
        self::assertSame('laravel-8-to-9', $full->steps[0]->hop->id);
        $skip = array_values(array_diff(array_map(static fn(PlanStep $s): string => $s->hop->id, $full->steps), ['laravel-8-to-9']));
        $plan = $planner->plan($project, new PlanOptions(fromPhp: '8.0', toPhp: '8.5', fromLaravel: '8', toLaravel: '13', skip: $skip), $this->today());

        return [$dir, $plan];
    }

    /**
     * Like laravelPlan(), but the run continues one PHP hop past the final
     * Laravel hop (PHP 8.2 to 8.4), so the Laravel gate is not the plan's last
     * step: an unblock it adds is a warning, and the final-target failure
     * lands on the tail step instead.
     */
    private function laravelPlanWithTail(string $dir): array
    {
        $project = (new ProjectDetector())->detect($dir);
        $planner = new Planner($this->policy(), new HopRegistry());
        $options = new PlanOptions(fromPhp: '8.2', toPhp: '8.4', fromLaravel: '10', toLaravel: '13');
        $full = $planner->plan($project, $options, $this->today());
        $ids = array_map(static fn(PlanStep $s): string => $s->hop->id, $full->steps);
        $at = array_search('laravel-12-to-13', $ids, true);
        self::assertIsInt($at);
        self::assertSame('php-83-to-84', $ids[$at + 1]);
        $skip = array_values(array_diff($ids, ['laravel-12-to-13', 'php-83-to-84']));
        $plan = $planner->plan($project, new PlanOptions(fromPhp: '8.2', toPhp: '8.4', fromLaravel: '10', toLaravel: '13', skip: $skip), $this->today());
        self::assertSame(['laravel-12-to-13', 'php-83-to-84'], array_map(static fn(PlanStep $s): string => $s->hop->id, $plan->steps));

        return [$dir, $plan];
    }

    public function test_a_laravel_hop_updates_the_lock_in_the_gate(): void
    {
        [$dir, $plan] = $this->laravelPlan($this->copyExample('laravel-blade'));
        self::assertSame(['laravel-12-to-13'], array_map(static fn(PlanStep $s): string => $s->hop->id, $plan->steps));

        $dockerRuns = 0;
        $fake = new FakeProcessRunner(static function (array $command) use ($dir, &$dockerRuns): ProcessResult {
            if ($command[0] === 'git') {
                return new ProcessResult(0, '', '');
            }
            if ($command[0] === 'docker' && ($command[1] ?? '') === 'run') {
                $script = $command[count($command) - 1];
                $dockerRuns++;
                if (str_contains($script, 'composer update -W')) {
                    // Simulate the container writing the refreshed lock through the mount.
                    file_put_contents($dir . '/composer.lock', json_encode(['packages' => ['laravel/framework' => ['version' => 'v13.39.0']]]));
                }

                return new ProcessResult(0, '', '');
            }
            if ($command[0] === 'docker') {
                return new ProcessResult(0, '', '');
            }

            return new ProcessResult(2, (string) json_encode(['file_diffs' => [['file' => $dir . '/app/Models/Post.php', 'diff' => 'd']], 'errors' => []]), '');
        });
        $executor = new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake), $fake);
        $report = $executor->execute($plan, new RunOptions());

        self::assertSame([StepResult::APPLIED], $this->statuses($report));
        $runs = $this->dockerRuns($fake);
        self::assertCount(2, $runs);
        $scripts = array_map(static fn(array $run): string => $run['command'][count($run['command']) - 1], $runs);
        self::assertStringContainsString('composer install --no-interaction --no-progress --prefer-dist', $scripts[0]);
        self::assertStringContainsString('composer update -W --no-interaction --no-progress laravel/framework', $scripts[1]);
        self::assertStringNotContainsString('composer install', $scripts[1]);
        // The refreshed lock lands in the app itself.
        self::assertFileExists($dir . '/composer.lock');
        $lock = json_decode((string) file_get_contents($dir . '/composer.lock'), true);
        self::assertSame('v13.39.0', $lock['packages']['laravel/framework']['version']);
        self::assertStringContainsString('The gate refreshed composer.lock (composer update -W laravel/framework).', implode(' ', $report->results[0]->messages));
    }

    public function test_a_laravel_dry_run_says_what_it_would_update_and_runs_nothing(): void
    {
        [$dir, $plan] = $this->laravelPlan($this->copyExample('laravel-blade'));
        $fake = $this->fake();
        $executor = new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake), $fake);
        $report = $executor->execute($plan, new RunOptions(dryRun: true));

        self::assertSame([], $this->dockerRuns($fake));
        self::assertSame(StepResult::PREVIEWED, $report->results[0]->status);
        self::assertStringContainsString('would run: composer update -W laravel/framework', implode(' ', $report->results[0]->messages));
        self::assertFileDoesNotExist($dir . '/composer.lock');
    }

    public function test_a_failed_lock_update_fails_the_step_in_the_composer_phase(): void
    {
        [$dir, $plan] = $this->laravelPlan($this->copyExample('laravel-blade'));

        $dockerRuns = 0;
        $fake = new FakeProcessRunner(static function (array $command) use (&$dockerRuns): ProcessResult {
            if ($command[0] === 'git') {
                return new ProcessResult(0, '', '');
            }
            if ($command[0] === 'docker' && ($command[1] ?? '') === 'run') {
                $dockerRuns++;
                if ($dockerRuns === 1) {
                    return new ProcessResult(0, '', '');
                }

                return new ProcessResult(4, "Problem 1 of 1\n- The lock file is not up to date.\nBOOSTER_GATE_PHASE=install\nBOOSTER_GATE_EXIT=4", '');
            }
            if ($command[0] === 'docker') {
                return new ProcessResult(0, '', '');
            }

            return new ProcessResult(2, (string) json_encode(['file_diffs' => [['file' => '/srv/app/Post.php', 'diff' => 'd']], 'errors' => []]), '');
        });
        $executor = new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake), $fake);
        $report = $executor->execute($plan, new RunOptions());

        self::assertSame([StepResult::GATE_FAILED], $this->statuses($report));
        $all = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString('Gate failed: composer update -W laravel/framework failed (exit 4)', $all);
        self::assertStringContainsString('This step changed composer.json and composer.lock.', $all);
        self::assertStringContainsString('Undo with:', $all);
        self::assertStringNotContainsString('"vendor/bin/phpunit" exited', $all);
        // A stale lock is not an advisory block: no retry, no unblock written.
        self::assertSame(2, $dockerRuns);
        $composer = json_decode((string) file_get_contents($dir . '/composer.json'), true);
        self::assertArrayNotHasKey('policy', $composer['config'] ?? []);
    }

    public function test_a_laravel_hop_blocked_by_advisories_unblocks_retries_and_fails_the_final_target(): void
    {
        [$dir, $plan] = $this->laravelPlan($this->copyExample('laravel-blade'));

        $dockerRuns = 0;
        $fake = new FakeProcessRunner(static function (array $command) use ($dir, &$dockerRuns): ProcessResult {
            if ($command[0] === 'git') {
                return new ProcessResult(0, '', '');
            }
            if ($command[0] === 'docker' && ($command[1] ?? '') === 'run') {
                $script = $command[count($command) - 1];
                $dockerRuns++;
                if ($dockerRuns === 2 && str_contains($script, 'composer update -W')) {
                    // Composer 2.10's policy blocking refuses the tagged releases.
                    return new ProcessResult(4, "Composer could not find a solution for your request.\n- Root composer.json requires laravel/framework ^13.0, found laravel/framework[v13.0.0, v13.1.0, v13.2.0] but these were not loaded, because they are affected by security advisories (\"PKSA-2026-0001\", \"PKSA-2026-0002\"). Go to https://packagist.org/security-advisories/ to find advisory details.\nBOOSTER_GATE_PHASE=install\nBOOSTER_GATE_EXIT=4", '');
                }
                if (str_contains($script, 'composer update -W')) {
                    // The retry, with the unblock in place, refreshes the lock.
                    file_put_contents($dir . '/composer.lock', json_encode(['packages' => ['laravel/framework' => ['version' => 'v13.39.0']]]));
                }

                return new ProcessResult(0, '', '');
            }
            if ($command[0] === 'docker') {
                return new ProcessResult(0, '', '');
            }

            return new ProcessResult(2, (string) json_encode(['file_diffs' => [['file' => $dir . '/app/Models/Post.php', 'diff' => 'd']], 'errors' => []]), '');
        });
        $executor = new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake), $fake);
        $report = $executor->execute($plan, new RunOptions());

        // The gate itself passes after the retry, but this hop is the plan's
        // last step, so the final target fails while the unblock remains.
        self::assertSame([StepResult::GATE_FAILED], $this->statuses($report));
        $runs = $this->dockerRuns($fake);
        self::assertCount(3, $runs);
        $scripts = array_map(static fn(array $run): string => $run['command'][count($run['command']) - 1], $runs);
        self::assertStringContainsString('composer install --no-interaction --no-progress --prefer-dist', $scripts[0]);
        self::assertStringContainsString('composer update -W --no-interaction --no-progress laravel/framework', $scripts[1]);
        self::assertStringContainsString('composer update -W --no-interaction --no-progress laravel/framework', $scripts[2]);
        // The temporary unblock is scoped to the package and the refused constraint.
        $composer = json_decode((string) file_get_contents($dir . '/composer.json'), true);
        $ignore = $composer['config']['policy']['advisories']['ignore']['laravel/framework'];
        self::assertIsArray($ignore);
        $rule = $ignore[0];
        self::assertSame('^13.0', $rule['constraint']);
        self::assertTrue($rule['on-block']);
        self::assertFalse($rule['on-audit']);
        self::assertStringContainsString('Booster temporary unblock', $rule['reason']);
        self::assertStringContainsString('PKSA-2026-0001', $rule['reason']);
        self::assertStringContainsString('PKSA-2026-0002', $rule['reason']);
        $all = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString('Composer refused tagged laravel/framework ^13.0 for known security advisories; Booster added a temporary, scoped unblock (config.policy.advisories.ignore). Remove it before release; Booster fails the final target while it is there.', $all);
        self::assertStringContainsString('Added a temporary advisory unblock to composer.json', $all);
        self::assertStringContainsString("composer.json still has Booster's temporary advisory unblock for laravel/framework (config.policy.advisories.ignore). Remove it before release; Booster fails the final target while it is there.", $all);
        self::assertStringContainsString('Gate passed: tests are green on PHP 8.3.', $all);
        self::assertFileExists($dir . '/composer.lock');
    }

    public function test_an_advisory_unblock_that_still_fails_reports_the_failure(): void
    {
        [$dir, $plan] = $this->laravelPlan($this->copyExample('laravel-blade'));

        $dockerRuns = 0;
        $fake = new FakeProcessRunner(static function (array $command) use (&$dockerRuns): ProcessResult {
            if ($command[0] === 'git') {
                return new ProcessResult(0, '', '');
            }
            if ($command[0] === 'docker' && ($command[1] ?? '') === 'run') {
                $script = $command[count($command) - 1];
                $dockerRuns++;
                if ($dockerRuns === 2 && str_contains($script, 'composer update -W')) {
                    return new ProcessResult(4, "- Root composer.json requires laravel/framework ^13.0, found laravel/framework[v13.0.0] but these were not loaded, because they are affected by security advisories (\"PKSA-2026-0001\").\nBOOSTER_GATE_PHASE=install\nBOOSTER_GATE_EXIT=4", '');
                }
                if ($dockerRuns === 3) {
                    // The unblock did not help: the same failure comes back.
                    return new ProcessResult(4, "Composer could not find a solution for your request.\n- Nothing matches your requirements.\nBOOSTER_GATE_PHASE=install\nBOOSTER_GATE_EXIT=4", '');
                }

                return new ProcessResult(0, '', '');
            }
            if ($command[0] === 'docker') {
                return new ProcessResult(0, '', '');
            }

            return new ProcessResult(2, (string) json_encode(['file_diffs' => [['file' => '/srv/app/Post.php', 'diff' => 'd']], 'errors' => []]), '');
        });
        $executor = new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake), $fake);
        $report = $executor->execute($plan, new RunOptions());

        self::assertSame([StepResult::GATE_FAILED], $this->statuses($report));
        // Baseline, the blocked gate, and exactly one retry: never a loop.
        self::assertSame(3, $dockerRuns);
        $all = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString('Composer refused tagged laravel/framework ^13.0 for known security advisories; Booster added a temporary, scoped unblock (config.policy.advisories.ignore). Remove it before release; Booster fails the final target while it is there.', $all);
        self::assertStringContainsString('Added a temporary advisory unblock to composer.json', $all);
        self::assertStringContainsString('Gate failed: composer update -W laravel/framework failed (exit 4)', $all);
        self::assertStringContainsString('Nothing matches your requirements.', $all);
        self::assertStringContainsString('This step changed composer.json and composer.lock.', $all);
        // The gate failed, so the final-target finding never gets a turn.
        self::assertStringNotContainsString("composer.json still has Booster's temporary advisory unblock", $all);
        // The unblock stays in composer.json for PROCTOR to report.
        $composer = json_decode((string) file_get_contents($dir . '/composer.json'), true);
        self::assertArrayHasKey('laravel/framework', $composer['config']['policy']['advisories']['ignore']);
    }

    public function test_a_laravel_hop_that_locks_a_dev_branch_unblocks_retries_and_fails_the_final_target(): void
    {
        $dir = $this->copyExample('laravel-blade');
        file_put_contents($dir . '/composer.json', (string) json_encode([
            'name' => 'example/laravel-blade',
            'require' => ['php' => '^8.0', 'laravel/framework' => '^8.0'],
            'require-dev' => ['phpunit/phpunit' => '^9.0'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        [$dir, $plan] = $this->laravelEightPlan($dir);

        $dockerRuns = 0;
        $fake = new FakeProcessRunner(static function (array $command) use ($dir, &$dockerRuns): ProcessResult {
            if ($command[0] === 'git') {
                return new ProcessResult(0, '', '');
            }
            if ($command[0] === 'docker' && ($command[1] ?? '') === 'run') {
                $script = $command[count($command) - 1];
                $dockerRuns++;
                if ($dockerRuns === 2 && str_contains($script, 'composer update -W')) {
                    // Live evidence, 2026-10-30: with dev stability allowed, Composer locked
                    // the branch instead of refusing, and the install phase still passed.
                    file_put_contents($dir . '/composer.lock', (string) json_encode(['packages' => [[
                        'name' => 'laravel/framework',
                        'version' => '9.x-dev',
                        'source' => ['reference' => '06605f514a3aba20bdab972cb49a2a3cf0f21532'],
                    ]]]));
                }
                if ($dockerRuns === 3 && str_contains($script, 'composer update -W')) {
                    // With the unblock in place, the tagged release loads.
                    file_put_contents($dir . '/composer.lock', (string) json_encode(['packages' => [['name' => 'laravel/framework', 'version' => 'v9.52.16']]]));
                }

                return new ProcessResult(0, '', '');
            }
            if ($command[0] === 'docker') {
                return new ProcessResult(0, '', '');
            }

            return new ProcessResult(2, (string) json_encode(['file_diffs' => [['file' => $dir . '/app/Models/Post.php', 'diff' => 'd']], 'errors' => []]), '');
        });
        $executor = new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake), $fake);
        $report = $executor->execute($plan, new RunOptions());

        // The gate itself passes after the retry, but this hop is the plan's
        // last step, so the final target fails while the unblock remains.
        self::assertSame([StepResult::GATE_FAILED], $this->statuses($report));
        $runs = $this->dockerRuns($fake);
        self::assertCount(3, $runs);
        $scripts = array_map(static fn(array $run): string => $run['command'][count($run['command']) - 1], $runs);
        self::assertStringContainsString('composer install --no-interaction --no-progress --prefer-dist', $scripts[0]);
        self::assertStringContainsString('composer update -W --no-interaction --no-progress laravel/framework', $scripts[1]);
        self::assertStringContainsString('composer update -W --no-interaction --no-progress laravel/framework', $scripts[2]);
        // The temporary unblock is scoped to the package and the hop's constraint.
        $composer = json_decode((string) file_get_contents($dir . '/composer.json'), true);
        $ignore = $composer['config']['policy']['advisories']['ignore']['laravel/framework'];
        self::assertIsArray($ignore);
        $rule = $ignore[0];
        self::assertSame('^9.0', $rule['constraint']);
        self::assertTrue($rule['on-block']);
        self::assertFalse($rule['on-audit']);
        self::assertStringContainsString('Booster temporary unblock', $rule['reason']);
        self::assertStringContainsString('branch version', $rule['reason']);
        $all = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString('Composer locked laravel/framework to 9.x-dev although the hop asks for ^9.0: the tagged releases were refused for known security advisories; Booster added a temporary, scoped unblock (config.policy.advisories.ignore). Remove it before release; Booster fails the final target while it is there.', $all);
        self::assertStringContainsString('Added a temporary advisory unblock to composer.json', $all);
        self::assertStringContainsString('laravel/framework now resolves to the tagged release v9.52.16.', $all);
        self::assertStringContainsString("composer.json still has Booster's temporary advisory unblock for laravel/framework (config.policy.advisories.ignore). Remove it before release; Booster fails the final target while it is there.", $all);
        self::assertStringContainsString('Gate passed: tests are green on PHP 8.0.', $all);
        $lock = json_decode((string) file_get_contents($dir . '/composer.lock'), true);
        self::assertSame('v9.52.16', $lock['packages'][0]['version']);
    }

    public function test_a_laravel_hop_whose_dev_fallback_survives_the_unblock_fails_the_install_phase(): void
    {
        $dir = $this->copyExample('laravel-blade');
        file_put_contents($dir . '/composer.json', (string) json_encode([
            'name' => 'example/laravel-blade',
            'require' => ['php' => '^8.0', 'laravel/framework' => '^8.0'],
            'require-dev' => ['phpunit/phpunit' => '^9.0'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        [$dir, $plan] = $this->laravelEightPlan($dir);

        $dockerRuns = 0;
        $fake = new FakeProcessRunner(static function (array $command) use ($dir, &$dockerRuns): ProcessResult {
            if ($command[0] === 'git') {
                return new ProcessResult(0, '', '');
            }
            if ($command[0] === 'docker' && ($command[1] ?? '') === 'run') {
                $script = $command[count($command) - 1];
                $dockerRuns++;
                if (($dockerRuns === 2 || $dockerRuns === 3) && str_contains($script, 'composer update -W')) {
                    // The unblock did not bring the tags back: the branch stays locked.
                    file_put_contents($dir . '/composer.lock', (string) json_encode(['packages' => [['name' => 'laravel/framework', 'version' => '9.x-dev']]]));
                }

                return new ProcessResult(0, '', '');
            }
            if ($command[0] === 'docker') {
                return new ProcessResult(0, '', '');
            }

            return new ProcessResult(2, (string) json_encode(['file_diffs' => [['file' => $dir . '/app/Models/Post.php', 'diff' => 'd']], 'errors' => []]), '');
        });
        $executor = new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake), $fake);
        $report = $executor->execute($plan, new RunOptions());

        self::assertSame([StepResult::GATE_FAILED], $this->statuses($report));
        // Baseline, the gate that locked the branch, and exactly one retry: never a loop.
        self::assertSame(3, $dockerRuns);
        $all = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString('Composer locked laravel/framework to 9.x-dev although the hop asks for ^9.0: the tagged releases were refused for known security advisories; Booster added a temporary, scoped unblock (config.policy.advisories.ignore). Remove it before release; Booster fails the final target while it is there.', $all);
        self::assertStringContainsString('Gate failed: composer update -W laravel/framework still resolves laravel/framework to 9.x-dev after the unblock; the tagged releases are not loadable.', $all);
        self::assertStringContainsString('This step changed composer.json and composer.lock.', $all);
        // The failure is reported as the lock phase, never as the tests exiting.
        self::assertStringNotContainsString('exited', $all);
        // The gate failed, so the final-target finding never gets a turn.
        self::assertStringNotContainsString("composer.json still has Booster's temporary advisory unblock", $all);
        // The unblock stays in composer.json for PROCTOR to report.
        $composer = json_decode((string) file_get_contents($dir . '/composer.json'), true);
        self::assertArrayHasKey('laravel/framework', $composer['config']['policy']['advisories']['ignore']);
    }

    public function test_the_final_step_fails_while_a_booster_unblock_remains(): void
    {
        $dir = $this->copyExample('laravel-blade');
        [$dir, $plan] = $this->laravelPlan($dir);
        // An earlier run left its unblock behind; this run adds nothing new.
        (new ComposerEditor())->advisoryUnblock($dir, 'laravel/framework', '^13.0', ['PKSA-2026-0001']);

        $dockerRuns = 0;
        $fake = new FakeProcessRunner(static function (array $command) use ($dir, &$dockerRuns): ProcessResult {
            if ($command[0] === 'git') {
                return new ProcessResult(0, '', '');
            }
            if ($command[0] === 'docker' && ($command[1] ?? '') === 'run') {
                $dockerRuns++;

                return new ProcessResult(0, '', '');
            }
            if ($command[0] === 'docker') {
                return new ProcessResult(0, '', '');
            }

            return new ProcessResult(2, (string) json_encode(['file_diffs' => [['file' => $dir . '/app/Models/Post.php', 'diff' => 'd']], 'errors' => []]), '');
        });
        $executor = new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake), $fake);
        $report = $executor->execute($plan, new RunOptions());

        // Baseline and the gate: the gate is green, but the plan's last step
        // fails while the unblock remains in composer.json.
        self::assertSame([StepResult::GATE_FAILED], $this->statuses($report));
        self::assertSame(2, $dockerRuns);
        $all = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString("composer.json still has Booster's temporary advisory unblock for laravel/framework (config.policy.advisories.ignore). Remove it before release; Booster fails the final target while it is there.", $all);
        self::assertStringContainsString('Gate passed: tests are green on PHP 8.3.', $all);
        // Nothing was added by this run: only the standing finding is reported.
        self::assertStringNotContainsString('Composer refused tagged', $all);
        self::assertStringNotContainsString('Added a temporary advisory unblock to composer.json', $all);
    }

    public function test_a_non_final_step_only_warns_about_the_unblock(): void
    {
        [$dir, $plan] = $this->laravelPlanWithTail($this->copyExample('laravel-blade'));

        $dockerRuns = 0;
        $fake = new FakeProcessRunner(static function (array $command) use ($dir, &$dockerRuns): ProcessResult {
            if ($command[0] === 'git') {
                return new ProcessResult(0, '', '');
            }
            if ($command[0] === 'docker' && ($command[1] ?? '') === 'run') {
                $script = $command[count($command) - 1];
                $dockerRuns++;
                if ($dockerRuns === 2 && str_contains($script, 'composer update -W')) {
                    // Composer 2.10's policy blocking refuses the tagged releases.
                    return new ProcessResult(4, "Composer could not find a solution for your request.\n- Root composer.json requires laravel/framework ^13.0, found laravel/framework[v13.0.0, v13.1.0, v13.2.0] but these were not loaded, because they are affected by security advisories (\"PKSA-2026-0001\", \"PKSA-2026-0002\"). Go to https://packagist.org/security-advisories/ to find advisory details.\nBOOSTER_GATE_PHASE=install\nBOOSTER_GATE_EXIT=4", '');
                }
                if ($dockerRuns === 3 && str_contains($script, 'composer update -W')) {
                    // The retry, with the unblock in place, refreshes the lock.
                    file_put_contents($dir . '/composer.lock', json_encode(['packages' => ['laravel/framework' => ['version' => 'v13.39.0']]]));
                }

                return new ProcessResult(0, '', '');
            }
            if ($command[0] === 'docker') {
                return new ProcessResult(0, '', '');
            }

            return new ProcessResult(2, (string) json_encode(['file_diffs' => [['file' => $dir . '/app/Models/Post.php', 'diff' => 'd']], 'errors' => []]), '');
        });
        $executor = new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake), $fake);
        $report = $executor->execute($plan, new RunOptions());

        // The Laravel hop is not last: it applies with a warning. The tail hop
        // is last, and the unblock is still in composer.json, so it fails.
        self::assertSame([StepResult::APPLIED, StepResult::GATE_FAILED], $this->statuses($report));
        self::assertSame(4, $dockerRuns);
        $first = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString('Composer refused tagged laravel/framework ^13.0 for known security advisories; Booster added a temporary, scoped unblock (config.policy.advisories.ignore). Remove it before release; Booster fails the final target while it is there.', $first);
        self::assertStringContainsString('Added a temporary advisory unblock to composer.json', $first);
        // The non-final finding is the first sentence only.
        self::assertStringContainsString("composer.json still has Booster's temporary advisory unblock for laravel/framework (config.policy.advisories.ignore).", $first);
        self::assertStringNotContainsString("composer.json still has Booster's temporary advisory unblock for laravel/framework (config.policy.advisories.ignore). Remove it before release", $first);
        self::assertStringContainsString('Gate passed: tests are green on PHP 8.3.', $first);
        $second = implode("\n", $report->results[1]->messages);
        self::assertStringContainsString("composer.json still has Booster's temporary advisory unblock for laravel/framework (config.policy.advisories.ignore). Remove it before release; Booster fails the final target while it is there.", $second);
    }
}
