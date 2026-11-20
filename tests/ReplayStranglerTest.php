<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Replay\ReplayAnswer;
use Waynestate\Booster\Replay\ReplayComparison;
use Waynestate\Booster\Replay\ReplayFile;
use Waynestate\Booster\Replay\ReplayGate;
use Waynestate\Booster\Replay\ReplayOutcome;
use Waynestate\Booster\Replay\ReplayRunner;
use Waynestate\Booster\Run\TargetImageRunner;
use Waynestate\Booster\ToolError;

/**
 * The replay gate's strangler pieces (design sections 5.2 to 5.5 and 9.5):
 * compare.ignore_cookies, the CakePHP CSRF names in the csrf preset, the
 * "csrf": true request flag, the signed-in sequence failure line, the
 * strangler compare preset, the X-Booster-Half report, compare.silent_sign_in
 * and ReplayFile::usableForStrangler(). Like a stunt double, the replay has to
 * take the same fall whichever half of the cast is on set.
 */
final class ReplayStranglerTest extends TestCase
{
    private const HTML = ['Content-Type', 'text/html; charset=UTF-8'];

    /**
     * @param array<string, mixed> $compare
     * @param list<mixed>          $normalize
     */
    private function file(array $compare = [], array $normalize = []): ReplayFile
    {
        return ReplayFile::fromArray(['normalize' => $normalize, 'compare' => $compare, 'requests' => [['path' => '/']]]);
    }

    /**
     * @param list<array{string, string}> $headers
     */
    private static function answer(string $body, array $headers = [self::HTML], int $status = 200, int $number = 1): ReplayAnswer
    {
        return new ReplayAnswer($number, $status, $headers, $body);
    }

    // compare.ignore_cookies (5.2)

    public function test_ignored_cookies_leave_the_compared_names_and_app_cookies_stay(): void
    {
        $file = $this->file(['ignore_cookies' => ['CAKEPHP', 'csrfToken', 'laravel_session', 'XSRF-TOKEN']]);
        $legacy = self::answer('<p>hi</p>', [self::HTML, ['Set-Cookie', 'CAKEPHP=abc; path=/'], ['Set-Cookie', 'csrfToken=x; path=/'], ['Set-Cookie', 'remember=1; path=/']]);
        $laravel = self::answer('<p>hi</p>', [self::HTML, ['Set-Cookie', 'XSRF-TOKEN=y; path=/'], ['Set-Cookie', 'laravel_session=z; path=/; httponly'], ['Set-Cookie', 'remember=1; path=/']]);

        self::assertContains('set-cookie: remember', ReplayComparison::view($legacy, $file));
        self::assertSame([], ReplayComparison::differences(ReplayFile::fromArray(['compare' => ['ignore_cookies' => ['CAKEPHP', 'csrfToken', 'laravel_session', 'XSRF-TOKEN']], 'requests' => [['path' => '/']]]), [$legacy], [$laravel]));

        $lost = self::answer('<p>hi</p>', [self::HTML, ['Set-Cookie', 'laravel_session=z; path=/']]);
        self::assertNotSame([], ReplayComparison::differences($file, [$legacy], [$lost]), 'an app cookie that stops being set still differs');
    }

