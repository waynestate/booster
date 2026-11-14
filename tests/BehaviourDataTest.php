<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Packaging\PharFiles;
use Waynestate\Booster\Scan\BehaviourScan;
use Waynestate\Booster\ToolError;

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

    public function test_the_laravel_13_hop_flags_array_last_and_the_unset_cookie_and_cache_names(): void
    {
        $result = (new BehaviourScan())->scan(__DIR__ . '/fixtures/behaviour/laravel13-fallbacks', 'laravel-12-to-13', []);
        $byId = [];
        foreach ($result->entries as $entry) {
            $byId[$entry->id] = $entry;
        }

        // A lone array_last() call is flagged on its own; array_first() is not called, so its row stays quiet.
        self::assertSame(['polyfill-array-last', 'session-cookie-name-fallback', 'cache-prefix-fallback'], array_keys($byId));
        self::assertSame(['src/Report.php:3'], $byId['polyfill-array-last']->hits);
        self::assertSame('use Arr::last() instead of the global helper', $byId['polyfill-array-last']->keepOld);
        // No config file at all is the Laravel 11+ slim skeleton: the framework fallback decides the name.
        self::assertSame(['config/session.php'], $byId['session-cookie-name-fallback']->hits);
        self::assertSame('set SESSION_COOKIE in .env to the cookie name the app sends today', $byId['session-cookie-name-fallback']->keepOld);
        self::assertSame(['config/cache.php'], $byId['cache-prefix-fallback']->hits);
        self::assertStringContainsString('REDIS_PREFIX', (string) $byId['cache-prefix-fallback']->keepOld);
        foreach ($byId as $id => $entry) {
            self::assertStringStartsWith('https://laravel.com/docs/13.x/upgrade#', $entry->source, $id);
        }
    }

    public function test_the_laravel_13_hop_stays_quiet_when_the_app_pins_its_cookie_and_cache_names(): void
    {
        $result = (new BehaviourScan())->scan(__DIR__ . '/fixtures/behaviour/laravel13-pinned', 'laravel-12-to-13', []);

        self::assertSame(['polyfill-array-first-last'], array_map(static fn($e): string => $e->id, $result->entries));
        self::assertSame(['src/Report.php:3'], $result->entries[0]->hits);
    }

    public function test_the_sweet_alert_toast_timer_entry_carries_the_parity_row(): void
    {
        $byId = [];
        foreach ($this->changes as $entry) {
            $byId[$entry['id']] = $entry;
        }
        self::assertSame([
            'what' => 'How long a flashed toast stays open',
            'before' => "The timer value the app sets today (SWEET_ALERT_TIMER in .env or 'timer' in config/sweetalert.php)",
            'after' => "SWEET_ALERT_TOAST_AUTO_CLOSE (or 'toast.auto_close') must equal that value",
            'how' => 'Fire a toast and time it with a stopwatch',
        ], $byId['swal-toast-timer-key']['parity'] ?? null);
        foreach ($this->changes as $entry) {
            if ($entry['id'] === 'swal-toast-timer-key') {
                continue;
            }
            self::assertArrayNotHasKey('parity', $entry, 'parity of ' . $entry['id']);
        }
    }

    public function test_the_wayne_ui_package_rows_ship_with_their_hops_and_checks(): void
    {
        $byId = [];
        foreach ($this->changes as $entry) {
            $byId[$entry['id']] = $entry;
        }
        foreach (['wayneui-bootstrap-data-hooks', 'wayneui-nav-active-prefix'] as $id) {
            self::assertArrayHasKey($id, $byId);
            self::assertSame('package:cit-web-infra/wayne-ui@2', $byId[$id]['hop']);
            self::assertSame(['kind' => 'package'], $byId[$id]['detect']);
            self::assertStringStartsWith('https://', (string) $byId[$id]['source']);
        }
        self::assertSame(
            'Click through every menu, collapsible panel, modal and dismissible alert after the move',
            $byId['wayneui-bootstrap-data-hooks']['check'],
        );
        self::assertSame(
            'Open a child page of each menu item and confirm the right item is highlighted and announced as current (aria-current)',
            $byId['wayneui-nav-active-prefix']['check'],
        );
    }

    public function test_a_full_parity_row_loads(): void
    {
        $path = $this->tempDir() . '/behaviour-changes.json';
        file_put_contents($path, (string) json_encode(['schema_version' => 1, 'changes' => [$this->parityEntry([
            'what' => 'How long a flashed toast stays open',
            'before' => 'The timer value the app sets today',
            'after' => 'SWEET_ALERT_TOAST_AUTO_CLOSE must equal that value',
            'how' => 'Fire a toast and time it with a stopwatch',
        ])]]));

        new BehaviourScan($path);
    }

    public function test_a_parity_row_missing_how_is_rejected(): void
    {
        $this->expectLoadError(['schema_version' => 1, 'changes' => [$this->parityEntry([
            'what' => 'w',
            'before' => 'b',
            'after' => 'a',
        ])]], 'has a parity row that needs non-empty string');
    }

    public function test_a_parity_row_with_an_integer_what_is_rejected(): void
    {
        $this->expectLoadError(['schema_version' => 1, 'changes' => [$this->parityEntry([
            'what' => 5,
            'before' => 'b',
            'after' => 'a',
            'how' => 'h',
        ])]], 'has a parity row that needs non-empty string');
    }

    public function test_a_string_parity_is_rejected(): void
    {
        $entry = $this->parityEntry([]);
        $entry['parity'] = 'not an object';
        $this->expectLoadError(['schema_version' => 1, 'changes' => [$entry]], 'has a parity row that needs non-empty string');
    }

    /**
     * @param array<string, mixed> $content
     */
    private function expectLoadError(array $content, string $needle): void
    {
        $path = $this->tempDir() . '/behaviour-changes.json';
        file_put_contents($path, (string) json_encode($content));
        $this->expectException(ToolError::class);
        $this->expectExceptionMessage($needle);

        new BehaviourScan($path);
    }

    /**
     * @param array<string, mixed> $parity
     *
     * @return array<string, mixed>
     */
    private function parityEntry(array $parity): array
    {
        return [
            'id' => 'parity-row',
            'hop' => 'php-80-to-81',
            'detect' => ['kind' => 'function', 'match' => 'a'],
            'label' => 'Label for parity-row',
            'keep_old' => null,
            'check' => 'Check it.',
            'source' => 'https://example.com/',
            'parity' => $parity,
        ];
    }
}
