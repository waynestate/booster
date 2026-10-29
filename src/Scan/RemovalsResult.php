<?php

declare(strict_types=1);

namespace Waynestate\Booster\Scan;

/**
 * The outcome of one removals scan for one PHP hop: what was found (capped),
 * how much was found in total, and how the file walk went. Advisory data:
 * nothing here ever fails a hop.
 */
final readonly class RemovalsResult
{
    /**
     * @param list<RemovalFinding> $findings
     */
    public function __construct(
        public string $from,
        public string $to,
        public array $findings,
        public int $total,
        public int $filesScanned,
        public int $filesSkipped,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'total' => $this->total,
            'truncated' => $this->total > count($this->findings),
            'files_scanned' => $this->filesScanned,
            'files_skipped' => $this->filesSkipped,
            'findings' => array_map(static fn(RemovalFinding $finding): array => $finding->toArray(), $this->findings),
        ];
    }
}
