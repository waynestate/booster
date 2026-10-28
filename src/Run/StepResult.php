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

    public const NOTHING_TO_DO = 'nothing-to-do';

    /**
     * @param list<array{file: string, diff: string}> $files
     * @param list<string>                            $messages
     * @param bool                                    $incomplete true when a dry-run preview could not see the whole app (a Laravel hop without vendor/)
     */
    public function __construct(
        public int $step,
        public string $hopId,
        public string $status,
        public array $files = [],
        public array $messages = [],
        public bool $incomplete = false,
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
            'incomplete' => $this->incomplete,
        ];
    }
}
