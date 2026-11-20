<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Replay\ReplayDrafter;
use Waynestate\Booster\Replay\ReplayFile;
use Waynestate\Booster\Replay\RouteCallScanner;

/**
 * Bug 10 (proof run 2026-11-20): `replay init` did not know CakePHP (it
 * drafted docroot ".", /bin/cake.php and /webroot/) and listed Slim routes
 * without their group prefixes.
 */
final class RouteCallScannerTest extends TestCase
{
    /** The shape the fleet's Slim 3 apps use: nested groups over $app, one route at the group's own URL. */
    private const SLIM = <<<'PHP'
        <?php
        $app->get('/', HomeAction::class);
        $app->group('/staff', function () use ($app) {
            $app->get('', 'staff.index');
            $app->get('/report/{id}', 'staff.report');
            $app->group('/admin', function () {
                $this->post('/save', 'admin.save');
                $this->map(['GET', 'POST'], '/users', 'admin.users');
            });
        })->add($auth);
        $app->get('/login/', 'login');
        $c->get('settings');
        $app->group('/api', ApiRoutes::class);
        $app->get('/after', 'after');
        PHP;

    public function test_slim_routes_carry_every_enclosing_group_prefix(): void
    {
        self::assertSame([
            ['GET', '/'],
            ['GET', '/staff'],
            ['GET', '/staff/report/{id}'],
            ['POST', '/staff/admin/save'],
            ['GET', '/staff/admin/users'],
            ['POST', '/staff/admin/users'],
            ['GET', '/login/'],
            ['GET', '/after'],
        ], RouteCallScanner::slim(self::SLIM));
    }

    public function test_slim_4_groups_over_the_collector_proxy_keep_their_prefix(): void
    {
        $code = "<?php\n\$app->group('/v1', function (RouteCollectorProxy \$group) {\n    \$group->get('/notes', Notes::class);\n    \$group->delete('/notes/{id}', Delete::class);\n});\n";

        self::assertSame([['GET', '/v1/notes'], ['DELETE', '/v1/notes/{id}']], RouteCallScanner::slim($code));
    }

    public function test_a_group_carries_its_prefix_into_the_route_classes_it_calls(): void
    {
        // The fleet's Slim 3 shape: config/routes.php groups call each page
        // class's static AddRoutes($app), declared in another file.
        $routes = <<<'PHP'
            <?php
            namespace App\Controller;
            return [
                'login' => function ($app) { Page\Login::addRoutes($app); },
                'staff' => function ($app) {
                    if (in_array('staff', $app->getContainer()['user']->roles)) {
                        $app->group('/staff/', function () use ($app) {
                            \App\Testeval\StaffController::addRoutes($app);
                        });
                    }
                },
                'api' => function ($app) {
                    $app->group('/api/', function () use ($app) {
                        $app->group('wsugeneral/', function () use ($app) {
                            \App\Controller\Api\WSUGeneral::addRoutes($app);
                        });
                    });
                },
            ];
            PHP;
        $login = "<?php\nnamespace App\\Controller\\Page;\nclass Login extends Page\n{\n    static function AddRoutes (\\Slim\\App \$app)\n    {\n        \$app->get('/[login[/]]', __CLASS__ . ':Login');\n        if (\$app->getContainer()['user']->isAuth()) {\n            \$app->get('/logout/', __CLASS__ . ':Logout');\n        }\n    }\n    function Login(\$request, \$response) { return \$this->get('view'); }\n}\n";
        $staff = "<?php\nnamespace App\\Testeval;\nclass StaffController\n{\n    static function AddRoutes(\\Slim\\App \$app)\n    {\n        \$app->get('', __CLASS__ . ':Home');\n        \$app->get('report/{id}', function (\$request, \$response) { return \$this->get('/not-a-route'); });\n    }\n    function Home() { return Model\\Job::GetExports(); }\n}\n";
        $api = "<?php\nnamespace App\\Controller\\Api;\nclass WSUGeneral\n{\n    static function AddRoutes (\\Slim\\App \$app) {\n        \$app->get('terms/', self::class . ':terms');\n        self::more(\$app);\n    }\n    private static function more(\$app) { \$app->post('save/', 'save'); }\n}\n";

        self::assertSame([
            ['GET', '/[login[/]]'],
            ['GET', '/logout/'],
            ['GET', '/staff/'],
            ['GET', '/staff/report/{id}'],
            ['GET', '/api/wsugeneral/terms/'],
            ['POST', '/api/wsugeneral/save/'],
        ], RouteCallScanner::slimFiles([$routes, $login, $staff, $api]));
    }

