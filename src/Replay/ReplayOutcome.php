<?php

declare(strict_types=1);

namespace Waynestate\Booster\Replay;

/**
 * What one record or check concluded, ready for a report: a status, the PHP
 * it ran on, the lines a person reads, and where the transcripts are.
 */
final readonly class ReplayOutcome
{
    public const RECORDED = 'recorded';

    public const PASSED = 'passed';

    public const DIFFERS = 'differs';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    /**
     * @param list<string> $lines
     */
    public function __construct(
        public string $status,
        public ?string $php,
        public array $lines,
        public string $file,
        public ?string $outDir = null,
        public ?string $transcript = null,
        public int $requests = 0,
        public int $differences = 0,
    ) {}

    public function failed(): bool
    {
        return in_array($this->status, [self::DIFFERS, self::FAILED], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'php' => $this->php,
            'file' => $this->file,
            'requests' => $this->requests,
            'differences' => $this->differences,
            'out_dir' => $this->outDir,
            'transcript' => $this->transcript,
            'lines' => $this->lines,
        ];
    }
}
