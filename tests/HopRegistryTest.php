<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;
use Waynestate\Booster\Hop\HopKind;
use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\ToolError;

final class HopRegistryTest extends PhpUnitTestCase
{
    public function test_every_rector_set_a_hop_names_exists_in_the_installed_packages(): void
    {
        foreach ((new HopRegistry())->all() as $hop) {
            foreach ($hop->rectorSets as $constant) {
                self::assertTrue(defined($constant), $hop->id . ' names a Rector set that does not exist: ' . $constant);
                self::assertFileExists((string) constant($constant), $hop->id);
            }
        }
    }

    public function test_class_renames_point_at_distinct_names(): void
    {
        $hop = (new HopRegistry())->get('cas-to-sesame');

        self::assertNotSame([], $hop->classRenames);
        foreach ($hop->classRenames as $from => $to) {
            self::assertNotSame($from, $to);
            self::assertStringStartsWith('Waynestate\\Sesame\\', $to);
        }
    }

    public function test_php_hops_cover_every_adjacent_pair_once(): void
    {
        $registry = new HopRegistry();
        $chain = $registry->phpChain();

        for ($i = 0; $i < count($chain) - 1; $i++) {
            $hop = $registry->get(HopRegistry::phpHopId($chain[$i], $chain[$i + 1]));
            self::assertSame(HopKind::Php, $hop->kind);
            self::assertSame($chain[$i], $hop->from);
            self::assertSame($chain[$i + 1], $hop->to);
        }
    }

    public function test_laravel_hop_uses_the_set_for_its_target_version(): void
    {
        $hop = (new HopRegistry())->get('laravel-12-to-13');

        self::assertSame(['RectorLaravel\Set\LaravelSetList::LARAVEL_130'], $hop->rectorSets);
        self::assertSame(['laravel/framework' => '^13.0'], $hop->composerSet);
    }

    public function test_a_laravel_major_without_a_rector_ruleset_gets_no_hop(): void
    {
        $data = json_decode((string) file_get_contents(__DIR__ . '/../data/versions.json'), true);
        self::assertIsArray($data);
        $data['laravel'][] = '14';
        $file = sys_get_temp_dir() . '/booster-hop-registry-test-' . getmypid() . '.json';
        file_put_contents($file, (string) json_encode($data));

        try {
            $registry = new HopRegistry($file);

            self::assertSame(['8', '9', '10', '11', '12', '13', '14'], $registry->laravelChain());
            self::assertTrue($registry->has('laravel-12-to-13'));
            self::assertFalse($registry->has('laravel-13-to-14'), 'rector-laravel ships no LARAVEL_140 set yet.');
        } finally {
            unlink($file);
        }
    }

    public function test_the_node_pin_hop_pins_engines_node_and_is_not_manual(): void
    {
        $registry = new HopRegistry();
        $hop = $registry->get(HopRegistry::NODE_PIN_ID);

        self::assertSame(HopKind::Node, $hop->kind);
        self::assertSame('any', $hop->from);
        self::assertSame($registry->nodePinTarget(), $hop->to);
        self::assertSame(['engines.node' => '>=' . $registry->nodePinTarget()], $hop->npmSet);
        self::assertFalse($hop->buildGate);
        self::assertFalse($hop->isManual());
    }

    public function test_the_php_80_hop_asks_for_extra_review(): void
    {
        self::assertTrue((new HopRegistry())->get('php-74-to-80')->extraReview);
        self::assertFalse((new HopRegistry())->get('php-80-to-81')->extraReview);
    }

    public function test_laravel_support_table(): void
    {
        $registry = new HopRegistry();

        self::assertTrue($registry->laravelSupportsPhp('12', '8.5'));
        self::assertFalse($registry->laravelSupportsPhp('13', '8.2'));
        self::assertFalse($registry->laravelSupportsPhp('7', '8.0'));
    }

    public function test_neighbours(): void
    {
        $registry = new HopRegistry();

        self::assertSame('8.0', $registry->nextPhp('7.4'));
        self::assertNull($registry->nextPhp('8.5'));
        self::assertNull($registry->nextPhp('4.0'));
        self::assertSame('13', $registry->nextLaravel('12'));
        self::assertNull($registry->nextLaravel('13'));
    }

    public function test_phpunit_hop_id(): void
    {
        self::assertSame('phpunit-9-to-10', HopRegistry::phpunitHopId('9', '10'));
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function phpunitChainNeighbours(): array
    {
        $chain = (new HopRegistry())->phpunitChain();
        $cases = [];
        foreach ($chain as $i => $major) {
            $cases['phpunit ' . $major] = [$major, $chain[$i + 1] ?? null];
        }
        $cases['unknown major'] = ['8', null];

        return $cases;
    }

    #[DataProvider('phpunitChainNeighbours')]
    public function test_next_phpunit_walks_the_chain_and_returns_null_at_the_end(string $from, ?string $expected): void
    {
        self::assertSame($expected, (new HopRegistry())->nextPhpunit($from));
    }

    public function test_phpunit_hops_cover_every_adjacent_pair_once(): void
    {
        $registry = new HopRegistry();
        $chain = $registry->phpunitChain();

        for ($i = 0; $i < count($chain) - 1; $i++) {
            $hop = $registry->get(HopRegistry::phpunitHopId($chain[$i], $chain[$i + 1]));
            self::assertSame(HopKind::Phpunit, $hop->kind);
            self::assertSame($chain[$i], $hop->from);
            self::assertSame($chain[$i + 1], $hop->to);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function phpunitMajors(): array
    {
        $cases = [];
        foreach ((new HopRegistry())->phpunitChain() as $major) {
            $cases['phpunit ' . $major] = [$major];
        }

        return $cases;
    }

    #[DataProvider('phpunitMajors')]
    public function test_every_major_in_the_phpunit_chain_has_a_php_floor(string $major): void
    {
        $registry = new HopRegistry();

        self::assertArrayHasKey($major, $registry->phpunitPhp());
        self::assertIsString($registry->phpunitPhp()[$major]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function laravelPhpunitFloors(): array
    {
        $cases = [];
        foreach ((new HopRegistry())->laravelPhpunit() as $laravel => $floor) {
            $cases['laravel ' . $laravel] = [(string) $laravel, (string) $floor];
        }

        return $cases;
    }

    #[DataProvider('laravelPhpunitFloors')]
    public function test_every_laravel_phpunit_floor_is_a_major_in_the_chain(string $laravel, string $floor): void
    {
        self::assertContains($floor, (new HopRegistry())->phpunitChain(), sprintf('Laravel %s ships PHPUnit %s, which is not in the chain.', $laravel, $floor));
    }

    public function test_unknown_hop_is_a_tool_error(): void
    {
        $this->expectException(ToolError::class);
        (new HopRegistry())->get('php-1-to-2');
    }

    public function test_missing_data_file_is_a_tool_error(): void
    {
        $this->expectException(ToolError::class);
        new HopRegistry(__DIR__ . '/fixtures/no-such-versions.json');
    }
}
