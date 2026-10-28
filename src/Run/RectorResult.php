<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

/**
 * What one Rector run did (or, in a dry run, would do).
 */
final readonly class RectorResult
{
    /**
     * @param list<array{file: string, diff: string}> $files  files that changed, relative to the app
     * @param list<string>                            $errors Rector's own errors (parse failures and the like)
     */
    public function __construct(
        public array $files,
        public array $errors,
    ) {}

    public function changedCount(): int
    {
        return count($this->files);
    }
}
