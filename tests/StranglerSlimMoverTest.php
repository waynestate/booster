<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Waynestate\Booster\Strangler\MoveResult;
use Waynestate\Booster\Strangler\SlimMover;
use Waynestate\Booster\Strangler\SlimUnitSource;
use Waynestate\Booster\Strangler\Unit;

/** The Slim Mover on a mounted fixture (design 4, 9.2, 10.1 to 10.4). */
final class StranglerSlimMoverTest extends TestCase
{
    private string $app;

    protected function setUp(): void
    {
        $this->app = sys_get_temp_dir() . '/booster-slim-mover-' . getmypid() . '-' . bin2hex(random_bytes(4));
        self::copyTree(__DIR__ . '/fixtures/strangler/slim-small', $this->app);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->app);
    }

    private function move(string $id): MoveResult
    {
        foreach ((new SlimUnitSource())->units($this->app) as $unit) {
            if ($unit->id === $id) {
                return (new SlimMover())->move($unit, $this->app);
            }
        }
        self::fail('no unit ' . $id);
    }

    private function read(string $file): string
    {
        self::assertFileExists($this->app . '/' . $file);

        return (string) file_get_contents($this->app . '/' . $file);
    }

    /** @param list<array{file: string, line: int, reason: string}> $refusals */
    private static function reasons(array $refusals): string
    {
        return implode("\n", array_column($refusals, 'reason'));
    }

    public function testFramework(): void
    {
        self::assertSame('slim', (new SlimMover())->framework());
    }

    public function testMovesAnActionClassBehindTheAdapter(): void
    {
        $result = $this->move('GET /items');

        self::assertTrue($result->moved, self::reasons($result->refusals));
        self::assertSame([], $result->refusals);
        foreach (['routes/web.php', 'composer.json', 'bootstrap/providers.php', 'app/Providers/LegacyServiceProvider.php', 'app/Http/Controllers/Legacy/booster-slim.php', 'app/Http/Controllers/Legacy/App/Action/ListItems.php', 'app/Http/Controllers/Legacy/App/Service/Catalog.php', 'app/Http/Controllers/Legacy/Support/SlimAdapter.php'] as $file) {
            self::assertContains($file, $result->changedFiles);
        }

        $web = $this->read('routes/web.php');
        self::assertStringContainsString("// booster:strangler GET /items from legacy/app/routes.php:25\n", $web);
        self::assertStringContainsString("Route::match(['GET'], 'items', [\\App\\Http\\Controllers\\Legacy\\Support\\SlimAdapter::class, 'handle'])", $web);
        self::assertStringContainsString("->defaults('booster_slim', 'class:App\\\\Http\\\\Controllers\\\\Legacy\\\\App\\\\Action\\\\ListItems')", $web);
        self::assertStringContainsString("->name('items');", $web);

        $action = $this->read('app/Http/Controllers/Legacy/App/Action/ListItems.php');
        self::assertStringContainsString('namespace App\\Http\\Controllers\\Legacy\\App\\Action;', $action);
        self::assertStringContainsString('use App\\Http\\Controllers\\Legacy\\App\\Service\\Catalog;', $action);
        self::assertStringContainsString('echo json_encode($this->catalog->items);', $action);

        $manifest = require $this->app . '/app/Http/Controllers/Legacy/booster-slim.php';
        self::assertSame('php-di', $manifest['container']);
        self::assertSame(['container/app-dependencies.php'], $manifest['files']);
        self::assertTrue($manifest['error_middleware']);
        self::assertTrue($manifest['body_parsing']);
        self::assertContains('greeting', $manifest['keys']);
        self::assertContains('App\\Http\\Controllers\\Legacy\\App\\Service\\Catalog', $manifest['keys']);
        self::assertStringContainsString('use App\\Http\\Controllers\\Legacy\\App\\Service\\Catalog;', $this->read('app/Http/Controllers/Legacy/container/app-dependencies.php'));

        self::assertSame(1, substr_count($this->read('bootstrap/providers.php'), 'App\\Providers\\LegacyServiceProvider::class'));
        $composer = json_decode($this->read('composer.json'), true);
        // No root composer.lock to conflict with: the legacy constraints as they are.
        self::assertSame('^1.7', $composer['require']['slim/psr7']);
        self::assertSame('^4.15', $composer['require']['slim/slim']);
        self::assertSame('^7.0', $composer['require']['php-di/php-di']);
        self::assertSame(['php', 'laravel/framework', 'php-di/php-di', 'slim/psr7', 'slim/slim'], array_keys($composer['require']));
    }

    public function testEveryWrittenPhpFileParses(): void
    {
        foreach (['GET /items', 'GET /items/{id}', 'FORM /contact', 'GET /'] as $id) {
            self::assertTrue($this->move($id)->moved, $id);
        }
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->app . '/app', RecursiveDirectoryIterator::SKIP_DOTS));
        $checked = 0;
        foreach ([...iterator_to_array($files, false), new \SplFileInfo($this->app . '/routes/web.php'), new \SplFileInfo($this->app . '/bootstrap/providers.php')] as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            token_get_all((string) file_get_contents($file->getPathname()), TOKEN_PARSE);
            ++$checked;
        }
        self::assertGreaterThan(10, $checked);
    }

    public function testRegexPlaceholderBecomesAWhereAndTheMethodIsKept(): void
    {
        self::assertTrue($this->move('GET /items/{id}')->moved);

        $web = $this->read('routes/web.php');
        self::assertStringContainsString("Route::match(['GET'], 'items/{id}',", $web);
        self::assertStringContainsString("->defaults('booster_slim', 'class:App\\\\Http\\\\Controllers\\\\Legacy\\\\App\\\\Action\\\\ViewItem@show')", $web);
        self::assertStringContainsString("->where('id', '[0-9]+');", $web);
    }

    public function testAFormMovesItsGetAndPostTogetherWithoutLaravelsCsrfCheck(): void
    {
        $result = $this->move('FORM /contact');

        self::assertTrue($result->moved);
        $web = $this->read('routes/web.php');
        self::assertStringContainsString("Route::match(['GET'], 'contact',", $web);
        self::assertStringContainsString("Route::match(['POST'], 'contact',", $web);
        self::assertSame(1, substr_count($web, 'ValidateCsrfToken'));
        self::assertStringContainsString('had no CSRF check in Slim', implode("\n", $result->manual));
    }

    public function testAClosureBecomesAClassBoundToTheLegacyContainer(): void
    {
        self::assertTrue($this->move('GET /')->moved);

        $closure = $this->read('app/Http/Controllers/Legacy/Closures/GetRoot.php');
        self::assertStringContainsString('final class GetRoot extends \\App\\Http\\Controllers\\Legacy\\Support\\LegacyClosure', $closure);
        self::assertStringContainsString("\$response->getBody()->write(\$this->get('greeting') . ' world');", $closure);
        self::assertStringContainsString('use Psr\\Http\\Message\\ResponseInterface as Response;', $closure);
        self::assertStringNotContainsString('RouteCollectorProxy', $closure);
        self::assertStringContainsString("->defaults('booster_slim', 'closure:App\\\\Http\\\\Controllers\\\\Legacy\\\\Closures\\\\GetRoot')", $this->read('routes/web.php'));
    }

    public function testASecondMoveOnlyAddsItsOwnFiles(): void
    {
        self::assertTrue($this->move('GET /items')->moved);
        $second = $this->move('GET /items/{id}');

        self::assertSame(['app/Http/Controllers/Legacy/App/Action/ViewItem.php', 'routes/web.php'], $second->changedFiles);
        self::assertSame(1, substr_count($this->read('bootstrap/providers.php'), 'LegacyServiceProvider'));
    }

    public function testAMovedUnitIsRefusedTheSecondTime(): void
    {
        self::assertTrue($this->move('GET /items')->moved);
        $again = $this->move('GET /items');

        self::assertFalse($again->moved);
        self::assertStringContainsString('already in routes/web.php', self::reasons($again->refusals));
    }

    public function testAnOptionalSegmentMovesOnePathAtATime(): void
    {
        $short = $this->move('GET /page');
        $long = $this->move('GET /page/{n}');

        self::assertTrue($short->moved, self::reasons($short->refusals));
        self::assertTrue($long->moved, self::reasons($long->refusals));
        $web = $this->read('routes/web.php');
        self::assertStringContainsString("Route::match(['GET'], 'page', ", $web);
        self::assertStringContainsString("Route::match(['GET'], 'page/{n}', ", $web);
    }

    public function testSignedInUnitsWaitForStepZeroAndRoleChecksMoveInBootstrapMode(): void
    {
        $pending = $this->move('GET /admin/users');
        self::assertFalse($pending->moved);
        self::assertStringContainsString('Step 0', self::reasons($pending->refusals));

        file_put_contents($this->app . '/booster-strangler.json', json_encode(['framework' => 'slim', 'mounted_at' => 'x', 'step0' => 'done', 'units' => []]));
        $done = $this->move('GET /admin/users');
        self::assertTrue($done->moved, self::reasons($done->refusals));

        // The role check runs per request in a copy of the legacy front
        // controller, whose routes script registers only the moved route.
        $manifest = require $this->app . '/app/Http/Controllers/Legacy/booster-slim.php';
        self::assertSame('bootstrap', $manifest['mode']);
        self::assertSame('app/Http/Controllers/Legacy/boot/public/index.php', $manifest['front']);
        $front = $this->read('app/Http/Controllers/Legacy/boot/public/index.php');
        self::assertStringContainsString('true;', $front, 'the legacy autoloader is not loaded');
        self::assertStringContainsString("require \base_path('app/Http/Controllers/Legacy/routes/app/routes.php')", $front);
        $routes = $this->read('app/Http/Controllers/Legacy/routes/app/routes.php');
        token_get_all($routes, TOKEN_PARSE);
        self::assertStringStartsWith("<?php
", $routes);
        self::assertStringContainsString('return function ($booster_app) {', $routes);
        self::assertStringContainsString("if ((\$_SESSION['role'] ?? '') === 'admin') {", $routes);
        self::assertStringContainsString("\$booster_app->get('/admin/users', \App\Http\Controllers\Legacy\App\Action\ListItems::class)", $routes);
        self::assertStringNotContainsString("->get('/contact'", $routes, 'an unmoved route stays out');
        // Unmoved named routes are there for url_for() only.
        self::assertStringContainsString("\$booster_app->map(['BOOSTER-NOT-MOVED'], '/', ", $routes);
        self::assertStringContainsString('SlimAdapter::bindContainer($booster_app->getContainer());', $routes);
        self::assertStringContainsString("->defaults('booster_slim', 'bootstrap')", $this->read('routes/web.php'));
    }

    public function testRefusesBeforeTheMount(): void
    {
        unlink($this->app . '/routes/web.php');
        $result = $this->move('GET /items');

        self::assertFalse($result->moved);
        self::assertStringContainsString('run the mount step first', self::reasons($result->refusals));
    }

    public function testRefusesAnUnknownUnit(): void
    {
        $unit = new Unit('GET /nowhere', 'route', ['GET'], 'nowhere', null, 'stateless', false, 'legacy/app/routes.php', 1, []);
        $result = (new SlimMover())->move($unit, $this->app);

        self::assertFalse($result->moved);
        self::assertStringContainsString('no Slim route', self::reasons($result->refusals));
    }

    public function testComposerJsonKeepsItsLayout(): void
    {
        $json = "{\n    \"keywords\": [\"a\", \"b\"],\n    \"require\": {\n        \"php\": \"^8.3\",\n        \"zeta/z\": \"^1.0\"\n    },\n    \"require-dev\": {}\n}\n";
        $out = SlimMover::withRequires($json, ['php' => '^8.3', 'zeta/z' => '^1.0', 'alpha/a' => '^2.0'], true);

        self::assertSame("{\n    \"keywords\": [\"a\", \"b\"],\n    \"require\": {\n        \"php\": \"^8.3\",\n        \"alpha/a\": \"^2.0\",\n        \"zeta/z\": \"^1.0\"\n    },\n    \"require-dev\": {}\n}\n", $out);
    }

    public function testRuntimeResourcesParse(): void
    {
        foreach (glob(dirname(__DIR__) . '/resources/strangler/slim/*.php') ?: [] as $file) {
            token_get_all((string) file_get_contents($file), TOKEN_PARSE);
            self::assertStringContainsString('declare(strict_types=1);', (string) file_get_contents($file), basename($file));
        }
        self::assertCount(7, glob(dirname(__DIR__) . '/resources/strangler/slim/*.php') ?: []);
    }

    public static function copyTree(string $from, string $to): void
    {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        mkdir($to, 0o777, true);
        foreach ($items as $item) {
            $target = $to . '/' . substr($item->getPathname(), strlen($from) + 1);
            if ($item->isDir()) {
                mkdir($target, 0o777, true);
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }

    public static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
