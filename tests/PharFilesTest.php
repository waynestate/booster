<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Packaging\PharFiles;

final class PharFilesTest extends TestCase
{
    public function test_the_test_suite_is_not_running_from_a_phar(): void
    {
        self::assertFalse(PharFiles::running());
    }

    public function test_path_resolves_bundled_files_to_the_checkout(): void
    {
        $root = dirname(__DIR__);

        self::assertSame($root . '/config/rector.php', PharFiles::path('config/rector.php'));
        self::assertFileExists(PharFiles::path('config/rector.php'));
        self::assertFileExists(PharFiles::path('resources/policy.json'));
        self::assertDirectoryExists(PharFiles::path('docker'));
    }

    public function test_path_strips_a_leading_slash(): void
    {
        $root = dirname(__DIR__);

        self::assertSame($root . '/config/rector.php', PharFiles::path('/config/rector.php'));
    }
}
