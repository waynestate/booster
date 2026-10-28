<?php

declare(strict_types=1);

namespace Waynestate\Booster\Project;

/**
 * The five permutations of the WSU stack. Detection looks at composer.json
 * and package.json only; it never executes app code.
 */
enum AppType: string
{
    case RawPhp = 'raw-php';
    case RawPhpVue = 'raw-php-vue';
    case LaravelBlade = 'laravel-blade';
    case LaravelBladeVue = 'laravel-blade-vue';
    case LaravelInertiaVue = 'laravel-inertia-vue';

    public function isLaravel(): bool
    {
        return match ($this) {
            self::LaravelBlade, self::LaravelBladeVue, self::LaravelInertiaVue => true,
            default => false,
        };
    }

    public function hasVue(): bool
    {
        return match ($this) {
            self::RawPhp, self::LaravelBlade => false,
            default => true,
        };
    }
}
