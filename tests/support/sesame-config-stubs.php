<?php

declare(strict_types=1);

/*
 * Test doubles for the two symbols a generated config/sesame.php calls at
 * load time, so the file can be included without SESAME installed. Both read
 * the environment first, which is all the config tests need: the real
 * Setting also falls back to the waynestate/playbook package, and the real
 * env() comes from Laravel.
 */

namespace Waynestate\Sesame\Support {
    if (!class_exists(Setting::class, false)) {
        final class Setting
        {
            public static function get(string $name): ?string
            {
                $value = getenv($name);

                return $value === false || $value === '' ? null : $value;
            }

            public static function env(string $name): ?string
            {
                return self::get($name);
            }
        }
    }
}

namespace {
    if (!function_exists('env')) {
        function env(string $name, mixed $default = null): mixed
        {
            $value = getenv($name);

            return $value === false || $value === '' ? $default : $value;
        }
    }
}
