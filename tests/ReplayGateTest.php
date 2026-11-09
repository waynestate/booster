<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Replay\ReplayFile;
use Waynestate\Booster\Replay\ReplayGate;
use Waynestate\Booster\Replay\ReplayOutcome;
use Waynestate\Booster\Replay\ReplayRecording;
use Waynestate\Booster\Replay\ReplayRunner;
use Waynestate\Booster\Run\TargetImageRunner;
use Waynestate\Booster\ToolError;

final class ReplayGateTest extends TestCase
{
    private const JSON = [['Content-Type', 'application/json']];

    private function compareFile(): ReplayFile
    {
        return ReplayFile::load(__DIR__ . '/fixtures/replay-app/booster-replay-compare.json');
    }

    /**
     * @param list<list<array{0: int, 1: string, 2?: list<array{string, string}>}>|string> $replays
     */
    private function gate(array $replays, ?ReplayFile $file = null, ?string $out = null, ?FakeProcessRunner &$fake = null): ReplayGate
    {
        $fake = ReplayFake::runner($replays);
        $runner = new ReplayRunner(new TargetImageRunner($fake, dirname(__DIR__) . '/docker'), $fake);

        return new ReplayGate($runner, $file ?? $this->compareFile(), $this->tempDir(), $out ?? $this->tempDir(), null);
    }

    public function test_record_then_check_passes_when_nothing_changed(): void
    {
        $answers = [[200, '{"code":"0","matches_zero":true}', self::JSON], [200, '{"code":"abc","matches_zero":true}', self::JSON]];
        $out = $this->tempDir();
        $gate = $this->gate([$answers, $answers], out: $out);

        $recorded = $gate->record('7.4');
        $checked = $gate->check('7.4', 'again');

        self::assertSame(ReplayOutcome::RECORDED, $recorded->status);
        self::assertSame('Replay: recorded 2 answers on PHP 7.4 from booster-replay-compare.json.', $recorded->lines[0]);
        self::assertStringContainsString('PHP logged 1 distinct message while serving them', $recorded->lines[1]);
        self::assertSame(ReplayOutcome::PASSED, $checked->status);
        self::assertSame('Replay passed: all 2 answers match the baseline from PHP 7.4.', $checked->lines[0]);
        self::assertFileExists($out . '/baseline-php7.4.txt');
        self::assertFileExists($out . '/again.txt');
        self::assertStringContainsString('   2x PHP Deprecated:  Old thing in /app/src/app.php on line 3', (string) file_get_contents($out . '/baseline-php7.4.php-errors.txt'));
    }

    public function test_a_baseline_of_one_redirect_everywhere_warns_that_it_proves_only_sign_in(): void
    {
        $signIn = [[302, '', [['Location', '/login?next=%2F']]], [302, '', [['Location', '/login?next=%2F']]]];
        $gate = $this->gate([$signIn, $signIn]);

        $recorded = $gate->record('5.6');

        self::assertSame(ReplayOutcome::RECORDED, $recorded->status);
        self::assertContains(
            'Warning: all 2 recorded answers are the same redirect (Location: /login?next=%2F). If that is the sign-in page, the replay proves only that the app reaches sign-in, not that the pages behind it still work. Give the replay a test login: ' . ReplayGate::SIGN_IN_GUIDE,
            $recorded->lines,
        );
        // It warns; the replay still gates every hop.
        self::assertSame(ReplayOutcome::PASSED, $gate->check('7.0', 'step-1')->status);
    }

    public function test_redirects_to_different_places_or_a_page_among_them_do_not_warn(): void
    {
        $mixed = [[302, '', [['Location', '/login']]], [200, '{"code":"abc"}', self::JSON]];
        $apart = [[302, '', [['Location', '/login']]], [301, '', [['Location', '/compare/']]]];

        self::assertEmpty(preg_grep('/^Warning: /', $this->gate([$mixed])->record('5.6')->lines));
        self::assertEmpty(preg_grep('/^Warning: /', $this->gate([$apart])->record('5.6')->lines));
    }

