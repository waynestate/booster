<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Scan\VendoredLibrary;
use Waynestate\Booster\Scan\VendoredLibraries;

final class VendoredLibrariesTest extends TestCase
{
    public function test_it_names_the_libraries_a_real_app_committed_in_2012(): void
    {
        $app = $this->tempDir();
        $this->makeFile($app . '/vendor/Doctrine/Doctrine/ORM/Version.php', "<?php\nconst VERSION = '2.2.2';\n");
        $this->makeFile($app . '/vendor/Smarty/Smarty.class.php', "<?php\nconst SMARTY_VERSION = 'Smarty-3.1.8';\n");
        $this->makeFile($app . '/vendor/Slim/Slim/Slim.php', "<?php\nconst VERSION = '1.6.7';\n");

        self::assertEquals(
            [
                new VendoredLibrary('Doctrine', 'vendor/Doctrine', '2.2.2', 'doctrine/orm'),
                new VendoredLibrary('Slim', 'vendor/Slim', '1.6.7', 'slim/slim'),
                new VendoredLibrary('Smarty', 'vendor/Smarty', '3.1.8', 'smarty/smarty'),
            ],
            VendoredLibraries::find($app),
        );
    }

    public function test_it_reads_each_version_the_way_the_real_2012_copies_state_it(): void
    {
        // Doctrine 2.2.2: a constant, under a docblock whose @version is a
        // version-control keyword. Smarty 3.1.8: a class constant, repeated in
        // the docblock. Slim 1.6.0: the docblock only, no constant at all.
        $app = $this->tempDir();
        $this->makeFile(
            $app . '/vendor/Doctrine/ORM/Version.php',
            "<?php\nnamespace Doctrine\\ORM;\n\n/**\n * Class to store and retrieve the version of Doctrine\n *\n * @version \$Revision\$\n */\nclass Version\n{\n    const VERSION = '2.2.2';\n}\n",
        );
        $this->makeFile(
            $app . '/vendor/Smarty/Smarty.class.php',
            "<?php\n/**\n * Project:     Smarty: the PHP compiling template engine\n * @package Smarty\n * @version 3.1.8\n */\nclass Smarty\n{\n    const SMARTY_VERSION = 'Smarty-3.1.8';\n}\n",
        );
        $this->makeFile(
            $app . '/vendor/Slim/Slim.php',
            "<?php\n/**\n * Slim - a micro PHP 5 framework\n *\n * @license     http://www.slimframework.com/license\n * @version     1.6.0\n * @package     Slim\n */\nclass Slim\n{\n}\n",
        );

        self::assertEquals(
            [
                new VendoredLibrary('Doctrine', 'vendor/Doctrine', '2.2.2', 'doctrine/orm'),
                new VendoredLibrary('Slim', 'vendor/Slim', '1.6.0', 'slim/slim'),
                new VendoredLibrary('Smarty', 'vendor/Smarty', '3.1.8', 'smarty/smarty'),
            ],
            VendoredLibraries::find($app),
        );
    }

    public function test_the_constant_wins_over_the_docblock(): void
    {
        $app = $this->tempDir();
        $this->makeFile($app . '/vendor/Slim/Slim.php', "<?php\n/**\n * @version 1.5.0\n */\nclass Slim\n{\n    const VERSION = '1.6.7';\n}\n");
        $this->makeFile($app . '/vendor/Smarty/Smarty.class.php', "<?php\n/**\n * @version 3.1.7\n */\nclass Smarty\n{\n    const SMARTY_VERSION = 'Smarty-3.1.8';\n}\n");

        self::assertEquals(
            [
                new VendoredLibrary('Slim', 'vendor/Slim', '1.6.7', 'slim/slim'),
                new VendoredLibrary('Smarty', 'vendor/Smarty', '3.1.8', 'smarty/smarty'),
            ],
            VendoredLibraries::find($app),
        );
    }

    public function test_a_docblock_alone_names_the_version_for_smarty_and_doctrine_too(): void
    {
        $app = $this->tempDir();
        $this->makeFile($app . '/vendor/Doctrine/DBAL/Version.php', "<?php\n/**\n * @version 2.2.2\n */\nclass Version\n{\n}\n");
        $this->makeFile($app . '/vendor/Smarty/Smarty.class.php', "<?php\n/**\n * @version 3.1.8\n */\nclass Smarty\n{\n}\n");

        self::assertEquals(
            [
                new VendoredLibrary('Doctrine', 'vendor/Doctrine', '2.2.2', 'doctrine/orm'),
                new VendoredLibrary('Smarty', 'vendor/Smarty', '3.1.8', 'smarty/smarty'),
            ],
            VendoredLibraries::find($app),
        );
    }

