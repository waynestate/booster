<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

/**
 * The outcome of the PROCTOR gate: one run of waynestate/proctor against the
 * upgraded app on its final PHP.
 */
final readonly class ProctorResult
{
    public const PASSED = 'passed';

    public const FAILED = 'failed';

    /** PROCTOR exited 2: it tripped over its own tooling and gave no verdict. */
    public const TRIPPED = 'tripped';

    /**
     * @param list<array{check: string, rule: string, message: string, path: ?string, line: int}> $failures findings with status fail, in PROCTOR's order
     * @param string|null $error PROCTOR's own words for a tripped run (stderr plus stdout)
     */
    public function __construct(
        public string $status,
        public int $exitCode,
        public ?string $php = null,
        public array $failures = [],
        public ?string $error = null,
    ) {}

    /** Whether this result fails the run when PROCTOR is not advisory. */
    public function blocking(): bool
    {
        return $this->status !== self::PASSED;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'exit_code' => $this->exitCode,
            'php' => $this->php,
            'failures' => $this->failures,
            'error' => $this->error,
        ];
    }
}
