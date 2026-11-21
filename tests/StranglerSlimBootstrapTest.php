<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use PHPUnit\Framework\TestCase;
use Waynestate\Booster\Strangler\AddsComposerPackages;
use Waynestate\Booster\Strangler\MoveResult;
use Waynestate\Booster\Strangler\SlimMover;
use Waynestate\Booster\Strangler\SlimUnitSource;

/**
 * Bootstrap mode on a Slim app with a Pimple container, an init script and
 * role-checked routes (design 9.2, 10.2): the shape the fleet's older Slim
 * apps have, which the PHP-DI path cannot rebuild.
 */
final class StranglerSlimBootstrapTest extends TestCase
{
    private const LEGACY = 'app/Http/Controllers/Legacy/';

    private string $app;

    protected function setUp(): void
    {
        $this->app = sys_get_temp_dir() . '/booster-slim-boot-' . getmypid() . '-' . bin2hex(random_bytes(4));
        StranglerSlimMoverTest::copyTree(__DIR__ . '/fixtures/strangler/slim-pimple', $this->app);
    }

    protected function tearDown(): void
    {
        StranglerSlimMoverTest::removeTree($this->app);
    }

    private function move(string $id): MoveResult
    {
        foreach ((new SlimUnitSource())->units($this->app) as $unit) {
            if ($unit->id === $id) {
                $result = (new SlimMover())->move($unit, $this->app);
                self::assertTrue($result->moved, implode("\n", array_column($result->refusals, 'reason')));

                return $result;
            }
        }
        self::fail('no unit ' . $id);
    }

    private function read(string $file): string
    {
        self::assertFileExists($this->app . '/' . $file);

        return (string) file_get_contents($this->app . '/' . $file);
    }

    public function testOptionalGroupsAreOneUnitPerPath(): void
    {
        $ids = array_map(static fn($u): string => $u->id, (new SlimUnitSource())->units($this->app));

        // "/[login[/]]" answers "/", "/login" and "/login/": two Laravel routes.
        self::assertContains('FORM /', $ids);
        self::assertContains('FORM /login', $ids);
        self::assertContains('GET /logout', $ids);
        self::assertContains('GET /staff', $ids);
    }

    public function testTheFrontControllerAndItsScriptsAreCopiedWithTheirRequiresRepointed(): void
    {
        $this->move('FORM /login');

        $manifest = require $this->app . '/' . self::LEGACY . 'booster-slim.php';
        self::assertSame(self::LEGACY . 'boot/public/index.php', $manifest['hosted']['front']);

        $front = $this->read(self::LEGACY . 'boot/public/index.php');
        self::assertStringContainsString("require \\base_path('" . self::LEGACY . "boot/config/init.php');", $front, 'a relative require resolves against legacy/public');
        self::assertStringContainsString("realpath(\\base_path('legacy/public') . '/../etc/config.ini')", $front, '__DIR__ is the legacy folder');
        $init = $this->read(self::LEGACY . 'boot/config/init.php');
        self::assertStringContainsString("require_once \\base_path('" . self::LEGACY . "boot/config/defines.php');", $init);
        self::assertStringContainsString('true;', $init, 'PROJECT_ROOT . vendor/autoload.php is the legacy autoloader');

        $class = $this->read(self::LEGACY . 'App/App.php');
        self::assertStringContainsString("new \\Pimple\\Container(require_once \\base_path('" . self::LEGACY . "boot/config/core.php'))", $class);
        self::assertStringContainsString("foreach (require_once \\base_path('" . self::LEGACY . "boot/config/middleware.php') as \$callback)", $class);
        self::assertStringContainsString("foreach (require_once \\base_path('" . self::LEGACY . "routes/config/routes.php') as \$callback)", $class);

        foreach (['boot/public/index.php', 'boot/config/init.php', 'boot/config/defines.php', 'boot/config/core.php', 'boot/config/middleware.php', 'routes/config/routes.php', 'App/App.php', 'App/Controller/Login.php', 'booster-slim.php'] as $file) {
            token_get_all($this->read(self::LEGACY . $file), TOKEN_PARSE);
        }
    }

    public function testPimpleKeysAreBoundAsLegacyKeys(): void
    {
        $this->move('FORM /login');

        $manifest = require $this->app . '/' . self::LEGACY . 'booster-slim.php';
        self::assertSame(['config', 'files', 'session', 'user', 'view'], $manifest['hosted']['keys']);
        $provider = $this->read('app/Providers/LegacyServiceProvider.php');
        self::assertStringContainsString("\$this->app->bind('legacy.' . \$key, static fn() => SlimAdapter::service(\$key));", $provider);
        self::assertStringContainsString("new LegacyContainer(\$app)", $provider);
    }

