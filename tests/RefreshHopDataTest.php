<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Policy\Policy;
use Waynestate\Booster\Policy\PolicyLocator;
use Waynestate\Booster\Refresh\HopDataRefresher;
use Waynestate\Booster\ToolError;

/**
 * Exercises the refresher that keeps data/versions.json honest. The frozen
 * feeds in tests/fixtures/hop-feeds stand in for the live Packagist, npm and
 * endoflife.date answers, so the test runs offline.
 */
final class RefreshHopDataTest extends TestCase
{
    private const FEEDS = __DIR__ . '/fixtures/hop-feeds';

    /** @var list<string> */
    private const FEED_FILES = [
        HopDataRefresher::FEED_PHPUNIT => 'packagist-phpunit-phpunit.json',
        HopDataRefresher::FEED_LARAVEL_FRAMEWORK => 'packagist-laravel-framework.json',
        HopDataRefresher::FEED_LARAVEL_SKELETON => 'packagist-laravel-laravel.json',
        HopDataRefresher::FEED_ENDOFLIFE => 'endoflife-laravel.json',
        HopDataRefresher::FEED_VITE => 'npm-vite-dist-tags.json',
        HopDataRefresher::FEED_LARAVEL_VITE_PLUGIN => 'npm-laravel-vite-plugin-dist-tags.json',
        HopDataRefresher::FEED_VUE_PLUGIN => 'npm-vitejs-plugin-vue-dist-tags.json',
    ];

    /** The hop data as it stood before this refresh: Node 24, the old Laravel-to-PHPUnit floors. */
    private function oldData(): array
    {
        return [
            'generated_on' => '2026-10-28',
            'php' => ['5.6', '7.0', '7.1', '7.2', '7.3', '7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'],
            'laravel' => ['8', '9', '10', '11', '12', '13'],
            'phpunit' => ['9', '10', '11', '12', '13'],
            'node_pin_target' => '24',
            'vite_version' => '^8.3',
            'laravel_vite_plugin_version' => '^3.2',
            'vue_plugin_version' => '^6.0',
            'phpunit_php' => ['9' => '7.3', '10' => '8.1', '11' => '8.2', '12' => '8.3', '13' => '8.4'],
            'laravel_phpunit' => ['10' => '9', '11' => '10', '12' => '11', '13' => '12'],
            'laravel_php' => [
                '8' => ['7.3', '8.1'],
                '9' => ['8.0', '8.2'],
                '10' => ['8.1', '8.3'],
                '11' => ['8.2', '8.4'],
                '12' => ['8.2', '8.5'],
                '13' => ['8.3', '8.5'],
            ],
        ];
    }

    /** @return array<string, array<mixed>> */
    private function feeds(): array
    {
        $feeds = [];
        foreach (self::FEED_FILES as $name => $file) {
            $data = json_decode((string) file_get_contents(self::FEEDS . '/' . $file), true);
            self::assertIsArray($data);
            $feeds[$name] = $data;
        }

        return $feeds;
    }

    /** A policy like the frozen fixture, plus one extra version per component. */
    private function policyWith(array $extra): Policy
    {
        $json = json_decode((string) file_get_contents(self::POLICY_FIXTURE), true);
        self::assertIsArray($json);
        foreach ($extra as $component => $versions) {
            $json['components'][$component]['versions'] = array_replace($json['components'][$component]['versions'], $versions);
        }
        $file = $this->tempDir() . '/policy.json';
        file_put_contents($file, (string) json_encode($json));

        return PolicyLocator::locate($file, null);
    }

