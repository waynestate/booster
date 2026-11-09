<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Plan\PlanOptions;
use Waynestate\Booster\Plan\Planner;
use Waynestate\Booster\Project\ProjectDetector;
use Waynestate\Booster\Replay\ReplayFile;
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
 * `booster run --vendored` on the 2012 fixture app: the hop runs between the
 * PHP hops, its gate installs the new composer.json, and the replay waits
 * for it, because the hand copies are what would crash on the new PHP.
 */
final class VendoredHopExecutorTest extends TestCase
{
    private function app(?callable $edit = null): string
    {
        $dir = $this->tempDir();
        $from = __DIR__ . '/fixtures/vendored-2012';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            self::assertInstanceOf(\SplFileInfo::class, $file);
            $target = $dir . substr($file->getPathname(), strlen($from));
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0775, true);
            }
            copy($file->getPathname(), $target);
        }
        if ($edit !== null) {
            $edit($dir);
        }

        return $dir;
    }

    /**
     * Docker answers through ReplayFake (gates pass, replays answer from
     * $replays); Rector changes one file per PHP hop.
     *
     * @param list<list<array{0: int, 1: string, 2?: list<array{string, string}>}>> $replays
     */
    private function fake(array $replays = []): FakeProcessRunner
    {
        $docker = ReplayFake::runner($replays);

        return new FakeProcessRunner(static function (array $command) use ($docker): ProcessResult {
            if (in_array($command[0], ['git', 'docker'], true)) {
                return $docker->run($command);
            }

            return new ProcessResult(2, (string) json_encode(['file_diffs' => [['file' => '/tmp/x/lib/helpers.php', 'diff' => "--- a\n+++ b"]], 'errors' => []]), '');
        });
    }

    private function execute(string $dir, FakeProcessRunner $fake, RunOptions $options, PlanOptions $plan): RunReport
    {
        $plan = (new Planner($this->policy(), new HopRegistry()))->plan((new ProjectDetector())->detect($dir), $plan, $this->today());

        return (new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake, dirname(__DIR__) . '/docker'), $fake))->execute($plan, $options);
    }

    /**
     * @return list<string> the shell scripts of the gate (non-replay) docker runs, in order
     */
    private static function gateScripts(FakeProcessRunner $fake): array
    {
        $scripts = [];
        foreach ($fake->calls as $call) {
            $command = $call['command'];
            if ($command[0] === 'docker' && ($command[1] ?? '') === 'run' && !in_array('--network', $command, true)) {
                $scripts[] = (string) $command[count($command) - 1];
            }
        }

        return $scripts;
    }

    public function test_a_dry_run_previews_the_move_and_changes_nothing(): void
    {
        $dir = $this->app();

        $report = $this->execute($dir, $this->fake(), new RunOptions(dryRun: true), new PlanOptions(fromPhp: '7.4', toPhp: '8.0', vendored: true));

        $result = $report->results[0];
        self::assertSame(['vendored-to-composer', StepResult::PREVIEWED], [$result->hopId, $result->status]);
        self::assertStringStartsWith('Dry run: would replace the hand-copied Doctrine 2.2.2', $result->messages[0]);
        self::assertDirectoryExists($dir . '/vendor/Slim');
        self::assertFileDoesNotExist($dir . '/composer.json');
        $text = (new TextWriter())->run($report, $dir, 'booster');
        self::assertStringContainsString('vendored-to-composer', $text);
    }

    public function test_a_real_run_moves_the_libraries_and_installs_them_in_the_gate(): void
    {
        $dir = $this->app();
        $fake = $this->fake();

        $report = $this->execute($dir, $fake, new RunOptions(), new PlanOptions(fromPhp: '7.4', toPhp: '8.0', vendored: true));

        self::assertFalse($report->failed(), implode("\n", array_merge(...array_map(static fn(StepResult $r): array => $r->messages, $report->results))));
        self::assertSame([StepResult::APPLIED, StepResult::APPLIED], array_map(static fn(StepResult $r): string => $r->status, $report->results));
        self::assertDirectoryDoesNotExist($dir . '/vendor/Doctrine');
        self::assertFileExists($dir . '/slim1-bridge.php');
        // The hop wrote ^7.4, and the PHP hop after it raised it.
        self::assertSame('^8.0', json_decode((string) file_get_contents($dir . '/composer.json'), true)['require']['php']);

        $messages = $report->results[0]->messages;
        self::assertContains('Gate passed: syntax check is clean on PHP 7.4.', $messages);
        self::assertStringContainsString('MANUAL: Commit composer.json and composer.lock.', implode("\n", $messages));

        // Baseline, the vendored hop's gate, the PHP hop's gate. The app had
        // no composer.json, so the baseline skips Composer; the hop's gate
        // installs the new composer.json, which writes the first lock.
        $scripts = self::gateScripts($fake);
        self::assertCount(3, $scripts);
        foreach ($scripts as $script) {
            self::assertStringContainsString('if [ -f composer.json ]; then', $script);
            self::assertStringContainsString('composer install --no-interaction', $script);
        }
    }

    public function test_an_app_with_a_lock_moves_only_the_new_packages_in_the_gate(): void
    {
        $dir = $this->app(static function (string $dir): void {
            file_put_contents($dir . '/composer.json', (string) json_encode(['require' => ['php' => '>=7.4', 'phpmailer/phpmailer' => '^6.9']]));
            file_put_contents($dir . '/composer.lock', (string) json_encode(['packages' => [['name' => 'phpmailer/phpmailer', 'version' => '6.9.1']], 'packages-dev' => []]));
        });
        $fake = $this->fake();

        $report = $this->execute($dir, $fake, new RunOptions(), new PlanOptions(fromPhp: '7.4', toPhp: '7.4', vendored: true));

        self::assertSame(['vendored-to-composer'], array_map(static fn(StepResult $r): string => $r->hopId, $report->results));
        self::assertSame(StepResult::APPLIED, $report->results[0]->status);
        self::assertStringContainsString('composer update -W --no-interaction --no-progress doctrine/orm doctrine/annotations symfony/cache slim/slim slim/psr7 smarty/smarty', self::gateScripts($fake)[1]);
    }

    public function test_the_replay_waits_for_the_vendored_hop_then_checks_every_step(): void
    {
        $dir = $this->app();
        $answers = [[200, '<h1>Batches</h1>']];
        $fake = $this->fake([$answers, $answers, $answers]);
        $replay = ReplayFile::fromArray(['requests' => [['path' => '/']]]);

        $report = $this->execute($dir, $fake, new RunOptions(replay: $replay, replayOut: $this->tempDir()), new PlanOptions(fromPhp: '7.3', toPhp: '8.0', vendored: true));

        self::assertFalse($report->failed());
        self::assertSame(['php-73-to-74', 'vendored-to-composer', 'php-74-to-80'], array_map(static fn(StepResult $r): string => $r->hopId, $report->results));
        self::assertContains('Replay deferred to step 2 (vendored-to-composer): the app still loads its hand-copied libraries, which may not run on PHP 7.4. The answers are compared once Composer\'s releases are in.', $report->results[0]->messages);
        self::assertContains('Replay passed: all 1 answer match the baseline from PHP 7.3.', $report->results[1]->messages);
        self::assertContains('Replay passed: all 1 answer match the baseline from PHP 7.3.', $report->results[2]->messages);
        $replays = array_filter($fake->calls, static fn(array $c): bool => in_array('--network', $c['command'], true));
        self::assertCount(3, $replays, 'The baseline, then the vendored hop and the PHP hop after it.');
    }

    public function test_the_tests_wait_for_the_vendored_hop_like_the_replay(): void
    {
        $dir = $this->app();
        $fake = $this->fake();

        $report = $this->execute($dir, $fake, new RunOptions(testCommand: 'php tests/run.php'), new PlanOptions(fromPhp: '7.3', toPhp: '8.0', vendored: true));

        self::assertFalse($report->failed());
        $scripts = self::gateScripts($fake);
        self::assertCount(4, $scripts);
        self::assertStringContainsString('php tests/run.php', $scripts[0], 'The baseline runs the tests on the PHP the app starts from.');
        self::assertStringContainsString("xargs -0 -r -n1 php -l", $scripts[1]);
        self::assertStringNotContainsString('php tests/run.php', $scripts[1]);
        self::assertStringContainsString('php tests/run.php', $scripts[2]);
        self::assertStringContainsString('php tests/run.php', $scripts[3]);
        self::assertSame(
            'Tests deferred to step 2 (vendored-to-composer): the app still loads its hand-copied libraries, which may not run on PHP 7.4. This step ran the syntax check; the tests run from that step on.',
            $report->results[0]->messages[array_search('Gate passed: syntax check is clean on PHP 7.4.', $report->results[0]->messages, true) - 1] ?? null,
        );
        self::assertContains('Gate passed: your test command passed on PHP 7.4.', $report->results[1]->messages);
        self::assertContains('Gate passed: your test command passed on PHP 8.0.', $report->results[2]->messages);
    }

    public function test_a_stop_sign_found_at_run_time_refuses_the_step(): void
    {
        $dir = $this->app();
        $fake = $this->fake();
        $plan = (new Planner($this->policy(), new HopRegistry()))->plan((new ProjectDetector())->detect($dir), new PlanOptions(fromPhp: '7.4', toPhp: '7.4', vendored: true), $this->today());
        // Someone adds a hook between the plan and the run.
        file_put_contents($dir . '/hooks.php', "<?php\n\$app->hook('slim.before', function () {});\n");

        $report = (new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake, dirname(__DIR__) . '/docker'), $fake))->execute($plan, new RunOptions());

        self::assertSame(StepResult::ERROR, $report->results[0]->status);
        self::assertSame('Refused: Booster will not guess past a stop sign, so nothing changed.', $report->results[0]->messages[0]);
        self::assertDirectoryExists($dir . '/vendor/Slim');
    }
}