    public function test_ignore_cookies_takes_exact_names_only(): void
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('is not a cookie name (exact names only, no patterns)');
        $this->file(['ignore_cookies' => ['laravel_*; x']]);
    }

    public function test_the_compare_keys_stay_out_of_the_fingerprint(): void
    {
        $plain = $this->file();
        $strangler = $this->file(['preset' => 'strangler', 'ignore_cookies' => ['CAKEPHP'], 'silent_sign_in' => ['/_laravel/login']]);

        self::assertSame($plain->fingerprint, $strangler->fingerprint);
        self::assertSame($plain->requestsFingerprint, $strangler->requestsFingerprint);
        self::assertSame($plain->serveFingerprint, $strangler->serveFingerprint);
    }

    // The csrf preset and the strangler's CSRF field (5.3)

    public function test_the_csrf_preset_hides_cakes_csrf_token_field_and_meta(): void
    {
        $file = $this->file([], ['csrf']);
        $one = self::answer('<meta name="csrfToken" content="aaa"/><input type="hidden" name="_csrfToken" autocomplete="off" value="bbb"><meta content="ccc" name="csrf-token">');
        $two = self::answer('<meta name="csrfToken" content="xxx"/><input type="hidden" name="_csrfToken" autocomplete="off" value="yyy"><meta content="zzz" name="csrf-token">');

        self::assertSame([], ReplayComparison::differences($file, [$one], [$two]));
        self::assertContains('<meta name="csrfToken" content="<CSRF>"/><input type="hidden" name="_csrfToken" autocomplete="off" value="<CSRF>"><meta content="<CSRF>" name="csrf-token">', ReplayComparison::view($one, $file));
    }

    public function test_the_strangler_preset_hides_which_frameworks_csrf_field_a_form_carries(): void
    {
        $cake = self::answer("<form method=\"post\" action=\"/articles/add\"><div style=\"display:none;\"></div>\n<input type=\"hidden\" name=\"_csrfToken\" autocomplete=\"off\" value=\"c1\">\n<input name=\"title\"></form>");
        $laravel = self::answer("<form method=\"post\" action=\"/articles/add\"><div style=\"display:none;\"></div>\n<input type=\"hidden\" name=\"_token\" value=\"l1\" autocomplete=\"off\">\n<input name=\"title\"></form>");
        $slim = self::answer("<form method=\"post\" action=\"/articles/add\"><div style=\"display:none;\"></div>\n<input type=\"hidden\" name=\"csrf_name\" value=\"n\"> <input type=\"hidden\" name=\"csrf_value\" value=\"v\">\n<input name=\"title\"></form>");
        $strangler = $this->file(['preset' => 'strangler'], ['csrf']);

        self::assertSame([], ReplayComparison::differences($strangler, [$cake], [$laravel]));
        self::assertSame([], ReplayComparison::differences($strangler, [$slim], [$laravel]), 'Slim-Csrf\'s two fields count as one');
        self::assertNotSame([], ReplayComparison::differences($this->file([], ['csrf']), [$cake], [$laravel]), 'the default preset still sees the field name');

        $noToken = self::answer("<form method=\"post\" action=\"/articles/add\"><div style=\"display:none;\"></div>\n<input name=\"title\"></form>");
        self::assertNotSame([], ReplayComparison::differences($strangler, [$cake], [$noToken]), 'a form that lost its CSRF field still differs');
        $renamed = self::answer("<form method=\"post\" action=\"/articles/add\"><div style=\"display:none;\"></div>\n<input type=\"hidden\" name=\"_token\" value=\"l1\">\n<input name=\"headline\"></form>");
        self::assertNotSame([], ReplayComparison::differences($strangler, [$cake], [$renamed]), 'the form\'s own fields are still compared');
    }

    // The strangler compare preset (9.5)

    public function test_the_strangler_preset_compares_cookie_attributes_and_security_headers(): void
    {
        $headers = [self::HTML, ['Set-Cookie', 'remember=1; Path=/; HttpOnly; SameSite=Lax; expires=Thu, 01 Jan 2032 00:00:00 GMT'], ['X-Frame-Options', 'sameorigin'], ['Content-Security-Policy', "script-src 'self' 'nonce-abc123=='"], ['Access-Control-Allow-Methods', 'POST, get']];
        $view = ReplayComparison::view(self::answer('ok', $headers), $this->file(['preset' => 'strangler']));

        self::assertContains('cookie remember: httponly; path=/; samesite=lax', $view);
        self::assertContains('x-frame-options: SAMEORIGIN', $view);
        self::assertContains("content-security-policy: script-src 'self' 'nonce-<NONCE>'", $view);
        self::assertContains('access-control-allow-methods: get, post', $view);
        self::assertNotContains('x-frame-options: SAMEORIGIN', ReplayComparison::view(self::answer('ok', $headers), $this->file()), 'the default preset leaves them out');

        $looser = [self::HTML, ['Set-Cookie', 'remember=1; Path=/; SameSite=Lax'], ['Content-Security-Policy', "script-src 'self' 'nonce-zzz'"], ['Access-Control-Allow-Methods', 'GET,POST']];
        $differences = ReplayComparison::differences($this->file(['preset' => 'strangler']), [self::answer('ok', $headers)], [self::answer('ok', $looser)]);
        self::assertCount(1, $differences);
        self::assertContains('- cookie remember: httponly; path=/; samesite=lax', $differences[0]['diff']);
        self::assertContains('- x-frame-options: SAMEORIGIN', $differences[0]['diff']);
        self::assertNotContains("- content-security-policy: script-src 'self' 'nonce-<NONCE>'", $differences[0]['diff'], 'a new nonce is not a difference');
    }

    public function test_ignored_cookies_have_no_attribute_lines_either(): void
    {
        $file = $this->file(['preset' => 'strangler', 'ignore_cookies' => ['laravel_session']]);
        $view = ReplayComparison::view(self::answer('ok', [self::HTML, ['Set-Cookie', 'laravel_session=a; path=/; httponly; samesite=lax']]), $file);

        self::assertSame([], array_values(array_filter($view, static fn(string $l): bool => str_contains($l, 'laravel_session'))));
    }

    public function test_an_unknown_compare_preset_names_the_two(): void
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('"compare.preset" must be "default" or "strangler"');
        $this->file(['preset' => 'laravel']);
    }

    // X-Booster-Half (9.5): reported, never compared

    public function test_the_half_header_is_reported_in_the_transcript_and_never_compared(): void
    {
        $file = $this->file(['preset' => 'strangler']);
        $legacy = self::answer('<p>page</p>', [self::HTML, ['X-Booster-Half', 'legacy']]);
        $laravel = self::answer('<p>page</p>', [self::HTML, ['X-Booster-Half', 'laravel']]);

        self::assertSame('laravel', $laravel->half());
        self::assertNull(self::answer('x')->half());
        self::assertNull(self::answer('x', [['X-Booster-Half', 'both']])->half());
        self::assertSame([], ReplayComparison::differences($file, [$legacy], [$laravel]));
        self::assertStringContainsString("=== 01 requests: GET /\nanswered by: laravel\nstatus: 200", ReplayComparison::transcript($file, [$laravel], 't'));
    }

    public function test_the_replay_container_sets_booster_replay_so_the_dispatcher_names_the_half(): void
    {
        $fake = ReplayFake::runner([]);
        $runner = new ReplayRunner(new TargetImageRunner($fake, dirname(__DIR__) . '/docker'), $fake);
        $command = $runner->runCommand('/app', '/scratch', 'img', ReplayFile::fromArray(['requests' => [['path' => '/']]]));

        self::assertContains('BOOSTER_REPLAY=1', $command);
    }

    public function test_a_check_reports_which_half_answered_each_request(): void
    {
        $file = ReplayFile::fromArray(['compare' => ['preset' => 'strangler'], 'requests' => [['path' => '/'], ['path' => '/articles'], ['path' => '/about']]]);
        $baseline = [[200, 'home', [self::HTML]], [200, 'list', [self::HTML]], [200, 'about', [self::HTML]]];
        $mounted = [[200, 'home', [self::HTML, ['X-Booster-Half', 'legacy']]], [200, 'list', [self::HTML, ['X-Booster-Half', 'laravel']]], [200, 'about', [self::HTML, ['X-Booster-Half', 'legacy']]]];
        $gate = $this->gate([$baseline, $mounted], $file);
        $gate->record('8.5');

        $outcome = $gate->check('8.5', 'step-1');

        self::assertSame(ReplayOutcome::PASSED, $outcome->status);
        self::assertContains('Answered by: legacy 2, laravel 1 (laravel: 02).', $outcome->lines);
        self::assertSame([2], $outcome->answeredBy('laravel'));
        self::assertSame([1, 3], $outcome->answeredBy('legacy'));
        self::assertSame(['1' => 'legacy', '2' => 'laravel', '3' => 'legacy'], (array) $outcome->toArray()['halves']);
    }

    // Signed-in sequences (5.4)

    public function test_a_first_request_that_lost_its_sign_in_says_the_sequence_must_sign_in_first(): void
    {
        $file = ReplayFile::fromArray(['sequences' => [
            ['name' => 'guest', 'requests' => [['path' => '/']]],
            ['name' => 'staff', 'requests' => [['path' => '/articles'], ['path' => '/articles/1']]],
        ]]);
        $baseline = [[200, 'home'], [200, 'list'], [200, 'one']];
        $replay = [[200, 'home'], [302, '', [['Location', 'http://127.0.0.1:8765/_laravel/login'], ['X-Booster-Half', 'laravel']]], [302, '', [['Location', '/users/login?redirect=%2Farticles%2F1']]]];
        $gate = $this->gate([$baseline, $replay], $file);
        $gate->record('8.5');

        $outcome = $gate->check('8.5', 'step-2');
        $text = implode("\n", $outcome->lines);

        self::assertSame(ReplayOutcome::DIFFERS, $outcome->status);
        self::assertStringContainsString('Signed-in sequence: 02 staff: GET /articles is the first request of sequence "staff" and now answers the redirect to sign-in (Location: /_laravel/login) (answered by laravel) where the baseline answered 200. A sequence must sign in through a legacy request before its first moved route', $text);
        self::assertStringContainsString(ReplayGate::SIGNED_IN_GUIDE, $text);
        self::assertStringNotContainsString('03 staff', substr($text, (int) strpos($text, 'Signed-in sequence')), 'a later legacy request gets no sign-in line');
    }

    public function test_a_later_request_laravel_answered_with_sign_in_names_the_laravel_halfs_own_session(): void
    {
        $file = ReplayFile::fromArray(['sequences' => [['name' => 'staff', 'requests' => [['method' => 'POST', 'path' => '/login', 'form' => ['user' => 'fake']], ['path' => '/articles']]]]]);
        $baseline = [[302, '', [['Location', '/articles']]], [200, 'list']];
        $replay = [[302, '', [['Location', '/articles']]], [302, '', [['Location', '/_laravel/login'], ['X-Booster-Half', 'laravel']]]];
        $gate = $this->gate([$baseline, $replay], $file);
        $gate->record('8.5');

        $text = implode("\n", $gate->check('8.5', 'step-2')->lines);

        self::assertStringContainsString('Signed-in sequence: 02 staff: GET /articles moved to Laravel and now answers the redirect to sign-in (Location: /_laravel/login) where the baseline answered 200. Each half keeps its own session', $text);
        self::assertStringContainsString('compare.silent_sign_in', $text);
    }

    public function test_step_0_may_move_a_listed_sign_in_hand_off_to_cas_and_everything_else_is_compared(): void
    {
        $file = ReplayFile::fromArray(['requests' => [['path' => '/'], ['path' => '/auth/login'], ['path' => '/reports']]]);
        $baseline = [[200, 'home'], [302, '', [['Location', 'https://login.wayne.edu/?need_validuser=1']]], [302, '', [['Location', 'https://login.wayne.edu/?need_validuser=1']]]];
        $cas = [['Location', 'https://cas.replay.invalid/cas/login?service=http%3A%2F%2F127.0.0.1%3A8765%2Fauth%2Flogin']];
        $gate = $this->gate([$baseline, [[200, 'home'], [302, '', $cas], [302, '', $cas]], [[200, 'home'], [302, '', $cas], [302, '', [...$cas, ['Set-Cookie', 'PHPSESSID=x; path=/']]]], [[200, 'home'], [302, '', $cas], [302, '', $cas]]], $file);
        $gate->record('8.5');

        $both = $gate->check('8.5', 'step-0', signInChanges: ['/auth/login', '/reports']);
        self::assertSame(ReplayOutcome::PASSED, $both->status, implode("\n", $both->lines));
        self::assertSame('Replay passed: 1 of 3 answers match the baseline from PHP 8.5, and 2 changed only where this step moves the sign-in on purpose:', $both->lines[0]);
        self::assertSame('  02 requests: GET /auth/login: Location https://login.wayne.edu/?need_validuser=1 -> https://cas.replay.invalid/cas/login?service=http%3A%2F%2F127.0.0.1%3A8765%2Fauth%2Flogin', $both->lines[1]);

        $cookie = $gate->check('8.5', 'step-0b', signInChanges: ['/auth/login', '/reports']);
        self::assertSame(ReplayOutcome::DIFFERS, $cookie->status, 'a new cookie is more than the Location');
        self::assertStringContainsString('1 of 3 answers differ', $cookie->lines[0]);
        self::assertContains('Changed only where this step moves the sign-in on purpose (accepted):', $cookie->lines);

        $unlisted = $gate->check('8.5', 'step-0c', signInChanges: ['/auth/login']);
        self::assertSame(ReplayOutcome::DIFFERS, $unlisted->status, 'a path the step did not list is compared as always');
    }

    public function test_sign_in_redirects_are_told_from_other_redirects(): void
    {
        foreach (['/_laravel/login', '/users/login?redirect=%2F', '/auth/login', 'https://cas.example.edu/cas/login?service=x', '/sign-in', '/login'] as $to) {
            self::assertTrue(ReplayGate::isSignInRedirect(self::answer('', [['Location', $to]], 302)), $to);
        }
        foreach (['/articles', '/blogin-stats', '/catalog'] as $to) {
            self::assertFalse(ReplayGate::isSignInRedirect(self::answer('', [['Location', $to]], 302)), $to);
        }
        self::assertFalse(ReplayGate::isSignInRedirect(self::answer('', [['Location', '/login']], 200)));
    }

    // The strangler requires the replay (5.5)

    public function test_usable_for_strangler_refuses_no_file_todos_one_redirect_and_errors(): void
    {
        $app = $this->tempDir();
        self::assertStringContainsString('the strangler requires the replay, and there is no booster-replay.json', (string) ReplayFile::usableForStrangler($app));

        file_put_contents($app . '/booster-replay.json', '{"requests": [{"path": "/", "todo": "fill me"}]}');
        self::assertStringContainsString('still has 1 todo item to fill in', (string) ReplayFile::usableForStrangler($app));

        file_put_contents($app . '/booster-replay.json', '{"requests": [{"path": "/"}], "typo": 1}');
        self::assertStringContainsString('the strangler requires a replay that parses: booster-replay.json: unknown key "typo"', (string) ReplayFile::usableForStrangler($app));

        file_put_contents($app . '/booster-replay.json', '{"requests": [{"path": "/"}, {"path": "/a"}]}');
        self::assertNull(ReplayFile::usableForStrangler($app));
        $redirect = [self::answer('', [['Location', '/login']], 302, 1), self::answer('', [['Location', '/login']], 302, 2)];
        self::assertStringContainsString('every answer in the replay baseline is the same redirect (Location: /login)', (string) ReplayFile::usableForStrangler($app, $redirect));
        $fatal = [self::answer('Fatal error: Uncaught Error: x', [self::HTML], 200, 1), self::answer('Fatal error: Uncaught Error: x', [self::HTML], 200, 2)];
        self::assertStringContainsString('All 2 recorded answers are errors in the replay baseline', (string) ReplayFile::usableForStrangler($app, $fatal));
        self::assertNull(ReplayFile::usableForStrangler($app, [self::answer('home', [self::HTML], 200, 1), self::answer('', [['Location', '/login']], 302, 2)]));

        file_put_contents($app . '/other.json', '{"requests": [{"path": "/"}]}');
        self::assertNull(ReplayFile::usableForStrangler($app, null, 'other.json'));
    }

    // The "csrf": true request flag (5.3)

    public function test_the_csrf_flag_goes_on_writes_after_the_first_request(): void
    {
        $file = ReplayFile::fromArray(['sequences' => [['name' => 'forms', 'requests' => [['path' => '/articles/add'], ['method' => 'POST', 'path' => '/articles/add', 'form' => ['title' => 'x'], 'csrf' => true], ['method' => 'POST', 'path' => '/api', 'json' => ['a' => 1], 'csrf' => true], ['method' => 'DELETE', 'path' => '/articles/1', 'csrf' => true]]]]]);

        self::assertSame('form', $file->requests[1]->toClientArray()['csrf']);
        self::assertSame('header', $file->requests[2]->toClientArray()['csrf']);
        $bare = $file->requests[3]->toClientArray();
        self::assertSame('form', $bare['csrf'], 'a write with no body posts a form holding only the token');
        self::assertContains(['Content-Type', 'application/x-www-form-urlencoded'], $bare['headers']);
        self::assertArrayNotHasKey('csrf', $file->requests[0]->toClientArray(), 'no flag, no key: older recordings keep their fingerprint');
    }

    public function test_the_csrf_flag_is_refused_where_it_cannot_work(): void
    {
        $cases = [
            'the first request of sequence "requests"' => ['requests' => [['method' => 'POST', 'path' => '/x', 'csrf' => true]]],
            'not GET' => ['requests' => [['path' => '/'], ['path' => '/x', 'csrf' => true]]],
            '"csrf" must be true or false' => ['requests' => [['path' => '/'], ['method' => 'POST', 'path' => '/x', 'csrf' => 'yes']]],
        ];
        foreach ($cases as $message => $data) {
            try {
                ReplayFile::fromArray($data);
                self::fail('expected a refusal: ' . $message);
            } catch (ToolError $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function test_silent_sign_in_takes_paths_and_reaches_the_client_plan(): void
    {
        self::assertSame(['/_laravel/login'], $this->file(['silent_sign_in' => ['/_laravel/login']])->clientPlan()['silent_sign_in']);
        self::assertArrayNotHasKey('silent_sign_in', $this->file()->clientPlan());

        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('must be a path on the app, such as /_laravel/login');
        $this->file(['silent_sign_in' => ['https://cas.example.edu/login']]);
    }

    /**
     * The real client against PHP's built-in server: the CSRF field from the
     * previous answer as a form field (with CakePHP's form protection fields)
     * or a header, a page with none, and the silent sign-in round trip.
     */
    public function test_the_client_takes_the_csrf_field_from_the_previous_answer_and_follows_silent_sign_in(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is not available.');
        }
        $app = $this->tempDir();
        file_put_contents($app . '/router.php', <<<'PHP'
            <?php
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            if ($path === '/form') {
                echo '<form method="post"><input type="hidden" name="_csrfToken" autocomplete="off" value="tok&amp;1"><input type="hidden" name="_Token[fields]" value="f1"><input name="title"></form>';
            } elseif ($path === '/save') {
                header('Content-Type: application/json');
                echo json_encode(['post' => $_POST, 'header' => isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : null]);
            } elseif ($path === '/meta') {
                echo '<head><meta content="m-1" name="csrf-token"></head>';
            } elseif ($path === '/api') {
                header('Content-Type: application/json');
                echo json_encode(['header' => isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : null, 'body' => file_get_contents('php://input')]);
            } elseif ($path === '/plain') {
                echo '<form method="post"><input name="title"></form>';
            } elseif ($path === '/secret') {
                if (!isset($_COOKIE['lsess']) || $_COOKIE['lsess'] !== 'in') {
                    header('Location: /_laravel/login?return=%2Fsecret', true, 302);
                } else {
                    header('X-Booster-Half: laravel');
                    echo 'the secret page';
                }
            } elseif ($path === '/_laravel/login') {
                setcookie('lsess', 'in', 0, '/');
                header('Location: http://127.0.0.1:' . $_SERVER['SERVER_PORT'] . (isset($_GET['back']) ? $_GET['back'] : '/secret'), true, 302);
            } elseif ($path === '/list') {
                if (!isset($_COOKIE['lsess'])) {
                    header('Location: /_laravel/login?back=' . rawurlencode('/list?a=2&b=x%20y'), true, 302);
                } else {
                    echo 'list ' . $_SERVER['QUERY_STRING'];
                }
            } elseif ($path === '/elsewhere') {
                header('Location: /other', true, 302);
            }
            PHP);
        $file = ReplayFile::fromArray([
            'compare' => ['silent_sign_in' => ['/_laravel/login']],
            'sequences' => [
                ['name' => 'form', 'requests' => [['path' => '/form'], ['method' => 'POST', 'path' => '/save', 'form' => ['title' => 'Hi', '_csrfToken' => 'stale'], 'csrf' => true]]],
                ['name' => 'json', 'requests' => [['path' => '/meta'], ['method' => 'POST', 'path' => '/api', 'json' => ['a' => 1], 'csrf' => true]]],
                ['name' => 'plain', 'requests' => [['path' => '/plain'], ['method' => 'POST', 'path' => '/save', 'form' => ['title' => 'Hi'], 'csrf' => true]]],
                ['name' => 'signin', 'requests' => [['path' => '/secret'], ['path' => '/elsewhere'], ['path' => '/secret']]],
                ['name' => 'sorted', 'requests' => [['path' => '/list', 'query' => 'b=x+y&a=2']]],
            ],
        ]);
        $answers = $this->runClient($app, $file);

        self::assertCount(10, $answers);
        $saved = json_decode($answers[1]->body, true);
        self::assertSame(['title' => 'Hi', '_csrfToken' => 'tok&1', '_Token' => ['fields' => 'f1']], $saved['post'], 'the stale token was replaced, the entity decoded');
        self::assertSame(['csrf: sent _csrfToken, _Token[fields] from the previous answer'], $answers[1]->actions);

        $api = json_decode($answers[3]->body, true);
        self::assertSame('m-1', $api['header']);
        self::assertSame('{"a":1}', $api['body']);

        self::assertSame(['title' => 'Hi'], json_decode($answers[5]->body, true)['post']);
        self::assertSame([], $answers[5]->notes, 'a page with no CSRF field is not a failure of the request file');
        self::assertSame(['csrf: the previous answer has no CSRF field, sent without one'], $answers[5]->actions);

        self::assertSame(200, $answers[6]->status);
        self::assertSame('the secret page', $answers[6]->body);
        self::assertSame('laravel', $answers[6]->half());
        self::assertSame(['sign-in: followed 302 to GET /_laravel/login?return=%2Fsecret', 'sign-in: followed 302 to GET /secret'], $answers[6]->actions);
        self::assertSame(302, $answers[7]->status, 'a redirect anywhere else is the answer');
        self::assertSame([], $answers[7]->actions);
        self::assertSame('the secret page', $answers[8]->body, 'the sequence keeps the Laravel half\'s session');
        self::assertSame([], $answers[8]->actions);
        self::assertSame('list a=2&b=x%20y', $answers[9]->body, 'the way back may sort and re-encode the query');
        self::assertCount(2, $answers[9]->actions);
    }

    /**
     * @return list<ReplayAnswer>
     */
    private function runClient(string $app, ReplayFile $file): array
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($probe, $errstr);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $app, $app . '/router.php'], [1 => ['file', $app . '/server.log', 'w'], 2 => ['file', $app . '/server.log', 'a']], $pipes);
        self::assertIsResource($server);
        try {
            file_put_contents($app . '/plan.json', json_encode($file->clientPlan(10), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $client = proc_open([PHP_BINARY, dirname(__DIR__) . '/resources/replay/client.php', $app . '/plan.json', $app . '/result.json', (string) $port], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $clientPipes);
            self::assertIsResource($client);
            $output = (string) stream_get_contents($clientPipes[1]) . (string) stream_get_contents($clientPipes[2]);
            self::assertSame(0, proc_close($client), $output . (string) @file_get_contents($app . '/server.log'));
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
        $result = json_decode((string) file_get_contents($app . '/result.json'), true);
        self::assertIsArray($result);
        self::assertTrue($result['started']);

        return array_map(static fn(array $a): ReplayAnswer => ReplayAnswer::fromArray($a), $result['answers']);
    }

    /**
     * @param list<list<array{0: int, 1: string, 2?: list<array{string, string}>}>|string> $replays
     */
    private function gate(array $replays, ReplayFile $file): ReplayGate
    {
        $fake = ReplayFake::runner($replays);
        $runner = new ReplayRunner(new TargetImageRunner($fake, dirname(__DIR__) . '/docker'), $fake);

        return new ReplayGate($runner, $file, $this->tempDir(), $this->tempDir(), null);
    }
}
