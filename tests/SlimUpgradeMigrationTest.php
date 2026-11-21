<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Psr\Container\ContainerInterface;
use Slim\Factory\AppFactory;
use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Waynestate\Booster\Hop\EngineId;
use Waynestate\Booster\Hop\Hop;
use Waynestate\Booster\Hop\HopKind;
use Waynestate\Booster\Run\ComposerEditor;
use Waynestate\Booster\Run\Slim\LegacyStubs;
use Waynestate\Booster\Run\Slim\SlimUpgradeMigration;
use Waynestate\Booster\Run\VendoredMigrationResult;

/**
 * The SlimUpgrade engine on small Slim 3 apps written into temp folders:
 * refusals, the glue under the PSR-4 root, the bootstrap and route rewrites,
 * the route adders a group calls, the Composer pins, the MANUAL report, the
 * dry run and the second run. The hop is built here (the registry gets its
 * own slim-3-to-4 hop separately).
 */
final class SlimUpgradeMigrationTest extends TestCase
{
    private const PROXY = '\\Slim\\Interfaces\\RouteCollectorProxyInterface';

    private const BOOTSTRAP = <<<'PHP'
        <?php

        require __DIR__ . '/../vendor/autoload.php';

        $app = new \Slim\App(['settings' => ['displayErrorDetails' => true]]);

        require __DIR__ . '/../src/routes.php';

        $app->run();

        PHP;

    private const ROUTES = <<<'PHP'
        <?php

        $app->group('/api', function () use ($app) {
            $app->get('/ping', function ($request, $response) {
                return $response;
            });
            \Acme\Controller\Users::routes($app);
        });

        $app->add(new \Acme\Middleware\Session());

        PHP;

    private const USERS = <<<'PHP'
        <?php

        namespace Acme\Controller;

        final class Users
        {
            public static function routes(\Slim\App $app): void
            {
                $app->get('/users', function ($request, $response) {
                    $response->getBody()->write('users');

                    return $response;
                });
            }
        }

        PHP;

    private const ADMIN = <<<'PHP'
        <?php

        namespace Acme\Controller;

        final class Admin
        {
            public static function register(\Slim\App $app): void
            {
                $app->get('/admin', self::class . ':index');
                $app->add(new \Acme\Middleware\Session());
            }
        }

        PHP;

    /** Routes typed with Slim 3's own request and response, as the official Slim 3 skeleton writes them. */
    private const SKELETON_ROUTES = <<<'PHP'
        <?php

        use Slim\Http\Request;
        use Slim\Http\Response;

        $app->get('/[{name}]', function (Request $request, Response $response, array $args) {
            return $response->withJson(['name' => $args['name'] ?? 'world']);
        });

        PHP;

    /** Fully qualified and global qualified request types, and the same name in a comment and a string. */
    private const TYPED_ROUTES = <<<'PHP'
        <?php

        // Slim\Http\Request stays in this comment.
        $app->get('/hello', function (\Slim\Http\Request $request, \Slim\Http\Response $response) {
            $label = 'Slim\Http\Request';

            return $response->write($label . $request->getParam('name'));
        });
        $app->get('/other', function (Slim\Http\Request $request, $response) {
            return $response;
        });

        PHP;

    /** A Slim 3 test harness: Environment::mock() and Request::createFromEnvironment(). */
    private const BASE_TEST_CASE = <<<'PHP'
        <?php

        namespace Tests\Functional;

        use Slim\Http\Environment;
        use Slim\Http\Request;
        use Slim\Http\Response;

        class BaseTestCase extends \PHPUnit\Framework\TestCase
        {
            public function runApp($requestMethod, $requestUri)
            {
                $environment = Environment::mock([
                    'REQUEST_METHOD' => $requestMethod,
                    'REQUEST_URI' => $requestUri,
                ]);
                $request = Request::createFromEnvironment($environment);
                $app = require __DIR__ . '/../../src/app.php';

                return $app->process($request, new Response());
            }
        }

        PHP;

    private const HTTP_CHANGE = "Added slim/http ^1.3 to require (the decorators that keep Slim 3's withJson, write, getParam and isPost on Slim 4's request and response).";

    private const GLUE_CHANGE = 'Wrote the legacy container, double-pass adapter, app glue, environment and HTTP factories in src/Legacy.';

    private static function hop(): Hop
    {
        return new Hop(
            id: 'slim-3-to-4',
            kind: HopKind::Framework,
            from: '3',
            to: '4',
            summary: 'Slim 3 to Slim 4.',
            composerSet: ['slim/slim' => '^4.15'],
            engine: EngineId::SlimUpgrade,
        );
    }

    private static function engine(): SlimUpgradeMigration
    {
        return new SlimUpgradeMigration(new ComposerEditor());
    }

    private function migrateApp(string $dir, bool $dryRun = false): VendoredMigrationResult
    {
        return self::engine()->migrate($dir, $dryRun, '8.4', self::hop());
    }

