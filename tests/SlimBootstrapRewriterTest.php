<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Run\Slim\BootstrapRewriter;

/**
 * The Slim 3 bootstrap rewrite: `new \Slim\App(...)` becomes the generated
 * LegacyApp glue, LegacyApp::finish() goes in before run(), and what has no
 * mechanical Slim 4 form becomes a MANUAL line. Fixtures follow the in-house
 * wrapper-class skeleton the fleet's Slim 3 apps share.
 */
final class SlimBootstrapRewriterTest extends TestCase
{
    private const NS = 'App\\Slim4';

    private const WRAPPER = <<<'PHP'
        <?php

        namespace App;

        class App
        {
            private $app;
            private $container;

            private $settings = [
                'outputBuffering' => 'append',
                'determineRouteBeforeAppMiddleware' => true,
                'displayErrorDetails' => false,
            ];

            public function __construct()
            {
                $this->app = new \Slim\App(['settings' => $this->settings]);
                $this->container = $this->app->getContainer();
            }

            public function run()
            {
                $this->app->run();
            }
        }

        PHP;

    /**
     * @return array{code: string, changes: list<string>, manual: list<string>}
     */
    private static function rewrite(string $php): array
    {
        return (new BootstrapRewriter())->rewrite($php, self::NS);
    }

    public function test_new_slim_app_becomes_legacy_app_create(): void
    {
        $result = self::rewrite(self::WRAPPER);

        self::assertStringContainsString(
            '$this->app = \\App\\Slim4\\LegacyApp::create([\'settings\' => $this->settings]);',
            $result['code'],
        );
        self::assertStringNotContainsString('new \\Slim\\App', $result['code']);
        self::assertContains('Replaced new \\Slim\\App(...) with LegacyApp::create(...).', $result['changes']);
        \PhpToken::tokenize($result['code'], TOKEN_PARSE);

        $unqualified = self::rewrite("<?php\n\$app = new Slim\\App(['settings' => ['displayErrorDetails' => (bool) getenv('DEBUG')]]);\n");
        self::assertSame(
            "<?php\n\$app = \\App\\Slim4\\LegacyApp::create(['settings' => ['displayErrorDetails' => (bool) getenv('DEBUG')]]);\n",
            $unqualified['code'],
        );

        $noArgs = self::rewrite("<?php\n\$app = new \\Slim\\App();\n");
        self::assertSame("<?php\n\$app = \\App\\Slim4\\LegacyApp::create();\n", $noArgs['code']);

        $other = "<?php\n\$app = new \\Other\\App(['settings' => []]);\n\$b = new App();\n";
        self::assertSame($other, self::rewrite($other)['code']);
    }

    public function test_short_name_with_use_import(): void
    {
        $php = "<?php\n\nuse Slim\\App;\n\nfunction build(): App\n{\n    return new App(['settings' => (function () { return ['x' => [1, 2]]; })()]);\n}\n";

        $result = self::rewrite($php);

        self::assertSame(
            "<?php\n\nuse Slim\\App;\n\nfunction build(): App\n{\n    return \\App\\Slim4\\LegacyApp::create(['settings' => (function () { return ['x' => [1, 2]]; })()]);\n}\n",
            $result['code'],
        );

        $aliased = self::rewrite("<?php\nuse Slim\\App as SlimApp;\n\$app = new SlimApp([]);\n\$other = new App([]);\n");
        self::assertSame("<?php\nuse Slim\\App as SlimApp;\n\$app = \\App\\Slim4\\LegacyApp::create([]);\n\$other = new App([]);\n", $aliased['code']);
    }

    public function test_finish_is_added_before_run(): void
    {
        $result = self::rewrite(self::WRAPPER);

        self::assertStringContainsString(
            "        \\App\\Slim4\\LegacyApp::finish(\$this->app);\n        \$this->app->run();\n",
            $result['code'],
        );
        self::assertContains('Added LegacyApp::finish() before run().', $result['changes']);
        \PhpToken::tokenize($result['code'], TOKEN_PARSE);

        $script = "<?php\n\$app = new \\Slim\\App(['settings' => []]);\n\$app->get('/', 'Home:index');\n\$app->run();\n";
        self::assertSame(
            "<?php\n\$app = \\App\\Slim4\\LegacyApp::create(['settings' => []]);\n\$app->get('/', 'Home:index');\n\\App\\Slim4\\LegacyApp::finish(\$app);\n\$app->run();\n",
            self::rewrite($script)['code'],
        );
    }

