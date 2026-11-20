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

    private const ERROR_HANDLER_LINE = "MANUAL: route exceptions now reach Slim's ErrorMiddleware before the app's own PHP error or exception handler; register that handler on the error middleware if you want the old page and logging.";

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
        self::assertSame('Wrote the legacy container, double-pass adapter and app glue in src/Legacy.', $result->changes[0]);
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
        self::assertContains('Wrote LegacyContainer, LegacyDoublePass in src/Legacy.', $result->changes);
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
        self::assertContains('Dry run: would write the legacy container, double-pass adapter and app glue in src/Legacy.', $result->changes);
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
        self::assertSame('^1.7', $require['slim/psr7']);
        self::assertArrayNotHasKey('php-di/php-di', $require);
        self::assertSame(['slim/slim', 'pimple/pimple', 'slim/psr7'], $result->packages);
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

    public function test_manual_report_has_the_error_handler_line(): void
    {
        $dir = $this->slimApp([
            'config/dependencies.php' => "<?php\n\n\$container['view'] = function (\$c) {\n    return new View(\$c->request->getUri(), \$c['response']);\n};\n",
        ]);

        $result = $this->migrateApp($dir);

        self::assertContains(self::ERROR_HANDLER_LINE, $result->manual);
        self::assertSame(self::ERROR_HANDLER_LINE, SlimUpgradeMigration::ERROR_HANDLER_LINE);
        foreach ($result->manual as $line) {
            self::assertStringStartsWith('MANUAL: ', $line);
        }
        self::assertSame($result->manual, array_values(array_unique($result->manual)));
        self::assertContains(
            "MANUAL: templates/home.twig: 2 call(s) of path_for( or base_url(: Twig-View 3 has no path_for; use url_for from the Twig-View runtime extension (base_url is fine when it is the app's own global).",
            $result->manual,
        );
        $reads = array_values(array_filter($result->manual, static fn(string $line): bool => str_starts_with($line, 'MANUAL: config/dependencies.php: 2 read(s)')));
        self::assertCount(1, $reads);
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
        self::assertContains(self::ERROR_HANDLER_LINE, $second->manual);
    }
}
