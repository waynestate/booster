<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Run\Cake\ApplicationScaffold;

final class CakeApplicationScaffoldTest extends TestCase
{
    private const SERVICE_CHANGE = "Added getAuthenticationService(): the Session authenticator, unauthenticatedRedirect from the old loginAction, queryParam 'redirect'.";

    private const IMPORTS_CHANGE = 'Added use imports: Authentication\AuthenticationService, Authentication\AuthenticationServiceInterface, Authentication\AuthenticationServiceProviderInterface, Authentication\Middleware\AuthenticationMiddleware, Psr\Http\Message\ServerRequestInterface.';

    public function test_full_scaffold_wires_the_authentication_plugin(): void
    {
        $result = (new ApplicationScaffold())->scaffold(self::application(), "['controller' => 'Users', 'action' => 'login']");

        $this->assertSame(self::lines(
            '<?php',
            'declare(strict_types=1);',
            '',
            'namespace App;',
            '',
            'use Authentication\AuthenticationService;',
            'use Authentication\AuthenticationServiceInterface;',
            'use Authentication\AuthenticationServiceProviderInterface;',
            'use Authentication\Middleware\AuthenticationMiddleware;',
            'use Cake\Core\Configure;',
            'use Cake\Error\Middleware\ErrorHandlerMiddleware;',
            'use Cake\Http\BaseApplication;',
            'use Cake\Http\Middleware\BodyParserMiddleware;',
            'use Cake\Http\MiddlewareQueue;',
            'use Cake\Routing\Middleware\AssetMiddleware;',
            'use Cake\Routing\Middleware\RoutingMiddleware;',
            'use Cake\Routing\Router;',
            'use Psr\Http\Message\ServerRequestInterface;',
            '',
            '/**',
            ' * Application setup class.',
            ' */',
            'class Application extends BaseApplication implements AuthenticationServiceProviderInterface',
            '{',
            '    public function bootstrap(): void',
            '    {',
            '        parent::bootstrap();',
            '',
            "        if (PHP_SAPI === 'cli') {",
            '            $this->bootstrapCli();',
            '        }',
            '',
            "        \$this->addPlugin('BootstrapUI');",
            "        \$this->addPlugin(\\CakeDC\\OracleDriver\\Plugin::class, ['bootstrap' => true]);",
            "        \$this->addPlugin('Authentication');",
            '    }',
            '',
            '    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue',
            '    {',
            '        $middlewareQueue',
            "            ->add(new ErrorHandlerMiddleware(Configure::read('Error')))",
            '            ->add(new AssetMiddleware([',
            "                'cacheTime' => Configure::read('Asset.cacheTime'),",
            '            ]))',
            '            ->add(new RoutingMiddleware($this))',
            '            ->add(new BodyParserMiddleware())',
            '            ->add(new AuthenticationMiddleware($this));',
            '',
            '        return $middlewareQueue;',
            '    }',
            '',
            '    protected function bootstrapCli(): void',
            '    {',
            "        \$this->addPlugin('Migrations');",
            '    }',
            '',
            '    /**',
            '     * The authentication service: the session keeps the identity the app',
            '     * sets when it signs someone in, and guests go to the old login action.',
            '     */',
            '    public function getAuthenticationService(ServerRequestInterface $request): AuthenticationServiceInterface',
            '    {',
            '        $service = new AuthenticationService([',
            "            'unauthenticatedRedirect' => Router::url(['controller' => 'Users', 'action' => 'login']),",
            "            'queryParam' => 'redirect',",
            '        ]);',
            "        \$service->loadAuthenticator('Authentication.Session');",
            '',
            '        return $service;',
            '    }',
            '}',
        ), $result['code']);
        $this->assertSame([
            'Added AuthenticationServiceProviderInterface to the Application class.',
            'Added use imports: Authentication\AuthenticationService, Authentication\AuthenticationServiceInterface, Authentication\AuthenticationServiceProviderInterface, Authentication\Middleware\AuthenticationMiddleware, Cake\Routing\Router, Psr\Http\Message\ServerRequestInterface.',
            "Added \$this->addPlugin('Authentication') to bootstrap().",
            'Added AuthenticationMiddleware after BodyParserMiddleware in middleware().',
            self::SERVICE_CHANGE,
        ], $result['changes']);
        $this->assertSame([], $result['manual']);
    }

    public function test_string_login_action_is_used_as_written(): void
    {
        $result = (new ApplicationScaffold())->scaffold(self::application(), " '/auth/login' ");

        $this->assertStringContainsString("            'unauthenticatedRedirect' => '/auth/login',\n", $result['code']);
        $this->assertStringNotContainsString('use Cake\Routing\Router;', $result['code']);
        $this->assertSame(self::IMPORTS_CHANGE, $result['changes'][1]);
        $this->assertSame([], $result['manual']);
    }

