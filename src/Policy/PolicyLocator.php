<?php

declare(strict_types=1);

namespace Waynestate\Booster\Policy;

use InvalidArgumentException;
use RuntimeException;
use Waynestate\Booster\Creed\Policy as CreedPolicy;

/**
 * Finds policy.json: the --policy option, then BOOSTER_POLICY, then the copy
 * bundled with Booster. The bundled copy is generated from CREED by
 * bin/export.php (composer creed-copy:check verifies it); it is never
 * edited by hand.
 */
final class PolicyLocator
{
    public static function bundledPath(): string
    {
        return dirname(__DIR__, 2) . '/src/Creed/policy.json';
    }

    public static function locate(?string $option, ?string $env): Policy
    {
        $path = null;
        if ($option !== null && $option !== '') {
            $path = $option;
        } elseif ($env !== null && $env !== '') {
            $path = $env;
        }
        if ($path !== null) {
            return self::fromFile($path, $path);
        }

        return self::fromFile(self::bundledPath(), 'bundled snapshot');
    }

    private static function fromFile(string $path, string $origin): Policy
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new PolicyException(sprintf('Policy file "%s" does not exist or is not readable.', $path));
        }

        try {
            $creed = CreedPolicy::fromFile($path);
            // @phpstan-ignore catch.neverThrown (fromFile declares only InvalidArgumentException, but file_get_contents can still fail on an I/O error)
        } catch (InvalidArgumentException|RuntimeException $e) {
            throw new PolicyException($e->getMessage());
        }

        return new Policy($creed, $origin);
    }
}
