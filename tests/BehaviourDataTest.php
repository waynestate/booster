<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Packaging\PharFiles;
use Waynestate\Booster\Scan\BehaviourScan;

/**
 * Guards the bundled data/behaviour-changes.json itself: it must load through
 * the scanner, and every row must stay well formed as releases curate it.
 */
final class BehaviourDataTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $changes;

    protected function setUp(): void
    {
        parent::setUp();
        // The default constructor reads the bundled file; a broken file throws here.
        new BehaviourScan();
        $raw = json_decode((string) file_get_contents(PharFiles::path('data/behaviour-changes.json')), true);
        self::assertIsArray($raw);
        self::assertIsArray($raw['changes'] ?? null);
        $this->changes = $raw['changes'];
    }

    public function test_ids_are_unique(): void
    {
        $ids = array_map(static fn(array $e): string => $e['id'], $this->changes);

        self::assertCount(count($ids), array_unique($ids));
    }

    public function test_at_most_three_rows_are_always(): void
    {
        $always = array_filter($this->changes, static fn(array $e): bool => ($e['detect']['kind'] ?? null) === 'always');

        self::assertLessThanOrEqual(BehaviourScan::MAX_ALWAYS, count($always));
    }

    public function test_every_source_is_an_https_url(): void
    {
        foreach ($this->changes as $entry) {
            self::assertStringStartsWith('https://', (string) $entry['source'], 'source of ' . $entry['id']);
        }
    }

    public function test_every_hop_is_well_formed_and_a_registered_booster_hop(): void
    {
        $registry = new HopRegistry();
        foreach ($this->changes as $entry) {
            $hop = (string) $entry['hop'];
            self::assertMatchesRegularExpression(
                '/^php-\d{2}-to-\d{2}$|^laravel-[0-9]+(\.[0-9]+)?-to-[0-9]+$|^package:[a-z0-9._\/-]+@\d+$/',
                $hop,
                'hop of ' . $entry['id'],
            );
            if (!str_starts_with($hop, 'package:')) {
                self::assertTrue($registry->has($hop), 'unregistered hop ' . $hop . ' of ' . $entry['id']);
            }
        }
    }
}
