<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Psr\Container\ContainerInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Waynestate\Booster\Run\Slim\RouteRewriter;

/**
 * The Slim 3 route and middleware rewrite. Text checks pin the exact edits;
 * the group checks also run the rewritten routes on slim/slim 4.15 (a dev
 * dependency) with a container set, as the hop's LegacyApp does, to prove a
 * grouped route keeps its prefix.
 */
final class SlimRouteRewriterTest extends TestCase
{
    private const NS = 'App\\Slim4';

    private const PROXY = '\\Slim\\Interfaces\\RouteCollectorProxyInterface';

    /**
     * @return array{code: string, changes: list<string>, manual: list<string>}
     */
    private static function rewrite(string $php): array
    {
        return (new RouteRewriter())->rewrite($php, self::NS);
    }

    /** Runs rewritten route code (with `$app` in scope) on a Slim 4 app that has a container, and answers $path. */
    private function serve(string $code, string $path): string
    {
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
        eval(substr($code, strlen('<?php')));
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost' . $path);

        return (string) $app->handle($request)->getBody();
    }

    public function test_group_head_gets_the_proxy_parameter_and_drops_app_from_use(): void
    {
        $php = <<<'PHP'
            <?php
            $app->group('/api/', function () use ($app) {
                $app->get('ping', function ($request, $response) {
                    $response->getBody()->write('pong');

                    return $response;
                });
                $app->group('v2/', function() use ($app) {
                    $app->get('ping', function ($request, $response) {
                        $response->getBody()->write('pong two');

                        return $response;
                    });
                });
            });

            PHP;

        $result = self::rewrite($php);

        self::assertStringContainsString("\$app->group('/api/', function (" . self::PROXY . ' $app) {', $result['code']);
        self::assertStringContainsString("\$app->group('v2/', function (" . self::PROXY . ' $app) {', $result['code']);
        self::assertStringNotContainsString('use ($app)', $result['code']);
        self::assertSame(['Rewrote 2 route group callable(s) to take the group\'s RouteCollectorProxyInterface.'], $result['changes']);
        self::assertSame([], $result['manual']);

        self::assertSame('pong', $this->serve($result['code'], '/api/ping'));
        self::assertSame('pong two', $this->serve($result['code'], '/api/v2/ping'));

        $property = self::rewrite("<?php\n\$this->slim->group('/x', function () use (\$slim) {\n    Home::addRoutes(\$slim);\n});\n\$this->app->group('/y', function () use (\$app) {\n    Y::addRoutes(\$app);\n});\n");
        self::assertSame(
            "<?php\n\$this->slim->group('/x', function (" . self::PROXY . " \$slim) {\n    Home::addRoutes(\$slim);\n});\n\$this->app->group('/y', function (" . self::PROXY . " \$app) {\n    Y::addRoutes(\$app);\n});\n",
            $property['code'],
        );
    }

    public function test_group_keeps_other_use_variables(): void
    {
        $php = "<?php\n\$app->group('/staff/', function () use (\$app, \$user, &\$count) {\n    \$count++;\n});\n"
            . "\$app->group('/admin/', function () use (\$c, \$app) : void {\n    Admin::addRoutes(\$app, \$c);\n});\n";

        $result = self::rewrite($php);

        self::assertSame(
            "<?php\n\$app->group('/staff/', function (" . self::PROXY . " \$app) use (\$user, &\$count) {\n    \$count++;\n});\n"
            . "\$app->group('/admin/', function (" . self::PROXY . " \$app) use (\$c) : void {\n    Admin::addRoutes(\$app, \$c);\n});\n",
            $result['code'],
        );
        \PhpToken::tokenize($result['code'], TOKEN_PARSE);
    }

    public function test_group_using_this_is_manual_and_untouched(): void
    {
        $php = <<<'PHP'
            <?php

            $app->group('/me/', function () use ($app) {
                $this->get('profile', 'Profile:show');
            });
            $app->group('/you/', function () {
                $app->get('profile', 'Profile:show');
            });
            $app->group('/them/', function () {
                Them::addRoutes();
            });

            PHP;

        $result = self::rewrite($php);

        self::assertSame($php, $result['code']);
        self::assertSame([], $result['changes']);
        self::assertSame([
            'MANUAL: route group at line 3 uses $this; Slim 4 binds the group callable to the container, review it.',
            'MANUAL: route group at line 6 calls $app without use ($app); give the group callable a ' . self::PROXY . ' $app parameter by hand.',
        ], $result['manual']);
    }

