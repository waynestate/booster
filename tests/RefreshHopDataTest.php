<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
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
            'vue_27' => '^2.7',
            'vue_compat' => '^3.5',
            'vue_loader_compat' => '^17.4',
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
        // Everything else the frozen feeds agree with the file on, except the
        // planning floors the refresher keeps ahead of the policy.
        self::assertSame(array_merge(HopDataRefresher::PHP_FLOOR_PREFIX, $this->oldData()['php']), $result['php']);
        self::assertSame(array_merge(HopDataRefresher::LARAVEL_FLOOR_PREFIX, $this->oldData()['laravel']), $result['laravel']);
        self::assertSame($this->oldData()['phpunit'], $result['phpunit']);
        self::assertSame($this->oldData()['phpunit_php'], $result['phpunit_php']);
        self::assertSame($this->oldData()['laravel_php'], $result['laravel_php']);
        self::assertSame('^8.3', $result['vite_version']);
        self::assertSame('^3.2', $result['laravel_vite_plugin_version']);
        self::assertSame('^6.0', $result['vue_plugin_version']);
        // The Vue recipe pins carry through untouched: no feed computes them.
        self::assertSame('^2.7', $result['vue_27']);
        self::assertSame('^3.5', $result['vue_compat']);
        self::assertSame('^17.4', $result['vue_loader_compat']);
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

        self::assertSame(array_merge(HopDataRefresher::LARAVEL_FLOOR_PREFIX, ['8', '9', '10', '11', '12', '13', '14']), $result['laravel']);
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

        self::assertSame(array_merge(HopDataRefresher::PHP_FLOOR_PREFIX, ['5.6', '7.0', '7.1', '7.2', '7.3', '7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5', '8.6']), $result['php']);
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

    /** The committed hop data file, decoded. */
    private function committedData(): array
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__) . '/data/versions.json'), true);
        self::assertIsArray($data);

        return $data;
    }

    /**
     * Runs bin/refresh-hop-data.php in a subprocess with remote URL fetching
     * disabled (allow_url_fopen=0), so any attempt to reach a feed fails the
     * run. Returns the exit code, stdout and stderr.
     *
     * @param list<string> $args
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function runScript(array $args): array
    {
        $cmd = [PHP_BINARY, '-d', 'allow_url_fopen=0', dirname(__DIR__) . '/bin/refresh-hop-data.php'];
        foreach ($args as $arg) {
            $cmd[] = $arg;
        }

        $process = new Process($cmd, dirname(__DIR__));
        $process->run();

        return [$process->getExitCode(), $process->getOutput(), $process->getErrorOutput()];
    }

    public function test_check_passes_offline_and_makes_no_network_call(): void
    {
        // Remote fetching is disabled for the child process: any attempt to
        // reach a feed would fail the run, so exit 0 proves the check stayed
        // local.
        [$code, $out, $err] = $this->runScript(['--check']);

        self::assertSame(0, $code);
        self::assertStringContainsString('is current', $out);
        self::assertStringNotContainsString('Could not fetch', $err);
    }

    public function test_online_mode_reaches_for_the_live_feeds(): void
    {
        // With remote fetching disabled, --online must try the live feeds and
        // fail on the very first one.
        [$code, , $err] = $this->runScript(['--online']);

        self::assertSame(1, $code);
        self::assertStringContainsString('Could not fetch', $err);
    }

    public function test_check_fails_when_the_file_drifts_from_the_fixtures(): void
    {
        $dataPath = dirname(__DIR__) . '/data/versions.json';
        $original = (string) file_get_contents($dataPath);
        try {
            $data = $this->committedData();
            $data['vite_version'] = '^9.0';
            file_put_contents($dataPath, (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

            [$code, $out] = $this->runScript(['--check']);

            self::assertSame(3, $code);
            self::assertStringContainsString('vite_version', $out);
        } finally {
            file_put_contents($dataPath, $original);
        }
    }

    public function test_check_reports_shape_problems(): void
    {
        $dataPath = dirname(__DIR__) . '/data/versions.json';
        $original = (string) file_get_contents($dataPath);
        try {
            $data = $this->committedData();
            unset($data['laravel_php']['13']);
            file_put_contents($dataPath, (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

            [$code, , $err] = $this->runScript(['--check']);

            self::assertSame(1, $code);
            self::assertStringContainsString('laravel_php', $err);
        } finally {
            file_put_contents($dataPath, $original);
        }
    }

    public function test_check_refuses_to_combine_with_offline_today_or_online(): void
    {
        $combos = [
            ['--offline', 'tests/fixtures/hop-feeds'],
            ['--today', '2026-10-29'],
            ['--online'],
        ];
        foreach ($combos as $args) {
            [$code, , $err] = $this->runScript(array_merge(['--check'], $args));

            self::assertSame(1, $code, 'arguments: ' . implode(' ', $args));
            self::assertStringContainsString('--check is offline', $err);
        }
    }

    public function test_online_refuses_to_combine_with_offline(): void
    {
        [$code, , $err] = $this->runScript(['--online', '--offline', 'tests/fixtures/hop-feeds']);

        self::assertSame(1, $code);
        self::assertStringContainsString('--online fetches the live feeds', $err);
    }

    public function test_shape_problems_accept_the_committed_file(): void
    {
        self::assertSame([], (new HopDataRefresher($this->policy()))->shapeProblems($this->committedData(), new DateTimeImmutable('2026-10-29')));
    }

    public function test_shape_problems_do_not_require_majors_below_a_tables_floor(): void
    {
        // The laravel chain starts at 5.8, but a table may start higher: the
        // majors below its own floor are not problems.
        $data = $this->committedData();
        unset($data['laravel_phpunit']['5.8'], $data['laravel_phpunit']['6'], $data['laravel_phpunit']['7']);
        self::assertArrayNotHasKey('7', $data['laravel_phpunit']);

        self::assertSame([], (new HopDataRefresher($this->policy()))->shapeProblems($data, new DateTimeImmutable('2026-10-29')));
    }

    public function test_shape_problems_flag_a_missing_floor_in_a_chain(): void
    {
        $data = $this->committedData();
        $data['php'] = array_values(array_diff($data['php'], ['5.5']));
        $data['laravel'] = array_values(array_diff($data['laravel'], ['5.8']));

        $problems = (new HopDataRefresher($this->policy()))->shapeProblems($data, new DateTimeImmutable('2026-10-29'));

        self::assertCount(2, $problems);
        self::assertStringContainsString('5.5', $problems[0]);
        self::assertStringContainsString('5.8', $problems[1]);
    }

    public function test_shape_problems_flag_a_policy_version_missing_from_a_chain(): void
    {
        $data = $this->committedData();
        $data['php'] = array_values(array_diff($data['php'], ['8.5']));

        $problems = (new HopDataRefresher($this->policy()))->shapeProblems($data, new DateTimeImmutable('2026-10-29'));

        self::assertCount(1, $problems);
        self::assertStringContainsString('PHP 8.5', $problems[0]);
    }

    public function test_shape_problems_flag_a_gap_in_a_table(): void
    {
        $data = $this->committedData();
        unset($data['phpunit_php']['11']);

        $problems = (new HopDataRefresher($this->policy()))->shapeProblems($data, new DateTimeImmutable('2026-10-29'));

        self::assertCount(1, $problems);
        self::assertStringContainsString('phpunit_php', $problems[0]);
        self::assertStringContainsString('11', $problems[0]);
    }
}
