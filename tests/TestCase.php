<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;
use Waynestate\Booster\Policy\Policy;
use Waynestate\Booster\Policy\PolicyLocator;
use Waynestate\Booster\Run\ProcessResult;

abstract class TestCase extends PhpUnitTestCase
{
    /** The fixed date every test runs with, so results do not change with the calendar. */
    protected const TODAY = '2026-10-28';

    /** `phpcs -i` output that carries the PHPCompatibility standard. */
    protected const PHPCOMPAT_STANDARDS = "The installed standards are PEAR, PSR1, PSR2, PSR12, Squiz, Zend, PHPCompatibility\n";

    /** A frozen copy of the bundled policy, named for the date it was copied from. */
    protected const POLICY_FIXTURE = __DIR__ . '/fixtures/policy/policy-2026-10-28.json';

    /** A real PROCTOR 0.2.7 JSON report with no failing findings. */
    protected const PROCTOR_PASS_FIXTURE = __DIR__ . '/fixtures/proctor/pass-report.json';

    /** A real PROCTOR 0.2.7 JSON report with failing findings (php-floor, lock-committed). */
    protected const PROCTOR_FAIL_FIXTURE = __DIR__ . '/fixtures/proctor/fail-report.json';

    /** The raw contents of one of the PROCTOR fixture reports. */
    protected static function proctorFixture(string $name): string
    {
        $contents = file_get_contents(__DIR__ . '/fixtures/proctor/' . $name);
        if ($contents === false) {
            throw new \RuntimeException('Missing PROCTOR fixture ' . $name);
        }

        return $contents;
    }

    /** Whether $command is Booster's PROCTOR gate call (php <proctor bin> check ...). */
    protected static function isProctorCommand(array $command): bool
    {
        return ($command[2] ?? '') === 'check' && str_ends_with((string) ($command[1] ?? ''), 'proctor');
    }

    /** @var list<string> */
    private array $tempDirs = [];

    protected function today(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::TODAY);
    }

    protected function policy(): Policy
    {
        // Tests run against a frozen copy of the bundled policy so they do not
        // change when the bundled snapshot is refreshed. The bundled copy itself
        // is smoke-tested in PolicyTest.
        return PolicyLocator::locate(self::POLICY_FIXTURE, null);
    }

    protected function example(string $name): string
    {
        return dirname(__DIR__) . '/examples/' . $name;
    }

    /** Copies an example into a fresh temp directory and returns it. */
    protected function copyExample(string $name): string
    {
        $dir = $this->tempDir();
        $this->copyTree($this->example($name), $dir);

        return $dir;
    }

    protected function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/booster-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0775, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    /**
     * A FakeProcessRunner that answers `phpcs -i` with a standard list carrying
     * PHPCompatibility and every other call with $scan, so the scan's discovery
     * step finds the standard and then runs the real scan command.
     */
    protected function phpcsFake(callable $scan): FakeProcessRunner
    {
        return new FakeProcessRunner(static function (array $command) use ($scan): ProcessResult {
            if (in_array('-i', $command, true)) {
                return new ProcessResult(0, self::PHPCOMPAT_STANDARDS, '');
            }

            return $scan();
        });
    }

    /**
     * A FakeProcessRunner that answers `phpstan --version` with a version line
     * and every other call with $scan, so the scan's discovery step finds a
     * runnable PHPStan and then runs the real scan command.
     */
    protected function phpstanFake(callable $scan): FakeProcessRunner
    {
        return new FakeProcessRunner(static function (array $command) use ($scan): ProcessResult {
            if (in_array('--version', $command, true)) {
                return new ProcessResult(0, "PHPStan - PHP Static Analysis Tool 2.3.5\n", '');
            }

            return $scan();
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeTree($dir);
        }
        $this->tempDirs = [];
    }

    private function copyTree(string $from, string $to): void
    {
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir($from . '/' . $entry)) {
                mkdir($to . '/' . $entry, 0775, true);
                $this->copyTree($from . '/' . $entry, $to . '/' . $entry);
            } else {
                copy($from . '/' . $entry, $to . '/' . $entry);
            }
        }
    }

    private function removeTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            is_dir($dir . '/' . $entry) ? $this->removeTree($dir . '/' . $entry) : unlink($dir . '/' . $entry);
        }
        rmdir($dir);
    }
}
