<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Plan\PlanOptions;
use Waynestate\Booster\Plan\Planner;
use Waynestate\Booster\Project\ProjectDetector;
use Waynestate\Booster\Replay\ReplayFile;
use Waynestate\Booster\Replay\ReplayOutcome;
use Waynestate\Booster\Report\TextWriter;
use Waynestate\Booster\Run\ComposerEditor;
use Waynestate\Booster\Run\HopExecutor;
use Waynestate\Booster\Run\ProcessResult;
use Waynestate\Booster\Run\RectorRunner;
use Waynestate\Booster\Run\RunOptions;
use Waynestate\Booster\Run\RunReport;
use Waynestate\Booster\Run\StepResult;
use Waynestate\Booster\Run\TargetImageRunner;

/**
 * `booster run --replay`: the replay baseline is recorded once, before the
 * first hop, and replayed after each hop's test gate; a changed answer fails
 * the hop like a red test.
 */
final class HopExecutorReplayTest extends TestCase
{
    private const JSON = [['Content-Type', 'application/json']];

    /**
     * @param list<list<array{0: int, 1: string, 2?: list<array{string, string}>}>|string> $replays
     */
    private function fake(array $replays): FakeProcessRunner
    {
        $docker = ReplayFake::runner($replays);

        return new FakeProcessRunner(static function (array $command) use ($docker): ProcessResult {
            if (in_array($command[0], ['git', 'docker'], true)) {
                return $docker->run($command);
            }

            // Rector: one changed file per hop, so every hop reaches its gate.
            return new ProcessResult(2, (string) json_encode(['file_diffs' => [['file' => '/tmp/x/src/F.php', 'diff' => "--- a\n+++ b"]], 'errors' => []]), '');
        });
    }

    private function execute(FakeProcessRunner $fake, RunOptions $options): RunReport
    {
        $dir = $this->copyExample('raw-php');
        $plan = (new Planner($this->policy(), new HopRegistry()))->plan((new ProjectDetector())->detect($dir), new PlanOptions(fromPhp: '8.1', toPhp: '8.3'), $this->today());

        return (new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake, dirname(__DIR__) . '/docker'), $fake))->execute($plan, $options);
    }

    private function replayFile(): ReplayFile
    {
        return ReplayFile::load(__DIR__ . '/fixtures/replay-app/booster-replay-compare.json');
    }

    /** @return list<array{int, string, list<array{string, string}>}> */
    private static function answers(bool $matchesZero): array
    {
        return [[200, '{"code":"0","matches_zero":true}', self::JSON], [200, '{"code":"abc","matches_zero":' . ($matchesZero ? 'true' : 'false') . '}', self::JSON]];
    }

    public function test_the_baseline_is_recorded_once_and_replayed_after_every_hop(): void
    {
        $fake = $this->fake([self::answers(true), self::answers(true), self::answers(true)]);
        $out = $this->tempDir();

        $report = $this->execute($fake, new RunOptions(replay: $this->replayFile(), replayOut: $out));

        self::assertFalse($report->failed());
        self::assertSame(ReplayOutcome::RECORDED, $report->replay?->status);
        self::assertSame('8.1', $report->replay->php);
        self::assertCount(2, $report->results);
        foreach ($report->results as $result) {
            self::assertSame(StepResult::APPLIED, $result->status);
            self::assertContains('Replay passed: all 2 answers match the baseline from PHP 8.1.', $result->messages);
        }
        self::assertFileExists($out . '/baseline-php8.1.txt');
        self::assertFileExists($out . '/step-1-php-81-to-82-php8.2.txt');
        self::assertFileExists($out . '/step-2-php-82-to-83-php8.3.txt');
        $replays = array_filter($fake->calls, static fn(array $c): bool => in_array('--network', $c['command'], true));
        self::assertCount(3, $replays);

        $text = (new TextWriter())->run($report, '/app', 'booster');
        self::assertStringContainsString("Replay: recorded 2 answers on PHP 8.1 from booster-replay-compare.json.\n", $text);
        self::assertSame('replay', array_key_last($report->toArray()));
        self::assertSame('recorded', $report->toArray()['replay']['status']);
    }

    public function test_a_changed_answer_fails_the_hop_and_stops_the_run(): void
    {
        $fake = $this->fake([self::answers(true), self::answers(false)]);

        $report = $this->execute($fake, new RunOptions(replay: $this->replayFile(), replayOut: $this->tempDir()));

        self::assertTrue($report->failed());
        self::assertSame(StepResult::GATE_FAILED, $report->results[0]->status);
        self::assertSame(StepResult::NOT_RUN, $report->results[1]->status);
        $messages = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString('Replay failed on PHP 8.2: 1 of 2 answers differ from the baseline from PHP 8.1.', $messages);
        self::assertStringContainsString('First difference: 02 requests / a word: GET /compare?code=abc', $messages);
        self::assertStringContainsString('+     "matches_zero": false', $messages);
        self::assertStringContainsString('Undo with: git checkout .', $messages);
    }

    public function test_a_baseline_that_cannot_be_recorded_stops_before_the_first_hop(): void
    {
        $fake = $this->fake([ReplayFake::SETUP_FAILS]);

        $report = $this->execute($fake, new RunOptions(replay: $this->replayFile(), replayOut: $this->tempDir()));

        self::assertTrue($report->failed());
        self::assertSame(ReplayOutcome::FAILED, $report->replay?->status);
        foreach ($report->results as $result) {
            self::assertSame(StepResult::NOT_RUN, $result->status);
        }
        $text = (new TextWriter())->run($report, '/app', 'booster');
        self::assertStringContainsString('The upgrade did not start: the replay baseline could not be recorded.', $text);
        // Rector never ran: nothing was changed.
        self::assertSame([], array_filter($fake->calls, static fn(array $c): bool => !in_array($c['command'][0], ['git', 'docker'], true)));
    }

    public function test_a_dry_run_sends_no_requests_and_says_so(): void
    {
        $fake = $this->fake([]);

        $report = $this->execute($fake, new RunOptions(dryRun: true, replay: $this->replayFile()));

        self::assertSame(ReplayOutcome::SKIPPED, $report->replay?->status);
        self::assertFalse($report->failed());
        self::assertSame([], array_filter($fake->calls, static fn(array $c): bool => in_array('--network', $c['command'], true)));
    }

    public function test_without_replay_the_run_and_its_json_are_unchanged(): void
    {
        $fake = $this->fake([]);

        $report = $this->execute($fake, new RunOptions());

        self::assertNull($report->replay);
        self::assertArrayNotHasKey('replay', $report->toArray());
        self::assertSame([], array_filter($fake->calls, static fn(array $c): bool => in_array('--network', $c['command'], true)));
    }
}
