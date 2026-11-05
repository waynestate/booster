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
