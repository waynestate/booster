<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Console\LocalTimeZone;

/**
 * A PHP with no date.timezone runs on UTC, so an evening run in Detroit said
 * "today" was tomorrow and stamped its deploy script after midnight.
 */
final class LocalTimeZoneTest extends TestCase
{
    public function test_tz_comes_first_and_may_carry_a_colon(): void
    {
        self::assertSame('America/Detroit', LocalTimeZone::detect(':America/Detroit', '/usr/share/zoneinfo/Europe/Paris', false, false));
    }

    public function test_the_localtime_link_names_the_zone(): void
    {
        self::assertSame('America/Detroit', LocalTimeZone::detect(false, '/usr/share/zoneinfo/America/Detroit', false, false));
        self::assertSame('America/Detroit', LocalTimeZone::detect(false, '../usr/share/zoneinfo/America/Detroit', false, false));
    }

    public function test_etc_timezone_then_intl(): void
    {
        self::assertSame('America/Detroit', LocalTimeZone::detect(false, false, "America/Detroit\n", 'Europe/Paris'));
        self::assertSame('Europe/Paris', LocalTimeZone::detect(false, false, false, 'Europe/Paris'));
    }

    public function test_a_php_ini_utc_gives_way_but_a_chosen_zone_wins(): void
    {
        // Seen on WSL: php.ini said UTC, /etc/localtime said America/New_York.
        self::assertTrue(LocalTimeZone::machineZoneWins(''));
        self::assertTrue(LocalTimeZone::machineZoneWins('UTC'));
        self::assertTrue(LocalTimeZone::machineZoneWins('Etc/UTC'));
        self::assertFalse(LocalTimeZone::machineZoneWins('America/Chicago'));
    }

    public function test_unknown_values_are_skipped_and_none_means_null(): void
    {
        self::assertSame('UTC', LocalTimeZone::detect('Not/AZone', false, false, 'UTC'));
        self::assertSame('US/Eastern', LocalTimeZone::detect('US/Eastern', false, false, false));
        self::assertNull(LocalTimeZone::detect('', false, false, 'Etc/Unknown'));
        self::assertNull(LocalTimeZone::detect(false, false, false, false));
    }
}