    public function test_missing_login_action_uses_the_placeholder_and_a_manual_note(): void
    {
        $result = (new ApplicationScaffold())->scaffold(self::application(), null);

        $this->assertStringContainsString("            'unauthenticatedRedirect' => '/login',\n", $result['code']);
        $this->assertStringNotContainsString('Router', $result['code']);
        $this->assertSame(
            ["MANUAL: no loginAction was found, so unauthenticatedRedirect in getAuthenticationService() is the placeholder '/login'; set it to the app's sign-in URL."],
            $result['manual'],
        );
        $this->assertSame(self::SERVICE_CHANGE, $result['changes'][4]);
    }

    public function test_multi_line_login_action_is_reindented(): void
    {
        $loginAction = "[\n                'controller' => 'Users',\n                'action' => 'login',\n            ]";
        $result = (new ApplicationScaffold())->scaffold(self::application(), $loginAction);

        $this->assertStringContainsString(self::lines(
            '        $service = new AuthenticationService([',
            "            'unauthenticatedRedirect' => Router::url([",
            "                'controller' => 'Users',",
            "                'action' => 'login',",
            '            ]),',
            "            'queryParam' => 'redirect',",
            '        ]);',
        ), $result['code']);
    }

    public function test_existing_implements_list_is_extended(): void
    {
        $source = str_replace(
            'class Application extends BaseApplication',
            'class Application extends BaseApplication implements EventDispatcherInterface',
            self::application(),
        );
        $result = (new ApplicationScaffold())->scaffold($source, "'/login'");

        $this->assertStringContainsString(
            "class Application extends BaseApplication implements EventDispatcherInterface, AuthenticationServiceProviderInterface\n{",
            $result['code'],
        );
        $this->assertSame('Added AuthenticationServiceProviderInterface to the Application class.', $result['changes'][0]);
    }

    public function test_already_implementing_the_interface_only_reports(): void
    {
        $source = str_replace(
            'class Application extends BaseApplication',
            'class Application extends BaseApplication implements \Authentication\AuthenticationServiceProviderInterface',
            self::application(),
        );
        $result = (new ApplicationScaffold())->scaffold($source, "'/login'");

        $this->assertSame($source, $result['code']);
        $this->assertSame([], $result['changes']);
        $this->assertSame(
            ['MANUAL: src/Application.php already implements AuthenticationServiceProviderInterface; check that its getAuthenticationService() loads the Authentication.Session authenticator.'],
            $result['manual'],
        );
    }

    public function test_scaffold_is_idempotent(): void
    {
        $scaffold = new ApplicationScaffold();
        $first = $scaffold->scaffold(self::application(), "['controller' => 'Users', 'action' => 'login']");
        $second = $scaffold->scaffold($first['code'], "['controller' => 'Users', 'action' => 'login']");

        $this->assertSame($first['code'], $second['code']);
        $this->assertSame([], $second['changes']);
        $this->assertSame(1, substr_count($second['code'], "addPlugin('Authentication')"));
        $this->assertSame(1, substr_count($second['code'], 'new AuthenticationMiddleware($this)'));
    }

    public function test_plugin_and_middleware_already_there_are_kept(): void
    {
        $source = str_replace(
            [
                "        \$this->addPlugin('BootstrapUI');\n",
                "            ->add(new BodyParserMiddleware());\n",
            ],
            [
                "        \$this->addPlugin('BootstrapUI');\n        \$this->addPlugin('Authentication', ['bootstrap' => false]);\n",
                "            ->add(new BodyParserMiddleware())\n            ->add(new \\Authentication\\Middleware\\AuthenticationMiddleware(\$this));\n",
            ],
            self::application(),
        );
        $result = (new ApplicationScaffold())->scaffold($source, "'/login'");

        $this->assertSame(1, substr_count($result['code'], "addPlugin('Authentication'"));
        $this->assertSame(1, substr_count($result['code'], 'AuthenticationMiddleware($this)'));
        $this->assertSame([
            'Added AuthenticationServiceProviderInterface to the Application class.',
            self::IMPORTS_CHANGE,
            self::SERVICE_CHANGE,
        ], $result['changes']);
    }

    public function test_middleware_goes_after_routing_when_there_is_no_body_parser(): void
    {
        $source = str_replace(
            "            ->add(new RoutingMiddleware(\$this))\n            ->add(new BodyParserMiddleware());\n",
            "            ->add(new RoutingMiddleware(\$this));\n",
            self::application(),
        );
        $result = (new ApplicationScaffold())->scaffold($source, "'/login'");

        $this->assertStringContainsString(
            "            ->add(new RoutingMiddleware(\$this))\n            ->add(new AuthenticationMiddleware(\$this));\n",
            $result['code'],
        );
        $this->assertSame('Added AuthenticationMiddleware after RoutingMiddleware in middleware().', $result['changes'][3]);
    }

