<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use PHPUnit\Framework\TestCase;
use Waynestate\Booster\Run\HopExecutor;

final class StranglerHeldByLockTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/booster-held-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/composer.lock', json_encode(['packages' => [
            ['name' => 'slim/http', 'version' => '1.3', 'require' => ['psr/http-message' => '^1.0']],
            ['name' => 'slim/psr7', 'version' => '1.6.2', 'require' => ['psr/http-message' => '^1.0']],
            ['name' => 'slim/slim', 'version' => '4.15.3', 'require' => ['psr/http-message' => '^1.1 || ^2.0']],
            ['name' => 'psr/http-message', 'version' => '1.1', 'require' => ['php' => '^7.2 || ^8.0']],
            ['name' => 'twig/twig', 'version' => '3.20.0', 'require' => ['php' => '>=8.1']],
        ], 'packages-dev' => []]));
    }

    protected function tearDown(): void
    {
        unlink($this->dir . '/composer.lock');
        rmdir($this->dir);
    }

    public function test_the_locked_requirers_of_the_package_composer_could_not_load_are_named(): void
    {
        $lines = [
            'Gate failed: composer update -W guzzlehttp/guzzle waynestate/sesame failed (exit 2) on PHP 8.5.',
            '    - waynestate/sesame 0.10.0 requires psr/http-message ^2.0 -> found psr/http-message[2.0] but these were not loaded, likely because it conflicts with another require.',
        ];

        self::assertSame(['slim/http', 'slim/psr7', 'slim/slim'], HopExecutor::heldByLock($this->dir, $lines, ['guzzlehttp/guzzle', 'waynestate/sesame']));
        self::assertSame(['slim/psr7', 'slim/slim'], HopExecutor::heldByLock($this->dir, $lines, ['slim/http']), 'a package already named is not named twice');
    }

    public function test_any_other_failure_names_nothing(): void
    {
        self::assertSame([], HopExecutor::heldByLock($this->dir, ['Gate failed: the tests failed (exit 1) on PHP 8.5.'], ['waynestate/sesame']));
        self::assertSame([], HopExecutor::heldByLock($this->dir . '/nowhere', ['found psr/http-message[2.0] but these were not loaded'], []));
    }
}