    public function test_no_dotted_version_in_the_main_files_docblock_leaves_the_version_unknown(): void
    {
        $app = $this->tempDir();
        // A version-control keyword, an @version outside a docblock line and
        // a version in some other file of the library all say nothing.
        $this->makeFile(
            $app . '/vendor/Slim/Slim.php',
            "<?php\n/**\n * Slim - a micro PHP 5 framework\n * @version \$Revision\$\n */\n\$tag = '@version 9.9.9';\nclass Slim\n{\n}\n",
        );
        $this->makeFile($app . '/vendor/Slim/View.php', "<?php\n/**\n * @version 1.6.0\n */\nclass Slim_View\n{\n}\n");
        $this->makeFile($app . '/vendor/Doctrine/ORM/Version.php', "<?php\n/**\n * @version \$Revision\$\n */\nclass Version\n{\n}\n");

        self::assertEquals(
            [
                new VendoredLibrary('Doctrine', 'vendor/Doctrine', null, 'doctrine/orm'),
                new VendoredLibrary('Slim', 'vendor/Slim', null, 'slim/slim'),
            ],
            VendoredLibraries::find($app),
        );
    }

    public function test_known_library_without_a_version_file_and_an_unknown_directory(): void
    {
        $app = $this->tempDir();
        $this->makeFile($app . '/vendor/Twig/x.php', "<?php\n");
        $this->makeFile($app . '/vendor/acme-thing/y.php', "<?php\n");

        self::assertEquals(
            [
                new VendoredLibrary('Twig', 'vendor/Twig', null, 'twig/twig'),
                new VendoredLibrary('acme-thing', 'vendor/acme-thing', null, null),
            ],
            VendoredLibraries::find($app),
        );
    }

    public function test_a_composer_managed_vendor_yields_nothing(): void
    {
        $app = $this->tempDir();
        $this->makeFile($app . '/vendor/composer/installed.json', '{}');
        $this->makeFile($app . '/vendor/monolog/monolog/x.php', "<?php\n");

        self::assertSame([], VendoredLibraries::find($app));
    }

    public function test_autoload_php_with_a_composer_directory_marks_the_vendor_managed(): void
    {
        $app = $this->tempDir();
        $this->makeFile($app . '/vendor/autoload.php', "<?php\n");
        $this->makeFile($app . '/vendor/composer/placeholder.txt', "no installed.json here\n");
        $this->makeFile($app . '/vendor/monolog/monolog/x.php', "<?php\n");

        self::assertSame([], VendoredLibraries::find($app));
    }

    public function test_a_lock_next_to_the_vendor_skips_only_the_packages_it_lists(): void
    {
        $app = $this->tempDir();
        $this->makeFile($app . '/composer.lock', json_encode([
            'packages' => [['name' => 'monolog/monolog', 'version' => '1.17.2']],
            'packages-dev' => [],
        ]));
        $this->makeFile($app . '/vendor/monolog/monolog/x.php', "<?php\n");
        $this->makeFile($app . '/vendor/Smarty/Smarty.class.php', "<?php\nconst SMARTY_VERSION = 'Smarty-3.1.8';\n");

        self::assertEquals(
            [new VendoredLibrary('Smarty', 'vendor/Smarty', '3.1.8', 'smarty/smarty')],
            VendoredLibraries::find($app),
        );
    }

    public function test_a_nested_vendor_is_reported_relative_to_the_app_root(): void
    {
        $app = $this->tempDir();
        $this->makeFile($app . '/src/vendor/PHPMailer/class.phpmailer.php', "<?php\npublic \$Version = '5.2.1';\n");

        self::assertEquals(
            [new VendoredLibrary('PHPMailer', 'src/vendor/PHPMailer', '5.2.1', 'phpmailer/phpmailer')],
            VendoredLibraries::find($app),
        );
    }

    public function test_node_modules_is_ignored_and_a_missing_path_yields_nothing(): void
    {
        $app = $this->tempDir();
        $this->makeFile($app . '/node_modules/left-pad/index.js', "// js\n");

        self::assertSame([], VendoredLibraries::find($app));
        self::assertSame([], VendoredLibraries::find($app . '/does-not-exist'));
    }

    public function test_to_array_shape(): void
    {
        $library = new VendoredLibrary('Doctrine', 'vendor/Doctrine', '2.2.2', 'doctrine/orm');

        self::assertSame(
            ['name' => 'Doctrine', 'path' => 'vendor/Doctrine', 'version' => '2.2.2', 'replace_with' => 'doctrine/orm'],
            $library->toArray(),
        );
    }

    public function test_loose_php_files_directly_in_the_vendor_count_as_one_entry(): void
    {
        $app = $this->tempDir();
        $this->makeFile($app . '/vendor/helper.php', "<?php\n");

        self::assertEquals(
            [new VendoredLibrary('vendor', 'vendor', null, null)],
            VendoredLibraries::find($app),
        );
    }

    private function makeFile(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, $contents);
    }
}
