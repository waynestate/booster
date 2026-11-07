<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

/**
 * The Slim 1 bridge the vendored-to-composer hop writes into an app. Each
 * test drives it the way a 2012 Slim 1 app does and checks the answer Slim
 * 1.6.7 gave: same status, same headers, same bytes. Slim 4 does the routing
 * underneath (slim/slim and slim/psr7 are dev dependencies for this reason).
 */
final class Slim1BridgeTest extends TestCase
{
    private const BRIDGE = __DIR__ . '/../resources/slim1/slim1-bridge.php';

    /** Slim 1.6.7's page chrome, copied from its source. */
    private const PAGE = '<html><head><title>%s</title><style>body{margin:0;padding:30px;font:12px/1.5 Helvetica,Arial,Verdana,sans-serif;}h1{margin:0;font-size:48px;font-weight:normal;line-height:48px;}strong{display:inline-block;width:65px;}</style></head><body><h1>%s</h1>%s</body></html>';

    public static function setUpBeforeClass(): void
    {
        require_once self::BRIDGE;
    }

    /**
     * A Slim app answering one request. $env is merged over a plain GET in
     * Slim 1's environment shape.
     *
     * @param array<string, mixed> $env
     * @param array<string, mixed> $settings
     */
    private static function app(string $method, string $path, array $env = [], array $settings = []): \Slim
    {
        $app = new \Slim($settings);
        $app->bridgeEnvironment(array_merge([
            'REQUEST_METHOD' => $method,
            'SCRIPT_NAME' => '',
            'PATH_INFO' => $path,
            'QUERY_STRING' => '',
            'SERVER_NAME' => 'localhost',
            'SERVER_PORT' => 80,
            'REMOTE_ADDR' => '127.0.0.1',
            'slim.url_scheme' => 'http',
            'slim.input' => '',
        ], $env));

        return $app;
    }

    public function test_a_route_gets_its_params_in_pattern_order_url_decoded(): void
    {
        $app = self::app('GET', '/batches/a%20b/items/x%2By');
        $app->get('/batches/:batch/items/:item', static function (string $batch, string $item): void {
            echo $batch . '|' . $item;
        });

        [$status, $headers, $body] = $app->bridgeHandle();

        self::assertSame(200, $status);
        self::assertSame('a b|x+y', $body);
        self::assertSame(['Content-Type' => 'text/html'], $headers);
    }

    public function test_a_percent_sign_is_decoded_once_as_in_slim_1(): void
    {
        $app = self::app('GET', '/p/%2541');
        $app->get('/p/:value', static function (string $value): void {
            echo $value;
        });

        self::assertSame('%41', $app->bridgeHandle()[2]);
    }

    public function test_the_first_registered_route_wins_even_over_a_later_static_route(): void
    {
        $app = self::app('GET', '/users/new');
        $app->get('/users/:id', static function (string $id): void {
            echo 'user ' . $id;
        });
        // Dead code in Slim 1 (the route above matched first). FastRoute
        // would refuse to build its table; the bridge leaves it out.
        $app->get('/users/new', static function (): void {
            echo 'new form';
        });
        // The same pattern twice: Slim 1 ran the first.
        $app->get('/users/:name', static function (string $name): void {
            echo 'never';
        });

        self::assertSame('user new', $app->bridgeHandle()[2]);
    }

    public function test_a_static_route_before_a_variable_route_still_wins(): void
    {
        $app = self::app('GET', '/users/new');
        $app->get('/users/new', static function (): void {
            echo 'new form';
        });
        $app->get('/users/:id', static function (string $id): void {
            echo 'user ' . $id;
        });

        self::assertSame('new form', $app->bridgeHandle()[2]);
    }

