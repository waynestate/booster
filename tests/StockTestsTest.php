<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Run\StockTests;

final class StockTestsTest extends TestCase
{
    private const FEATURE = <<<'PHP'
<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
PHP;

    private const UNIT = <<<'PHP'
<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ExampleTest extends TestCase
{
    public function test_that_true_is_true(): void
    {
        $this->assertTrue(true);
    }
}
PHP;

    /**
     * @param array<string, string> $files relative path to content
     */
    private function appWithTests(array $files): string
    {
        $dir = $this->tempDir();
        foreach ($files as $relative => $content) {
            $target = $dir . '/' . $relative;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0775, true);
            }
            file_put_contents($target, $content);
        }

        return $dir;
    }

    public function test_the_two_stock_examples_are_recognised(): void
    {
        // A base TestCase.php under tests/ is part of the stock skeleton and does not count.
        $dir = $this->appWithTests([
            'tests/Feature/ExampleTest.php' => self::FEATURE,
            'tests/Unit/ExampleTest.php' => self::UNIT,
            'tests/TestCase.php' => "<?php\n",
        ]);

        $this->assertTrue(StockTests::onlyStockExamples($dir));
    }

    public function test_a_third_test_file_means_real_tests(): void
    {
        $dir = $this->appWithTests([
            'tests/Feature/ExampleTest.php' => self::FEATURE,
            'tests/Unit/ExampleTest.php' => self::UNIT,
            'tests/Feature/PostTest.php' => "<?php\n",
        ]);

        $this->assertFalse(StockTests::onlyStockExamples($dir));
    }

    public function test_a_stock_file_edited_past_recognition_is_not_stock(): void
    {
        $edited = str_replace('assertStatus(200)', 'assertStatus(201)', self::FEATURE);
        $dir = $this->appWithTests([
            'tests/Feature/ExampleTest.php' => $edited,
            'tests/Unit/ExampleTest.php' => self::UNIT,
        ]);

        $this->assertFalse(StockTests::onlyStockExamples($dir));
    }

    public function test_an_app_without_tests_is_not_stock(): void
    {
        $dir = $this->tempDir();

        $this->assertFalse(StockTests::onlyStockExamples($dir));
    }
}
