<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

/**
 * What the CAS to SESAME migration did (or, in a dry run, would do).
 */
final readonly class CasMigrationResult
{
    /**
     * @param list<string>                            $changes  human-readable changes, prefixed "Dry run: would" in a dry run
     * @param list<array{file: string, diff: string}> $files    files that changed, relative to the app
     * @param list<string>                            $manual   things BOOSTER will not do; each names the file
     * @param list<string>                            $failures model files left with WayneCasable in a trait use line
     */
    public function __construct(
        public array $changes,
        public array $files,
        public array $manual,
        public array $failures = [],
    ) {}

    public function changedCount(): int
    {
        return count($this->files);
    }
}
