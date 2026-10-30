<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

/**
 * The outcome of a step's gate, retries included. A failed gate carries its
 * full message list; a passed gate carries only the notes worth reporting
 * (an advisory unblock that made the pass possible), which may be none.
 */
final readonly class GateReport
{
    /**
     * @param list<string> $lines
     */
    public function __construct(
        public bool $passed,
        public array $lines,
    ) {}
}
