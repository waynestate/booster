<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Packaging\RunScratch;
use Waynestate\Booster\Run\RectorRunner;
use Waynestate\Booster\Run\SymfonyProcessRunner;

/**
 * Runs the real Rector, from Booster's own vendor, on a tiny app. Every other
 * Rector test uses the fake runner, which is why 1.8.0 and 1.8.1 shipped with
 * Rector 2.8.1 next to PHPStan 2.3.4: the two load different copies of
 * phpdoc-parser in one process, and Rector died on the first closure it met
 * ("CallableTypeNode::__construct(): Argument #1 ($identifier) must be of type
 * IdentifierTypeNode, FullyQualifiedNameNode given"). If a dependency update
 * splits the pair again, this test fails before a release does.
 *
 * Booster boosts the app, and this test boosts Booster's confidence.
 */
final class RectorRealProcessTest extends TestCase
{
    public function test_real_rector_rewrites_a_tiny_app_with_closures_and_reports_no_errors(): void
    {
        $app = $this->tempDir();
        file_put_contents($app . '/composer.json', '{"name": "fixture/tiny", "require": {"php": "^7.4"}}' . "\n");
        mkdir($app . '/src');
        // A closure passed to array_map is the shape that tripped the phpdoc-parser
        // clash; strpos() !== false is something the PHP 8.0 set really rewrites.
        $source = <<<'PHP'
            <?php

            function shout(array $names): array
            {
                return array_map(function ($name) {
                    return strtoupper($name);
                }, $names);
            }

            function hasAt(string $email): bool
            {
                return strpos($email, '@') !== false;
            }

            PHP;
        file_put_contents($app . '/src/helpers.php', $source);

        $result = (new RectorRunner(new SymfonyProcessRunner()))
            ->run((new HopRegistry())->get('php-74-to-80'), $app, true, '8.0');

        self::assertSame([], $result->errors, 'Real Rector reported errors: ' . implode("\n", $result->errors));
        self::assertSame(['src/helpers.php'], array_column($result->files, 'file'));
        self::assertStringContainsString('str_contains($email, \'@\')', $result->files[0]['diff']);
        self::assertSame($source, file_get_contents($app . '/src/helpers.php'), 'A dry run must not write the file.');
    }

    public function test_real_rector_keeps_phpstans_container_cache_in_this_runs_scratch_directory(): void
    {
        // 1.8.2 left it in sys_get_temp_dir(), where the second phar run found a
        // container pointing into the first run's deleted phar copy and died.
        $app = $this->tempDir();
        file_put_contents($app . '/composer.json', '{"name": "fixture/tiny", "require": {"php": "^7.4"}}' . "\n");
        file_put_contents($app . '/a.php', "<?php\n\nfunction a(string \$s): bool\n{\n    return strpos(\$s, 'x') !== false;\n}\n");

        $result = (new RectorRunner(new SymfonyProcessRunner()))
            ->run((new HopRegistry())->get('php-74-to-80'), $app, true, '8.0');

        self::assertSame([], $result->errors, 'Real Rector reported errors: ' . implode("\n", $result->errors));
        $built = glob(RunScratch::dir('rector-container') . '/cache/PHPStan/*') ?: [];
        self::assertNotSame([], $built, "PHPStan's container must be cached in the run scratch directory, not in the shared temp directory.");
    }

    public function test_real_rector_leaves_a_loose_switch_on_a_query_string_alone(): void
    {
        // The demo app's report sorting: $get['order'][0]['column'] is the string
        // "1" from the query string. case 1: matches it; the match arm 1 => does
        // not, so ChangeSwitchToMatchRector would silently send every request to
        // default. Booster must not write that.
        $app = $this->tempDir();
        file_put_contents($app . '/composer.json', '{"name": "fixture/tiny", "require": {"php": "^7.4"}}' . "\n");
        $source = <<<'PHP'
            <?php

            function orderColumn(array $get): string
            {
                switch ($get['order'][0]['column']) {
                    case 0:
                        $column = 'name';
                        break;
                    case 1:
                        $column = 'date';
                        break;
                    default:
                        $column = '';
                }

                return $column;
            }

            PHP;
        file_put_contents($app . '/report.php', $source);

        $result = (new RectorRunner(new SymfonyProcessRunner()))
            ->run((new HopRegistry())->get('php-74-to-80'), $app, true, '8.0');

        self::assertSame([], $result->errors, 'Real Rector reported errors: ' . implode("\n", $result->errors));
        foreach ($result->files as $file) {
            self::assertStringNotContainsString('match (', $file['diff'], 'A loose switch must never become a strict match.');
        }
        self::assertArrayNotHasKey('ChangeSwitchToMatchRector', $result->rules);
    }
}