    public function test_cakephp_routes_read_scopes_prefixes_connects_and_fallbacks(): void
    {
        $code = <<<'PHP'
            <?php
            use Cake\Routing\Route\DashedRoute;
            use Cake\Routing\RouteBuilder;

            return function (RouteBuilder $routes): void {
                $routes->setRouteClass(DashedRoute::class);
                $routes->scope('/', function (RouteBuilder $builder): void {
                    $builder->connect('/', ['controller' => 'Pages', 'action' => 'display', 'home']);
                    $builder->connect('/pages/*', 'Pages::display');
                    $builder->fallbacks();
                });
                $routes->scope('/api', function (RouteBuilder $builder): void {
                    $builder->get('/status', 'Status::index');
                    $builder->resources('UserProfiles');
                });
                $routes->prefix('Admin', function (RouteBuilder $builder): void {
                    $builder->connect('/', ['controller' => 'Dashboard', 'action' => 'index']);
                    $builder->fallbacks();
                });
                $routes->plugin('DebugKit', ['path' => '/debug-kit'], function (RouteBuilder $builder): void {
                    $builder->fallbacks();
                });
            };
            PHP;

        $found = RouteCallScanner::cake($code);

        self::assertSame([
            ['GET', '/', " ['controller' => 'Pages', 'action' => 'display', 'home']"],
            ['GET', '/pages/*', " 'Pages::display'"],
            ['GET', '/api/status', " 'Status::index'"],
            ['GET', '/api/user-profiles', ''],
            ['GET', '/admin', " ['controller' => 'Dashboard', 'action' => 'index']"],
        ], $found['routes']);
        self::assertSame([['/', ''], ['/admin', 'Admin']], $found['fallbacks']);
        self::assertTrue($found['dashed']);
        self::assertSame('view_all', RouteCallScanner::inflect('viewAll', false));
        self::assertSame('user-profiles', RouteCallScanner::inflect('UserProfiles', true));
    }

    /** The CakePHP 4 skeleton plus one app controller, the shape of the fleet's CakePHP 4 apps. */
    private function cakeApp(): string
    {
        $app = $this->tempDir();
        foreach (['config', 'webroot', 'bin', 'src/Controller/Admin', 'templates/Pages'] as $dir) {
            mkdir($app . '/' . $dir, 0777, true);
        }
        file_put_contents($app . '/composer.json', (string) json_encode(['require' => ['php' => '>=7.4', 'cakephp/cakephp' => '4.5.*']]));
        file_put_contents($app . '/config/routes.php', "<?php\nuse Cake\\Routing\\Route\\DashedRoute;\nuse Cake\\Routing\\RouteBuilder;\nreturn function (RouteBuilder \$routes): void {\n    \$routes->setRouteClass(DashedRoute::class);\n    \$routes->scope('/', function (RouteBuilder \$builder): void {\n        \$builder->connect('/', ['controller' => 'Dashboard', 'action' => 'index']);\n        \$builder->connect('/pages/*', 'Pages::display');\n        \$builder->fallbacks();\n    });\n};\n");
        file_put_contents($app . '/webroot/index.php', "<?php\n");
        file_put_contents($app . '/webroot/phpinfo.php', "<?php phpinfo();\n");
        file_put_contents($app . '/bin/cake.php', "<?php\n");
        file_put_contents($app . '/templates/Pages/home.php', "<h1>Home</h1>\n");
        file_put_contents($app . '/src/Controller/AppController.php', "<?php\nclass AppController extends Controller { public function initialize(): void {} }\n");
        file_put_contents($app . '/src/Controller/PagesController.php', "<?php\nclass PagesController extends AppController { public function display(string ...\$path) {} }\n");
        file_put_contents($app . '/src/Controller/StudentRostersController.php', "<?php\nclass StudentRostersController extends AppController\n{\n    public function initialize(): void {}\n    public function beforeFilter(EventInterface \$event) {}\n    public function index() {}\n    public function viewAll(\$term = null) {}\n    public function edit(\$id) {}\n    protected function load() {}\n    private function _helper() {}\n}\n");
        file_put_contents($app . '/src/Controller/Admin/UsersController.php', "<?php\nclass UsersController extends AppController { public function index() {} }\n");

        return $app;
    }

