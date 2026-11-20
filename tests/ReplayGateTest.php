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
    private function gate(array $replays, ?ReplayFile $file = null, ?string $out = null, ?FakeProcessRunner &$fake = null, ?string $phpLog = null): ReplayGate
    {
        $fake = ReplayFake::runner($replays, 0, $phpLog);
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

    /**
     * Bug 14 (proof run 2026-11-20): a Slim 3 app answered all five requests
     * with one fatal (a gitignored log folder was missing), shown with a 200
     * because the app turns display_errors on, and every hop "passed".
     */
    private const FATAL_BODY = "<br />\n<b>Fatal error</b>:  Uncaught Exception: Required '/app/var/logs/' is not a directory in /app/config/init.php:41\nStack trace:\n#0 {main}\n  thrown in <b>/app/config/init.php</b> on line <b>41</b><br />\n";

    private const FATAL_LOG = "[20-Nov-2026 01:35:00 UTC] PHP Fatal error:  Uncaught Exception: Required '/app/var/logs/' is not a directory in /app/config/init.php:41\n";

    public function test_a_baseline_where_every_answer_is_the_same_fatal_is_not_usable(): void
    {
        $fatal = [[200, self::FATAL_BODY], [200, self::FATAL_BODY]];
        $gate = $this->gate([$fatal], phpLog: self::FATAL_LOG);

        $outcome = $gate->record('7.2');

        self::assertSame(ReplayOutcome::FAILED, $outcome->status);
        self::assertNull($gate->recording());
        self::assertSame('Replay baseline on PHP 7.2 is not usable: all 2 recorded answers are errors, before any change. A replay of it would only prove the app fails the same way after each hop.', $outcome->lines[0]);
        self::assertSame("  01 requests / a number: GET /compare?code=0 answered 200 with a PHP error: Fatal error: Uncaught Exception: Required '/app/var/logs/' is not a directory in /app/config/init.php:41", $outcome->lines[1]);
        self::assertStringStartsWith('  02 requests / a word: GET /compare?code=abc answered 200 with a PHP error: Fatal error: Uncaught Exception', $outcome->lines[2]);
        self::assertStringStartsWith('PHP logged 1 distinct message while serving them: ', $outcome->lines[3]);
        self::assertStringContainsString('or pass --allow-failing-baseline to replay against the errors anyway', $outcome->lines[4]);
    }

    public function test_a_baseline_of_server_errors_or_one_error_page_everywhere_is_not_usable(): void
    {
        $fiveHundreds = [[500, '<html><head><title>Slim Application Error</title></head><body><h1>Slim Application Error</h1></body></html>'], [503, '']];
        $outcome = $this->gate([$fiveHundreds])->record('7.4');
        self::assertSame(ReplayOutcome::FAILED, $outcome->status);
        self::assertSame('  01 requests / a number: GET /compare?code=0 answered 500: Slim Application Error', $outcome->lines[1]);
        self::assertSame('  02 requests / a word: GET /compare?code=abc answered 503.', $outcome->lines[2]);

        $notFound = [[404, '<h1>Not Found</h1><p>The page was not found.</p>'], [404, '<h1>Not Found</h1><p>The page was not found.</p>']];
        $outcome = $this->gate([$notFound])->record('7.4');
        self::assertSame(ReplayOutcome::FAILED, $outcome->status);
        self::assertSame('Replay baseline on PHP 7.4 is not usable: all 2 recorded answers are the same 404 page, before any change. A replay of it would only prove the app fails the same way after each hop.', $outcome->lines[0]);
        self::assertSame('  01 requests / a number: GET /compare?code=0 and every other request answered 404: Not Found', $outcome->lines[1]);
    }

    public function test_error_answers_the_file_expects_are_a_usable_baseline(): void
    {
        $file = ReplayFile::fromArray(['requests' => [['path' => '/gone', 'expect' => 404], ['path' => '/also-gone', 'expect' => 404], ['path' => '/boom', 'expect' => 500]]]);
        $answers = [[404, 'Not Found'], [404, 'Not Found'], [500, 'Server Error']];

        $outcome = $this->gate([$answers], $file)->record('8.1');

        self::assertSame(ReplayOutcome::RECORDED, $outcome->status);
        self::assertEmpty(preg_grep('/^Warning: /', $outcome->lines));
    }

    public function test_with_allow_failing_baseline_an_error_baseline_is_recorded_with_a_warning(): void
    {
        $fatal = [[200, self::FATAL_BODY], [200, self::FATAL_BODY]];
        $gate = $this->gate([$fatal, $fatal], phpLog: self::FATAL_LOG);

        $outcome = $gate->record('7.2', false, true);

        self::assertSame(ReplayOutcome::RECORDED, $outcome->status);
        self::assertSame('Warning: All 2 recorded answers are errors. Recorded anyway because of --allow-failing-baseline, so the replay proves only that the app fails the same way after each hop.', $outcome->lines[1]);
        self::assertSame(ReplayOutcome::PASSED, $gate->check('7.3', 'step-1')->status);
    }

    public function test_some_error_answers_and_a_fatal_in_the_log_warn_but_record(): void
    {
        $mixed = [[200, '{"code":"0","matches_zero":true}', self::JSON], [500, 'Internal Server Error']];

        $outcome = $this->gate([$mixed], phpLog: self::FATAL_LOG)->record('7.4');

        self::assertSame(ReplayOutcome::RECORDED, $outcome->status);
        self::assertContains('Warning: 1 of 2 recorded answers are errors, so those requests replay the error, not the page:', $outcome->lines);
        self::assertContains('  02 requests / a word: GET /compare?code=abc answered 500: Internal Server Error', $outcome->lines);
        $log = preg_grep('/^Warning: PHP logged 1 fatal error while serving the baseline/', $outcome->lines);
        self::assertCount(1, $log);
        self::assertStringEndsWith("First: 1x PHP Fatal error: Uncaught Exception: Required '/app/var/logs/' is not a directory in /app/config/init.php:41", (string) reset($log));
    }

    public function test_the_php_error_finder_reads_plain_and_html_fatals_and_ignores_pages_that_mention_errors(): void
    {
        self::assertSame('Parse error: syntax error, unexpected end of file in /app/x.php on line 3', ReplayGate::phpErrorIn("PHP Parse error:  syntax error, unexpected end of file in /app/x.php on line 3\n"));
        self::assertSame("Fatal error: Uncaught Exception: Required '/app/var/logs/' is not a directory in /app/config/init.php:41", ReplayGate::phpErrorIn(self::FATAL_BODY));
        self::assertNull(ReplayGate::phpErrorIn('<p>No errors here, and fatal errors are rare.</p>'));
        self::assertNull(ReplayGate::phpErrorIn(''));
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

    /**
     * The compare fixture with only its serve section changed (or one request's
     * query), so the other half of the fingerprints stays put.
     *
     * @param array<string, mixed> $serve
     */
    private function compareFileWith(array $serve): ReplayFile
    {
        $path = __DIR__ . '/fixtures/replay-app/booster-replay-compare.json';
        $data = (array) json_decode((string) file_get_contents($path), true);
        $data['serve'] = $serve;

        return ReplayFile::fromArray($data, $path);
    }

    public function test_a_serve_change_passes_with_the_flag_and_names_the_change_in_report_and_transcript(): void
    {
        $answers = [[200, '{"code":"0","matches_zero":true}', self::JSON], [200, '{"code":"abc","matches_zero":true}', self::JSON]];
        $out = $this->tempDir();
        $gate = $this->gate([$answers], out: $out);
        $gate->record('7.4');
        $recording = $gate->recording();
        self::assertNotNull($recording);

        // The hop changed how the app is served: the same requests, a new setup command.
        $moved = $this->compareFileWith(['docroot' => 'public', 'front_controller' => 'index.php', 'setup' => 'php seed.php']);
        $second = $this->gate([$answers], file: $moved, out: $out);
        $second->useRecording($recording, '/tmp/rec.json', true);

        $outcome = $second->check('8.0', 'step-mount', false, true);

        self::assertSame(ReplayOutcome::PASSED, $outcome->status);
        self::assertSame([
            'Replay passed: all 2 answers match the baseline from PHP 7.4.',
            'serve: docroot public, front index.php -> docroot public, front index.php, setup "php seed.php"',
        ], array_slice($outcome->lines, 0, 2));
        $transcript = (string) file_get_contents($out . '/step-mount.txt');
        self::assertStringContainsString("Booster replay: step-mount on PHP 8.0\nRequest file: booster-replay-compare.json\nserve: docroot public, front index.php -> docroot public, front index.php, setup \"php seed.php\"\n", $transcript);
    }

    public function test_a_serve_change_without_the_flag_is_refused_like_any_other_file_change(): void
    {
        $answers = [[200, '{"code":"0","matches_zero":true}', self::JSON], [200, '{"code":"abc","matches_zero":true}', self::JSON]];
        $gate = $this->gate([$answers]);
        $gate->record('7.4');
        $recording = $gate->recording();
        self::assertNotNull($recording);

        $moved = $this->compareFileWith(['docroot' => 'public', 'front_controller' => 'index.php', 'setup' => 'php seed.php']);

        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('was made from a different version of booster-replay-compare.json');

        $this->gate([], file: $moved)->useRecording($recording, '/tmp/rec.json');
    }

    public function test_with_the_flag_a_changed_request_still_refuses_the_recording(): void
    {
        $answers = [[200, '{"code":"0","matches_zero":true}', self::JSON], [200, '{"code":"abc","matches_zero":true}', self::JSON]];
        $gate = $this->gate([$answers]);
        $gate->record('7.4');
        $recording = $gate->recording();
        self::assertNotNull($recording);

        $path = __DIR__ . '/fixtures/replay-app/booster-replay-compare.json';
        $data = (array) json_decode((string) file_get_contents($path), true);
        $data['requests'][1]['query']['code'] = 'xyz';
        $changed = ReplayFile::fromArray($data, $path);

        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('was made from a different version of booster-replay-compare.json');

        $this->gate([], file: $changed)->useRecording($recording, '/tmp/rec.json', true);
    }

    public function test_a_recording_from_before_the_split_fingerprints_needs_a_new_record(): void
    {
        // The old format: no requests_fingerprint / serve_fingerprint keys.
        $json = (string) json_encode(['format' => ReplayRecording::FORMAT, 'php' => '7.4', 'fingerprint' => $this->compareFile()->fingerprint, 'answers' => []]);
        $path = $this->tempDir() . '/old-rec.json';
        file_put_contents($path, $json);

        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('was made before Booster could compare across a serve change');

        $this->gate([])->useRecording(ReplayRecording::load($path), $path, true);
    }

    public function test_the_flag_says_nothing_when_the_serve_section_did_not_change(): void
    {
        $answers = [[200, '{"code":"0","matches_zero":true}', self::JSON], [200, '{"code":"abc","matches_zero":true}', self::JSON]];
        $out = $this->tempDir();
        $gate = $this->gate([$answers], out: $out);
        $gate->record('7.4');
        $recording = $gate->recording();
        self::assertNotNull($recording);

        $second = $this->gate([$answers], out: $out);
        $second->useRecording($recording, '/tmp/rec.json', true);

        $outcome = $second->check('8.0', 'check', false, true);

        self::assertSame(ReplayOutcome::PASSED, $outcome->status);
        self::assertEmpty(preg_grep('/^serve: /', $outcome->lines));
        self::assertStringNotContainsString('serve: ', (string) file_get_contents($out . '/check.txt'));
    }

    public function test_a_serve_change_with_differing_answers_names_the_change_too(): void
    {
        $before = [[200, '{"code":"0","matches_zero":true}', self::JSON], [200, '{"code":"abc","matches_zero":true}', self::JSON]];
        $after = [[200, '{"code":"0","matches_zero":true}', self::JSON], [200, '{"code":"abc","matches_zero":false}', self::JSON]];
        $gate = $this->gate([$before]);
        $gate->record('7.4');
        $recording = $gate->recording();
        self::assertNotNull($recording);

        $moved = $this->compareFileWith(['docroot' => 'public', 'front_controller' => 'index.php', 'setup' => 'php seed.php']);
        $second = $this->gate([$after], file: $moved);
        $second->useRecording($recording, '/tmp/rec.json', true);

        $outcome = $second->check('8.0', 'step-1', false, true);

        self::assertTrue($outcome->failed());
        self::assertSame('Replay failed on PHP 8.0: 1 of 2 answers differ from the baseline from PHP 7.4.', $outcome->lines[0]);
        self::assertSame('serve: docroot public, front index.php -> docroot public, front index.php, setup "php seed.php"', $outcome->lines[1]);
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
