<?php

declare(strict_types=1);

namespace Waynestate\Booster\Scan;

/**
 * One hand-copied library sitting in a vendor directory the app committed
 * straight into git, with no Composer behind it. A booster never rewrites
 * cargo, but it can at least name the cargo and say what modern package
 * would carry the same load.
 */
final readonly class VendoredLibrary
{
    public function __construct(
        /** Display name, e.g. Doctrine. */
        public string $name,
        /** Path relative to the app root, forward slashes, e.g. vendor/Doctrine. */
        public string $path,
        /** The version found in the library's own source, or null when unknown. */
        public ?string $version,
        /** The Composer package that replaces this copy, or null when there is none. */
        public ?string $replaceWith,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'path' => $this->path,
            'version' => $this->version,
            'replace_with' => $this->replaceWith,
        ];
    }
}