    /**
     * @param array<string, mixed> $extra top-level composer.json keys to add or replace
     */
    private static function composerJson(array $require = [], ?array $psr4 = ['Acme\\' => 'src/'], array $extra = []): string
    {
        $data = ['name' => 'acme/app', 'require' => ['php' => '^7.4', 'slim/slim' => '^3.12', ...$require]];
        if ($psr4 !== null) {
            $data['autoload'] = ['psr-4' => $psr4];
        }

        return json_encode([...$data, ...$extra], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * A temp app with these files (relative path to content).
     *
     * @param array<string, string> $files
     */
    private function app(array $files): string
    {
        $dir = $this->tempDir();
        foreach ($files as $path => $content) {
            if (!is_dir(dirname($dir . '/' . $path))) {
                mkdir(dirname($dir . '/' . $path), 0775, true);
            }
            file_put_contents($dir . '/' . $path, $content);
        }

        return $dir;
    }

    /**
     * The standard Slim 3 app: a bootstrap, a routes file with a group and
     * middleware, a controller route adder a group calls and one that cannot
     * be widened, and a template using path_for.
     *
     * @param array<string, string> $overrides
     */
    private function slimApp(array $overrides = []): string
    {
        return $this->app([
            'composer.json' => self::composerJson(),
            'public/index.php' => self::BOOTSTRAP,
            'src/routes.php' => self::ROUTES,
            'src/Controller/Users.php' => self::USERS,
            'src/Controller/Admin.php' => self::ADMIN,
            'templates/home.twig' => "<a href=\"{{ path_for('home') }}\">Home</a>\n<a href=\"{{ path_for('about') }}\">About</a>\n",
            ...$overrides,
        ]);
    }

    /**
     * Every file under $dir, relative path to content.
     *
     * @return array<string, string>
     */
    private static function snapshot(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            self::assertInstanceOf(\SplFileInfo::class, $file);
            if ($file->isFile()) {
                $files[str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1))] = (string) file_get_contents($file->getPathname());
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * @return array<string, mixed>
     */
    private static function composer(string $dir): array
    {
        $data = json_decode((string) file_get_contents($dir . '/composer.json'), true);
        self::assertIsArray($data);

        return $data;
    }

    public function test_missing_composer_json_is_refused(): void
    {
        $dir = $this->app(['public/index.php' => self::BOOTSTRAP]);
        $before = self::snapshot($dir);

        $result = $this->migrateApp($dir);

        self::assertTrue($result->refused());
        self::assertStringContainsString('composer.json', $result->refusals[0]);
        self::assertSame([], $result->files);
        self::assertSame($before, self::snapshot($dir));
    }

    public function test_app_without_slim_is_refused(): void
    {
        $dir = $this->app([
            'composer.json' => json_encode(['require' => ['php' => '^8.1'], 'autoload' => ['psr-4' => ['Acme\\' => 'src/']]], JSON_THROW_ON_ERROR),
            'public/index.php' => self::BOOTSTRAP,
        ]);
        $before = self::snapshot($dir);

        $result = $this->migrateApp($dir);

        self::assertSame(['composer.json does not require slim/slim, so this is not a Slim app.'], $result->refusals);
        self::assertSame($before, self::snapshot($dir));

        $noHop = self::engine()->migrate($this->slimApp(), false, '8.4');
        self::assertTrue($noHop->refused(), 'Without its hop the engine has no pins, so it refuses.');
    }

    public function test_app_without_a_bootstrap_file_is_refused(): void
    {
        $dir = $this->app([
            'composer.json' => self::composerJson(),
            'src/routes.php' => self::ROUTES,
            // Only Slim's own copy builds an app here, and vendor/ is never read.
            'vendor/slim/slim/example.php' => self::BOOTSTRAP,
            'cache/old-index.php' => self::BOOTSTRAP,
        ]);
        $before = self::snapshot($dir);

        $result = $this->migrateApp($dir);

        self::assertCount(1, $result->refusals);
        self::assertStringContainsString('no bootstrap to rewrite', $result->refusals[0]);
        self::assertSame($before, self::snapshot($dir));
    }

    public function test_app_without_psr4_is_refused(): void
    {
        $dir = $this->slimApp(['composer.json' => self::composerJson(psr4: null)]);
        $before = self::snapshot($dir);

        $result = $this->migrateApp($dir);

        self::assertSame(['The app has no PSR-4 autoload entry to put the Slim 4 glue in.'], $result->refusals);
        self::assertSame(SlimUpgradeMigration::NO_PSR4, $result->refusals[0]);
        self::assertSame($before, self::snapshot($dir));
    }

    public function test_stubs_are_written_under_the_psr4_root(): void
    {
        // The src/ entry wins even when it is not the first one.
        $dir = $this->slimApp(['composer.json' => self::composerJson(psr4: ['Acme\\Tests\\' => 'tests/', 'Acme\\' => 'src/'])]);

        $result = $this->migrateApp($dir);

        self::assertFalse($result->refused());
        self::assertSame(LegacyStubs::container('Acme\\Legacy'), file_get_contents($dir . '/src/Legacy/LegacyContainer.php'));
        self::assertSame(LegacyStubs::doublePass('Acme\\Legacy'), file_get_contents($dir . '/src/Legacy/LegacyDoublePass.php'));
        self::assertSame(LegacyStubs::app('Acme\\Legacy'), file_get_contents($dir . '/src/Legacy/LegacyApp.php'));
        self::assertSame(LegacyStubs::environment('Acme\\Legacy'), file_get_contents($dir . '/src/Legacy/LegacyEnvironment.php'));
        self::assertSame(LegacyStubs::http('Acme\\Legacy'), file_get_contents($dir . '/src/Legacy/LegacyHttp.php'));
        self::assertSame(self::GLUE_CHANGE, $result->changes[0]);
        self::assertDirectoryDoesNotExist($dir . '/tests/Legacy');

        // With no src/ entry, the first entry's folder and namespace are used.
        $other = $this->slimApp(['composer.json' => self::composerJson(psr4: ['Shop\\' => './lib', 'Shop\\Tests\\' => 'tests/'])]);
        $this->migrateApp($other);
        self::assertSame(LegacyStubs::app('Shop\\Legacy'), file_get_contents($other . '/lib/Legacy/LegacyApp.php'));
        self::assertStringContainsString('\\Shop\\Legacy\\LegacyApp::create(', (string) file_get_contents($other . '/public/index.php'));
    }

    public function test_existing_different_stub_is_not_overwritten_and_is_manual(): void
    {
        $mine = "<?php\n\n// The app's own LegacyApp.\n";
        $dir = $this->slimApp(['src/Legacy/LegacyApp.php' => $mine]);

        $result = $this->migrateApp($dir);

        self::assertSame($mine, file_get_contents($dir . '/src/Legacy/LegacyApp.php'));
        self::assertSame(LegacyStubs::container('Acme\\Legacy'), file_get_contents($dir . '/src/Legacy/LegacyContainer.php'));
        self::assertContains('Wrote LegacyContainer, LegacyDoublePass, LegacyEnvironment, LegacyHttp in src/Legacy.', $result->changes);
        $lines = array_values(array_filter($result->manual, static fn(string $line): bool => str_starts_with($line, 'MANUAL: src/Legacy/LegacyApp.php exists and differs')));
        self::assertCount(1, $lines);
        self::assertNotContains('src/Legacy/LegacyApp.php', array_column($result->files, 'file'));

        // The same file with the generated content is simply kept.
        $same = $this->slimApp(['src/Legacy/LegacyApp.php' => LegacyStubs::app('Acme\\Legacy')]);
        $kept = $this->migrateApp($same);
        self::assertSame([], array_values(array_filter($kept->manual, static fn(string $line): bool => str_contains($line, 'exists and differs'))));
    }

    public function test_bootstrap_and_routes_are_rewritten(): void
    {
        $dir = $this->slimApp();

        $result = $this->migrateApp($dir);

        $index = (string) file_get_contents($dir . '/public/index.php');
        self::assertStringContainsString("\$app = \\Acme\\Legacy\\LegacyApp::create(['settings' => ['displayErrorDetails' => true]]);", $index);
        self::assertStringContainsString("\\Acme\\Legacy\\LegacyApp::finish(\$app);\n\$app->run();", $index);
        self::assertContains('Rewrote the bootstrap in public/index.php.', $result->changes);

        $routes = (string) file_get_contents($dir . '/src/routes.php');
        self::assertStringContainsString("\$app->group('/api', function (" . self::PROXY . ' $app) {', $routes);
        self::assertStringContainsString('$app->add(new \\Acme\\Legacy\\LegacyDoublePass(new \\Acme\\Middleware\\Session()));', $routes);
        self::assertContains("Rewrote 1 route group callable(s) to take the group's RouteCollectorProxyInterface in src/routes.php.", $result->changes);

        // The controller route adder the group calls is widened, though its own file opens no group.
        self::assertStringContainsString('public static function routes(' . self::PROXY . ' $app): void', (string) file_get_contents($dir . '/src/Controller/Users.php'));
        self::assertContains('Widened 1 route adder parameter(s) from \\Slim\\App to RouteCollectorProxyInterface in src/Controller/Users.php, so a route group can call them.', $result->changes);
        self::assertSame([], array_values(array_filter($result->manual, static fn(string $line): bool => str_contains($line, 'Users.php'))));

        // One that also calls ->add() (not on the group proxy) stays \Slim\App, as a MANUAL line.
        $admin = (string) file_get_contents($dir . '/src/Controller/Admin.php');
        self::assertStringContainsString('public static function register(\\Slim\\App $app): void', $admin);
        self::assertContains(
            'MANUAL: src/Controller/Admin.php: line 7: $app is typed \\Slim\\App in a route adder; inside a Slim 4 group it gets a RouteCollectorProxy, so widen it to ' . self::PROXY . ' when a group calls it.',
            $result->manual,
        );

        // The glue is never run through the rewriters.
        self::assertSame(LegacyStubs::app('Acme\\Legacy'), file_get_contents($dir . '/src/Legacy/LegacyApp.php'));

        $diffs = array_column($result->files, 'diff', 'file');
        self::assertStringContainsString("--- a/public/index.php\n+++ b/public/index.php\n@@ ", $diffs['public/index.php']);
        self::assertStringContainsString("\n-\$app = new \\Slim\\App(['settings' => ['displayErrorDetails' => true]]);\n+\$app = \\Acme\\Legacy\\LegacyApp::create(", $diffs['public/index.php']);
        self::assertStringStartsWith("--- /dev/null\n+++ b/src/Legacy/LegacyApp.php\n@@ -0,0 +1,", $diffs['src/Legacy/LegacyApp.php']);
    }

    public function test_widened_route_adder_runs_inside_a_slim_4_group(): void
    {
        $dir = $this->slimApp();
        $this->migrateApp($dir);

        $namespace = 'BoosterSlimUpgrade' . bin2hex(random_bytes(4));
        $code = str_replace('namespace Acme\\Controller;', 'namespace ' . $namespace . ';', (string) file_get_contents($dir . '/src/Controller/Users.php'));
        eval(substr($code, strlen('<?php')));
        $class = $namespace . '\\Users';

        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException('No container entry ' . $id);
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
        $app = AppFactory::create(null, $container);
        $app->group('/api', function (RouteCollectorProxyInterface $group) use ($class): void {
            $class::routes($group);
        });
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/api/users');

        self::assertSame('users', (string) $app->handle($request)->getBody());
    }

    public function test_dry_run_writes_nothing_and_reports_diffs(): void
    {
        $dir = $this->slimApp();
        $before = self::snapshot($dir);

        $result = $this->migrateApp($dir, true);

        self::assertSame($before, self::snapshot($dir));
        self::assertDirectoryDoesNotExist($dir . '/src/Legacy');
        self::assertNotSame([], $result->changes);
        foreach ($result->changes as $line) {
            self::assertStringStartsWith('Dry run: would ', $line);
        }
        self::assertContains('Dry run: would write' . substr(self::GLUE_CHANGE, strlen('Wrote')), $result->changes);
        self::assertContains('Dry run: would rewrite the bootstrap in public/index.php.', $result->changes);
        self::assertContains('Dry run: would set slim/slim to ^4.15 in require.', $result->changes);

        $diffs = array_column($result->files, 'diff', 'file');
        foreach (['src/Legacy/LegacyContainer.php', 'public/index.php', 'src/routes.php', 'src/Controller/Users.php', 'composer.json'] as $file) {
            self::assertArrayHasKey($file, $diffs);
            self::assertStringContainsString('@@ ', $diffs[$file]);
        }
        self::assertStringContainsString('+        "pimple/pimple": "^3"', $diffs['composer.json']);

        // The dry run reports the same files the real run writes.
        $real = $this->migrateApp($dir);
        self::assertSame(array_column($result->files, 'file'), array_column($real->files, 'file'));
        self::assertSame($result->manual, $real->manual);
    }

    public function test_composer_pins_and_pimple_are_required(): void
    {
        $dir = $this->slimApp();

        $result = $this->migrateApp($dir);

        $require = self::composer($dir)['require'];
        self::assertIsArray($require);
        self::assertSame('^4.15', $require['slim/slim']);
        self::assertSame('^3', $require['pimple/pimple']);
        self::assertSame('^1.6', $require['slim/psr7']);
        self::assertSame('^1.3', $require['slim/http']);
        self::assertArrayNotHasKey('php-di/php-di', $require);
        self::assertSame(['slim/slim', 'pimple/pimple', 'slim/psr7', 'slim/http'], $result->packages);
        self::assertContains('Set slim/slim to ^4.15 in require.', $result->changes);

        // A newer slim/psr7 the app already asks for is kept, and is not a package the gate updates.
        $newer = $this->slimApp(['composer.json' => self::composerJson(['slim/psr7' => '^1.8'])]);
        $kept = $this->migrateApp($newer);
        self::assertSame('^1.8', self::composer($newer)['require']['slim/psr7'] ?? null);
        self::assertNotContains('slim/psr7', $kept->packages);
    }

    public function test_twig_view_moves_only_when_present(): void
    {
        $with = $this->slimApp(['composer.json' => self::composerJson(['slim/twig-view' => '^2.5', 'twig/twig' => '^2.12'])]);
        $result = $this->migrateApp($with);
        $require = self::composer($with)['require'];
        self::assertIsArray($require);
        self::assertSame('^3.4', $require['slim/twig-view']);
        self::assertSame('^3.8', $require['twig/twig']);
        self::assertContains('slim/twig-view', $result->packages);
        self::assertContains('twig/twig', $result->packages);

        // Twig-View alone moves Twig-View; Twig is never added.
        $viewOnly = $this->slimApp(['composer.json' => self::composerJson(['slim/twig-view' => '^2.5'])]);
        $this->migrateApp($viewOnly);
        $require = self::composer($viewOnly)['require'];
        self::assertIsArray($require);
        self::assertSame('^3.4', $require['slim/twig-view']);
        self::assertArrayNotHasKey('twig/twig', $require);

        $without = $this->slimApp();
        $plain = $this->migrateApp($without);
        $require = self::composer($without)['require'];
        self::assertIsArray($require);
        self::assertArrayNotHasKey('slim/twig-view', $require);
        self::assertArrayNotHasKey('twig/twig', $require);
        self::assertNotContains('slim/twig-view', $plain->packages);
    }

    public function test_manual_report_lists_only_what_a_person_must_do(): void
    {
        $dir = $this->slimApp([
            'config/dependencies.php' => "<?php\n\n\$container['view'] = function (\$c) {\n    return new View(\$c->request->getUri(), \$c['response']);\n};\n",
        ]);

        $result = $this->migrateApp($dir);

        foreach ($result->manual as $line) {
            self::assertStringStartsWith('MANUAL: ', $line);
        }
        self::assertSame($result->manual, array_values(array_unique($result->manual)));
        self::assertContains(
            "MANUAL: templates/home.twig: 2 call(s) of path_for( or base_url(: Twig-View 3 has no path_for; use url_for from the Twig-View runtime extension (base_url is fine when it is the app's own global).",
            $result->manual,
        );
        // Bug S3: the glue holds Slim 3's request, response and environment
        // entries and reads its handler keys, so neither is a MANUAL line.
        self::assertSame([], array_values(array_filter(
            $result->manual,
            static fn(string $line): bool => str_contains($line, 'config/dependencies.php') || str_contains($line, 'ErrorMiddleware') || str_contains($line, 'handler'),
        )));
        // The route rewriter's own MANUAL lines are listed with their file.
        self::assertNotSame([], array_filter($result->manual, static fn(string $line): bool => str_starts_with($line, 'MANUAL: src/Controller/Admin.php: line 7:')));
        // Reported, never edited.
        self::assertStringContainsString("path_for('home')", (string) file_get_contents($dir . '/templates/home.twig'));
    }

    public function test_second_run_changes_nothing(): void
    {
        $dir = $this->slimApp(['composer.json' => self::composerJson(['slim/twig-view' => '^2.5', 'twig/twig' => '^2.12'])]);
        $first = $this->migrateApp($dir);
        self::assertNotSame([], $first->files);
        $after = self::snapshot($dir);

        $second = $this->migrateApp($dir);

        self::assertFalse($second->refused());
        self::assertSame($after, self::snapshot($dir));
        self::assertSame([], $second->files);
        self::assertSame([], $second->packages);
        self::assertSame(['Nothing left to move: the app already runs on the Slim 4 glue.'], $second->changes);
        self::assertSame($first->manual, $second->manual);
    }

    public function test_slim_psr7_is_pinned_to_1_6(): void
    {
        // slim/psr7 1.6 runs on the PHP 7.4 the hop is planned at; 1.7 and up need PHP 8.0.
        $dir = $this->slimApp();
        $result = $this->migrateApp($dir);
        self::assertSame('^1.6', self::composer($dir)['require']['slim/psr7'] ?? null);
        self::assertContains('Added slim/psr7 ^1.6 to require (the PSR-7 messages the glue builds).', $result->changes);

        $old = $this->slimApp(['composer.json' => self::composerJson(['slim/psr7' => '^1.5'])]);
        $raised = $this->migrateApp($old);
        self::assertSame('^1.6', self::composer($old)['require']['slim/psr7'] ?? null);
        self::assertContains('Set slim/psr7 to ^1.6 in require (the PSR-7 messages the glue builds).', $raised->changes);

        $same = $this->slimApp(['composer.json' => self::composerJson(['slim/psr7' => '^1.6'])]);
        $kept = $this->migrateApp($same);
        self::assertSame('^1.6', self::composer($same)['require']['slim/psr7'] ?? null);
        self::assertNotContains('slim/psr7', $kept->packages);
    }

    public function test_slim_http_is_required_for_the_slim_3_request_and_response_methods(): void
    {
        $dir = $this->slimApp(['src/routes.php' => self::SKELETON_ROUTES]);

        $result = $this->migrateApp($dir);

        self::assertSame('^1.3', self::composer($dir)['require']['slim/http'] ?? null);
        self::assertContains('slim/http', $result->packages);
        self::assertContains(self::HTTP_CHANGE, $result->changes);

        // An app that never names the classes still calls their methods (bug S4), and the dry run says it would.
        $response = $this->slimApp([
            'app/Handlers/health.php' => "<?php\n\nfunction health(\$request, \$response)\n{\n    return \$request->isPost() ? \$response->withJson(['ok' => true]) : \$response->write('ok');\n}\n",
        ]);
        $dry = $this->migrateApp($response, true);
        self::assertContains('Dry run: would add' . substr(self::HTTP_CHANGE, strlen('Added')), $dry->changes);
        $this->migrateApp($response);
        self::assertSame('^1.3', self::composer($response)['require']['slim/http'] ?? null);

        // An older slim/http the app already lists is raised.
        $older = $this->slimApp(['composer.json' => self::composerJson(['slim/http' => '^0.8']), 'src/routes.php' => self::SKELETON_ROUTES]);
        $this->migrateApp($older);
        self::assertSame('^1.3', self::composer($older)['require']['slim/http'] ?? null);
    }

    public function test_slim_http_request_is_renamed_to_server_request(): void
    {
        $relative = "<?php\n\nnamespace Acme\\Controller;\n\nfunction local(Slim\\Http\\Request \$request)\n{\n    return \$request;\n}\n";
        $dir = $this->slimApp(['src/routes.php' => self::TYPED_ROUTES, 'src/Controller/relative.php' => $relative]);

        $result = $this->migrateApp($dir);

        $routes = (string) file_get_contents($dir . '/src/routes.php');
        self::assertStringContainsString('function (\\Slim\\Http\\ServerRequest $request, \\Slim\\Http\\Response $response) {', $routes);
        self::assertStringContainsString('function (Slim\\Http\\ServerRequest $request, $response) {', $routes);
        // Never in comments or strings.
        self::assertStringContainsString('// Slim\\Http\\Request stays in this comment.', $routes);
        self::assertStringContainsString("\$label = 'Slim\\Http\\Request';", $routes);
        self::assertSame(2, substr_count($routes, 'Slim\\Http\\Request'));
        self::assertContains('Rewrote Slim\\Http\\Request to its slim/http decorator, Slim\\Http\\ServerRequest in src/routes.php (2 times).', $result->changes);
        self::assertStringContainsString("\n+\$app->get('/hello', function (\\Slim\\Http\\ServerRequest \$request,", array_column($result->files, 'diff', 'file')['src/routes.php']);

        // In a namespace, a qualified name without an import is the app's own class, so it stays.
        self::assertSame($relative, file_get_contents($dir . '/src/Controller/relative.php'));

        // A second run renames nothing more.
        $second = $this->migrateApp($dir);
        self::assertSame([], $second->files);
    }

    public function test_slim_http_request_alias_keeps_the_bare_name(): void
    {
        $home = <<<'PHP'
            <?php

            namespace Acme\Controller;

            use Slim\Http\Request;
            use Slim\Http\Request as HttpRequest;

            final class Home
            {
                public function __invoke(Request $request, HttpRequest $again)
                {
                    return $request;
                }
            }

            PHP;
        $group = "<?php\n\nnamespace Acme\\Controller;\n\nuse Slim\\Http\\{Request, Response};\nuse \\Slim\\Http\\Request as Leading;\n\nfunction group(Request \$request, Response \$response, Leading \$leading) {}\n";
        $dir = $this->slimApp(['src/Controller/Home.php' => $home, 'src/Controller/group.php' => $group]);

        $result = $this->migrateApp($dir);

        $rewritten = (string) file_get_contents($dir . '/src/Controller/Home.php');
        self::assertStringContainsString("use Slim\\Http\\ServerRequest as Request;\nuse Slim\\Http\\ServerRequest as HttpRequest;\n", $rewritten);
        self::assertStringContainsString('public function __invoke(Request $request, HttpRequest $again)', $rewritten);
        self::assertStringNotContainsString('Slim\\Http\\Request', $rewritten);

        $grouped = (string) file_get_contents($dir . '/src/Controller/group.php');
        self::assertStringContainsString("use Slim\\Http\\{ServerRequest as Request, Response};\nuse \\Slim\\Http\\ServerRequest as Leading;\n", $grouped);
        self::assertStringContainsString('function group(Request $request, Response $response, Leading $leading) {}', $grouped);
        self::assertContains('Rewrote Slim\\Http\\Request to its slim/http decorator, Slim\\Http\\ServerRequest in src/Controller/group.php (2 times).', $result->changes);
        self::assertSame('^1.3', self::composer($dir)['require']['slim/http'] ?? null);
    }

    public function test_environment_moves_to_the_generated_glue(): void
    {
        // Bug S1: Slim 3's Environment has no Slim 4 form, so it moves to LegacyEnvironment.
        $mock = <<<'PHP'
            <?php

            namespace Acme\Support;

            use Slim\Http\Environment;

            final class Mock
            {
                public static function env(): Environment
                {
                    return Environment::mock(['REQUEST_URI' => '/']);
                }
            }

            PHP;
        // The routes file also goes through the route rewriter, which has its own Environment rule.
        $routes = self::ROUTES . "\$env = \\Slim\\Http\\Environment::mock();\n";
        $grouped = "<?php\n\nnamespace Acme\\Support;\n\nuse Slim\\Http\\{Environment, Request};\n\n\$env = Environment::mock();\n\$headers = new \\Slim\\Http\\Headers();\n";
        $dir = $this->slimApp(['src/Support/Mock.php' => $mock, 'src/routes.php' => $routes, 'src/Support/grouped.php' => $grouped]);

        $result = $this->migrateApp($dir);

        self::assertSame(str_replace('use Slim\\Http\\Environment;', 'use Acme\\Legacy\\LegacyEnvironment as Environment;', $mock), file_get_contents($dir . '/src/Support/Mock.php'));
        self::assertStringContainsString("\$env = \\Acme\\Legacy\\LegacyEnvironment::mock();\n", (string) file_get_contents($dir . '/src/routes.php'));
        self::assertContains("Rewrote Slim 3's Container, Environment, Request::createFromEnvironment() and new Response() to the generated LegacyContainer, LegacyEnvironment and LegacyHttp (and container-interop's ContainerInterface to PSR-11's) in src/Support/Mock.php.", $result->changes);
        self::assertSame([], array_values(array_filter(
            $result->manual,
            static fn(string $line): bool => str_starts_with($line, 'MANUAL: src/routes.php') || str_starts_with($line, 'MANUAL: src/Support/Mock.php'),
        )));

        // A group use cannot be split by itself, and Slim 3's Headers has no Slim 4 form: both are reported.
        self::assertContains('MANUAL: src/Support/grouped.php: lines 5, 7: ' . SlimUpgradeMigration::ENVIRONMENT_NOTE, $result->manual);
        self::assertContains('MANUAL: src/Support/grouped.php: line 8: ' . SlimUpgradeMigration::SLIM3_ONLY_NOTE, $result->manual);
        self::assertStringContainsString('use Slim\\Http\\{Environment, ServerRequest as Request};', (string) file_get_contents($dir . '/src/Support/grouped.php'));

        $second = $this->migrateApp($dir);
        self::assertSame([], $second->files);
    }

    public function test_test_harness_moves_to_the_generated_glue(): void
    {
        $dir = $this->slimApp([
            'tests/Functional/BaseTestCase.php' => self::BASE_TEST_CASE,
            // Folders the hop does not read stay out of the report.
            'scripts/mock.php' => "<?php\n\n\$env = \\Slim\\Http\\Environment::mock();\n",
        ]);

        $result = $this->migrateApp($dir);

        $harness = (string) file_get_contents($dir . '/tests/Functional/BaseTestCase.php');
        self::assertStringContainsString("use Acme\\Legacy\\LegacyEnvironment as Environment;\nuse Slim\\Http\\ServerRequest as Request;\nuse Slim\\Http\\Response;\n", $harness);
        self::assertStringContainsString('$request = \\Acme\\Legacy\\LegacyHttp::createRequest($environment);', $harness);
        self::assertStringContainsString('return $app->process($request, \\Acme\\Legacy\\LegacyHttp::createResponse());', $harness);
        \PhpToken::tokenize($harness, TOKEN_PARSE);
        self::assertContains('Rewrote Slim\\Http\\Request to its slim/http decorator, Slim\\Http\\ServerRequest in tests/Functional/BaseTestCase.php.', $result->changes);
        self::assertContains("Rewrote Slim 3's Container, Environment, Request::createFromEnvironment() and new Response() to the generated LegacyContainer, LegacyEnvironment and LegacyHttp (and container-interop's ContainerInterface to PSR-11's) in tests/Functional/BaseTestCase.php (3 times).", $result->changes);
        self::assertSame([], array_values(array_filter($result->manual, static fn(string $line): bool => str_contains($line, 'BaseTestCase.php') || str_contains($line, 'scripts/mock.php'))));
        self::assertSame((string) file_get_contents($dir . '/scripts/mock.php'), "<?php\n\n\$env = \\Slim\\Http\\Environment::mock();\n");
        self::assertContains(self::HTTP_CHANGE, $result->changes);
    }

    public function test_slim_http_is_required_even_when_the_app_never_names_it(): void
    {
        // Bug S4: Slim 3 apps call getParam(), isPost(), withJson() and write()
        // on the request and response without naming their classes.
        $note = "<?php\n\n// Slim\\Http\\Request was Slim 3's request.\n\$name = 'Slim\\Http\\Response';\n";
        $dir = $this->slimApp([
            'src/note.php' => $note,
            // vendor/ is never read, and scripts/ is outside the folders the hop reads.
            'vendor/slim/slim/Slim/Http/Request.php' => "<?php\n\nnamespace Slim\\Http;\n\nclass Request extends \\Slim\\Http\\Message {}\n",
            'scripts/env.php' => "<?php\n\n\$env = \\Slim\\Http\\Environment::mock();\n",
        ]);

        $result = $this->migrateApp($dir);

        $require = self::composer($dir)['require'];
        self::assertIsArray($require);
        self::assertSame('^1.3', $require['slim/http'] ?? null);
        self::assertSame(['slim/slim', 'pimple/pimple', 'slim/psr7', 'slim/http'], $result->packages);
        self::assertSame([self::HTTP_CHANGE], array_values(array_filter([...$result->changes, ...$result->manual], static fn(string $line): bool => str_contains($line, 'Slim\\Http') || str_contains($line, 'slim/http'))));
        self::assertSame($note, file_get_contents($dir . '/src/note.php'));
    }

    public function test_slim_container_type_hints_move_to_the_generated_container(): void
    {
        // Bug S7 (a Slim 3 fleet app): controllers typed \Slim\Container died with a
        // TypeError on every page once the app ran on the legacy container.
        $controller = <<<'PHP'
            <?php

            namespace Acme\Controller;

            use Slim\Container;
            use Interop\Container\ContainerInterface;

            /** @param \Slim\Container $container (comments stay) */
            class Page
            {
                public function __construct(\Slim\Container $container, ?ContainerInterface $other = null, ?\Interop\Container\ContainerInterface $third = null)
                {
                }

                public static function make(Container $c): self
                {
                    return new self($c);
                }
            }
            PHP;
        $grouped = "<?php\n\nnamespace Acme;\n\nuse Slim\\{App, Container};\n\nfunction box(Container \$c) {}\n";
        $dir = $this->slimApp(['src/Controller/Page.php' => $controller, 'src/grouped.php' => $grouped]);

        $result = $this->migrateApp($dir);

        $page = (string) file_get_contents($dir . '/src/Controller/Page.php');
        \PhpToken::tokenize($page, TOKEN_PARSE);
        self::assertStringContainsString("use Acme\\Legacy\\LegacyContainer as Container;\nuse Psr\\Container\\ContainerInterface;\n", $page);
        self::assertStringContainsString('public function __construct(\\Acme\\Legacy\\LegacyContainer $container, ?ContainerInterface $other = null, ?\\Psr\\Container\\ContainerInterface $third = null)', $page);
        self::assertStringContainsString('public static function make(Container $c): self', $page);
        self::assertStringContainsString('/** @param \\Slim\\Container $container (comments stay) */', $page);
        self::assertContains("Rewrote Slim 3's Container, Environment, Request::createFromEnvironment() and new Response() to the generated LegacyContainer, LegacyEnvironment and LegacyHttp (and container-interop's ContainerInterface to PSR-11's) in src/Controller/Page.php (4 times).", $result->changes);
        self::assertContains('MANUAL: src/grouped.php: lines 5, 7: ' . SlimUpgradeMigration::CONTAINER_NOTE, $result->manual);
        self::assertSame($grouped, file_get_contents($dir . '/src/grouped.php'));

        // A second run finds nothing left to move.
        $again = $this->migrateApp($dir);
        self::assertSame($page, file_get_contents($dir . '/src/Controller/Page.php'));
        self::assertSame([], array_values(array_filter($again->changes, static fn(string $line): bool => str_contains($line, 'Page.php'))));
    }

    public function test_twig_names_move_when_the_hop_moves_twig(): void
    {
        // Bug S8 (a Slim 3 fleet app): Twig 3 has no underscore names, and Twig-View 3's
        // constructor takes a loader.
        $core = "<?php\n\nreturn ['view' => function (\$c) {\n    \$view = new Slim\\Views\\Twig('templates', []);\n    \$view->addExtension(new Twig_Extension_Debug());\n\n    return \$view;\n}];\n";
        $files = ['src/core.php' => $core, 'scripts/mail.php' => "<?php\n\n\$t = new Twig_Environment();\n"];
        $dir = $this->slimApp(['composer.json' => self::composerJson(['slim/twig-view' => '^2.5']), ...$files]);

        $result = $this->migrateApp($dir);

        $moved = (string) file_get_contents($dir . '/src/core.php');
        self::assertStringContainsString("\$view = Slim\\Views\\Twig::create('templates', []);", $moved);
        self::assertStringContainsString('new \\Twig\\Extension\\DebugExtension()', $moved);
        self::assertContains("Rewrote Twig 1 and 2's underscore class names and new Slim\\Views\\Twig(...) to Twig 3's namespaced classes and Twig::create(...) in src/core.php (2 times).", $result->changes);
        self::assertSame($files['scripts/mail.php'], file_get_contents($dir . '/scripts/mail.php'), 'scripts/ is outside the folders the hop reads');

        // An app that keeps no Twig is left alone.
        $plain = $this->slimApp($files);
        $this->migrateApp($plain);
        self::assertSame($core, file_get_contents($plain . '/src/core.php'));
    }

    public function test_slim_3s_uri_base_path_moves_to_the_request_attribute(): void
    {
        // a Slim 3 fleet app (2026-11-20): a signed-in /login/ redirected to Slim 3's
        // $request->getUri()->getBasePath(), a 500 on Slim 4.
        $login = <<<'PHP'
            <?php

            namespace Acme\Controller;

            final class Login
            {
                public function show($request, $response)
                {
                    return $response->withRedirect($request->getUri()->getBasePath() . '/');
                }

                public function home($request, $response)
                {
                    $uri = $request->getUri();

                    return $response->withRedirect($uri->getBasePath());
                }
            }

            PHP;
        $dir = $this->slimApp(['src/Controller/Login.php' => $login]);

        $result = $this->migrateApp($dir);

        $moved = (string) file_get_contents($dir . '/src/Controller/Login.php');
        self::assertStringContainsString("return \$response->withRedirect(\$request->getAttribute(\\Slim\\Routing\\RouteContext::BASE_PATH, '') . '/');", $moved);
        self::assertStringContainsString('return $response->withRedirect($uri->getBasePath());', $moved);
        self::assertContains("Rewrote Slim 3's \$request->getUri()->getBasePath(), which Slim 4's Uri lacks, to the base path the routing glue puts on the request (RouteContext::BASE_PATH) in src/Controller/Login.php.", $result->changes);
        self::assertContains("MANUAL: src/Controller/Login.php: line 16: Slim 4's Uri has no getBasePath(); read \$request->getAttribute(\\Slim\\Routing\\RouteContext::BASE_PATH, '') on the request the route or middleware gets, or \$app->getBasePath().", $result->manual);

        // The glue's own $app->getBasePath() is Slim 4's and stays; a second run changes nothing.
        $after = self::snapshot($dir);
        $this->migrateApp($dir);
        self::assertSame($after, self::snapshot($dir));
    }
}