    public function test_group_body_is_byte_for_byte(): void
    {
        $body = <<<'PHP'
            {
                // use ($app) stays a comment;   odd   spacing stays too
                $app->get( 'a' , 'A:a' ) ;
                $label = "function () use (\$app) {";
                $html = <<<HTML
                    <p>{$label} \$app->add(\$x)</p>
                    HTML;
                if ($app->getContainer()->has('user')) { Users::addRoutes($app); }
            }
            PHP;
        $php = "<?php\n\$app->group('/a/', function () use (\$app) " . $body . ");\n";

        $result = self::rewrite($php);

        self::assertSame(
            "<?php\n\$app->group('/a/', function (" . self::PROXY . ' $app) ' . $body . ");\n",
            $result['code'],
        );
    }

    public function test_static_group_callable(): void
    {
        $php = <<<'PHP'
            <?php
            $app->group('/s/', static function () use ($app) {
                $app->get('one', function ($request, $response) {
                    $response->getBody()->write('one');

                    return $response;
                });
            });
            $app->group('/f/', static fn () => $app->get('two', function ($request, $response) {
                $response->getBody()->write('two');

                return $response;
            }));

            PHP;

        $result = self::rewrite($php);

        // Slim 4 binds a group callable to the container, and a static closure cannot be bound, so `static` goes.
        self::assertStringContainsString("\$app->group('/s/', function (" . self::PROXY . ' $app) {', $result['code']);
        self::assertStringContainsString("\$app->group('/f/', fn (" . self::PROXY . " \$app) => \$app->get('two',", $result['code']);
        self::assertStringNotContainsString('static', $result['code']);
        self::assertSame(['Rewrote 2 route group callable(s) to take the group\'s RouteCollectorProxyInterface.'], $result['changes']);

        self::assertSame('one', $this->serve($result['code'], '/s/one'));
        self::assertSame('two', $this->serve($result['code'], '/f/two'));
    }

    public function test_add_params_widened_only_when_a_group_is_rewritten(): void
    {
        $controller = <<<'PHP'
            <?php

            namespace App\Controller;

            use Slim\App;

            class Login
            {
                public static function addRoutes(App $app)
                {
                    $app->group('/login', function () use ($app) {
                        $app->get('', __CLASS__ . ':show');
                    });
                }

                public static function other(\Slim\App $app)
                {
                    $app->get('/other', __CLASS__ . ':other');
                }

                public static function boot(\Slim\App $app)
                {
                    return $app->getContainer();
                }
            }

            PHP;

        $result = self::rewrite($controller);

        self::assertStringContainsString('public static function addRoutes(' . self::PROXY . ' $app)', $result['code']);
        self::assertStringContainsString('public static function other(\\Slim\\App $app)', $result['code']);
        self::assertStringContainsString('public static function boot(\\Slim\\App $app)', $result['code']);
        self::assertStringContainsString('use Slim\\App;', $result['code']);
        self::assertContains('Widened 1 route adder parameter(s) from \\Slim\\App to RouteCollectorProxyInterface.', $result['changes']);
        self::assertSame([
            'MANUAL: line 16: $app is typed \\Slim\\App in a route adder; inside a Slim 4 group it gets a RouteCollectorProxy, so widen it to ' . self::PROXY . ' when a group calls it.',
        ], $result['manual']);

        $noRewrittenGroup = "<?php\nclass Routes\n{\n    public function addRoutes(\\Slim\\App \$app)\n    {\n        \$app->group('/x', 'App\\Routes:group');\n    }\n}\n";
        $untouched = self::rewrite($noRewrittenGroup);
        self::assertSame($noRewrittenGroup, $untouched['code']);
        self::assertSame([], $untouched['changes']);
        self::assertCount(1, $untouched['manual']);
        self::assertStringStartsWith('MANUAL: line 4: $app is typed \\Slim\\App in a route adder', $untouched['manual'][0]);
    }

