<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

/**
 * What the vendored-to-Composer move did (or, in a dry run, would do).
 * A refused move changed nothing and says why in $refusals.
 */
final readonly class VendoredMigrationResult
{
    /**
     * @param list<string>                            $changes  human-readable changes, prefixed "Dry run: would" in a dry run
     * @param list<array{file: string, diff: string}> $files    files that changed, relative to the app
     * @param list<string>                            $manual   what a person does next; each line starts with MANUAL:
     * @param list<string>                            $refusals why Booster would not move the libraries; empty when it did
     * @param list<string>                            $packages the Composer packages the move added to composer.json
     */
    public function __construct(
        public array $changes,
        public array $files,
        public array $manual,
        public array $refusals = [],
        public array $packages = [],
    ) {}

    public function refused(): bool
    {
        return $this->refusals !== [];
    }
}
