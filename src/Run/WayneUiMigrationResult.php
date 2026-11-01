<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

/**
 * What the WayneUI 1.x to 2.x migration did (or, in a dry run, would do).
 */
final readonly class WayneUiMigrationResult
{
    /**
     * @param list<string>                            $changes  human-readable changes, prefixed "Dry run: would" in a dry run
     * @param list<array{file: string, diff: string}> $files    files that changed, relative to the app
     * @param list<string>                            $manual   things BOOSTER will not do; each names the file and line
     * @param int                                     $rewrites individual rewrites across all files
     */
    public function __construct(
        public array $changes,
        public array $files,
        public array $manual,
        public int $rewrites = 0,
    ) {}

    public function changedCount(): int
    {
        return count($this->files);
    }
}
