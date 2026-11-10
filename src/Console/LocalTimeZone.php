<?php

declare(strict_types=1);

namespace Waynestate\Booster\Console;

use DateTimeZone;
use IntlTimeZone;

/**
 * The machine's own time zone, for a PHP that was never told one.
 *
 * A PHP with no date.timezone in its php.ini runs on UTC, and many php.ini
 * files set UTC only to silence PHP's warning, so in the evening in Detroit
 * the plan said "today 2026-11-10" and a deploy script was stamped after
 * midnight. When php.ini names no zone, or UTC, Booster asks the machine: the
 * TZ variable, then /etc/localtime (Linux, macOS, WSL), then /etc/timezone,
 * then ICU (intl), which reads Windows' setting too. Any other php.ini zone
 * is a choice and wins. A zone it cannot verify is ignored, and with no
 * answer PHP keeps the zone it has. Time zones: proof that even the clock has
 * a dependency tree.
 */
final class LocalTimeZone
{
    /** Sets PHP's default zone to the machine's when php.ini names none or UTC; returns the zone in use. */
    public static function apply(): string
    {
        if (!self::machineZoneWins((string) ini_get('date.timezone'))) {
            return date_default_timezone_get();
        }
        $zone = self::detect(
            getenv('TZ'),
            is_link('/etc/localtime') ? readlink('/etc/localtime') : false,
            is_file('/etc/timezone') ? file_get_contents('/etc/timezone') : false,
            class_exists(IntlTimeZone::class) ? IntlTimeZone::createDefault()->getID() : false,
        );
        if ($zone !== null) {
            date_default_timezone_set($zone);
        }

        return date_default_timezone_get();
    }

    /** Whether the machine's zone should replace php.ini's date.timezone: when it names none, or UTC. */
    public static function machineZoneWins(string $ini): bool
    {
        return in_array(strtoupper(trim($ini)), ['', 'UTC', 'ETC/UTC'], true);
    }

    /**
     * The first valid zone id among the sources, in order, or null.
     *
     * @param string|false $tz        the TZ variable (a zone id, or ":Zone/Name")
     * @param string|false $localtime where /etc/localtime points, such as /usr/share/zoneinfo/America/Detroit
     * @param string|false $etcZone   the content of /etc/timezone
     * @param string|false $intl      ICU's default zone id
     */
    public static function detect(string|false $tz, string|false $localtime, string|false $etcZone, string|false $intl): ?string
    {
        $candidates = [];
        if (is_string($tz)) {
            $candidates[] = ltrim(trim($tz), ':');
        }
        if (is_string($localtime) && preg_match('#zoneinfo/(.+)$#', $localtime, $m) === 1) {
            $candidates[] = $m[1];
        }
        if (is_string($etcZone)) {
            $candidates[] = trim($etcZone);
        }
        if (is_string($intl)) {
            $candidates[] = $intl;
        }
        $valid = DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC);
        foreach ($candidates as $candidate) {
            // UTC is a fine answer too: it just means the machine runs on UTC.
            if ($candidate === 'UTC' || in_array($candidate, $valid, true)) {
                return $candidate;
            }
        }

        return null;
    }
}
