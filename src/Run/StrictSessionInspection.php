<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

/**
 * What the session-strict-mode hop found in an app, read-only: the
 * session_start() calls that still run without strict session IDs, the
 * files and settings that already turn them on, and the stop signs that make
 * the hop refuse. The planner reads it to place the hop; the hop reads it
 * again before it writes anything.
 */
final readonly class StrictSessionInspection
{
    /**
     * @param array<string, list<int>> $calls     file (relative to the app) to the lines of its uncovered session_start() calls
     * @param list<string>             $covered   files or settings that already turn strict mode on, one line each
     * @param list<string>             $refusals  stop signs; any one makes the hop refuse and change nothing
     * @param list<string>             $manual    what a person checks by hand; each line starts with MANUAL:
     * @param string|null              $appWide   why the whole app already runs with strict session IDs, or null
     */
    public function __construct(
        public array $calls,
        public array $covered,
        public array $refusals,
        public array $manual,
        public ?string $appWide = null,
    ) {}

    /** True when there is a call to cover and nothing stops the hop. */
    public function pending(): bool
    {
        return $this->appWide === null && $this->calls !== [] && $this->refusals === [];
    }

    public function callCount(): int
    {
        return array_sum(array_map('count', $this->calls));
    }

    public function fileCount(): int
    {
        return count($this->calls);
    }

    /** "3 session_start() calls in 2 files", for warnings and messages. */
    public function summary(): string
    {
        $calls = $this->callCount();
        $files = $this->fileCount();

        return sprintf('%d session_start() call%s in %d file%s', $calls, $calls === 1 ? '' : 's', $files, $files === 1 ? '' : 's');
    }
}