    public function test_replay_init_drafts_a_cakephp_app_from_its_routes_and_controllers(): void
    {
        $app = $this->cakeApp();

        $draft = (new ReplayDrafter())->draft($app);
        $data = (array) json_decode($draft['json'], true);
        $file = ReplayFile::fromArray($data);

        self::assertSame('webroot', $file->docroot);
        self::assertSame('index.php', $file->frontController);
        self::assertSame(ReplayDrafter::CAKE_SETUP, $data['serve']['setup']);
        self::assertSame('false', $data['serve']['env']['DEBUG']);
        self::assertStringStartsWith('config/routes.php', $draft['source']);
        self::assertSame([
            'GET /',
            'GET /pages/home',
            'GET /student-rosters',
            'GET /student-rosters/view-all',
            'GET /student-rosters/edit/{id}',
            'GET /booster-replay-no-such-page',
        ], array_map(static fn($r): string => $r->method . ' ' . $r->path, $file->requests));
        self::assertStringContainsString('replace the placeholders in the path', implode("\n", $file->todos));
        // Never the CLI entry point, the webroot folder or a phpinfo() page.
        self::assertStringNotContainsString('cake.php', $draft['json']);
        self::assertStringNotContainsString('phpinfo', $draft['json']);
    }

    public function test_a_phpinfo_page_is_never_drafted_for_a_raw_php_app(): void
    {
        $app = $this->tempDir();
        file_put_contents($app . '/index.php', "<?php echo 'home';\n");
        file_put_contents($app . '/info.php', "<?php phpinfo();\n");

        $draft = (new ReplayDrafter())->draft($app);

        self::assertStringNotContainsString('info.php', $draft['json']);
        self::assertStringContainsString('"path": "/"', $draft['json']);
    }

    public function test_replay_init_drafts_slim_group_routes_with_their_prefix(): void
    {
        $app = $this->tempDir();
        mkdir($app . '/public');
        mkdir($app . '/config');
        file_put_contents($app . '/public/index.php', "<?php\nrequire __DIR__ . '/../config/routes.php';\n\$app->run();\n");
        file_put_contents($app . '/config/routes.php', self::SLIM);

        $draft = (new ReplayDrafter())->draft($app);
        $file = ReplayFile::fromArray((array) json_decode($draft['json'], true));

        self::assertSame('public', $file->docroot);
        self::assertSame('index.php', $file->frontController);
        self::assertSame([
            'GET /',
            'GET /staff',
            'GET /staff/report/{id}',
            'GET /staff/admin/users',
            'GET /login/',
            'GET /after',
            'GET /booster-replay-no-such-page',
            'POST /staff/admin/save',
            'POST /staff/admin/users',
        ], array_map(static fn($r): string => $r->method . ' ' . $r->path, $file->requests));
    }
}