    public function testTheLegacyCsrfCheckStaysAndLaravelsIsOffOnTheWrite(): void
    {
        $result = $this->move('FORM /login');

        $manifest = require $this->app . '/' . self::LEGACY . 'booster-slim.php';
        self::assertSame('App\Middleware\CheckCsrf', $manifest['hosted']['csrf']);
        self::assertStringContainsString('still has the legacy CSRF check (App\Middleware\CheckCsrf)', implode("\n", $result->manual));
        // The legacy middleware array is copied, so the check runs in the pipeline.
        self::assertStringContainsString('new \App\Http\Controllers\Legacy\App\Middleware\CheckCsrf(', $this->read(self::LEGACY . 'boot/config/middleware.php'));
        $web = $this->read('routes/web.php');
        self::assertStringContainsString("Route::match(['POST'], 'login', ", $web);
        self::assertStringContainsString('->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class, \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])' . \Waynestate\Booster\Strangler\HelmetHold::SKIP . ';', $web);
    }

    public function testRoleChecksAndRouteMiddlewareAreKeptInTheGeneratedRoutesScript(): void
    {
        $this->move('GET /logout');
        $this->move('GET /staff');

        $routes = $this->read(self::LEGACY . 'routes/config/routes.php');
        self::assertStringContainsString("return [\n    'booster' => function (\$booster_app) {", $routes, 'the legacy array shape is kept');
        self::assertStringContainsString('if ($app->getContainer()[\'user\']->isAuth()) {', $routes);
        self::assertStringContainsString("\$booster_app->get('/logout/', 'App\\\\Http\\\\Controllers\\\\Legacy\\\\App\\\\Controller\\\\Login' . ':Logout')->add(new \\App\\Http\\Controllers\\Legacy\\App\\Middleware\\NoCache());", $routes);
        self::assertStringContainsString("if (in_array('staff', \$app->getContainer()['user']->roles)) {", $routes);
        self::assertStringContainsString("\$booster_app->get('/staff/', ", $routes, 'a group route is registered at its full pattern');
        self::assertStringNotContainsString("->post('/[login[/]]'", $routes, 'an unmoved route stays out');
        self::assertStringContainsString("\$booster_app->map(['BOOSTER-NOT-MOVED'], '/[login[/]]', ", $routes, 'url_for still finds an unmoved named route');
        self::assertStringContainsString('SlimAdapter::bindContainer($booster_app->getContainer());', $routes);

        $web = $this->read('routes/web.php');
        self::assertStringContainsString("Route::match(['GET'], 'logout', ", $web);
        self::assertStringContainsString("Route::match(['GET'], 'staff', ", $web);
    }

    /** @return list<string> the SLIM-HOSTED: lines of a move */
    private static function hostedLines(MoveResult $result): array
    {
        return array_values(array_filter($result->manual, static fn(string $m): bool => str_starts_with($m, SlimMover::HOSTED_PREFIX)));
    }

    public function testAnAppBuiltByItsOwnClassRunsAPlainUnitBehindTheAdapterInItsOwnSetup(): void
    {
        mkdir($this->app . '/config');
        file_put_contents($this->app . '/config/helmet.php', "<?php\n\nreturn [\n    'policy_path' => null,\n];\n");
        $login = $this->move('FORM /login');

        self::assertStringContainsString("'scope' => 'web'", $this->read('config/helmet.php'), 'the setup path holds HELMET off the moved routes too (testeval, 2026-11-20)');
        self::assertContains('config/helmet.php', $login->changedFiles);

        self::assertSame([], self::hostedLines($login), 'the app class, its Pimple container and its CSRF middleware no longer force hosting');
        self::assertSame([], SlimMover::hostedUnits($this->app));
        $web = $this->read('routes/web.php');
        self::assertStringContainsString("->defaults('booster_slim', 'class:App\\\\Http\\\\Controllers\\\\Legacy\\\\App\\\\Controller\\\\Login@Login')", $web);
        self::assertStringContainsString("->defaults('booster_slim', 'class:App\\\\Http\\\\Controllers\\\\Legacy\\\\App\\\\Controller\\\\Login@PostLogin')", $web);
        self::assertStringNotContainsString("'bootstrap'", $web);
        $manifest = require $this->app . '/' . self::LEGACY . 'booster-slim.php';
        self::assertSame('app', $manifest['container']);
        self::assertSame(self::LEGACY . 'boot/public/index.php', $manifest['hosted']['front'], 'the adapter runs the copied setup');
        $routes = $this->read(self::LEGACY . 'routes/config/routes.php');
        self::assertStringContainsString("\$booster_app->get('/[login[/]]', ", $routes, 'the setup registers the moved route, so its routing decides');
        self::assertStringContainsString("\$booster_app->post('/[login[/]]', ", $routes);
        self::assertStringContainsString('recording every $app->add() middleware, then answers with the moved handler at their center (Slim never dispatches)', implode("\n", $login->manual));
        self::assertStringContainsString('reading and writing the legacy session token', implode("\n", $login->manual));
        foreach (['LegacyDispatch.php', 'LegacyDispatched.php'] as $support) {
            self::assertFileExists($this->app . '/' . self::LEGACY . 'Support/' . $support);
        }
    }

