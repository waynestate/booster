<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Waynestate\Booster\Run\VendoredMigration;
use Waynestate\Booster\Scan\VendoredLibrary;

/**
 * The vendored-to-composer engine on a pocket copy of a 2012 app
 * (tests/fixtures/vendored-2012): Doctrine 2.2, Smarty 3.1 and Slim 1.6 in
 * vendor/, no composer.json. What goes, what gets written, and every stop
 * sign that makes it refuse and touch nothing.
 */
final class VendoredMigrationTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/fixtures/vendored-2012';

    private function app(): string
    {
        $dir = $this->tempDir();
        $this->copy(self::FIXTURE, $dir);

        return $dir;
    }

    private function copy(string $from, string $to): void
    {
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir($from . '/' . $entry)) {
                mkdir($to . '/' . $entry, 0775, true);
                $this->copy($from . '/' . $entry, $to . '/' . $entry);
            } else {
                copy($from . '/' . $entry, $to . '/' . $entry);
            }
        }
    }

    private static function put(string $file, string $contents): void
    {
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        file_put_contents($file, $contents);
    }

    public function test_inspect_finds_the_three_libraries_and_the_php_they_need(): void
    {
        $inspection = (new VendoredMigration())->inspect($this->app());

        self::assertTrue($inspection->movable(), implode("\n", $inspection->refusals));
        self::assertSame(['Doctrine', 'Slim', 'Smarty'], array_map(static fn(VendoredLibrary $l): string => $l->name, $inspection->moves));
        self::assertSame('7.4', $inspection->floor);
        self::assertSame('Doctrine 2.2.2, Slim 1.6.0 and Smarty 3.1.8', $inspection->summary());
        self::assertSame([], $inspection->leftAlone);
    }

    public function test_the_floor_comes_from_the_recipes(): void
    {
        self::assertSame('7.4', VendoredMigration::floorFor(new VendoredLibrary('Slim', 'vendor/Slim', '1.6.0', 'slim/slim')));
        self::assertSame('7.1', VendoredMigration::floorFor(new VendoredLibrary('Smarty', 'vendor/Smarty', '3.1.8', 'smarty/smarty')));
        self::assertNull(VendoredMigration::floorFor(new VendoredLibrary('PHPMailer', 'vendor/PHPMailer', '5.2.1', 'phpmailer/phpmailer')));
    }

    public function test_a_dry_run_says_what_it_would_do_and_writes_nothing(): void
    {
        $app = $this->app();

        $result = (new VendoredMigration())->migrate($app, true, '7.4');

        self::assertFalse($result->refused());
        self::assertStringStartsWith('Dry run: would replace the hand-copied Doctrine 2.2.2 in vendor/Doctrine/ (2 files) with doctrine/orm 2.20 from Composer.', $result->changes[0]);
        foreach ($result->changes as $change) {
            self::assertMatchesRegularExpression('/^(Dry run: would |  [a-z])/', $change);
            self::assertStringNotContainsString('would wrote', $change);
            self::assertStringNotContainsString('would  ', $change);
        }
        self::assertSame(['Dry run: would write composer.json:', '  require php ^7.4'], array_slice($result->changes, 3, 2));
        self::assertDirectoryExists($app . '/vendor/Doctrine');
        self::assertFileDoesNotExist($app . '/composer.json');
        self::assertFileDoesNotExist($app . '/' . VendoredMigration::BRIDGE_FILE);
        self::assertFileEquals(self::FIXTURE . '/index.php', $app . '/index.php');
    }

    public function test_the_move_replaces_the_copies_with_composer_and_rewrites_the_loading_lines(): void
    {
        $app = $this->app();

        $result = (new VendoredMigration())->migrate($app, false, '7.4');

        self::assertFalse($result->refused(), implode("\n", $result->refusals));
        self::assertDirectoryDoesNotExist($app . '/vendor', 'The hand copies leave, and an empty vendor/ with them.');
        self::assertSame(
            <<<'JSON'
                {
                    "require": {
                        "php": "^7.4",
                        "doctrine/annotations": "^1.14",
                        "doctrine/orm": "^2.20",
                        "slim/psr7": "^1.6",
                        "slim/slim": "^4.15",
                        "smarty/smarty": "^4.5",
                        "symfony/cache": "^4.4 || ^5.4 || ^6.4 || ^7.0"
                    },
                    "autoload": {
                        "classmap": [
                            "slim1-bridge.php"
                        ]
                    }
                }

                JSON,
            file_get_contents($app . '/composer.json'),
        );
        self::assertSame(['doctrine/orm', 'doctrine/annotations', 'symfony/cache', 'slim/slim', 'slim/psr7', 'smarty/smarty'], $result->packages);
        self::assertFileEquals(dirname(__DIR__) . '/resources/slim1/slim1-bridge.php', $app . '/slim1-bridge.php');

        $index = (string) file_get_contents($app . '/index.php');
        self::assertStringStartsWith("<?php\n// The front controller, as written in 2012.\nrequire_once 'vendor/autoload.php';\nuse Doctrine\\ORM\\Tools\\Setup;\n\nrequire 'lib/SmartyView.php';\n", $index);
        self::assertStringNotContainsString('registerAutoloadDirectory', $index);
        self::assertStringNotContainsString('SMARTY_DIR', $index);
        self::assertStringNotContainsString('vendor/Slim', $index);
        // The app's own code is not touched beyond the loading lines.
        self::assertStringContainsString("\$app->get('/batches/:id', function (\$id) use (\$app) {", $index);
        self::assertFileEquals(self::FIXTURE . '/lib/helpers.php', $app . '/lib/helpers.php');

        $files = array_column($result->files, 'file');
        self::assertSame(['vendor/Doctrine/', 'vendor/Slim/', 'vendor/Smarty/', 'composer.json', 'index.php', 'slim1-bridge.php'], $files);
        self::assertStringContainsString('MANUAL: Commit composer.json and composer.lock.', implode("\n", $result->manual));
        self::assertStringContainsString('booster run --replay', implode("\n", $result->manual));
        self::assertStringContainsString('DISTINCT(alias.field)', implode("\n", $result->manual));
    }

    public function test_each_file_that_loaded_a_copy_loads_the_autoloader_with_its_own_path_and_line_endings(): void
    {
        $app = $this->app();
        self::put($app . '/cron/nightly.php', "<?php\r\ninclude_once dirname(__FILE__) . '/../vendor/Smarty/Smarty.class.php'; // templates\r\necho 'ok';\r\n");

        (new VendoredMigration())->migrate($app, false, '7.4');

        self::assertSame("<?php\r\nrequire_once dirname(__FILE__) . '/../vendor/autoload.php'; // templates\r\necho 'ok';\r\n", file_get_contents($app . '/cron/nightly.php'));
    }

    public function test_a_file_that_already_loads_the_autoloader_just_loses_the_copy_requires(): void
    {
        $app = $this->app();
        self::put($app . '/worker.php', "<?php\nrequire __DIR__ . '/vendor/autoload.php';\nrequire 'vendor/Slim/Slim.php';\n");

        (new VendoredMigration())->migrate($app, false, '7.4');

        self::assertSame("<?php\nrequire __DIR__ . '/vendor/autoload.php';\n", file_get_contents($app . '/worker.php'));
    }

    public function test_an_existing_composer_json_keeps_its_entries_and_its_php(): void
    {
        $app = $this->app();
        self::put($app . '/composer.json', json_encode([
            'name' => 'wsu/old-app',
            'require' => ['phpmailer/phpmailer' => '^6.9', 'php' => '>=7.4', 'smarty/smarty' => '^3.1'],
            'autoload' => ['psr-4' => ['App\\' => 'src/']],
        ]));

        $result = (new VendoredMigration())->migrate($app, false, '8.1');

        $data = json_decode((string) file_get_contents($app . '/composer.json'), true);
        self::assertSame('wsu/old-app', $data['name']);
        self::assertSame(['php', 'doctrine/annotations', 'doctrine/orm', 'phpmailer/phpmailer', 'slim/psr7', 'slim/slim', 'smarty/smarty', 'symfony/cache'], array_keys($data['require']));
        self::assertSame('>=7.4', $data['require']['php']);
        self::assertSame('^3.1', $data['require']['smarty/smarty']);
        self::assertSame(['psr-4' => ['App\\' => 'src/'], 'classmap' => ['slim1-bridge.php']], $data['autoload']);
        self::assertContains('  kept smarty/smarty ^3.1 (already required)', explode("\n", $result->files[3]['diff']));
        self::assertNotContains('smarty/smarty', $result->packages);
    }

    public function test_only_smarty_needs_no_bridge_and_runs_from_php_7_1(): void
    {
        $app = $this->tempDir();
        self::put($app . '/vendor/Smarty/Smarty.class.php', "<?php\nconst SMARTY_VERSION = 'Smarty-3.1.21';\n");
        self::put($app . '/index.php', "<?php\nrequire_once('vendor/Smarty/Smarty.class.php');\n\$smarty = new Smarty();\n");

        $migration = new VendoredMigration();
        self::assertSame('7.1', $migration->inspect($app)->floor);
        $result = $migration->migrate($app, false, '7.1');

        self::assertFalse($result->refused());
        self::assertSame("<?php\nrequire_once('vendor/autoload.php');\n\$smarty = new Smarty();\n", file_get_contents($app . '/index.php'));
        self::assertSame(['require' => ['php' => '^7.1', 'smarty/smarty' => '^4.5']], json_decode((string) file_get_contents($app . '/composer.json'), true));
        self::assertFileDoesNotExist($app . '/slim1-bridge.php');
    }

    public function test_libraries_without_a_recipe_and_nested_copies_stay_where_they_are(): void
    {
        $app = $this->app();
        self::put($app . '/vendor/PHPMailer/class.phpmailer.php', "<?php\nclass PHPMailer { public \$Version = '5.2.1'; }\n");
        self::put($app . '/admin/vendor/Smarty/Smarty.class.php', "<?php\nconst SMARTY_VERSION = 'Smarty-3.1.8';\n");

        $result = (new VendoredMigration())->migrate($app, false, '7.4');

        self::assertFalse($result->refused(), implode("\n", $result->refusals));
        self::assertFileExists($app . '/vendor/PHPMailer/class.phpmailer.php');
        self::assertFileExists($app . '/admin/vendor/Smarty/Smarty.class.php');
        self::assertDirectoryDoesNotExist($app . '/vendor/Smarty');
        $manual = implode("\n", $result->manual);
        self::assertStringContainsString('MANUAL: vendor/PHPMailer/ (PHPMailer 5.2.1) stays where it is: Booster has no recipe for it yet.', $manual);
        self::assertStringContainsString("MANUAL: admin/vendor/Smarty/ (Smarty 3.1.8) stays where it is: Booster moves only the app root's vendor/.", $manual);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function stopSigns(): iterable
    {
        yield 'Doctrine class loader' => ['lib/boot.php', "<?php\nuse Doctrine\\Common\\ClassLoader;\n\$loader = new ClassLoader('Entities', __DIR__);\n", "lib/boot.php:3 uses Doctrine's ClassLoader"];
        yield 'a Slim hook' => ['lib/hooks.php', "<?php\n\$app->hook('slim.before', function () {});\n", 'lib/hooks.php:2 calls $app->hook(), which the Slim 1 bridge does not carry'];
        yield 'a cookie on the response' => ['lib/auth.php', "<?php\n\$app->response()->setCookie('sid', 'x');\n", 'calls setCookie() on the Slim response'];
        yield 'Slim middleware' => ['lib/mw.php', "<?php\nclass Timer extends Slim_Middleware {}\n", 'uses Slim_Middleware, which the Slim 1 bridge does not carry'];
        yield 'a regex route' => ['routes.php', "<?php\n\$app->get('/files/:path+', function (\$path) {});\n", 'has the route pattern "/files/:path+"'];
        yield 'SMARTY_DIR used elsewhere' => ['lib/plugins.php', "<?php\n\$dir = SMARTY_DIR . 'plugins';\n", 'lib/plugins.php:2 uses SMARTY_DIR'];
        yield 'a copy path that is not a require' => ['lib/paths.php', "<?php\n\$lib = 'vendor/Slim/Slim.php';\n", 'lib/paths.php:2 reaches into the hand-copied vendor/Slim'];
        yield 'a register call Booster cannot read' => ['lib/dc.php', "<?php\nSetup::registerAutoloadDirectory(\n    'vendor'\n);\n", 'lib/dc.php:2 calls registerAutoloadDirectory in a shape Booster does not rewrite'];
        yield 'the bridge file name is taken' => ['slim1-bridge.php', "<?php\n", 'slim1-bridge.php already exists'];
        yield 'composer.json that is not JSON' => ['composer.json', '{not json', 'composer.json is not valid JSON'];
    }

    #[DataProvider('stopSigns')]
    public function test_a_stop_sign_refuses_the_move_and_changes_nothing(string $file, string $contents, string $reason): void
    {
        $app = $this->app();
        self::put($app . '/' . $file, $contents);
        $migration = new VendoredMigration();

        self::assertFalse($migration->inspect($app)->movable());
        $result = $migration->migrate($app, false, '7.4');

        self::assertTrue($result->refused());
        self::assertStringContainsString($reason, implode("\n", $result->refusals));
        self::assertSame([], $result->changes);
        self::assertDirectoryExists($app . '/vendor/Slim');
        self::assertFileEquals(self::FIXTURE . '/index.php', $app . '/index.php');
        self::assertSame($contents, file_get_contents($app . '/' . $file));
    }

    public function test_a_major_the_recipe_does_not_cover_is_a_stop_sign(): void
    {
        $app = $this->app();
        self::put($app . '/vendor/Smarty/Smarty.class.php', "<?php\ndefine('SMARTY_VERSION', 'Smarty-2.6.26');\n");

        $result = (new VendoredMigration())->migrate($app, false, '7.4');

        self::assertSame(['vendor/Smarty/ holds Smarty 2.6.26; the recipe moves Smarty 3.x only.'], $result->refusals);
        self::assertDirectoryExists($app . '/vendor/Doctrine');
    }

    public function test_an_app_with_nothing_to_move_is_refused_with_a_reason(): void
    {
        $app = $this->tempDir();
        self::put($app . '/index.php', "<?php\necho 'hi';\n");

        $result = (new VendoredMigration())->migrate($app, false, '7.4');

        self::assertSame(['Found no hand-copied library under vendor/ that Booster knows how to move.'], $result->refusals);
    }

    public function test_compiled_templates_are_a_cache_not_a_stop_sign(): void
    {
        $app = $this->app();
        $compiled = "<?php /* Smarty version Smarty-3.1.8, created on 2013-02-01 10:00:00\n         compiled from \"templates/home.tpl\" */ ?>\n<?php require_once SMARTY_DIR . 'plugins/modifier.escape.php';\n";
        self::put($app . '/tmp/template_compile/1a2b3c.file.home.tpl.php', $compiled);
        self::put($app . '/tmp/template_compile/4d5e6f.file.batch.tpl.php', $compiled);

        $result = (new VendoredMigration())->migrate($app, false, '7.4');

        self::assertFalse($result->refused(), implode("\n", $result->refusals));
        self::assertContains(
            "MANUAL: tmp/template_compile/ holds 2 of Smarty's compiled templates, a cache the hand copy wrote; Booster left them alone. Delete the compiled files (Smarty rebuilds them on the next request) and keep the folder out of git.",
            $result->manual,
        );
        self::assertSame($compiled, file_get_contents($app . '/tmp/template_compile/1a2b3c.file.home.tpl.php'));
    }

    public function test_smarty_dir_in_a_cache_folder_says_the_cache_is_the_likely_fix(): void
    {
        $app = $this->app();
        self::put($app . '/cache/templates_c/home.php', "<?php\nrequire SMARTY_DIR . 'plugins/function.html_options.php';\n");

        $refusals = (new VendoredMigration())->inspect($app)->refusals;

        self::assertSame(
            ["cache/templates_c/home.php:2 uses SMARTY_DIR, which pointed at the hand copy: require SMARTY_DIR . 'plugins/function.html_options.php'; (the file sits in a cache folder: if it is a compiled template, delete the cache, Smarty rebuilds it, and plan again)"],
            $refusals,
        );
    }

    public function test_a_commented_out_call_is_not_a_stop_sign(): void
    {
        // The fixture's index.php mentions $app->flash() in a comment.
        self::assertStringContainsString("// \$app->flash('info', 'Saved');", (string) file_get_contents(self::FIXTURE . '/index.php'));

        self::assertTrue((new VendoredMigration())->inspect($this->app())->movable());
    }
}
