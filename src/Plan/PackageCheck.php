<?php

declare(strict_types=1);

namespace Waynestate\Booster\Plan;

/**
 * What the dependency check learned about one direct dependency: its newest
 * stable release, whether that release blocks a planned hop, and whether the
 * project is abandoned. checked=false means the question could not be asked
 * (offline, or the package lives in a VCS repository).
 */
final readonly class PackageCheck
{
    public function __construct(
        public string $package,
        public ?string $newest,
        public ?string $blocks,
        public bool $abandoned,
        public ?string $replacement,
        public bool $checked,
        public ?string $reason,
        public ?string $blockWarning,
        public ?string $abandonedWarning,
    ) {}

    /**
     * The JSON shape: stable keys only. The human-readable warnings stay in
     * Plan::$warnings, where every other plan warning already lives.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'package' => $this->package,
            'newest' => $this->newest,
            'blocks' => $this->blocks,
            'abandoned' => $this->abandoned,
            'checked' => $this->checked,
        ];
    }
}
