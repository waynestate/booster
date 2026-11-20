<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Run\Cake\SkeletonRewriter;

/**
 * The CakePHP 5 skeleton moves no cakephp/upgrade rule makes, each fixture
 * shaped like the CakePHP 4 app skeleton file that broke on the real run
 * (cakephp-app-4.x, 2026-11-20). Bones only; no meat was harmed.
 */
final class CakeSkeletonRewriterTest extends TestCase
{
    private const BOOTSTRAP = "<?php\nrequire __DIR__ . DIRECTORY_SEPARATOR . 'paths.php';\n\n/*\n * Bootstrap CakePHP.\n */\nrequire CORE_PATH . 'config' . DS . 'bootstrap.php';\n\nuse Cake\\Core\\Configure;\n";

    // CAKE-3: config/app.php died on env() because CakePHP 5 loads its functions on request.
    public function test_bootstrap_loads_the_global_functions_after_the_core_bootstrap(): void
    {
        $result = (new SkeletonRewriter())->bootstrap(self::BOOTSTRAP);

        $this->assertStringContainsString("require CORE_PATH . 'config' . DS . 'bootstrap.php';\n\n/*\n * Load global functions for collections, translations, debugging etc.\n */\nrequire CAKE . 'functions.php';\n\nuse Cake", $result['code']);
        $this->assertCount(1, $result['changes']);
        $this->assertSame([], $result['manual']);
    }

    public function test_bootstrap_is_left_alone_when_it_already_loads_them(): void
    {
        $code = str_replace("\nuse Cake", "\nrequire CAKE . 'functions.php';\nuse Cake", self::BOOTSTRAP);
        $result = (new SkeletonRewriter())->bootstrap($code);

        $this->assertSame($code, $result['code']);
        $this->assertSame([], $result['changes']);
    }

    public function test_bootstrap_keeps_crlf_and_names_a_manual_step_without_the_core_require(): void
    {
        $crlf = (new SkeletonRewriter())->bootstrap(str_replace("\n", "\r\n", self::BOOTSTRAP));
        $this->assertStringContainsString("\r\nrequire CAKE . 'functions.php';\r\n", $crlf['code']);
        $this->assertStringNotContainsString("functions.php';\n", str_replace("\r\n", '', $crlf['code']));

        $odd = (new SkeletonRewriter())->bootstrap("<?php\nrequire 'vendor/cakephp/cakephp/config/bootstrap.php';\n");
        $this->assertSame([SkeletonRewriter::FUNCTIONS_MANUAL], $odd['manual']);
        $this->assertSame([], $odd['changes']);
    }

    public function test_phpunit_xml_moves_the_extension_and_the_whitelist(): void
    {
        $xml = <<<'XML'
            <phpunit bootstrap="tests/bootstrap.php">
                <extensions>
                    <extension class="Cake\TestSuite\Fixture\PHPUnitExtension"/>
                </extensions>
                <filter>
                    <whitelist>
                        <directory suffix=".php">src/</directory>
                        <directory suffix=".php">plugins/*/src/</directory>
                        <exclude>
                            <file>src/Console/Installer.php</file>
                        </exclude>
                    </whitelist>
                </filter>
            </phpunit>
            XML;
        $result = (new SkeletonRewriter())->phpunitXml($xml, 'phpunit.xml.dist');

        $this->assertStringContainsString('<bootstrap class="Cake\TestSuite\Fixture\Extension\PHPUnitExtension"/>', $result['code']);
        $this->assertStringNotContainsString('<whitelist', $result['code']);
        $this->assertStringContainsString("<source>\n        <include>\n            <directory suffix=\".php\">src/</directory>\n            <directory suffix=\".php\">plugins/*/src/</directory>\n        </include>\n        <exclude>\n            <file>src/Console/Installer.php</file>\n        </exclude>\n    </source>", $result['code']);
        $this->assertCount(2, $result['changes']);
        $this->assertSame([], $result['manual']);
    }

    public function test_phpunit_xml_names_a_manual_step_for_shapes_it_does_not_know(): void
    {
        $xml = "<phpunit>\n<extensions><extension class=\"My\\Listener\"/></extensions>\n<filter><whitelist processUncoveredFilesFromWhitelist=\"true\"><directory>src</directory><blacklist/></whitelist></filter>\n</phpunit>";
        $result = (new SkeletonRewriter())->phpunitXml($xml, 'phpunit.xml');

        $this->assertSame($xml, $result['code']);
        $this->assertSame(SkeletonRewriter::EXTENSION_MANUAL . ' (phpunit.xml)', $result['manual'][0]);
        $this->assertStringContainsString('--migrate-configuration', $result['manual'][1]);
    }