    public function test_app_add_is_wrapped_in_double_pass(): void
    {
        $php = <<<'PHP'
            <?php
            $app->add(new \App\Middleware\CheckSession($container));
            foreach ($middleware as $callback) {
                $this->app->add($callback($this->app));
            }
            $this->slim->add(function ($request, $response, $next) {
                return $next($request, $response);
            });

            PHP;

        $result = self::rewrite($php);

        self::assertSame(<<<'PHP'
            <?php
            $app->add(new \App\Slim4\LegacyDoublePass(new \App\Middleware\CheckSession($container)));
            foreach ($middleware as $callback) {
                $this->app->add(new \App\Slim4\LegacyDoublePass($callback($this->app)));
            }
            $this->slim->add(new \App\Slim4\LegacyDoublePass(function ($request, $response, $next) {
                return $next($request, $response);
            }));

            PHP, $result['code']);
        self::assertSame(['Wrapped 3 middleware registration(s) in LegacyDoublePass.'], $result['changes']);
        \PhpToken::tokenize($result['code'], TOKEN_PARSE);
    }

    public function test_route_add_chain_is_wrapped(): void
    {
        $php = <<<'PHP'
            <?php
            $app->get('/x', 'X:show')->add($auth)->add(new Csrf());
            $app->get('/y', 'Y:show')->setName('y')->add(new Flash());
            $app->group('/g/', function () use ($app) {
                $app->post('save', 'G:save')->add('csrf');
            })->add(new Auth($app->getContainer()));

            PHP;

        $result = self::rewrite($php);

        self::assertSame(<<<'PHP'
            <?php
            $app->get('/x', 'X:show')->add(new \App\Slim4\LegacyDoublePass($auth))->add(new \App\Slim4\LegacyDoublePass(new Csrf()));
            $app->get('/y', 'Y:show')->setName('y')->add(new \App\Slim4\LegacyDoublePass(new Flash()));
            $app->group('/g/', function (\Slim\Interfaces\RouteCollectorProxyInterface $app) {
                $app->post('save', 'G:save')->add('csrf');
            })->add(new \App\Slim4\LegacyDoublePass(new Auth($app->getContainer())));

            PHP, $result['code']);
        self::assertContains('Wrapped 4 middleware registration(s) in LegacyDoublePass.', $result['changes']);
        // Middleware added by container name cannot be wrapped in the file; the entry is.
        self::assertSame(
            ["MANUAL: middleware 'csrf' at line 5 is added by its container name; Slim 4 resolves that entry as PSR-15, so wrap it in \\App\\Slim4\\LegacyDoublePass by hand."],
            $result['manual'],
        );
    }

    public function test_add_on_other_receivers_is_untouched(): void
    {
        $php = <<<'PHP'
            <?php
            $container['csrf']->add($guard);
            $this->container->get('flash')->add('info', 'Saved');
            $this->container->csrf->add($guard);
            $app->getContainer()->get('twig')->add($extension);
            $twig->add($extension);
            $collection->add($item);
            $this->items->add($item);
            $app->add();

            PHP;

        $result = self::rewrite($php);

        self::assertSame($php, $result['code']);
        self::assertSame([], $result['changes']);
    }

    public function test_already_wrapped_add_is_not_wrapped_again(): void
    {
        $php = <<<'PHP'
            <?php
            use App\Slim4\LegacyDoublePass;

            $app->add(new \App\Slim4\LegacyDoublePass(new A()));
            $app->add(new LegacyDoublePass(new B()));
            $app->get('/c', 'C:show')->add(new \app\slim4\legacydoublepass($c));

            PHP;

        $result = self::rewrite($php);

        self::assertSame($php, $result['code']);
        self::assertSame([], $result['changes']);
    }

    public function test_get_attribute_route_becomes_route_context(): void
    {
        $php = <<<'PHP'
            <?php
            $route = $request->getAttribute('route');
            $id = $req->getAttribute("route")->getArgument('id');
            $name = $request->getAttribute('routeName');
            $other = $this->request->getAttribute('route');

            PHP;

        $result = self::rewrite($php);

        self::assertSame(<<<'PHP'
            <?php
            $route = \Slim\Routing\RouteContext::fromRequest($request)->getRoute();
            $id = \Slim\Routing\RouteContext::fromRequest($req)->getRoute()->getArgument('id');
            $name = $request->getAttribute('routeName');
            $other = $this->request->getAttribute('route');

            PHP, $result['code']);
        self::assertSame(['Replaced 2 getAttribute(\'route\') read(s) with RouteContext::fromRequest()->getRoute().'], $result['changes']);
        self::assertSame(
            ["MANUAL: the route is only on the request after Slim's routing middleware ran; code that reads it in outer middleware sees null."],
            $result['manual'],
        );
    }