    public function test_the_frozen_feeds_move_node_and_the_laravel_phpunit_floors(): void
    {
        $result = (new HopDataRefresher($this->policy()))->refresh($this->oldData(), $this->feeds(), new DateTimeImmutable('2026-10-29'));

        self::assertSame('2026-10-29', $result['generated_on']);
        self::assertSame('26', $result['node_pin_target']);
        self::assertSame(['10' => '10', '11' => '11', '12' => '11', '13' => '12'], $result['laravel_phpunit']);
        // Everything else the frozen feeds agree with the file on.
        self::assertSame($this->oldData()['php'], $result['php']);
        self::assertSame($this->oldData()['laravel'], $result['laravel']);
        self::assertSame($this->oldData()['phpunit'], $result['phpunit']);
        self::assertSame($this->oldData()['phpunit_php'], $result['phpunit_php']);
        self::assertSame($this->oldData()['laravel_php'], $result['laravel_php']);
        self::assertSame('^8.3', $result['vite_version']);
        self::assertSame('^3.2', $result['laravel_vite_plugin_version']);
        self::assertSame('^6.0', $result['vue_plugin_version']);
    }

    public function test_a_refresh_is_idempotent(): void
    {
        $refresher = new HopDataRefresher($this->policy());
        $once = $refresher->refresh($this->oldData(), $this->feeds(), new DateTimeImmutable('2026-10-29'));
        $twice = $refresher->refresh($once, $this->feeds(), new DateTimeImmutable('2026-10-29'));

        self::assertSame($once, $twice);
    }

