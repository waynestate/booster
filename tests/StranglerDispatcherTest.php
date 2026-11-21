<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Strangler\RouteTable;
use Waynestate\Booster\Strangler\Unit;

/**
 * The dispatcher (resources/strangler/dispatcher.php) served for real by
 * PHP's built-in server through Booster's replay router, the way the replay
 * serves a mounted app. Both halves are small fakes that report which
 * autoloader loaded and which server variables they saw, so every test can
 * check that one request boots exactly one framework.
 */
final class StranglerDispatcherTest extends TestCase
{
    /** @var resource|null */
    private $server = null;

    private int $port = 0;

    private string $app = '';

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        parent::tearDown();
    }

    /** A mounted app: dispatcher, a fake Laravel root, a fake legacy root, and one moved route. */
    private function mountedApp(string $router = 'replay'): void
    {
        $app = $this->tempDir();
        $this->app = $app;
        $dispatcher = (string) file_get_contents(dirname(__DIR__) . '/resources/strangler/dispatcher.php');
        $dispatcher = str_replace("\$legacyDocroot = 'legacy/public';", "\$legacyDocroot = 'legacy/webroot';", $dispatcher);
        $files = [
            'public/index.php' => $dispatcher,
            // Laravel's half: its autoloader, and an app whose handleRequest reports.
            'vendor/autoload.php' => "<?php\nnamespace {\n    function booster_test_laravel_loaded() {}\n}\nnamespace Illuminate\\Http {\n    final class Request { public static function capture(): self { return new self(); } }\n}\n",
            'bootstrap/app.php' => <<<'PHP'
                <?php
                return new class {
                    public function handleRequest($request): void
                    {
                        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
                        if (str_ends_with($path, '/nope')) {
                            http_response_code(404);
                        }
                        if (str_ends_with($path, '/boom')) {
                            http_response_code(500);
                        }
                        header('Content-Type: application/json');
                        echo json_encode(['half' => 'laravel', 'files' => get_included_files(), 'laravel' => function_exists('booster_test_laravel_loaded'), 'legacy' => function_exists('booster_test_legacy_loaded'), 'script' => $_SERVER['SCRIPT_NAME'], 'start' => defined('LARAVEL_START'), 'unit' => $_SERVER['BOOSTER_MOVED_UNIT'] ?? null]);
                    }
                };
                PHP,
            // The legacy half: its own autoloader and front controller.
            'legacy/vendor/autoload.php' => "<?php\nfunction booster_test_legacy_loaded() {}\n",
            'legacy/webroot/index.php' => <<<'PHP'
                <?php
                require dirname(__DIR__) . '/vendor/autoload.php';
                $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
                if (str_ends_with($path, '/nope')) {
                    http_response_code(404);
                }
                if (str_ends_with($path, '/boom')) {
                    http_response_code(500);
                }
                header('Content-Type: application/json');
                echo json_encode(['half' => 'legacy', 'files' => get_included_files(), 'laravel' => function_exists('booster_test_laravel_loaded'), 'legacy' => function_exists('booster_test_legacy_loaded'), 'script' => $_SERVER['SCRIPT_NAME'], 'filename' => $_SERVER['SCRIPT_FILENAME'], 'self' => $_SERVER['PHP_SELF'], 'docroot' => $_SERVER['DOCUMENT_ROOT'], 'cwd' => getcwd(), 'uri' => $_SERVER['REQUEST_URI'], 'globals' => array_values(array_filter(array_keys($GLOBALS), fn ($k) => str_starts_with($k, 'booster'))), 'marks' => array_values(array_filter(array_keys($_SERVER), fn ($k) => preg_match('/BOOSTER_(LEGACY|MOVED)/', $k) === 1))]);
                PHP,
            'legacy/webroot/raw.php' => "<?php\necho json_encode(['half' => 'raw', 'script' => \$_SERVER['SCRIPT_NAME'], 'cwd' => getcwd()]);\n",
            'legacy/webroot/css/app.css' => "body { color: #0c5449; }\n",
            'legacy/webroot/build/legacy.js' => "console.log('legacy');\n",
            'legacy/webroot/data.blob' => "blob\n",
            'legacy/webroot/.env' => "SECRET=1\n",
            'legacy/webroot/.well-known/security.txt' => "Contact: mailto:security@example.edu\n",
            'legacy/composer.json' => "{}\n",
            'public/robots.txt' => "User-agent: *\n",
        ];
        foreach ($files as $path => $contents) {
            if (!is_dir(dirname($app . '/' . $path))) {
                mkdir(dirname($app . '/' . $path), 0775, true);
            }
            file_put_contents($app . '/' . $path, $contents);
        }
        $table = RouteTable::load($app);
        $table->add(new Unit('GET /users/{id}', 'route', ['GET'], 'users/{id:\d+}', 'users.view', 'stateless', false, 'app/routes.php', 3));
        $table->add(new Unit('FORM /notes/add', 'form', ['GET', 'POST'], 'notes/add', null, 'write', false, 'app/routes.php', 4, [
            new Unit('GET /notes/add', 'route', ['GET'], 'notes/add', null, 'write', false, 'app/routes.php', 4),
            new Unit('POST /notes', 'route', ['POST'], 'notes', null, 'write', false, 'app/routes.php', 5),
        ]));
        $table->add(new Unit('GET /logout/', 'route', ['GET'], 'logout/', null, 'stateless', false, 'app/routes.php', 6));
        $table->save();

        $routerFile = dirname(__DIR__) . '/resources/replay/router.php';
        if ($router === 'subpath') {
            // A sub-path deploy (Apache serving the app under /portal/): the
            // front controller's SCRIPT_NAME carries the base.
            $routerFile = $app . '/subpath-router.php';
            file_put_contents($routerFile, "<?php\n\$_SERVER['SCRIPT_NAME'] = '/portal/index.php';\n\$_SERVER['PHP_SELF'] = '/portal/index.php';\n\$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/public/index.php';\nchdir(__DIR__ . '/public');\nrequire __DIR__ . '/public/index.php';\n");
        }
        if ($router === 'apache') {
            // What Apache does with a rule the mount moved into public/.htaccess:
            // the rule's E= flag, REDIRECT_ prefixed after the internal redirect.
            $routerFile = $app . '/apache-router.php';
            file_put_contents($routerFile, "<?php\nforeach (['TARGET', 'FILE'] as \$k) {\n    if (isset(\$_SERVER['HTTP_X_TEST_' . \$k])) {\n        \$_SERVER['REDIRECT_BOOSTER_LEGACY_' . \$k] = \$_SERVER['HTTP_X_TEST_' . \$k];\n    }\n}\n\$_SERVER['SCRIPT_NAME'] = '/index.php';\n\$_SERVER['PHP_SELF'] = '/index.php';\n\$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/public/index.php';\nchdir(__DIR__ . '/public');\nrequire __DIR__ . '/public/index.php';\n");
        }
        $this->start($app, $routerFile);
    }

    private function start(string $app, string $router): void
    {
        for ($try = 0; $try < 5; $try++) {
            $this->port = random_int(20000, 45000);
            $env = ['BOOSTER_REPLAY_FRONT' => 'index.php', 'BOOSTER_REPLAY' => '1', 'PATH' => (string) getenv('PATH')];
            $this->server = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-S', '127.0.0.1:' . $this->port, '-t', $app . '/public', $router], [0 => ['pipe', 'r'], 1 => ['file', $app . '/server.log', 'a'], 2 => ['file', $app . '/server.log', 'a']], $pipes, $app, $env);
            for ($wait = 0; $wait < 50; $wait++) {
                $socket = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.1);
                if (is_resource($socket)) {
                    fclose($socket);

                    return;
                }
                usleep(50000);
            }
            if (is_resource($this->server)) {
                proc_terminate($this->server);
                proc_close($this->server);
            }
        }
        self::fail('php -S did not start: ' . @file_get_contents($app . '/server.log'));
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string, json: array<string, mixed>|null}
     */
    private function request(string $method, string $path, array $headers = []): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $context = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'follow_location' => 0, 'header' => implode("\r\n", $lines), 'timeout' => 20]]);
        // fopen's wrapper_data, not $http_response_header (deprecated on PHP 8.5).
        $stream = @fopen('http://127.0.0.1:' . $this->port . $path, 'r', false, $context);
        $body = '';
        $raw = [];
        if (is_resource($stream)) {
            $raw = stream_get_meta_data($stream)['wrapper_data'] ?? [];
            $body = (string) stream_get_contents($stream);
            fclose($stream);
        }
        $raw = is_array($raw) ? array_values(array_filter($raw, 'is_string')) : [];
        $status = (int) (explode(' ', $raw[0] ?? '')[1] ?? 0);
        $out = [];
        foreach (array_slice($raw, 1) as $line) {
            [$name, $value] = array_map('trim', explode(':', $line, 2)) + [1 => ''];
            $out[strtolower($name)] = $value;
        }
        $json = json_decode($body, true);

        return ['status' => $status, 'headers' => $out, 'body' => $body, 'json' => is_array($json) ? $json : null];
    }

    private static function assertOnlyLegacy(array $answer, string $why): void
    {
        self::assertSame('legacy', $answer['headers']['x-booster-half'] ?? null, $why);
        self::assertSame('legacy', $answer['json']['half'] ?? null, $why . ': ' . $answer['body']);
        self::assertTrue($answer['json']['legacy'], $why . ': the legacy autoloader loaded');
        self::assertFalse($answer['json']['laravel'], $why . ': Laravel\'s autoloader never loads on a legacy request');
        foreach ($answer['json']['files'] as $file) {
            self::assertStringNotContainsString('/bootstrap/app.php', str_replace('\\', '/', $file), $why);
        }
    }

    private static function assertOnlyLaravel(array $answer, string $why): void
    {
        self::assertSame('laravel', $answer['headers']['x-booster-half'] ?? null, $why);
        self::assertSame('laravel', $answer['json']['half'] ?? null, $why . ': ' . $answer['body']);
        self::assertTrue($answer['json']['laravel'], $why);
        self::assertFalse($answer['json']['legacy'], $why . ': the legacy autoloader never loads on a Laravel request');
        self::assertTrue($answer['json']['start'], $why . ': the stock front controller defines LARAVEL_START');
        foreach ($answer['json']['files'] as $file) {
            self::assertStringNotContainsString('/legacy/', str_replace('\\', '/', $file), $why);
        }
    }

    public function test_unmoved_paths_go_to_the_legacy_front_controller_with_the_server_variables_it_saw_before(): void
    {
        $this->mountedApp();
        $home = $this->request('GET', '/?x=1');
        self::assertSame(200, $home['status']);
        self::assertOnlyLegacy($home, 'GET /');
        self::assertSame('/index.php', $home['json']['script']);
        self::assertSame('/index.php', $home['json']['self']);
        self::assertSame('/?x=1', $home['json']['uri'], 'the request URI is untouched');
        $webroot = (string) realpath($this->app . '/legacy/webroot');
        self::assertSame($webroot . DIRECTORY_SEPARATOR . 'index.php', $home['json']['filename']);
        self::assertSame($webroot, $home['json']['docroot']);
        self::assertSame($webroot, $home['json']['cwd']);
        self::assertSame([], $home['json']['globals'], 'the dispatcher leaves no variables behind for legacy');

        self::assertOnlyLegacy($this->request('GET', '/users/abc'), 'a path the moved route\'s constraint refuses');
        self::assertOnlyLegacy($this->request('GET', '/users/7/edit'), 'a longer path');
        self::assertOnlyLegacy($this->request('OPTIONS', '/users/7'), 'OPTIONS stays with legacy\'s catch-all');
        self::assertOnlyLegacy($this->request('POST', '/users/7'), 'a method the moved unit does not declare (legacy answers its 405)');
        self::assertOnlyLegacy($this->request('GET', '/.env'), 'a dotfile is never served');
        self::assertOnlyLegacy($this->request('GET', '/css/../../composer.json'), 'nothing outside the legacy docroot is served');
    }

    public function test_moved_routes_and_laravel_prefixes_go_to_laravel_only(): void
    {
        $this->mountedApp();
        self::assertOnlyLaravel($this->request('GET', '/users/7'), 'a moved route');
        $head = $this->request('HEAD', '/users/7');
        self::assertSame('laravel', $head['headers']['x-booster-half'] ?? null, 'HEAD counts as GET');
        self::assertOnlyLaravel($this->request('GET', '/notes/add'), 'a moved form\'s GET');
        self::assertOnlyLaravel($this->request('POST', '/notes'), 'the same form\'s POST moved with it');
        self::assertOnlyLegacy($this->request('GET', '/notes'), 'the form\'s POST path answers GET in legacy');
        self::assertOnlyLaravel($this->request('GET', '/_laravel/up'), 'a Laravel-owned prefix');
        self::assertOnlyLaravel($this->request('GET', '/_laravel'), 'the prefix itself');
        self::assertOnlyLegacy($this->request('GET', '/_laravelish'), 'only the prefix, not a lookalike');
        self::assertOnlyLaravel($this->request('GET', '/build/assets/app.js'), '/build/ with no legacy file there');
        self::assertSame("console.log('legacy');\n", $this->request('GET', '/build/legacy.js')['body'], '/build/ where legacy has the file');
    }

    public function test_404_and_500_stay_in_the_half_that_answered(): void
    {
        $this->mountedApp();
        $legacy404 = $this->request('GET', '/nope');
        self::assertSame(404, $legacy404['status']);
        self::assertOnlyLegacy($legacy404, 'legacy 404');
        $legacy500 = $this->request('GET', '/boom');
        self::assertSame(500, $legacy500['status']);
        self::assertOnlyLegacy($legacy500, 'legacy 500');
        $laravel404 = $this->request('GET', '/_laravel/nope');
        self::assertSame(404, $laravel404['status']);
        self::assertOnlyLaravel($laravel404, 'Laravel 404');
        $laravel500 = $this->request('GET', '/_laravel/boom');
        self::assertSame(500, $laravel500['status']);
        self::assertOnlyLaravel($laravel500, 'Laravel 500');
    }

    public function test_legacy_static_files_are_streamed_with_validators(): void
    {
        $this->mountedApp();
        $css = $this->request('GET', '/css/app.css');
        self::assertSame(200, $css['status']);
        self::assertSame("body { color: #0c5449; }\n", $css['body']);
        self::assertSame('text/css; charset=UTF-8', $css['headers']['content-type'] ?? null);
        self::assertSame('legacy', $css['headers']['x-booster-half'] ?? null);
        $etag = $css['headers']['etag'] ?? '';
        self::assertMatchesRegularExpression('/^"[0-9a-f]+-[0-9a-f]+"$/', $etag);
        self::assertNotSame('', $css['headers']['last-modified'] ?? '');

        $again = $this->request('GET', '/css/app.css', ['If-None-Match' => $etag]);
        self::assertSame(304, $again['status']);
        self::assertSame('', $again['body']);
        $since = $this->request('GET', '/css/app.css', ['If-Modified-Since' => $css['headers']['last-modified']]);
        self::assertSame(304, $since['status']);
        $head = $this->request('HEAD', '/css/app.css');
        self::assertSame(200, $head['status']);
        self::assertSame('', $head['body']);
        $post = $this->request('POST', '/css/app.css');
        self::assertSame(200, $post['status'], 'php -S and Apache both answer any method on a static file');
        self::assertSame("body { color: #0c5449; }\n", $post['body']);
        $js = $this->request('GET', '/build/legacy.js');
        self::assertSame('application/javascript', $js['headers']['content-type'] ?? null, 'php -S\'s own type, no charset');
        $unknown = $this->request('GET', '/data.blob');
        self::assertSame("blob\n", $unknown['body']);
        self::assertArrayNotHasKey('content-type', $unknown['headers'], 'php -S sends no type for an unknown extension');

        self::assertSame("Contact: mailto:security@example.edu\n", $this->request('GET', '/.well-known/security.txt')['body'], '.well-known/ is the one dotted folder served');
        self::assertSame("User-agent: *\n", $this->request('GET', '/robots.txt')['body'], 'a real file under public/ is served before the dispatcher runs');
    }

    public function test_a_legacy_php_script_runs_as_its_own_script(): void
    {
        $this->mountedApp();
        $raw = $this->request('GET', '/raw.php');
        self::assertSame('raw', $raw['json']['half'] ?? null, $raw['body']);
        self::assertSame('/raw.php', $raw['json']['script']);
        self::assertSame((string) realpath($this->app . '/legacy/webroot'), $raw['json']['cwd']);
    }

    public function test_the_base_path_of_a_sub_path_deploy_is_stripped_and_kept_for_legacy(): void
    {
        $this->mountedApp('subpath');
        self::assertOnlyLaravel($this->request('GET', '/portal/users/7'), 'a moved route under /portal');
        self::assertOnlyLaravel($this->request('GET', '/portal/_laravel/up'), 'the Laravel prefix under /portal');
        $home = $this->request('GET', '/portal/');
        self::assertOnlyLegacy($home, 'legacy under /portal');
        self::assertSame('/portal/index.php', $home['json']['script'], 'legacy derives the same base as before');
        self::assertSame("body { color: #0c5449; }\n", $this->request('GET', '/portal/css/app.css')['body']);
    }

    public function test_an_encoded_slash_never_splits_a_segment(): void
    {
        $this->mountedApp();
        self::assertOnlyLegacy($this->request('GET', '/users/7%2F8'), 'users/7%2F8 is users/7/8 to Laravel and the legacy router alike, so it stays legacy\'s');
        self::assertOnlyLaravel($this->request('GET', '/users/%37'), 'other escapes are decoded (%37 is 7)');
    }

    public function test_a_moved_unit_is_named_for_laravel(): void
    {
        $this->mountedApp();
        $moved = $this->request('GET', '/users/7');
        self::assertSame('GET /users/{id}', $moved['json']['unit'] ?? null, 'bootstrap/app.php skips TrimStrings and ConvertEmptyStringsToNull on it');
        self::assertNull($this->request('GET', '/_laravel/up')['json']['unit'] ?? null, 'Laravel\'s own pages are not moved units');
    }

    public function test_a_trailing_slash_route_moves_only_with_its_slash(): void
    {
        $this->mountedApp();
        self::assertOnlyLaravel($this->request('GET', '/logout/'), 'the route as FastRoute declared it');
        $slashless = $this->request('GET', '/logout');
        self::assertOnlyLegacy($slashless, 'Laravel would trim the slash and answer; the table keeps /logout legacy\'s');
        $nope = $this->request('GET', '/nope/');
        self::assertOnlyLegacy($nope, 'an unmoved slash path');
    }

    public function test_a_legacy_rule_moved_into_public_htaccess_keeps_its_request_with_legacy(): void
    {
        $this->mountedApp('apache');
        $css = $this->request('GET', '/users/7', ['X-Test-Target' => 'css/app.css']);
        self::assertSame("body { color: #0c5449; }\n", $css['body'], 'the rule\'s target is served, not the moved route');
        self::assertSame('legacy', $css['headers']['x-booster-half'] ?? null);
        $raw = $this->request('GET', '/_laravel/up', ['X-Test-Target' => 'raw.php']);
        self::assertSame('raw', $raw['json']['half'] ?? null, $raw['body']);
        self::assertSame('/raw.php', $raw['json']['script']);
        $front = $this->request('GET', '/users/7', ['X-Test-Target' => 'index.php']);
        self::assertOnlyLegacy($front, 'a target of the legacy front controller');
        self::assertSame([], $front['json']['marks'], 'legacy never sees the mount\'s variables');
        self::assertSame(404, $this->request('GET', '/users/7', ['X-Test-Target' => 'css/none.css'])['status'], 'a missing target is a 404, as Apache answered it');
        self::assertSame(404, $this->request('GET', '/users/7', ['X-Test-Target' => '../composer.json'])['status'], 'nothing outside the legacy docroot');
        self::assertSame("console.log('legacy');\n", $this->request('GET', '/build/legacy.js', ['X-Test-File' => '1'])['body'], 'a pass-through serves the legacy file at the path');
        self::assertSame(404, $this->request('GET', '/users/7', ['X-Test-File' => '1'])['status'], 'a pass-through never reaches a front controller');
        self::assertOnlyLaravel($this->request('GET', '/users/7'), 'without the rule, the moved route');
    }

    public function test_without_the_replay_flag_no_half_header_is_sent(): void
    {
        $app = $this->tempDir();
        mkdir($app . '/public', 0775, true);
        mkdir($app . '/legacy/public', 0775, true);
        copy(dirname(__DIR__) . '/resources/strangler/dispatcher.php', $app . '/public/index.php');
        file_put_contents($app . '/legacy/public/index.php', "<?php echo 'legacy';\n");
        $this->port = random_int(20000, 45000);
        $env = ['BOOSTER_REPLAY_FRONT' => 'index.php', 'PATH' => (string) getenv('PATH')];
        $this->server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $this->port, '-t', $app . '/public', dirname(__DIR__) . '/resources/replay/router.php'], [0 => ['pipe', 'r'], 1 => ['file', $app . '/server.log', 'a'], 2 => ['file', $app . '/server.log', 'a']], $pipes, $app, $env);
        for ($wait = 0; $wait < 50 && !is_resource(@fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.1)); $wait++) {
            usleep(50000);
        }
        $answer = $this->request('GET', '/');
        self::assertSame('legacy', $answer['body']);
        self::assertArrayNotHasKey('x-booster-half', $answer['headers']);
    }
}
