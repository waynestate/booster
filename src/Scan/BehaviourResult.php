<?php

declare(strict_types=1);

namespace Waynestate\Booster\Scan;

/**
 * The behaviour-changes scan result for one hop: the curated entries that
 * apply and were found (or always apply), plus how much of the app was read.
 */
final class BehaviourResult
{
    /**
     * @param list<BehaviourEntry> $entries
     */
    public function __construct(
        public readonly string $hop,
        public readonly array $entries,
        public readonly int $filesScanned,
        public readonly int $filesSkipped,
    ) {}

    /** A copy without the package entries, for steps that are not the plan's last. */
    public function withoutPackages(): self
    {
        return new self(
            $this->hop,
            array_values(array_filter($this->entries, static fn(BehaviourEntry $entry): bool => $entry->kind !== 'package')),
            $this->filesScanned,
            $this->filesSkipped,
        );
    }

    /**
     * @return array{hop: string, files_scanned: int, files_skipped: int, entries: list<array{id: string, label: string, keep_old: string|null, check: string, source: string, hits: list<string>, total: int}>}
     */
    public function toArray(): array
    {
        return [
            'hop' => $this->hop,
            'files_scanned' => $this->filesScanned,
            'files_skipped' => $this->filesSkipped,
            'entries' => array_map(static fn(BehaviourEntry $entry): array => $entry->toArray(), $this->entries),
        ];
    }
}