    public function test_no_assignment_found_gives_a_manual_line(): void
    {
        $php = "<?php\n\nrequire __DIR__ . '/../bootstrap.php';\n\n\$app->run();\n";

        $result = self::rewrite($php);

        self::assertSame($php, $result['code']);
        self::assertSame([], $result['changes']);
        self::assertSame(['MANUAL: add \\App\\Slim4\\LegacyApp::finish($app) just before $app->run().'], $result['manual']);

        $console = "<?php\n\$application = new Console();\n\$application->run();\n";
        self::assertSame([], self::rewrite($console)['manual']);
    }

    public function test_handler_keys_and_app_settings_need_no_manual_line(): void
    {
        // Bug S3: the glue reads Slim 3's handler keys and leaves the app's own
        // settings in the container, so neither is a MANUAL line any more.
        $php = <<<'PHP'
            <?php
            $settings = [
                'displayErrorDetails' => true,
                'db' => ['host' => 'localhost'],
            ];
            $app = new \Slim\App(['settings' => array(
                'logger' => ['name' => 'app'],
            )]);
            $container = $app->getContainer();
            $container['errorHandler'] = function ($c) {
                return new \App\Handlers\Error($c->logger);
            };
            $container["phpErrorHandler"] = fn ($c) => $c['errorHandler'];
            $container['settings']['renderer'] = ['path' => 'templates/'];
            unset($this->container['notAllowedHandler']);
            $debug = $container->settings->get('displayErrorDetails');

            PHP;

        $manual = self::rewrite($php)['manual'];

        self::assertSame(["MANUAL: line 16: settings is a plain array on Slim 4; replace ->get('k') with ['k']."], $manual);
    }

    public function test_process_moves_to_legacy_app_process(): void
    {
        // Bug S1: the slim-skeleton test harness runs the app with $app->process($request, $response).
        $php = <<<'PHP'
            <?php
            use Slim\App;

            class BaseTestCase
            {
                public function runApp($request, $response)
                {
                    $app = new App(['settings' => []]);
                    $response = $app->process($request, $response);
                    $other->process($request, $response);

                    return $response;
                }
            }

            PHP;

        $result = self::rewrite($php);

        self::assertStringContainsString('$response = \\App\\Slim4\\LegacyApp::process($app, $request, $response);', $result['code']);
        self::assertStringContainsString('$other->process($request, $response);', $result['code']);
        self::assertContains('Replaced $app->process(...) with LegacyApp::process($app, ...).', $result['changes']);
        \PhpToken::tokenize($result['code'], TOKEN_PARSE);

        self::assertSame($result['code'], self::rewrite($result['code'])['code'], 'a second run changes nothing');
    }

    public function test_strings_and_comments_untouched(): void
    {
        $php = <<<'PHP'
            <?php
            // $app = new \Slim\App(['settings' => ['legacy' => true]]); $app->run();
            /* $container['errorHandler'] = null; */
            $doc = 'new \Slim\App([]) then $app->run()';
            $html = <<<HTML
                <p>new \Slim\App(['settings' => []]) and \$app->run()</p>
                HTML;
            $twice = "new Slim\\App(); \$c['errorHandler'] = 1;";

            PHP;

        $result = self::rewrite($php);

        self::assertSame($php, $result['code']);
        self::assertSame([], $result['changes']);
        self::assertSame([], $result['manual']);
    }

    public function test_rewrite_is_idempotent(): void
    {
        $first = self::rewrite(self::WRAPPER);
        $second = self::rewrite($first['code']);

        self::assertSame($first['code'], $second['code']);
        self::assertSame([], $second['changes']);
        self::assertSame(1, substr_count($second['code'], 'LegacyApp::finish('));
        self::assertSame(1, substr_count($second['code'], 'LegacyApp::create('));
    }
}
