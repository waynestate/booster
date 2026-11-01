<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Run\VendoredDirs;

final class VendoredDirsTest extends TestCase
{
    public function test_it_finds_vendored_directories_at_any_depth(): void
    {
        $app = $this->tempDir();
        $this->makeFile($app . '/vendor/a/x.php', "<?php\n");
        $this->makeFile($app . '/node_modules/b/y.php', "<?php\n");
        $this->makeFile($app . '/src/vendor/lib/z.php', '<?php this is a parse error');
        $this->makeFile($app . '/app/Http/Foo.php', "<?php\n");
        $this->makeFile($app . '/a/b/node_modules/c.js', "// js\n");
        $this->makeFile($app . '/.git/vendor/q', "ref: refs/heads/main\n");

        self::assertSame(['a/b/node_modules', 'node_modules', 'src/vendor', 'vendor'], VendoredDirs::find($app));
    }

    public function test_nested_returns_only_the_directories_below_the_app_root(): void
    {
        self::assertSame(
            ['a/b/node_modules', 'src/vendor'],
            VendoredDirs::nested(['a/b/node_modules', 'node_modules', 'src/vendor', 'vendor']),
        );
    }

    public function test_a_missing_path_yields_nothing(): void
    {
        self::assertSame([], VendoredDirs::find($this->tempDir() . '/does-not-exist'));
    }

    private function makeFile(string $path, string $contents): void
    {
        mkdir(dirname($path), 0775, true);
        file_put_contents($path, $contents);
    }
}
