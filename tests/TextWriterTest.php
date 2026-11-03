<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Report\TextWriter;
use Waynestate\Booster\Scan\Finding;
use Waynestate\Booster\Scan\ScanResult;

/**
 * The plain-wording contract of the PHPCompatibility section: what a newcomer
 * reads before the findings, including singular counts.
 */
final class TextWriterTest extends TestCase
{
    /**
     * @param list<Finding> $findings
     */
    private function render(array $findings): string
    {
        return (new TextWriter())->phpCompat(ScanResult::ok('8.0', $findings));
    }

    public function test_no_findings_says_the_check_found_nothing(): void
    {
        self::assertSame('PHPCompatibility check for PHP 8.0: no findings.', $this->render([]));
    }

    public function test_one_error_and_one_warning_use_singular_words(): void
    {
        $findings = [
            new Finding('src/A.php', 1, 'm', 'error', 'S'),
            new Finding('src/B.php', 2, 'm', 'warning', 'S'),
        ];

        self::assertStringStartsWith('PHPCompatibility check for PHP 8.0: 1 error, 1 warning.', $this->render($findings));
    }

    public function test_counts_are_pluralised_per_column(): void
    {
        $findings = [
            new Finding('src/A.php', 1, 'm', 'error', 'S'),
            new Finding('src/B.php', 2, 'm', 'error', 'S'),
            new Finding('src/C.php', 3, 'm', 'warning', 'S'),
        ];

        self::assertStringStartsWith('PHPCompatibility check for PHP 8.0: 2 errors, 1 warning.', $this->render($findings));
    }

    public function test_zero_warnings_stays_plural(): void
    {
        $findings = [new Finding('src/A.php', 1, 'm', 'error', 'S')];

        self::assertStringStartsWith('PHPCompatibility check for PHP 8.0: 1 error, 0 warnings.', $this->render($findings));
    }
}
