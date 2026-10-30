<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

/**
 * Detects the app whose whole test suite is still Laravel's stock examples,
 * the two files `laravel new` writes: tests/Feature/ExampleTest.php, which
 * asks for "/" and expects 200, and tests/Unit/ExampleTest.php, which asserts
 * true. Such a suite passes on any code, so a green gate proves nothing.
 */
final class StockTests
{
    /**
     * True when tests/ holds nothing but the two stock example tests, each
     * still carrying its stock assertion. Any other test file, or a stock
     * file edited past recognition, means the app has real tests. Non-test
     * helpers under tests/ (a base TestCase, a Pest bootstrap) do not count.
     */
    public static function onlyStockExamples(string $path): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $tests = $path . '/tests';
        if (!is_dir($tests)) {
            return false;
        }

        $testFiles = [];
        $prefix = $path . '/';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tests, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getBasename(), 'Test.php')) {
                $testFiles[] = substr(str_replace('\\', '/', $file->getPathname()), strlen($prefix));
            }
        }
        sort($testFiles);
        if ($testFiles !== ['tests/Feature/ExampleTest.php', 'tests/Unit/ExampleTest.php']) {
            return false;
        }

        $feature = file_get_contents($tests . '/Feature/ExampleTest.php');
        $unit = file_get_contents($tests . '/Unit/ExampleTest.php');
        if ($feature === false || $unit === false) {
            return false;
        }

        return preg_match("/->get\(\s*'\/'\s*\)/", $feature) === 1
            && preg_match('/assertStatus\(\s*200\s*\)/', $feature) === 1
            && preg_match('/assertTrue\(\s*true\s*\)/', $unit) === 1;
    }
}
