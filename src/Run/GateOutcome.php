<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

/**
 * The outcome of one gate container run. The container runs Composer first
 * and the app's tests second, and reports which phase it reached, so a stale
 * lock (Composer exit 4) is read as an install failure, never as the test
 * command exiting 4.
 */
final readonly class GateOutcome
{
    public const PHASE_INSTALL = 'install';

    public const PHASE_TESTS = 'tests';

    /**
     * @param list<string> $output the container's output with Booster's marker lines removed
     */
    public function __construct(
        public string $phase,
        public int $exitCode,
        public array $output,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }
}
