<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Run\NonUtf8Files;

final class NonUtf8FilesTest extends TestCase
{
    public function test_it_finds_only_php_files_that_are_not_valid_utf8(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/a.php', "<?php // cafe\n");
        file_put_contents($dir . '/b.php', "<?php // caf\xE9\n");
        mkdir($dir . '/resources/views', 0775, true);
        file_put_contents($dir . '/resources/views/c.blade.php', "<div>caf\xE9</div>\n");
        mkdir($dir . '/src/vendor', 0775, true);
        file_put_contents($dir . '/src/vendor/d.php', "<?php // caf\xE9\n");

        self::assertSame(['b.php'], NonUtf8Files::find($dir, ['src/vendor']));
    }

    public function test_it_never_enters_the_directories_rector_skips_anyway(): void
    {
        $dir = $this->tempDir();
        foreach ([
            '.git/config.php',
            'storage/App.php',
            'bootstrap/cache/Cache.php',
            'public/build/Build.php',
        ] as $path) {
            mkdir(dirname($dir . '/' . $path), 0775, true);
            file_put_contents($dir . '/' . $path, "<?php // caf\xE9\n");
        }
        file_put_contents($dir . '/e.php', "<?php // caf\xE9\n");

        self::assertSame(['e.php'], NonUtf8Files::find($dir, []));
    }

    public function test_it_ignores_ide_helpers_and_non_php_files(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/_ide_helper.php', "<?php // caf\xE9\n");
        file_put_contents($dir . '/.phpstorm.meta.php', "<?php // caf\xE9\n");
        file_put_contents($dir . '/f.txt', "caf\xE9\n");

        self::assertSame([], NonUtf8Files::find($dir, []));
    }

    public function test_a_missing_app_directory_yields_nothing(): void
    {
        self::assertSame([], NonUtf8Files::find($this->tempDir() . '/nope', []));
    }
}
