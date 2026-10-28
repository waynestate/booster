<?php

declare(strict_types=1);

namespace Waynestate\Booster\Policy;

/**
 * Finds policy.json: the --policy option, then BOOSTER_POLICY, then the copy
 * bundled with BOOSTER. The bundled copy is a snapshot of CREED's file, used
 * until CREED is on Packagist and can be required instead.
 */
final class PolicyLocator
{
    public static function bundledPath(): string
    {
        return dirname(__DIR__, 2) . '/resources/policy.json';
    }

    public static function locate(?string $option, ?string $env): PolicySource
    {
        if ($option !== null && $option !== '') {
            return new JsonFilePolicySource($option);
        }
        if ($env !== null && $env !== '') {
            return new JsonFilePolicySource($env);
        }

        return new JsonFilePolicySource(self::bundledPath(), 'bundled snapshot');
    }
}