    public function test_an_unknown_path_gets_slim_1s_404_page_byte_for_byte(): void
    {
        $app = self::app('GET', '/nowhere');
        $app->get('/', static function (): void {
            echo 'home';
        });

        [$status, $headers, $body] = $app->bridgeHandle();

        self::assertSame(404, $status);
        self::assertSame('text/html', $headers['Content-Type']);
        $expected = sprintf(self::PAGE, '404 Page Not Found', '404 Page Not Found', '<p>The page you are looking for could not be found. Check the address bar to ensure your URL is spelled correctly. If all else fails, you can visit our home page at the link below.</p><a href="/">Visit the Home Page</a>');
        self::assertSame($expected, $body);
    }

    public function test_a_custom_not_found_handler_writes_the_404_body(): void
    {
        $app = self::app('GET', '/nowhere');
        $app->notFound(static function (): void {
            echo 'lost';
        });

        self::assertSame([404, ['Content-Type' => 'text/html'], 'lost'], $app->bridgeHandle());
    }

    public function test_a_known_path_with_the_wrong_method_gets_a_405_with_allow(): void
    {
        $app = self::app('DELETE', '/batches/7');
        $app->get('/batches/:id', static function (string $id): void {});
        $app->post('/batches/:id', static function (string $id): void {});

        [$status, $headers, $body] = $app->bridgeHandle();

        self::assertSame(405, $status);
        self::assertSame('GET HEAD POST', $headers['Allow']);
        self::assertSame('HTTP method not allowed for the requested resource. Use one of these instead: GET, HEAD, POST', $body);
    }

    public function test_redirect_answers_302_with_the_location_and_the_url_as_body(): void
    {
        $app = self::app('GET', '/old');
        $app->get('/old', static function () use (&$app): void {
            echo 'thrown away';
            $app->redirect('/new');
        });

        [$status, $headers, $body] = $app->bridgeHandle();

        self::assertSame(302, $status);
        self::assertSame('/new', $headers['Location']);
        self::assertSame('/new', $body);
    }

    public function test_halt_discards_what_the_route_echoed(): void
    {
        $app = self::app('GET', '/secret');
        $app->get('/secret', static function () use (&$app): void {
            echo 'half a page';
            $app->halt(403, 'Forbidden');
        });

        self::assertSame([403, ['Content-Type' => 'text/html'], 'Forbidden'], $app->bridgeHandle());
    }

    public function test_stop_keeps_what_the_route_echoed(): void
    {
        $app = self::app('GET', '/early');
        $app->get('/early', static function () use (&$app): void {
            echo 'kept';
            $app->stop();
        });

        self::assertSame('kept', $app->bridgeHandle()[2]);
    }