    // CAKE-4: a controller that still loads RequestHandler answers every request with a 500 on 5.
    public function test_request_handler_load_goes_and_view_classes_come_in(): void
    {
        $code = "<?php\nclass AppController extends Controller\n{\n    public function initialize(): void\n    {\n        parent::initialize();\n\n        \$this->loadComponent('RequestHandler', [\n            'enableBeforeRedirect' => false,\n        ]);\n        \$this->loadComponent('Flash');\n    }\n}\n";
        $result = (new SkeletonRewriter())->requestHandler($code, true);

        $this->assertTrue($result['removed']);
        $this->assertTrue($result['viewClasses']);
        $this->assertStringNotContainsString('loadComponent(\'RequestHandler\'', $result['code']);
        $this->assertStringNotContainsString('enableBeforeRedirect', $result['code']);
        // The MANUAL scan looks for the class name; the new docblock must not trip it.
        $this->assertStringNotContainsString('RequestHandlerComponent', $result['code']);
        $this->assertStringContainsString("\$this->loadComponent('Flash');", $result['code']);
        $this->assertStringContainsString("public function viewClasses(): array\n    {\n        return [\\Cake\\View\\JsonView::class, \\Cake\\View\\XmlView::class];\n    }\n}\n", $result['code']);
        $this->assertSame(1, preg_match('/^<\?php\n.*\}\n$/s', $result['code']));
    }

    public function test_request_handler_without_extensions_or_load_changes_nothing_more(): void
    {
        $code = "<?php\nclass ErrorController extends AppController\n{\n    public function initialize(): void\n    {\n        \$this->loadComponent(\"RequestHandler\");\n    }\n}\n";
        $result = (new SkeletonRewriter())->requestHandler($code, false);
        $this->assertTrue($result['removed']);
        $this->assertFalse($result['viewClasses']);
        $this->assertSame("<?php\nclass ErrorController extends AppController\n{\n    public function initialize(): void\n    {\n    }\n}\n", $result['code']);

        $none = (new SkeletonRewriter())->requestHandler("<?php\n\$this->loadComponent('Flash');\n", true);
        $this->assertFalse($none['removed']);
        $this->assertSame("<?php\n\$this->loadComponent('Flash');\n", $none['code']);
    }

    // CAKE-5: the deprecation for 'scopes' => false and the renamed core cache, the skeleton home page's 500.
    public function test_config_moves_log_scopes_and_follows_the_cache_rename(): void
    {
        $code = "'Log' => [\n    'debug' => ['scopes' => false],\n    'error' => ['scopes' => false, 'levels' => ['error']],\n    'queries' => ['scopes' => ['queriesLog']],\n],\nCache::getConfig('_cake_core_');\n";
        $result = (new SkeletonRewriter())->config($code, true);
        $this->assertSame(3, $result['changes']);
        $this->assertStringContainsString("'debug' => ['scopes' => null]", $result['code']);
        $this->assertStringContainsString("'error' => ['scopes' => null, 'levels'", $result['code']);
        $this->assertStringContainsString("['scopes' => ['queriesLog']]", $result['code']);
        $this->assertStringContainsString("'_cake_translations_'", $result['code']);

        $kept = (new SkeletonRewriter())->config("Cache::getConfig('_cake_core_');", false);
        $this->assertSame(0, $kept['changes']);
    }

    public function test_template_connects_through_the_driver(): void
    {
        $code = "<?php\ntry {\n    \$connection = ConnectionManager::get('default');\n    \$connected = \$connection->connect();\n} catch (Exception \$e) {}\n\$other = ConnectionManager::get('test')->connect();\n\$settings = Cache::getConfig('_cake_core_');\n";
        $result = (new SkeletonRewriter())->template($code, true);

        $this->assertSame(3, $result['changes']);
        $this->assertStringContainsString('$connected = $connection->getDriver()->connect();', $result['code']);
        $this->assertStringContainsString("ConnectionManager::get('test')->getDriver()->connect();", $result['code']);
        $this->assertStringContainsString("Cache::getConfig('_cake_translations_')", $result['code']);
        $this->assertSame(0, (new SkeletonRewriter())->template("<?php\n\$socket->connect();\n", false)['changes']);
    }
}
