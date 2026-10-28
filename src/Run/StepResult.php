<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

/**
 * The outcome of one plan step.
 */
final readonly class StepResult
{
    public const PREVIEWED = 'previewed';

    public const APPLIED = 'applied';

    public const MANUAL = 'manual';

    public const CAPPED = 'capped';

    public const GATE_FAILED = 'gate-failed';

    public const ERROR = 'error';

    public const NOT_RUN = 'not-run';

    /**
     * @param list<array{file: string, diff: string}> $files
     * @param list<string>                            $messages
     */
    public function __construct(
        public int $step,
        public string $hopId,
        public string $status,
        public array $files = [],
        public array $messages = [],
    ) {}

    public function failed(): bool
    {
        return in_array($this->status, [self::CAPPED, self::GATE_FAILED, self::ERROR], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'step' => $this->step,
            'hop' => $this->hopId,
            'status' => $this->status,
            'changed_files' => count($this->files),
            'files' => $this->files,
            'messages' => $this->messages,
        ];
    }
}