    public function test_the_refreshed_data_round_trips_through_the_registry(): void
    {
        $result = (new HopDataRefresher($this->policy()))->refresh($this->oldData(), $this->feeds(), new DateTimeImmutable('2026-10-29'));
        $file = $this->tempDir() . '/versions.json';
        file_put_contents($file, (string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $registry = new HopRegistry($file);

        self::assertSame('26', $registry->nodePinTarget());
        self::assertSame('10', $registry->laravelPhpunit()['10']);
        self::assertSame('11', $registry->laravelPhpunit()['11']);
    }

    public function test_a_new_laravel_major_in_the_policy_extends_the_chain_and_tables(): void
    {
        $policy = $this->policyWith([
            'laravel' => ['14' => ['active_until' => '2028-09-01', 'security_until' => '2029-03-01']],
        ]);
        $feeds = $this->feeds();
        $feeds[HopDataRefresher::FEED_LARAVEL_SKELETON] = [
            'packages' => ['laravel/laravel' => [['version' => '14.0.0', 'require-dev' => ['phpunit/phpunit' => '^13.0']]]],
        ];
        $feeds[HopDataRefresher::FEED_LARAVEL_FRAMEWORK] = [
            'packages' => ['laravel/framework' => [['version' => '14.0.0', 'require' => ['php' => '^8.4']]]],
        ];
        $feeds[HopDataRefresher::FEED_ENDOFLIFE] = [
            ['cycle' => '14', 'supportedPhpVersions' => '8.4 - 8.6'],
        ];

        $result = (new HopDataRefresher($policy))->refresh($this->oldData(), $feeds, new DateTimeImmutable('2026-10-29'));

        self::assertSame(['8', '9', '10', '11', '12', '13', '14'], $result['laravel']);
        self::assertSame('13', $result['laravel_phpunit']['14']);
        self::assertSame(['8.4', '8.6'], $result['laravel_php']['14']);
        // Majors the synthetic feeds dropped keep their previous values.
        self::assertSame('9', $result['laravel_phpunit']['10']);
        self::assertSame(['8.3', '8.5'], $result['laravel_php']['13']);
    }

    public function test_a_new_php_major_in_the_policy_extends_the_php_chain(): void
    {
        $policy = $this->policyWith([
            'php' => ['8.6' => ['active_until' => '2027-11-20', 'security_until' => '2029-11-20']],
        ]);

        $result = (new HopDataRefresher($policy))->refresh($this->oldData(), $this->feeds(), new DateTimeImmutable('2026-10-29'));

        self::assertSame(['5.6', '7.0', '7.1', '7.2', '7.3', '7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5', '8.6'], $result['php']);
        // No table grows: tables follow their own chains, not the PHP chain.
        self::assertSame($this->oldData()['phpunit_php'], $result['phpunit_php']);
        self::assertSame($this->oldData()['laravel_php'], $result['laravel_php']);
    }

    public function test_a_new_phpunit_major_in_the_feed_extends_the_chain_and_floor(): void
    {
        $feeds = $this->feeds();
        $feeds[HopDataRefresher::FEED_PHPUNIT] = [
            'packages' => ['phpunit/phpunit' => [
                ['version' => '14.0.0', 'require' => ['php' => '>=8.5']],
                ['version' => '13.0.0', 'require' => ['php' => '>=8.4.1']],
            ]],
        ];

        $result = (new HopDataRefresher($this->policy()))->refresh($this->oldData(), $feeds, new DateTimeImmutable('2026-10-29'));

        self::assertSame(['9', '10', '11', '12', '13', '14'], $result['phpunit']);
        self::assertSame('8.5', $result['phpunit_php']['14']);
        self::assertSame('8.4', $result['phpunit_php']['13']);
    }

    public function test_packagist_entries_inherit_the_nearest_explicit_require(): void
    {
        $feed = [
            'packages' => ['phpunit/phpunit' => [
                ['version' => '10.5.0', 'require' => ['php' => '>=8.1']],
                ['version' => '10.0.0'],
                ['version' => '9.6.0', 'require' => ['php' => '^7.3']],
                ['version' => '9.0.0'],
            ]],
        ];

        $effective = HopDataRefresher::constraintsPerMajor($feed, 'require', 'php');

        self::assertSame(['oldest' => '>=8.1', 'newest' => '>=8.1'], $effective['10']);
        self::assertSame(['oldest' => '^7.3', 'newest' => '^7.3'], $effective['9']);
    }

    #[DataProvider('phpFloorProvider')]
    public function test_php_floor_reads_the_lower_bound_as_major_minor(string $constraint, ?string $expected): void
    {
        self::assertSame($expected, HopDataRefresher::phpFloor($constraint));
    }

    /** @return list<array{string, ?string}> */
    public static function phpFloorProvider(): array
    {
        return [
            ['^7.3', '7.3'],
            ['^8.0.2', '8.0'],
            ['>=8.1', '8.1'],
            ['>=8.4.1', '8.4'],
            ['nonsense', null],
        ];
    }

    #[DataProvider('lowestMajorProvider')]
    public function test_lowest_major_reads_the_lowest_accepted_major(string $constraint, ?string $expected): void
    {
        self::assertSame($expected, HopDataRefresher::lowestMajor($constraint));
    }

    /** @return list<array{string, ?string}> */
    public static function lowestMajorProvider(): array
    {
        return [
            ['^9.5.10', '9'],
            ['^12.5.12', '12'],
            ['^8.5.8|^9.3.3', '8'],
            ['*', null],
        ];
    }

    #[DataProvider('caretFromTagProvider')]
    public function test_caret_from_tag_keeps_major_and_minor(string $tag, ?string $expected): void
    {
        self::assertSame($expected, HopDataRefresher::caretFromTag($tag));
    }

    /** @return list<array{string, ?string}> */
    public static function caretFromTagProvider(): array
    {
        return [
            ['8.3.5', '^8.3'],
            ['v6.0.10', '^6.0'],
            ['latest', null],
        ];
    }

    public function test_a_missing_feed_is_a_tool_error(): void
    {
        $feeds = $this->feeds();
        unset($feeds[HopDataRefresher::FEED_ENDOFLIFE]);

        $this->expectException(ToolError::class);
        (new HopDataRefresher($this->policy()))->refresh($this->oldData(), $feeds, new DateTimeImmutable('2026-10-29'));
    }

    public function test_an_unparseable_constraint_keeps_the_previous_value(): void
    {
        $feeds = $this->feeds();
        $feeds[HopDataRefresher::FEED_PHPUNIT] = [
            'packages' => ['phpunit/phpunit' => [['version' => '13.0.0', 'require' => ['php' => 'not-a-constraint']]]],
        ];

        $result = (new HopDataRefresher($this->policy()))->refresh($this->oldData(), $feeds, new DateTimeImmutable('2026-10-29'));

        self::assertSame('8.4', $result['phpunit_php']['13']);
    }
}
