<?php

declare(strict_types=1);

namespace Waynestate\Booster\Policy;

use Composer\InstalledVersions;
use InvalidArgumentException;
use RuntimeException;
use Waynestate\Booster\Packaging\PharFiles;
use Waynestate\Creed\Policy as CreedPolicy;

/**
 * Finds policy.json: the --policy option, then BOOSTER_POLICY, then the
 * policy file of the installed waynestate/creed package. CREED is the single
 * source of the version policy; a new CREED release is picked up with
 * `composer update waynestate/creed`.
 */
final class PolicyLocator
{
    /** The Composer package that owns the default policy file. */
    public const CREED_PACKAGE = 'waynestate/creed';

    /** The policy file inside the creed package, relative to the project root. */
    public const CREED_POLICY_PATH = 'vendor/waynestate/creed/policy/policy.json';

    /**
     * The policy file of the installed waynestate/creed package, as a real
     * filesystem path (a phar:// path would not do for child processes).
     */
    public static function defaultPath(): string
    {
        return PharFiles::path(self::CREED_POLICY_PATH);
    }

    /**
     * The origin label for the default policy: the package name plus its
     * installed version. Falls back to the bare package name when the version
     * cannot be found, for example in a phar built without the composer
     * metadata.
     */
    public static function defaultOrigin(): string
    {
        if (InstalledVersions::isInstalled(self::CREED_PACKAGE)) {
            $version = InstalledVersions::getPrettyVersion(self::CREED_PACKAGE);
            if (is_string($version) && $version !== '') {
                return self::CREED_PACKAGE . ' ' . $version;
            }
        }

        return self::CREED_PACKAGE;
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

        return self::fromFile(self::defaultPath(), self::defaultOrigin());
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