    public function test_one_statement_per_middleware_gets_its_own_statement(): void
    {
        $source = self::lines(
            '<?php',
            'namespace App;',
            '',
            'use Cake\Http\BaseApplication;',
            '',
            'class Application extends BaseApplication',
            '{',
            '    public function bootstrap(): void',
            '    {',
            '        parent::bootstrap();',
            '    }',
            '',
            '    public function middleware($queue)',
            '    {',
            '        $queue->add(new \Cake\Routing\Middleware\RoutingMiddleware($this));',
            '        $queue->add(new \Cake\Http\Middleware\BodyParserMiddleware());',
            '',
            '        return $queue;',
            '    }',
            '}',
        );
        $result = (new ApplicationScaffold())->scaffold($source, null);

        $this->assertStringContainsString(self::lines(
            'use Authentication\AuthenticationService;',
            'use Authentication\AuthenticationServiceInterface;',
            'use Authentication\AuthenticationServiceProviderInterface;',
            'use Authentication\Middleware\AuthenticationMiddleware;',
            'use Cake\Http\BaseApplication;',
            'use Psr\Http\Message\ServerRequestInterface;',
            '',
            'class Application extends BaseApplication implements AuthenticationServiceProviderInterface',
            '{',
            '    public function bootstrap(): void',
            '    {',
            '        parent::bootstrap();',
            "        \$this->addPlugin('Authentication');",
            '    }',
            '',
            '    public function middleware($queue)',
            '    {',
            '        $queue->add(new \Cake\Routing\Middleware\RoutingMiddleware($this));',
            '        $queue->add(new \Cake\Http\Middleware\BodyParserMiddleware());',
            '        $queue->add(new AuthenticationMiddleware($this));',
            '',
        ), $result['code']);
    }

    public function test_existing_imports_are_not_repeated(): void
    {
        $source = str_replace(
            "use Cake\\Routing\\Middleware\\RoutingMiddleware;\n",
            "use Cake\\Routing\\Middleware\\RoutingMiddleware;\nuse Cake\\Routing\\Router;\nuse Psr\\Http\\Message\\ServerRequestInterface;\n",
            self::application(),
        );
        $result = (new ApplicationScaffold())->scaffold($source, "['action' => 'login']");

        $this->assertSame(1, substr_count($result['code'], 'use Cake\Routing\Router;'));
        $this->assertSame(1, substr_count($result['code'], 'use Psr\Http\Message\ServerRequestInterface;'));
        $this->assertSame(
            'Added use imports: Authentication\AuthenticationService, Authentication\AuthenticationServiceInterface, Authentication\AuthenticationServiceProviderInterface, Authentication\Middleware\AuthenticationMiddleware.',
            $result['changes'][1],
        );
    }

    public function test_crlf_files_stay_crlf(): void
    {
        $source = str_replace("\n", "\r\n", self::application());
        $result = (new ApplicationScaffold())->scaffold($source, "'/login'");

        $this->assertSame(0, preg_match('/(?<!\r)\n/', $result['code']));
        $this->assertStringContainsString("        \$this->addPlugin('Authentication');\r\n    }", $result['code']);
    }

    public function test_a_file_without_a_class_is_left_with_a_manual_note(): void
    {
        $source = "<?php\n\nreturn [];\n";
        $result = (new ApplicationScaffold())->scaffold($source, "'/login'");

        $this->assertSame($source, $result['code']);
        $this->assertSame([], $result['changes']);
        $this->assertSame(['MANUAL: no Application class found in src/Application.php; wire the Authentication plugin by hand.'], $result['manual']);
    }

    /** A CakePHP 4 skeleton-shaped Application.php that loads AuthComponent in its controllers. */
    private static function application(): string
    {
        return self::lines(
            '<?php',
            'declare(strict_types=1);',
            '',
            'namespace App;',
            '',
            'use Cake\Core\Configure;',
            'use Cake\Error\Middleware\ErrorHandlerMiddleware;',
            'use Cake\Http\BaseApplication;',
            'use Cake\Http\Middleware\BodyParserMiddleware;',
            'use Cake\Http\MiddlewareQueue;',
            'use Cake\Routing\Middleware\AssetMiddleware;',
            'use Cake\Routing\Middleware\RoutingMiddleware;',
            '',
            '/**',
            ' * Application setup class.',
            ' */',
            'class Application extends BaseApplication',
            '{',
            '    public function bootstrap(): void',
            '    {',
            '        parent::bootstrap();',
            '',
            "        if (PHP_SAPI === 'cli') {",
            '            $this->bootstrapCli();',
            '        }',
            '',
            "        \$this->addPlugin('BootstrapUI');",
            "        \$this->addPlugin(\\CakeDC\\OracleDriver\\Plugin::class, ['bootstrap' => true]);",
            '    }',
            '',
            '    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue',
            '    {',
            '        $middlewareQueue',
            "            ->add(new ErrorHandlerMiddleware(Configure::read('Error')))",
            '            ->add(new AssetMiddleware([',
            "                'cacheTime' => Configure::read('Asset.cacheTime'),",
            '            ]))',
            '            ->add(new RoutingMiddleware($this))',
            '            ->add(new BodyParserMiddleware());',
            '',
            '        return $middlewareQueue;',
            '    }',
            '',
            '    protected function bootstrapCli(): void',
            '    {',
            "        \$this->addPlugin('Migrations');",
            '    }',
            '}',
        );
    }

    private static function lines(string ...$lines): string
    {
        return implode("\n", $lines) . "\n";
    }
}