    public function testARoleCheckedUnitIsAnsweredOnlyWhileItsCheckHoldsWithoutHosting(): void
    {
        $staff = $this->move('GET /staff');

        self::assertSame([], self::hostedLines($staff));
        self::assertStringContainsString("legacy/config/routes.php:10 GET /staff/ is answered only while `in_array('staff', \$app->getContainer()['user']->roles)` holds", implode("\n", $staff->manual));
        self::assertStringContainsString("if (in_array('staff', \$app->getContainer()['user']->roles)) {", $this->read(self::LEGACY . 'routes/config/routes.php'));
        self::assertStringContainsString("->defaults('booster_slim', 'class:", $this->read('routes/web.php'));
    }

    public function testRouteMiddlewareStillHostsTheUnitWithItsReason(): void
    {
        $this->move('FORM /login');
        $logout = $this->move('GET /logout');

        [$line] = self::hostedLines($logout);
        self::assertSame('SLIM-HOSTED: GET /logout runs through Slim inside Laravel because of middleware new \App\Middleware\NoCache() at legacy/app/Controller/Login.php:15; port the check to Laravel middleware before finish', $line);
        self::assertSame(['GET /logout'], SlimMover::hostedUnits($this->app));
        $routes = $this->read(self::LEGACY . 'routes/config/routes.php');
        self::assertStringContainsString("\$booster_app->get('/[login[/]]', ", $routes, 'the earlier unit stays registered');
        self::assertStringContainsString("\$booster_app->get('/logout/', ", $routes);
    }

    public function testAnAppThatBuildsSlimAppWithNewIsSlimHostedWithTheSite(): void
    {
        $file = $this->app . '/legacy/app/App.php';
        $code = (string) file_get_contents($file);
        file_put_contents($file, str_replace('\Slim\Factory\AppFactory::create(null, new \Pimple\Psr11\Container($container))', 'new \Slim\App(new \Slim\Psr7\Factory\ResponseFactory(), new \Pimple\Psr11\Container($container))', $code));

        [$line] = self::hostedLines($this->move('FORM /login'));
        self::assertStringContainsString('the app builds its Slim app with new \Slim\App; Booster runs moved handlers in the app\'s own middleware only when the Slim app comes from AppFactory::create', $line);
        self::assertStringContainsString('at legacy/app/App.php:12', $line);
        self::assertStringContainsString('the legacy CSRF check App\Middleware\CheckCsrf at legacy/config/middleware.php:5', $line);
    }

    public function testComposerAddsKeepTheLegacyConstraintWidenSlimAndSkipAConflict(): void
    {
        $mover = new SlimMover();
        foreach ((new SlimUnitSource())->units($this->app) as $unit) {
            if ($unit->id === 'FORM /login') {
                $result = $mover->move($unit, $this->app);
            }
        }
        self::assertTrue(isset($result) && $result->moved);

        $adds = $mover->lastComposerAdds();
        self::assertInstanceOf(AddsComposerPackages::class, $mover);
        self::assertSame($adds, $mover->composerAdds());
        self::assertSame('^3.5', $adds['pimple/pimple'] ?? null, 'no conflict: the legacy constraint as is');
        self::assertSame('^4.15 || ^5.0', $adds['slim/slim'] ?? null, 'psr/http-message ^1.1 against the root 2.0: the next major is allowed');
        self::assertArrayNotHasKey('league/flysystem-sftp', $adds);
        self::assertStringContainsString('does not require league/flysystem-sftp ^1.1: the legacy copy needs league/flysystem ~1.0, the Laravel half has 3.30.0', implode("\n", $result->manual));
        $composer = json_decode($this->read('composer.json'), true);
        self::assertIsArray($composer);
        self::assertSame('^4.15 || ^5.0', $composer['require']['slim/slim']);
    }

    public function testSesameUserArrayChecksUnwrapTheUserObject(): void
    {
        $result = $this->move('GET /staff');

        $staff = $this->read(self::LEGACY . 'App/Controller/Staff.php');
        self::assertStringContainsString("is_array(\\is_object(\$__booster_user = \$user) && method_exists(\$__booster_user, 'toArray') ? \$__booster_user->toArray() : \$__booster_user)", $staff);
        self::assertStringContainsString("is_array(\\is_object(\$__booster_user = sesame_user()) && method_exists(\$__booster_user, 'toArray')", $staff);
        self::assertStringContainsString('reads $user[...] from sesame_user()', implode("\n", $result->manual));
        token_get_all($staff, TOKEN_PARSE);
    }
}
