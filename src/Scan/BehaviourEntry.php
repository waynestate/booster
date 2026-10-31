<?php

declare(strict_types=1);

namespace Waynestate\Booster\Scan;

/**
 * One curated behaviour change that applies to a hop and was found in the
 * app (or always applies): what to check, what keeps the old behaviour,
 * where the app touches it, and the source that confirms it.
 */
final class BehaviourEntry
{
    /**
     * @param list<string> $hits file:line locations, capped at BehaviourScan::MAX_HITS; 'composer.json' for package rows, the config file for config rows, empty for always rows
     */
    public function __construct(
        public readonly string $id,
        public readonly string $kind,
        public readonly string $label,
        public readonly ?string $keepOld,
        public readonly string $check,
        public readonly string $source,
        public readonly array $hits,
        public readonly int $total,
    ) {}

    /**
     * @return array{id: string, label: string, keep_old: string|null, check: string, source: string, hits: list<string>, total: int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'keep_old' => $this->keepOld,
            'check' => $this->check,
            'source' => $this->source,
            'hits' => $this->hits,
            'total' => $this->total,
        ];
    }
}