    public function test_path_for_is_manual_with_a_line_number(): void
    {
        $php = <<<'PHP'
            <?php

            $url = $this->router->pathFor('home', ['id' => 1]);
            $full = $router->urlFor('home');
            $fine = RouteContext::fromRequest($request)->getRouteParser()->urlFor('home');
            $info = $request->getAttribute('routeInfo');
            $sub = $app->subRequest('GET', '/inner');
            $env = \Slim\Http\Environment::mock(['REQUEST_URI' => '/']);
            $route->setArgument('id', '5');
            $router = $app->getContainer()->get('router');
            $router = $container['router'];
            $router = $c->router;
            $ok = $response->withStatus(200)->withHeader('Content-Length', '3');

            PHP;

        $result = self::rewrite($php);

        self::assertSame($php, $result['code']);
        self::assertSame([
            'MANUAL: pathFor() at line 3 has no like-for-like; build the URL with RouteContext::fromRequest($request)->getRouteParser()->urlFor().',
            'MANUAL: urlFor() at line 4 has no like-for-like; build the URL with RouteContext::fromRequest($request)->getRouteParser()->urlFor().',
            "MANUAL: getAttribute('routeInfo') at line 6 has no Slim 4 equivalent; use RouteContext::fromRequest(\$request)->getRoutingResults().",
            'MANUAL: subRequest() at line 7 was removed in Slim 4; call $app->handle() with a new request instead.',
            'MANUAL: setArgument() at line 9: Route::setArgument exists in Slim 4; check the name.',
            'MANUAL: line 8 uses Slim\\Http\\Environment, which Slim 4 removed; build requests with a PSR-17 ServerRequestFactory.',
            "MANUAL: line 10 reads Slim 3's router from the container; Slim 4 has no 'router' entry, use \$app->getRouteCollector() or RouteContext::fromRequest(\$request)->getRouteParser().",
            "MANUAL: line 11 reads Slim 3's router from the container; Slim 4 has no 'router' entry, use \$app->getRouteCollector() or RouteContext::fromRequest(\$request)->getRouteParser().",
            "MANUAL: line 12 reads Slim 3's router from the container; Slim 4 has no 'router' entry, use \$app->getRouteCollector() or RouteContext::fromRequest(\$request)->getRouteParser().",
        ], $result['manual']);
    }

    public function test_strings_and_comments_untouched(): void
    {
        $php = <<<'PHP'
            <?php
            // $app->group('/x', function () use ($app) { $app->add($mw); });
            /* $request->getAttribute('route'); $router->pathFor('home'); */
            $doc = '$app->add(new Mw()); $request->getAttribute(\'route\')';
            $html = <<<HTML
                <a href="\$this->router->pathFor('home')">function () use (\$app)</a>
                HTML;
            /** @param \Slim\App $app $app->subRequest() */
            $text = "\$app->get('/x', 'X:y')->add(\$auth)";

            PHP;

        $result = self::rewrite($php);

        self::assertSame($php, $result['code']);
        self::assertSame([], $result['changes']);
        self::assertSame([], $result['manual']);
    }

    public function test_rewrite_is_idempotent(): void
    {
        $php = <<<'PHP'
            <?php

            use Slim\App;

            final class Routes
            {
                public static function addRoutes(App $app): void
                {
                    $app->group('/api/', function () use ($app, $c) {
                        $app->get('user/{ id }[/]', 'Api\User:show')->add(new CheckAuth($c));
                        $app->group('admin/', static function () use ($app) {
                            Admin::addRoutes($app);
                        });
                    })->add(new RoleCheck());
                    $app->add(new SessionMiddleware());
                }

                public function show($request, $response, array $args)
                {
                    $id = $request->getAttribute('route')->getArgument('id');
                    $url = $this->router->pathFor('home');

                    return $response;
                }
            }

            PHP;

        $first = self::rewrite($php);
        $second = self::rewrite($first['code']);

        self::assertNotSame($php, $first['code']);
        self::assertSame($first['code'], $second['code']);
        self::assertSame([], $second['changes']);
        self::assertCount(4, $first['changes']);
        self::assertSame(3, substr_count($first['code'], 'LegacyDoublePass('));
        self::assertSame(1, substr_count($first['code'], 'RouteContext::fromRequest($request)->getRoute()'));
        self::assertSame(3, substr_count($first['code'], self::PROXY));
        \PhpToken::tokenize($first['code'], TOKEN_PARSE);
    }
}
