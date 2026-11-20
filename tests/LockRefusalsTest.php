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

    public function test_it_names_the_locked_packages_that_lean_on_phpunit(): void
    {
        // The public slim-skeleton 4.5.0 (2026-11-20): phpstan-prophecy 1.0.2
        // conflicts with PHPUnit 12, and prophecy 1.22 needs comparator 7 or
        // older while PHPUnit 13 needs 8. -W never moves either, so the 12
        // and 13 steps' updates failed until they were named.
        $dir = $this->tempDir();
        file_put_contents($dir . '/composer.lock', (string) json_encode([
            'packages' => [['name' => 'slim/slim', 'version' => '4.15.3', 'require' => ['php' => '^7.4 || ^8.0', 'psr/log' => '^1.1']]],
            'packages-dev' => [
                ['name' => 'jangregor/phpstan-prophecy', 'version' => '1.0.2', 'require' => ['phpstan/phpstan' => '^1.0.0'], 'conflict' => ['phpspec/prophecy' => '<1.7.0 || >=2.0.0', 'phpunit/phpunit' => '<6.0.0 || >=12.0.0']],
                ['name' => 'phpspec/prophecy', 'version' => 'v1.22.0', 'require' => ['sebastian/comparator' => '^3.0 || ^4.0 || ^5.0 || ^6.0 || ^7.0']],
                ['name' => 'phpspec/prophecy-phpunit', 'version' => 'v2.5.0', 'require' => ['phpspec/prophecy' => '^1.18', 'phpunit/phpunit' => '^9.1 || ^10.1 || ^11.0 || ^12.0 || ^13.0']],
                ['name' => 'phpstan/phpstan', 'version' => '1.12.34', 'require' => ['php' => '^7.2|^8.0']],
                ['name' => 'phpunit/phpunit', 'version' => '11.5.58', 'require' => ['php' => '>=8.2', 'sebastian/comparator' => '^6.3']],
                ['name' => 'sebastian/comparator', 'version' => '6.3.3', 'require' => ['sebastian/diff' => '^6.0']],
                ['name' => 'sebastian/diff', 'version' => '6.0.2', 'require' => ['php' => '>=8.2']],
            ],
        ]));

        self::assertSame(['jangregor/phpstan-prophecy', 'phpspec/prophecy', 'phpspec/prophecy-phpunit'], LockRefusals::phpunitDependents($dir, '12'));
        self::assertSame(['phpspec/prophecy', 'phpspec/prophecy-phpunit'], LockRefusals::phpunitDependents($dir, '11'));
        self::assertSame([], LockRefusals::phpunitDependents($this->tempDir(), '12'));

        // A conflict that covers only part of the major does not hold it, and
        // phpstan-prophecy 1.0.0's "<6.0.0,>=10.0.0" (an AND, so empty) holds nothing.
        self::assertTrue(LockRefusals::allowsPhpunit(['conflict' => ['phpunit/phpunit' => '>=12.5.0']], '12'));
        self::assertTrue(LockRefusals::allowsPhpunit(['conflict' => ['phpunit/phpunit' => '<6.0.0,>=10.0.0']], '13'));
        self::assertFalse(LockRefusals::allowsPhpunit(['conflict' => ['phpunit/phpunit' => '<6.0.0 || >=12.0.0']], '13'));
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

    public function test_a_release_broken_on_the_php_is_updated_too(): void
    {
        // Bug S-twig (testeval): Twig 2.5.0 allows PHP 7.4 but trims the
        // whitespace before every {{ there, so the 7.4 hop's replay failed.
        $dir = $this->app(['require' => ['php' => '>=7']], [
            ['twig/twig', 'v2.5.0', '^7.0'],
            ['slim/twig-view', '2.5.1', '>=5.5.0'],
        ]);

        $why = 'its lexer trims the whitespace before every {{ on PHP 7.4 and later (fixed in 2.8.0)';
        self::assertSame(['twig/twig' => ['version' => 'v2.5.0', 'constraint' => '>=2.8.0', 'reason' => $why]], LockRefusals::find($dir, '7.4'));
        self::assertArrayHasKey('twig/twig', LockRefusals::find($dir, '8.0'));
        self::assertSame([], LockRefusals::find($dir, '7.3'));
        self::assertSame([], LockRefusals::find($dir, '7.4', false), 'a failed install names only what refused it');
        self::assertSame('twig/twig v2.5.0: ' . $why, LockRefusals::describe(LockRefusals::find($dir, '7.4')));

        // Fixed releases, and the 1.x line from its own fix, are left alone.
        $fixed = $this->app(['require' => ['php' => '>=7']], [['twig/twig', 'v2.8.0', '^7.0'], ['twig/twig-extra', 'v1.0.0', null]]);
        self::assertSame([], LockRefusals::find($fixed, '7.4'));
        $one = $this->app(['require' => ['php' => '>=7']], [['twig/twig', 'v1.35.0', '>=5.4.0']]);
        self::assertSame('>=1.39.0', LockRefusals::find($one, '7.4')['twig/twig']['constraint'] ?? null);
        $branch = $this->app(['require' => ['php' => '>=7']], [['twig/twig', 'dev-main', '^7.0']]);
        self::assertSame([], LockRefusals::find($branch, '7.4'));
    }

    public function test_no_lock_or_an_unreadable_constraint_refuses_nothing(): void
    {
        self::assertSame([], LockRefusals::find($this->tempDir(), '8.0'));
        $dir = $this->app(null, [['acme/odd', '1.0.0', 'not a constraint']]);
        self::assertSame([], LockRefusals::find($dir, '8.0'));
    }
}
