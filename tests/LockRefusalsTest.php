<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Run\LockRefusals;

final class LockRefusalsTest extends TestCase
{
    /**
     * @param array<string, mixed>|null $composer
     * @param list<array{0: string, 1: string, 2: string|null}> $packages
     * @param list<array{0: string, 1: string, 2: string|null}> $dev
     */
    private function app(?array $composer, array $packages, array $dev = []): string
    {
        $dir = $this->tempDir();
        if ($composer !== null) {
            file_put_contents($dir . '/composer.json', (string) json_encode($composer));
        }
        $lock = static fn(array $list): array => array_map(static fn(array $p): array => ['name' => $p[0], 'version' => $p[1]] + ($p[2] === null ? [] : ['require' => ['php' => $p[2]]]), $list);
        file_put_contents($dir . '/composer.lock', (string) json_encode(['packages' => $lock($packages), 'packages-dev' => $lock($dev)]));

        return $dir;
    }

    public function test_it_names_the_locked_packages_that_leave_the_php_out(): void
    {
        $dir = $this->app(['require' => ['php' => '^7.4']], [
            ['symfony/service-contracts', 'v1.1.2', '^7.1.3'],
            ['doctrine/orm', '2.20.14', '^7.1 || ^8.0'],
            ['psr/log', '1.1.4', null],
        ], [['phpunit/phpunit', '8.5.40', '>=7.2 <8.0']]);

        self::assertSame([
            'symfony/service-contracts' => ['version' => 'v1.1.2', 'constraint' => '^7.1.3'],
            'phpunit/phpunit' => ['version' => '8.5.40', 'constraint' => '>=7.2 <8.0'],
        ], LockRefusals::find($dir, '8.0'));
        self::assertSame([], LockRefusals::find($dir, '7.4'));
        self::assertSame('symfony/service-contracts v1.1.2 needs php ^7.1.3', LockRefusals::describe(['symfony/service-contracts' => ['version' => 'v1.1.2', 'constraint' => '^7.1.3']]));
    }

    public function test_an_unpinned_app_is_checked_on_the_newest_patch(): void
    {
        // The gate image runs the newest 8.0 patch, so a floor of 8.0.2 installs.
        $dir = $this->app(['require' => ['php' => '^8.0']], [['symfony/string', 'v6.0.19', '>=8.0.2']]);

        self::assertSame([], LockRefusals::find($dir, '8.0'));
    }

    public function test_a_platform_pin_is_what_composer_checks(): void
    {
        // Pinned at 8.0.0, Composer refuses a package that needs 8.0.2.
        $dir = $this->app(['require' => ['php' => '^8.0'], 'config' => ['platform' => ['php' => '8.0.0']]], [['symfony/string', 'v6.0.19', '>=8.0.2']]);
        self::assertArrayHasKey('symfony/string', LockRefusals::find($dir, '8.0'));

        // On a hop to 8.4 the pin moves to 8.4.1, the first 8.4 release.
        $dir = $this->app(['require' => ['php' => '^8.3'], 'config' => ['platform' => ['php' => '8.3.0']]], [['phpunit/phpunit', '13.0.0', '>=8.4.1']]);
        self::assertSame([], LockRefusals::find($dir, '8.4'));
    }

    public function test_no_lock_or_an_unreadable_constraint_refuses_nothing(): void
    {
        self::assertSame([], LockRefusals::find($this->tempDir(), '8.0'));
        $dir = $this->app(null, [['acme/odd', '1.0.0', 'not a constraint']]);
        self::assertSame([], LockRefusals::find($dir, '8.0'));
    }
}
