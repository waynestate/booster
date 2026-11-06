<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Symfony\Component\Process\Process;
use Waynestate\Booster\Replay\ReplayFile;
use Waynestate\Booster\Replay\ReplayGate;
use Waynestate\Booster\Replay\ReplayOutcome;
use Waynestate\Booster\Replay\ReplayRunner;
use Waynestate\Booster\Run\SymfonyProcessRunner;
use Waynestate\Booster\Run\TargetImageRunner;

/**
 * The replay gate for real: the fixture notes app served by PHP's built-in
 * server in the booster-target images for PHP 7.4 and 8.0, and full
 * `booster run --replay` upgrades across the 7.4 to 8.0 hop.
 *
 * It needs Docker and a few minutes (the first build of an image takes
 * longer), so it is not part of `composer ci`. Run it with:
 *
 *     BOOSTER_DOCKER_TESTS=1 vendor/bin/phpunit tests/ReplayDockerTest.php
 *
 * from a shell whose Docker can mount this machine's temp folder (Linux,
 * macOS, or WSL with Docker Desktop on Windows).
 */
final class ReplayDockerTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('BOOSTER_DOCKER_TESTS') !== '1') {
            self::markTestSkipped('Set BOOSTER_DOCKER_TESTS=1 to run the Docker end-to-end replay tests.');
        }
    }

    private function app(): string
    {
        $dir = $this->tempDir();
        $this->copyFixture(__DIR__ . '/fixtures/replay-app', $dir);
        // The container may run as another user; let it read everything.
        @chmod($dir, 0777);

        return $dir;
    }

    private function copyFixture(string $from, string $to): void
    {
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir($from . '/' . $entry)) {
                mkdir($to . '/' . $entry, 0777, true);
                $this->copyFixture($from . '/' . $entry, $to . '/' . $entry);
            } else {
                copy($from . '/' . $entry, $to . '/' . $entry);
            }
        }
    }

    private function gate(string $app, string $file, string $out): ReplayGate
    {
        $processes = new SymfonyProcessRunner();
        $runner = new ReplayRunner(new TargetImageRunner($processes), $processes);

        return new ReplayGate($runner, ReplayFile::load($app . '/' . $file), $app, $out);
    }

    public function test_the_notes_app_answers_the_same_on_74_and_80_and_normalizers_hide_tokens_and_times(): void
    {
        $app = $this->app();
        $out = $this->tempDir();
        $gate = $this->gate($app, 'booster-replay.json', $out);

        $recorded = $gate->record('7.4');
        self::assertSame(ReplayOutcome::RECORDED, $recorded->status, implode("\n", $recorded->lines));
        self::assertSame(11, $recorded->requests);
        $baseline = (string) file_get_contents($out . '/baseline-php7.4.txt');
        self::assertStringContainsString("=== 08 save then read back / save: POST /notes\nstatus: 302\ncontent-type: text/html; charset=utf-8\nlocation: /notes/3\nallow: -\nset-cookie: last_note", $baseline);
        self::assertStringContainsString('"title": "Replay note"', $baseline);
        self::assertStringContainsString("=== 04 browse / wrong method: GET /notes\nstatus: 405\ncontent-type: text/html; charset=utf-8\nlocation: -\nallow: POST", $baseline);
        self::assertStringContainsString('name="_token" value="<CSRF>"', $baseline);

        // Nothing changed: the same app on the next PHP answers the same.
        $checked = $gate->check('8.0', 'php80');
        self::assertSame(ReplayOutcome::PASSED, $checked->status, implode("\n", $checked->lines));

        // Without the normalizers the random CSRF token and the clock differ:
        // the recording still matches (normalizers are not part of the
        // fingerprint), and the check fails on them.
        $data = (array) json_decode((string) file_get_contents($app . '/booster-replay.json'), true);
        unset($data['normalize']);
        file_put_contents($app . '/booster-replay-raw.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $raw = $this->gate($app, 'booster-replay-raw.json', $this->tempDir());
        $raw->useRecording($gate->recording() ?? throw new \LogicException('no recording'), 'memory');
        $unnormalized = $raw->check('7.4', 'raw');
        self::assertSame(ReplayOutcome::DIFFERS, $unnormalized->status);
        self::assertStringContainsString('First difference: 01 browse / home: GET /', implode("\n", $unnormalized->lines));
    }

    public function test_the_php_8_string_comparison_change_fails_with_a_clear_first_difference(): void
    {
        $app = $this->app();
        $gate = $this->gate($app, 'booster-replay-compare.json', $this->tempDir());

        self::assertSame(ReplayOutcome::RECORDED, $gate->record('7.4')->status);
        $outcome = $gate->check('8.0', 'php80');

        self::assertSame(ReplayOutcome::DIFFERS, $outcome->status);
        self::assertSame([
            'Replay failed on PHP 8.0: 1 of 2 answers differ from the baseline from PHP 7.4.',
            'First difference: 02 requests / a word: GET /compare?code=abc (- baseline, + now)',
            '        "code": "abc",',
            '  -     "matches_zero": true',
            '  +     "matches_zero": false',
            '    }',
        ], array_slice($outcome->lines, 0, 6));
    }

    /**
     * The whole thing: `booster run --replay` on a git working copy of the
     * notes app, across the 7.4 to 8.0 hop. With the main request file the
     * hop passes; with the comparison file the hop fails on the changed
     * answer and leaves the changes in place.
     */
    public function test_booster_run_with_replay_passes_and_fails_the_hop_end_to_end(): void
    {
        foreach (['booster-replay.json' => 0, 'booster-replay-compare.json' => 1] as $file => $expectedExit) {
            $app = $this->app();
            $this->git($app, ['init', '-q']);
            $this->git($app, ['add', '-A']);
            $this->git($app, ['-c', 'user.name=Booster Test', '-c', 'user.email=booster@example.invalid', 'commit', '-q', '-m', 'fixture']);
            $out = $this->tempDir();

            $process = new Process([PHP_BINARY, dirname(__DIR__) . '/bin/booster', 'run', '--path=' . $app, '--to-php=8.0', '--no-package-check', '--proctor-advisory', '--replay=' . $file, '--replay-out=' . $out, '--format=json'], null, null, null, 1800.0);
            $process->run();
            $report = json_decode($process->getOutput(), true);

            self::assertIsArray($report, $process->getOutput() . $process->getErrorOutput());
            self::assertSame($expectedExit, $process->getExitCode(), $process->getOutput());
            self::assertSame('recorded', $report['replay']['status']);
            self::assertSame('7.4', $report['replay']['php']);
            $step = $report['results'][0];
            self::assertSame('php-74-to-80', $step['hop']);
            if ($expectedExit === 0) {
                self::assertSame('applied', $step['status']);
                self::assertContains('Replay passed: all 11 answers match the baseline from PHP 7.4.', $step['messages']);
            } else {
                self::assertSame('gate-failed', $step['status']);
                self::assertStringContainsString('First difference: 02 requests / a word: GET /compare?code=abc', implode("\n", $step['messages']));
            }
            self::assertFileExists($out . '/baseline-php7.4.txt');
            self::assertFileExists($out . '/step-1-php-74-to-80-php8.0.txt');
        }
    }

    /**
     * @param list<string> $args
     */
    private function git(string $dir, array $args): void
    {
        $process = new Process(['git', '-C', $dir, ...$args]);
        $process->mustRun();
    }
}
