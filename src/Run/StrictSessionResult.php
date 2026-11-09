<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

/**
 * What the session-strict-mode hop did (or, in a dry run, would do). A
 * refused hop changed nothing and says why in $refusals; a hop with nothing
 * to cover changed nothing and says why in $notes.
 */
final readonly class StrictSessionResult
{
    /**
     * @param list<string>                            $changes  human-readable changes, prefixed "Dry run: would" in a dry run
     * @param list<array{file: string, diff: string}> $files    files that changed, relative to the app
     * @param list<string>                            $manual   what a person does next; each line starts with MANUAL:
     * @param list<string>                            $refusals why Booster would not change the app; empty when it did
     * @param list<string>                            $notes    what was already covered, and why nothing changed when nothing did
     */
    public function __construct(
        public array $changes,
        public array $files,
        public array $manual,
        public array $refusals = [],
        public array $notes = [],
    ) {}

    public function refused(): bool
    {
        return $this->refusals !== [];
    }
}
