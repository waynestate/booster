<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;
use Waynestate\Booster\Policy\JsonFilePolicySource;
use Waynestate\Booster\Policy\PolicyLocator;

abstract class TestCase extends PhpUnitTestCase
{
    /** The fixed date every test runs with, so results do not change with the calendar. */
    protected const TODAY = '2026-10-28';

    /** @var list<string> */
    private array $tempDirs = [];

    protected function today(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::TODAY);
    }

    protected function policy(): JsonFilePolicySource
    {
        return new JsonFilePolicySource(PolicyLocator::bundledPath());
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