    public function test_a_changed_answer_fails_with_the_first_difference_and_where_the_transcripts_are(): void
    {
        $out = $this->tempDir();
        $gate = $this->gate([
            [[200, '{"code":"0","matches_zero":true}', self::JSON], [200, '{"code":"abc","matches_zero":true}', self::JSON]],
            [[200, '{"code":"0","matches_zero":true}', self::JSON], [200, '{"code":"abc","matches_zero":false}', self::JSON]],
        ], out: $out);
        $gate->record('7.4');

        $outcome = $gate->check('8.0', 'step-1-php-74-to-80-php8.0');

        self::assertTrue($outcome->failed());
        self::assertSame(1, $outcome->differences);
        self::assertSame([
            'Replay failed on PHP 8.0: 1 of 2 answers differ from the baseline from PHP 7.4.',
            'First difference: 02 requests / a word: GET /compare?code=abc (- baseline, + now)',
            '        "code": "abc",',
            '  -     "matches_zero": true',
            '  +     "matches_zero": false',
            '    }',
            sprintf('Every difference: diff %s/baseline-php7.4.txt %s/step-1-php-74-to-80-php8.0.txt', $out, $out),
        ], array_slice($outcome->lines, 0, 7));
    }

    public function test_a_baseline_that_does_not_meet_its_expectations_is_not_usable(): void
    {
        $file = ReplayFile::fromArray(['requests' => [['path' => '/', 'expect' => 200], ['path' => '/gone']]]);
        $gate = $this->gate([[[500, 'Fatal error'], [0, '']]], $file);

        $outcome = $gate->record('7.4');

        self::assertSame(ReplayOutcome::FAILED, $outcome->status);
        self::assertNull($gate->recording());
        self::assertStringContainsString('01 requests: GET / answered 500; the request file expects 200.', implode("\n", $outcome->lines));
        self::assertStringContainsString('02 requests: GET /gone got no answer (could not connect).', implode("\n", $outcome->lines));
    }

    public function test_a_failing_setup_command_says_there_was_no_fake_data(): void
    {
        $outcome = $this->gate([ReplayFake::SETUP_FAILS])->record('7.4');

        self::assertSame(ReplayOutcome::FAILED, $outcome->status);
        self::assertSame('Replay baseline could not be recorded on PHP 7.4:', $outcome->lines[0]);
        self::assertSame('  The setup command failed (exit 1), so there was no fake data to serve.', $outcome->lines[1]);
        self::assertSame('  SQLSTATE[HY000]: unable to open database file', $outcome->lines[2]);
    }

    public function test_a_server_that_never_answers_shows_its_log(): void
    {
        $outcome = $this->gate([ReplayFake::NO_SERVER])->record('7.4');

        self::assertSame(ReplayOutcome::FAILED, $outcome->status);
        self::assertStringContainsString("The app's built-in PHP server did not answer (exit 3).", implode("\n", $outcome->lines));
        self::assertStringContainsString('PHP Fatal error: Uncaught Error in router', implode("\n", $outcome->lines));
    }

    public function test_a_file_with_todos_cannot_be_recorded(): void
    {
        $file = ReplayFile::fromArray(['serve' => ['todo' => 'name the setup command'], 'requests' => [['path' => '/']]]);

        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('still has 1 todo item for a person to fill in');

        $this->gate([], $file)->record('7.4');
    }

    public function test_a_recording_from_another_request_file_is_refused(): void
    {
        $other = new ReplayRecording('7.4', 'not-this-file', []);

        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('was made from a different version of booster-replay-compare.json');

        $this->gate([])->useRecording($other, '/tmp/rec.json');
    }

    public function test_a_saved_recording_round_trips_and_checks_like_a_fresh_one(): void
    {
        $answers = [[200, '{"code":"0","matches_zero":true}', self::JSON], [200, "\xff\xfe binary", [['Content-Type', 'application/octet-stream']]]];
        $gate = $this->gate([$answers]);
        $gate->record('7.4');
        $json = (string) $gate->recording()?->toJson();
        $path = $this->tempDir() . '/rec.json';
        file_put_contents($path, $json);

        $second = $this->gate([$answers]);
        $second->useRecording(ReplayRecording::load($path), $path);

        self::assertSame(ReplayOutcome::PASSED, $second->check('8.4', 'check')->status);
        self::assertStringContainsString('"body_encoding": "base64"', $json);
        self::assertStringContainsString('"body": "{\"code\":\"0\",\"matches_zero\":true}"', $json);
    }

    public function test_the_default_out_dir_is_outside_the_app_and_named_for_it(): void
    {
        $dir = ReplayGate::defaultOutDir('/srv/apps/my notes/', new \DateTimeImmutable('2026-11-05 20:15:00'));

        self::assertSame(sys_get_temp_dir() . '/booster-replay-my-notes-20261105-201500', $dir);
    }
}
