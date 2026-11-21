<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Replay\ReplayFile;
use Waynestate\Booster\Run\ProcessResult;
use Waynestate\Booster\Run\TargetImageRunner;
use Waynestate\Booster\Strangler\LegacyHtaccess;
use Waynestate\Booster\Strangler\Mount;
use Waynestate\Booster\Strangler\MountBaker;
use Waynestate\Booster\Strangler\MountResult;
use Waynestate\Booster\Strangler\MountSourdough;
use Waynestate\Booster\Strangler\RouteTable;
use Waynestate\Booster\ToolError;

/**
 * The mount step on the strangler fixtures (tests/fixtures/strangler), with a
 * fake baker that writes the files Sourdough's laravel-blade shape writes
 * that the mount edits, so no test starts Docker.
 */
final class StranglerMountTest extends TestCase
{
    private static function fixture(string $name): string
    {
        return dirname(__DIR__) . '/tests/fixtures/strangler/' . $name . '/before';
    }

    private function copyFixture(string $name): string
    {
        $dir = $this->tempDir();
        self::copyDir(self::fixture($name), $dir);

        return $dir;
    }

    private static function copyDir(string $from, string $to): void
    {
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir($from . '/' . $entry)) {
                @mkdir($to . '/' . $entry, 0775, true);
                self::copyDir($from . '/' . $entry, $to . '/' . $entry);
            } else {
                copy($from . '/' . $entry, $to . '/' . $entry);
            }
        }
    }

    private static function fakeBaker(?callable $fail = null): MountBaker
    {
        return new class ($fail) implements MountBaker {
            /** @var list<string> */
            public array $baked = [];

            public function __construct(private $fail) {}

            public function bake(string $targetDir, string $name): void
            {
                if ($this->fail !== null) {
                    ($this->fail)();
                }
                $this->baked[] = $name;
                $files = [
                    'composer.json' => json_encode(['name' => 'laravel/laravel', 'require' => ['php' => '^8.3', 'laravel/framework' => '^13.0'], 'config' => ['platform' => ['php' => '8.3.0']], 'scripts' => ['post-autoload-dump' => ['@php artisan package:discover --ansi'], 'post-update-cmd' => ['@boost-update']]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                    'artisan' => "#!/usr/bin/env php\n<?php\n",
                    'public/index.php' => "<?php\n// Laravel's stock front controller\n",
                    'public/.htaccess' => "RewriteEngine On\n",
                    'public/robots.txt' => "User-agent: *\nDisallow:\n",
                    'public/favicon.ico' => "ico",
                    'bootstrap/app.php' => "<?php\n\nuse Illuminate\Foundation\Application;\nuse Illuminate\Foundation\Configuration\Middleware;\n\nreturn Application::configure(basePath: dirname(__DIR__))\n    ->withRouting(\n        health: '/up',\n        then: function () {\n            Route::helmetReady('/ready', []);\n        },\n    )\n    ->withMiddleware(function (Middleware \$middleware) {\n        \$middleware->redirectGuestsTo('/login');\n    })->create();\n",
                    'routes/web.php' => "<?php\n\n// /ready, HELMET's readiness probe, is registered in bootstrap/app.php,\n// outside the web group.\n\nRoute::get('/', fn () => view('home'))->name('home');\n\nRoute::get('/dashboard', fn () => view('dashboard'))\n    ->name('dashboard');\n",
                    'pint.json' => json_encode(['preset' => 'laravel', 'exclude' => ['vendor', 'storage']], JSON_PRETTY_PRINT),
                    '.gitignore' => "/vendor\n/node_modules\n.env\n/storage/*.key\n",
                    '.env.example' => "APP_NAME=Laravel\nAPP_KEY=\nDB_CONNECTION=sqlite\n",
                    'README.md' => "# A Sourdough app\n",
                    'build.sh' => "#!/bin/sh\n# Squire's laravel kit\n",
                    'app/Models/User.php' => "<?php\n",
                ];
                foreach ($files as $path => $contents) {
                    @mkdir(dirname($targetDir . '/' . $path), 0775, true);
                    file_put_contents($targetDir . '/' . $path, $contents);
                }
            }

            public function describe(): string
            {
                return 'a fake Sourdough (sourdough new --laravel --no-playbook)';
            }
        };
    }

    private function mount(string $dir, string $framework, ?MountBaker $baker = null): MountResult
    {
        return (new Mount($baker ?? self::fakeBaker(), dirname(__DIR__) . '/resources/strangler'))->run($dir, $framework);
    }

    public function test_a_slim_app_whose_composer_root_is_the_repository_root(): void
    {
        $dir = $this->copyFixture('slim4');
        $before = ReplayFile::load($dir . '/booster-replay.json');
        $result = $this->mount($dir, 'slim');

        self::assertTrue($result->mounted, implode("\n", array_column($result->refusals, 'reason')));
        self::assertSame('.', $result->composerRoot);
        self::assertSame('legacy/public', $result->legacyDocroot);
        self::assertSame('public', $result->oldPublicDir);
        // The legacy app moved unchanged; the repository files stayed.
        foreach (['composer.json', 'app/routes.php', 'src/Application/Actions/Action.php', 'public/index.php', 'public/robots.txt', '.gitignore', '.htaccess', 'phpunit.xml'] as $moved) {
            self::assertFileExists($dir . '/legacy/' . $moved, $moved . ' moved into legacy/');
        }
        self::assertSame((string) file_get_contents(self::fixture('slim4') . '/app/routes.php'), (string) file_get_contents($dir . '/legacy/app/routes.php'));
        foreach (['README.md', 'CONTRIBUTING.md', 'docker-compose.yml', 'booster-replay.json'] as $stayed) {
            self::assertFileExists($dir . '/' . $stayed, $stayed . ' stays at the root');
            self::assertFileDoesNotExist($dir . '/legacy/' . $stayed);
        }
        self::assertSame((string) file_get_contents(self::fixture('slim4') . '/README.md'), (string) file_get_contents($dir . '/README.md'), 'the app\'s README is kept');
        // The Laravel half at the root.
        self::assertFileExists($dir . '/artisan');
        self::assertFileExists($dir . '/app/Models/User.php');
        self::assertFileExists($dir . '/build.sh');
        // A baked public/ file never shadows a legacy URL (the web server serves public/ first).
        self::assertFileDoesNotExist($dir . '/public/robots.txt');
        self::assertContains('Sourdough\'s public/robots.txt was not written: legacy/public/robots.txt answers that URL, and a file in public/ would be served before the dispatcher runs.', $result->notes);
        self::assertFileExists($dir . '/public/favicon.ico', 'the app has no favicon, so Laravel\'s is served');
        $htaccess = (string) file_get_contents($dir . '/public/.htaccess');
        self::assertStringContainsString('RewriteRule ^ index.php [L]', $htaccess);
        self::assertStringNotContainsString('[L,R=301]', $htaccess, 'no trailing-slash 301 in front of legacy paths');
        // The fixture's own redirect and denials moved in, ahead of the front controller.
        $front = strpos($htaccess, LegacyHtaccess::FRONT_MARKER);
        self::assertNotFalse($front);
        foreach ([
            "    RewriteCond %{CONTEXT_DOCUMENT_ROOT}::%{REQUEST_FILENAME}%{PATH_INFO} ^(.*?)/?::\\1(.*)$\n    RewriteCond %1/../legacy/public%2 !-f\n    RewriteRule ^old-users/?$ /users [R=301,L]\n",
            "    RewriteRule ^private(/|$) - [F]\n",
            "    RewriteCond \$1 \"\.(ini|sql)$\"\n    RewriteRule (?:^|/)([^/]*)$ - [F]\n",
            "    RewriteRule ^_laravel(?:/|$) index.php [END]\n",
        ] as $moved) {
            $at = strpos($htaccess, $moved);
            self::assertNotFalse($at, 'moved: ' . $moved . "\n" . $htaccess);
            self::assertLessThan($front, $at, 'ahead of the front controller: ' . $moved);
        }
        self::assertStringNotContainsString('E=BASE', $htaccess, 'the Slim BASE lines are dropped: the dispatcher derives the base');
        self::assertNotEmpty(array_filter($result->notes, static fn(string $n): bool => str_starts_with($n, 'public/.htaccess carries the rules of public/.htaccess (lines ')));
        // Moved code gets its input as legacy gave it.
        $app = (string) file_get_contents($dir . '/bootstrap/app.php');
        self::assertStringContainsString("use Illuminate\Foundation\Configuration\Middleware;\nuse Illuminate\Http\Request;\n", $app);
        self::assertStringContainsString("->withMiddleware(function (Middleware \$middleware) {\n        // Booster's strangler mount: code moved from legacy/", $app);
        self::assertStringContainsString("        \$middleware->trimStrings(except: [\$movedFromLegacy]);\n        \$middleware->convertEmptyStringsToNull(except: [\$movedFromLegacy]);\n\n        \$middleware->redirectGuestsTo('/login');", $app);
        self::assertStringContainsString("health: '/_laravel/up'", $app);

        // The dispatcher, the empty table, the install hook.
        $dispatcher = (string) file_get_contents($dir . '/public/index.php');
        self::assertStringContainsString("\$legacyDocroot = 'legacy/public'; // booster:legacy-docroot", $dispatcher);
        self::assertStringContainsString("\$legacyFront = 'index.php'; // booster:legacy-front", $dispatcher);
        self::assertStringContainsString('$legacyRouter = true; // booster:legacy-router', $dispatcher, 'the replay file named a front controller: REPLAY\'s router served the legacy run');
        self::assertSame([], RouteTable::load($dir)->rows());
        self::assertSame([], require $dir . '/bootstrap/booster-moved.php');
        self::assertFileExists($dir . '/bin/install-legacy.php');
        $composer = json_decode((string) file_get_contents($dir . '/composer.json'), true);
        self::assertSame(['@php bin/install-legacy.php'], $composer['scripts']['post-install-cmd']);
        self::assertSame(['@boost-update', '@php bin/install-legacy.php'], $composer['scripts']['post-update-cmd']);
        self::assertSame('laravel/laravel', $composer['name'], 'the root composer.json is Laravel\'s');

        // Laravel's own routes moved under /_laravel.
        self::assertStringContainsString("health: '/_laravel/up'", (string) file_get_contents($dir . '/bootstrap/app.php'));
        self::assertStringContainsString("Route::helmetReady('/_laravel/ready'", (string) file_get_contents($dir . '/bootstrap/app.php'));
        $web = (string) file_get_contents($dir . '/routes/web.php');
        self::assertStringContainsString("Route::get('/_laravel', ", $web);
        self::assertStringContainsString("Route::get('/_laravel/dashboard', ", $web);
        self::assertStringNotContainsString("Route::get('/', ", $web);
        self::assertContains('legacy', json_decode((string) file_get_contents($dir . '/pint.json'), true)['exclude']);

        // The replay file: same requests, new serve block.
        $after = ReplayFile::load($dir . '/booster-replay.json');
        self::assertSame($before->requestsFingerprint, $after->requestsFingerprint, 'the requests are the ones recorded, so --serve-changed can compare');
        self::assertNotSame($before->serveFingerprint, $after->serveFingerprint);
        self::assertSame('public', $after->docroot);
        self::assertSame('index.php', $after->frontController);
        self::assertSame('mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache && (cd legacy && mkdir -p logs var/cache)', $after->setup);
        self::assertSame(Mount::REPLAY_APP_KEY, $after->env['APP_KEY']);
        self::assertSame(32, strlen((string) base64_decode(substr(Mount::REPLAY_APP_KEY, 7), true)), 'Laravel\'s cipher needs a 32-byte key');
        self::assertArrayNotHasKey('BOOSTER_REPLAY', $after->env, 'the replay container sets it, not the request file');
        self::assertSame('file', $after->env['SESSION_DRIVER']);

        // The report.
        self::assertStringStartsWith('Citadel: set the app\'s public directory to public (it was public)', $result->manual[0]);
        self::assertContains('Sourdough\'s README.md was not written: the app already has one, and the app\'s copy was kept. Compare them by hand.', $result->manual);
        self::assertContains('Sourdough feed will skip public/index.php until the finish step: the dispatcher is app-owned until then.', $result->manual);
        self::assertSame(['PHPSESSID', 'laravel_session', 'XSRF-TOKEN'], $result->ignoreCookies);
        self::assertSame('laravel_session', $after->env['SESSION_COOKIE']);
        // The strangler compare block, where this Booster's parser reads it
        // (REPLAY lane); before that merges, a note says what was not written.
        $raw = json_decode((string) file_get_contents($dir . '/booster-replay.json'), true);
        if (isset($raw['compare'])) {
            self::assertSame(['preset' => 'strangler', 'ignore_cookies' => ['PHPSESSID', 'laravel_session', 'XSRF-TOKEN'], 'silent_sign_in' => ['/_laravel/login']], $raw['compare']);
        } else {
            self::assertNotEmpty(array_filter($result->notes, static fn(string $n): bool => str_contains($n, 'preset strangler, ignore_cookies PHPSESSID, laravel_session, XSRF-TOKEN, silent_sign_in /_laravel/login')));
        }
        $htaccess = array_values(array_filter($result->manual, static fn(string $l): bool => str_contains($l, '.htaccess:')));
        self::assertSame([], $htaccess, 'the skeleton\'s .htaccess files hold only the standard front-controller block: ' . implode("\n", $htaccess));
        self::assertContains('legacy/', $result->changedFiles);
        self::assertContains('public/index.php', $result->changedFiles);
    }

    public function test_a_cakephp_app_keeps_webroot_and_its_login_form_flow(): void
    {
        $dir = $this->copyFixture('cake5');
        $result = $this->mount($dir, 'cakephp');

        self::assertTrue($result->mounted, implode("\n", array_column($result->refusals, 'reason')));
        self::assertSame('legacy/webroot', $result->legacyDocroot);
        self::assertFileExists($dir . '/legacy/webroot/index.php');
        self::assertFileExists($dir . '/legacy/bin/booster-fixture-db.php');
        self::assertFileExists($dir . '/legacy/index.php', 'the root index.php that requires webroot/ moved with the app');
        self::assertStringContainsString("\$legacyDocroot = 'legacy/webroot';", (string) file_get_contents($dir . '/public/index.php'));
        $after = ReplayFile::load($dir . '/booster-replay.json');
        self::assertStringContainsString('(cd legacy && mkdir -p logs tmp/cache/models', (string) $after->setup);
        self::assertSame('false', $after->env['DEBUG'], 'the legacy half\'s own replay env is kept');
        self::assertSame(['PHPSESSID', 'csrfToken', 'laravel_session', 'XSRF-TOKEN'], $result->ignoreCookies);
        self::assertContains('Citadel: a dashboard config file written to config/app_local.php now belongs at legacy/config/app_local.php; change its path in the dashboard.', $result->manual);
        // .env.example: the keys from the legacy example, secrets blank.
        $env = (string) file_get_contents($dir . '/.env.example');
        self::assertStringContainsString('# The legacy half (legacy/) reads these, from legacy/config/.env.default.', $env);
        self::assertStringContainsString("\nSECURITY_SALT=\n", $env);
        self::assertStringContainsString("\nDEBUG=\"true\"\n", $env);
        self::assertSame(1, substr_count($env, 'APP_NAME='), 'a key Laravel\'s example already has is not repeated');
        // The Cake .htaccess files are the standard ones (the httpoxy lines are comments).
        self::assertSame([], array_values(array_filter($result->manual, static fn(string $l): bool => str_contains($l, '.htaccess:'))));
    }

    public function test_a_fleet_shape_app_whose_composer_root_is_src(): void
    {
        $dir = $this->tempDir();
        $files = [
            'Dockerfile' => "FROM php\n",
            'build.sh' => "#!/bin/sh\n# the app's own build\n",
            'deployments/001.sh' => "#!/bin/sh\n",
            '.gitignore' => "/src/vendor/\nsrc/tmp/*\n!/src/tmp/.keep\n/vendor\n",
            'parity.json' => json_encode(['public_dir' => 'src/webroot']),
            'src/composer.json' => json_encode(['require' => ['php' => '^8.5', 'cakephp/cakephp' => '^5.4']]),
            'src/webroot/index.php' => "<?php\n",
            'src/config/app.php' => "<?php\nreturn [\n    'Session' => [\n        'defaults' => 'php',\n        'cookie' => 'ATTEND',\n    ],\n];\n",
            'src/config/app_local.example.php' => "<?php\nreturn [];\n",
            'src/.htaccess' => "<IfModule mod_rewrite.c>\n    RewriteEngine on\n    RewriteRule    ^$    webroot/    [L]\n    RewriteRule    (.*) webroot/$1    [L]\n</IfModule>\nphp_value upload_max_filesize 64M\n",
            'src/webroot/.htaccess' => "<IfModule mod_rewrite.c>\n    RewriteEngine On\n    RewriteCond %{REQUEST_FILENAME} !-f\n    RewriteRule ^ index.php [L]\n</IfModule>\nHeader set X-Frame-Options DENY\n<FilesMatch \"\.(ttf|woff2?)$\">\n    Header set Access-Control-Allow-Origin \"*\"\n</FilesMatch>\n",
            // No front_controller: the legacy run was plain php -S, so the dispatcher answers folders as it did.
            'src/booster-replay.json' => json_encode(['version' => 1, 'serve' => ['docroot' => 'webroot', 'setup' => 'mkdir -p tmp logs'], 'requests' => [['path' => '/']]]),
        ];
        foreach ($files as $path => $contents) {
            @mkdir(dirname($dir . '/' . $path), 0775, true);
            file_put_contents($dir . '/' . $path, $contents);
        }
        $result = $this->mount($dir, 'cakephp');

        self::assertTrue($result->mounted, implode("\n", array_column($result->refusals, 'reason')));
        self::assertSame('src', $result->composerRoot);
        self::assertSame('src/webroot', $result->oldPublicDir);
        self::assertDirectoryDoesNotExist($dir . '/src');
        self::assertFileExists($dir . '/legacy/composer.json');
        self::assertFileExists($dir . '/legacy/booster-replay.json', 'legacy keeps its own replay file for booster run --path=legacy');
        self::assertFileExists($dir . '/deployments/001.sh');
        self::assertSame("#!/bin/sh\n# the app's own build\n", (string) file_get_contents($dir . '/build.sh'), 'the app\'s build.sh is kept');
        self::assertContains('Sourdough\'s build.sh was not written: the app already has one, and the app\'s copy was kept. Compare them by hand.', $result->manual);
        self::assertStringStartsWith('Citadel: set the app\'s public directory to public (it was src/webroot)', $result->manual[0]);
        self::assertSame('public', json_decode((string) file_get_contents($dir . '/parity.json'), true)['public_dir']);

        $gitignore = (string) file_get_contents($dir . '/.gitignore');
        self::assertStringContainsString("/legacy/vendor/\nlegacy/tmp/*\n!/legacy/tmp/.keep\n/vendor\n", $gitignore);
        self::assertStringContainsString("# The Laravel half (added by Booster's strangler mount)\n/node_modules\n.env\n/storage/*.key\n", $gitignore);

        $after = ReplayFile::load($dir . '/booster-replay.json');
        self::assertStringContainsString('$legacyRouter = false; // booster:legacy-router', (string) file_get_contents($dir . '/public/index.php'));
        self::assertSame('public', $after->docroot);
        self::assertStringEndsWith('(cd legacy && mkdir -p tmp logs)', (string) $after->setup);
        self::assertSame(['ATTEND', 'csrfToken', 'laravel_session', 'XSRF-TOKEN'], $result->ignoreCookies);

        $htaccess = array_values(array_filter($result->manual, static fn(string $l): bool => str_contains($l, '.htaccess:')));
        self::assertSame([
            'src/.htaccess:6: "php_value upload_max_filesize 64M" no longer applies once the docroot is public/ (the file is legacy/.htaccess now). Carry it to public/.htaccess or the Citadel config, or drop it.',
            'src/webroot/.htaccess:7: "<FilesMatch "\.(ttf|woff2?)$">" could not move to public/.htaccess: lines 7-9, a filename block: the dispatcher serves legacy files now, so it would never match them. The file is legacy/webroot/.htaccess now; carry it to public/.htaccess or the Citadel config by hand, or drop it.',
        ], $htaccess);
        // The docroot's per-directory header moved as written, at the top.
        self::assertStringStartsWith("# Moved by Booster's strangler mount from src/webroot/.htaccess, as written.\nHeader set X-Frame-Options DENY\n\n", (string) file_get_contents($dir . '/public/.htaccess'));
        self::assertContains('public/.htaccess carries the rules of src/webroot/.htaccess (lines 6), rewritten for the new layout.', $result->notes);
    }

    public function test_it_refuses_and_changes_nothing(): void
    {
        $dir = $this->copyFixture('slim4');
        mkdir($dir . '/legacy');
        $result = $this->mount($dir, 'slim');
        self::assertFalse($result->mounted);
        self::assertStringContainsString('legacy is already there', $result->refusals[0]['reason']);
        self::assertFileExists($dir . '/composer.json');

        $cake = $this->copyFixture('slim4');
        $result = $this->mount($cake, 'cakephp');
        self::assertFalse($result->mounted);
        self::assertStringContainsString('does not require cakephp/cakephp', $result->refusals[0]['reason']);

        $empty = $this->tempDir();
        $result = $this->mount($empty, 'slim');
        self::assertFalse($result->mounted);
        self::assertStringContainsString('No composer.json requiring slim/slim', $result->refusals[0]['reason']);

        $this->expectException(ToolError::class);
        $this->mount($empty, 'laravel');
    }

    public function test_a_failed_bake_moves_nothing(): void
    {
        $dir = $this->copyFixture('slim4');
        $listing = scandir($dir);
        try {
            $this->mount($dir, 'slim', self::fakeBaker(static function (): void {
                throw new ToolError('Sourdough could not bake');
            }));
            self::fail('the mount carried on after a failed bake');
        } catch (ToolError $e) {
            self::assertSame('Sourdough could not bake', $e->getMessage());
        }
        self::assertSame($listing, scandir($dir));
        self::assertFileDoesNotExist($dir . '/legacy');
    }

    public function test_sourdough_bakes_at_the_pinned_version_in_the_gate_image(): void
    {
        $script = MountSourdough::script('my-app', false, false);
        self::assertStringContainsString("composer create-project 'waynestate/sourdough:" . MountSourdough::VERSION . "' /tmp/booster-sourdough --no-dev", $script);
        self::assertStringContainsString("'php' '/tmp/booster-sourdough/bin/sourdough' 'new' '/app' '--laravel' '--no-input' '--name' 'my-app' '--no-playbook' '--no-advisor'", $script);
        self::assertStringNotContainsString('--no-playbook', MountSourdough::script('my-app', true, true));

        $commands = [];
        $processes = new FakeProcessRunner(static function (array $command) use (&$commands): ProcessResult {
            $commands[] = $command;
            if (in_array('run', $command, true)) {
                $target = '';
                foreach ($command as $i => $part) {
                    if ($part === '-v' && str_ends_with($command[$i + 1], ':/app')) {
                        $target = substr($command[$i + 1], 0, -5);
                    }
                }
                file_put_contents($target . '/composer.json', '{}');
                file_put_contents($target . '/artisan', '');

                return new ProcessResult(0, "BOOSTER_GATE_PHASE=tests\n", '');
            }

            return new ProcessResult(0, '', '');
        });
        $baker = new MountSourdough(new TargetImageRunner($processes, dirname(__DIR__) . '/docker'));
        $target = $this->tempDir();
        $baker->bake($target, 'my-app');
        self::assertFileExists($target . '/artisan');
        $run = array_values(array_filter($commands, static fn(array $c): bool => in_array('run', $c, true)));
        self::assertNotEmpty($run);
        self::assertStringContainsString('sourdough', implode(' ', end($run)));
        self::assertSame('Sourdough ' . MountSourdough::VERSION . ' (sourdough new --laravel --no-playbook) on PHP 8.5', $baker->describe());
    }

    public function test_install_legacy_installs_the_legacy_root_from_its_lock(): void
    {
        $dir = $this->tempDir();
        mkdir($dir . '/bin');
        mkdir($dir . '/legacy');
        copy(dirname(__DIR__) . '/resources/strangler/install-legacy.php', $dir . '/bin/install-legacy.php');
        file_put_contents($dir . '/legacy/composer.json', '{}');
        file_put_contents($dir . '/fake-composer.php', "<?php\nfile_put_contents(__DIR__ . '/args.json', json_encode(array_slice(\$argv, 1)));\nexit(3);\n");

        $run = static function (array $env) use ($dir): int {
            $process = proc_open([PHP_BINARY, $dir . '/bin/install-legacy.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, $env + ['PATH' => (string) getenv('PATH')]);
            self::assertIsResource($process);
            fclose($pipes[0]);
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);

            return proc_close($process);
        };

        self::assertSame(3, $run(['COMPOSER_BINARY' => $dir . '/fake-composer.php', 'COMPOSER_DEV_MODE' => '1']), 'Composer\'s exit code is the hook\'s');
        self::assertSame(['install', '--working-dir=' . $dir . '/legacy', '--no-interaction'], json_decode((string) file_get_contents($dir . '/args.json'), true));
        $run(['COMPOSER_BINARY' => $dir . '/fake-composer.php', 'COMPOSER_DEV_MODE' => '0']);
        self::assertSame(['install', '--working-dir=' . $dir . '/legacy', '--no-interaction', '--no-dev', '--optimize-autoloader'], json_decode((string) file_get_contents($dir . '/args.json'), true));

        unlink($dir . '/legacy/composer.json');
        self::assertSame(0, $run(['COMPOSER_BINARY' => $dir . '/fake-composer.php']), 'no legacy/ (after the finish step) is nothing to do');
    }

    public function test_the_root_lists_the_legacy_package_sources_with_relative_paths_into_legacy(): void
    {
        $dir = $this->copyFixture('slim4');
        $composer = json_decode((string) file_get_contents($dir . '/composer.json'), true);
        $composer['repositories'] = [
            ['type' => 'vcs', 'url' => 'https://git.wayne.edu/enterprise-apps/sesame.git'],
            ['type' => 'path', 'url' => './packages/sesame', 'options' => ['symlink' => false]],
            ['type' => 'path', 'url' => '/opt/shared/pkg'],
        ];
        file_put_contents($dir . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $result = $this->mount($dir, 'slim');

        self::assertTrue($result->mounted);
        $root = json_decode((string) file_get_contents($dir . '/composer.json'), true);
        self::assertSame([
            ['type' => 'vcs', 'url' => 'https://git.wayne.edu/enterprise-apps/sesame.git'],
            ['type' => 'path', 'url' => 'legacy/packages/sesame', 'options' => ['symlink' => false]],
            ['type' => 'path', 'url' => '/opt/shared/pkg'],
        ], $root['repositories']);
        self::assertSame('./packages/sesame', json_decode((string) file_get_contents($dir . '/legacy/composer.json'), true)['repositories'][1]['url'], 'legacy/ keeps its own');
        self::assertContains('composer.json lists legacy/\'s 3 Composer repositories too (a relative path repository now points into legacy/), so both halves resolve packages from the same sources.', $result->notes);
    }

    public function test_the_root_never_floors_below_the_legacy_php(): void
    {
        // An app at the 1.20.0 end state asks for PHP 8.5; Sourdough's Laravel half asks for 8.3.
        $dir = $this->copyFixture('slim4');
        $composer = json_decode((string) file_get_contents($dir . '/composer.json'), true);
        $composer['require']['php'] = '^8.5';
        file_put_contents($dir . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $result = $this->mount($dir, 'slim');

        self::assertTrue($result->mounted);
        $root = json_decode((string) file_get_contents($dir . '/composer.json'), true);
        self::assertSame('^8.5', $root['require']['php']);
        self::assertSame('php', array_key_first($root['require']));
        self::assertSame('^13.0', $root['require']['laravel/framework'], 'the rest of the Laravel half\'s require is kept');
        self::assertContains('composer.json requires PHP ^8.5, as legacy/ does (the Laravel half asked for ^8.3): one PHP runs both halves.', $result->notes);
        self::assertSame('8.5.0', $root['config']['platform']['php'], 'Sourdough\'s platform pin moves up with the floor');

        self::assertArrayNotHasKey('repositories', $root, 'no legacy repositories, none added');

        // The stock fixture floors at 7.4, below the Laravel half: the root keeps Laravel's own.
        $plain = $this->copyFixture('slim4');
        $this->mount($plain, 'slim');
        self::assertSame('^8.3', json_decode((string) file_get_contents($plain . '/composer.json'), true)['require']['php']);
    }
}