    public function test_render_goes_through_the_apps_own_view_class(): void
    {
        $dir = sys_get_temp_dir() . '/booster-slim1-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/hello.php', 'Hello <?php echo $name; ?> from <?php echo $site; ?>');
        try {
            $view = new class extends \Slim_View {
                public function render($template)
                {
                    return '[' . parent::render($template) . ']';
                }
            };
            $app = self::app('GET', '/hi/Ada', [], ['templates.path' => $dir . '/', 'view' => $view]);
            $app->view()->setData('site', 'WSU');
            $app->get('/hi/:name', static function (string $name) use (&$app): void {
                $app->render('hello.php', ['name' => $name], 201);
            });

            [$status, , $body] = $app->bridgeHandle();

            self::assertSame(201, $status);
            self::assertSame('[Hello Ada from WSU]', $body);
        } finally {
            @unlink($dir . '/hello.php');
            @rmdir($dir);
        }
    }

    public function test_params_merge_the_query_and_the_form_body_and_the_raw_body_stays_readable(): void
    {
        $app = self::app('POST', '/save', [
            'QUERY_STRING' => 'page=2&q=old',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'slim.input' => 'q=new&name=Ada+L',
        ]);
        $app->post('/save', static function () use (&$app): void {
            $request = $app->request();
            echo json_encode([$request->params('page'), $request->params('q'), $request->post('name'), $request->get('q'), $request->getBody()]);
        });

        self::assertSame('["2","new","Ada L","old","q=new&name=Ada+L"]', $app->bridgeHandle()[2]);
    }

    public function test_method_override_reaches_a_put_route(): void
    {
        $app = self::app('POST', '/batches/3', [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'slim.input' => '_METHOD=PUT&title=x',
        ]);
        $app->put('/batches/:id', static function (string $id) use (&$app): void {
            echo 'put ' . $id . ' ' . $app->request()->put('title') . ' ' . $app->request()->getMethod();
        });

        self::assertSame('put 3 x PUT', $app->bridgeHandle()[2]);
    }

    public function test_a_pattern_with_a_trailing_slash_redirects_the_bare_path(): void
    {
        $app = self::app('GET', '/reports');
        $app->get('/reports/', static function (): void {
            echo 'reports';
        });

        [$status, $headers] = $app->bridgeHandle();

        self::assertSame(301, $status);
        self::assertSame('/reports/', $headers['Location']);

        $app = self::app('GET', '/reports/');
        $app->get('/reports/', static function (): void {
            echo 'reports';
        });
        self::assertSame('reports', $app->bridgeHandle()[2]);
    }

    public function test_conditions_restrict_a_param_including_ones_with_groups(): void
    {
        $route = static function (\Slim $app): void {
            $app->get('/n/:id/:kind', static function (string $id, string $kind): void {
                echo $id . ' ' . $kind;
            })->conditions(['id' => '\d+', 'kind' => '(open|closed)']);
        };

        $app = self::app('GET', '/n/12/open');
        $route($app);
        self::assertSame('12 open', $app->bridgeHandle()[2]);

        $app = self::app('GET', '/n/abc/open');
        $route($app);
        self::assertSame(404, $app->bridgeHandle()[0]);
    }

    public function test_route_middleware_runs_before_the_route(): void
    {
        $app = self::app('GET', '/admin');
        $app->get('/admin', static function () use (&$app): void {
            $app->halt(401, 'sign in first');
        }, static function (): void {
            echo 'never';
        });

        self::assertSame([401, ['Content-Type' => 'text/html'], 'sign in first'], $app->bridgeHandle());
    }

    public function test_an_exception_in_debug_mode_shows_slim_1s_error_page(): void
    {
        $app = self::app('GET', '/boom');
        $app->get('/boom', static function (): void {
            throw new \RuntimeException('Database went for coffee');
        });

        [$status, , $body] = $app->bridgeHandle();

        self::assertSame(500, $status);
        self::assertStringStartsWith('<html><head><title>Slim Application Error</title>', $body);
        self::assertStringContainsString('<div><strong>Message:</strong> Database went for coffee</div>', $body);
    }

    public function test_an_exception_outside_debug_mode_uses_the_error_page_or_the_apps_handler(): void
    {
        $app = self::app('GET', '/boom', [], ['debug' => false]);
        $app->get('/boom', static function (): void {
            throw new \RuntimeException('nope');
        });
        self::assertSame([500, ['Content-Type' => 'text/html'], sprintf(self::PAGE, 'Error', 'Error', '<p>A website error has occured. The website administrator has been notified of the issue. Sorry for the temporary inconvenience.</p>')], $app->bridgeHandle());

        $app = self::app('GET', '/boom', [], ['debug' => false]);
        $app->error(static function (\Exception $e): void {
            echo 'sorry: ' . $e->getMessage();
        });
        $app->get('/boom', static function (): void {
            throw new \RuntimeException('nope');
        });
        self::assertSame('sorry: nope', $app->bridgeHandle()[2]);
    }

    public function test_the_app_can_hang_its_own_properties_on_slim(): void
    {
        $app = self::app('GET', '/');
        // A real app kept its entity manager and user here; PHP 8.2 would
        // warn about dynamic properties without the attribute on the class.
        $app->em = 'entity manager';
        $app->get('/', static function () use (&$app): void {
            echo $app->em;
        });

        self::assertSame('entity manager', $app->bridgeHandle()[2]);
    }

    public function test_mode_comes_from_the_settings_and_configure_mode_runs_for_it(): void
    {
        $app = self::app('GET', '/', [], ['mode' => 'production']);
        $ran = [];
        $app->configureMode('production', static function () use (&$ran): void {
            $ran[] = 'production';
        });
        $app->configureMode('development', static function () use (&$ran): void {
            $ran[] = 'development';
        });

        self::assertSame('production', $app->getMode());
        self::assertSame(['production'], $ran);
    }

    public function test_url_for_builds_the_path_of_a_named_route(): void
    {
        $app = self::app('GET', '/', ['SCRIPT_NAME' => '/wsam']);
        $app->get('/batches/:id', static function (string $id): void {})->name('batch');

        self::assertSame('/wsam/batches/42', $app->urlFor('batch', ['id' => 42]));
    }

    public function test_a_204_answer_drops_the_body_and_content_type(): void
    {
        $app = self::app('GET', '/ping');
        $app->get('/ping', static function () use (&$app): void {
            echo 'ignored';
            $app->status(204);
        });

        self::assertSame([204, [], ''], $app->bridgeHandle());
    }

    /**
     * Slim 1's Slim_Environment rules for the path an app sees: the script
     * name comes off when the URL names it, its folder when a rewrite hid
     * it, and the query string never counts.
     */
    public function test_the_environment_strips_the_script_or_its_folder_and_the_query(): void
    {
        $server = $_SERVER;
        try {
            $_SERVER = ['REQUEST_METHOD' => 'GET', 'SCRIPT_NAME' => '/wsam/index.php', 'REQUEST_URI' => '/wsam/index.php/batches/7?x=1', 'QUERY_STRING' => 'x=1', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];
            $env = \Slim_Http_Request::environmentFromGlobals();
            self::assertSame('/wsam/index.php', $env['SCRIPT_NAME']);
            self::assertSame('/batches/7', $env['PATH_INFO']);
            self::assertSame('XMLHttpRequest', $env['X_REQUESTED_WITH']);

            $_SERVER = ['REQUEST_METHOD' => 'GET', 'SCRIPT_NAME' => '/wsam/index.php', 'REQUEST_URI' => '/wsam/batches/7', 'QUERY_STRING' => ''];
            $env = \Slim_Http_Request::environmentFromGlobals();
            self::assertSame('/wsam', $env['SCRIPT_NAME']);
            self::assertSame('/batches/7', $env['PATH_INFO']);
        } finally {
            $_SERVER = $server;
        }
    }

    /**
     * The bridge runs on the app's PHP, which can be as old as 7.4 (the
     * oldest Slim 4 supports). A token scan catches PHP 8 syntax and
     * functions; an attribute must sit alone on its line, so PHP 7.4 reads it
     * as a comment.
     */
    public function test_the_bridge_stays_php_7_4_compatible(): void
    {
        $code = (string) file_get_contents(self::BRIDGE);
        foreach (token_get_all($code) as $token) {
            if (!is_array($token)) {
                continue;
            }
            self::assertNotContains($token[0], [T_MATCH, T_NULLSAFE_OBJECT_OPERATOR, T_READONLY, T_ENUM], 'The bridge uses ' . token_name($token[0]));
            if ($token[0] === T_STRING) {
                self::assertNotContains(strtolower($token[1]), ['str_contains', 'str_starts_with', 'str_ends_with', 'array_is_list', 'get_debug_type'], 'The bridge calls ' . $token[1]);
            }
        }
        foreach (explode("\n", $code) as $number => $line) {
            if (str_contains($line, '#[')) {
                self::assertMatchesRegularExpression('/^\s*#\[\w+\]\s*$/', $line, 'Line ' . ($number + 1) . ' must hold the attribute alone.');
            }
        }
    }
}
