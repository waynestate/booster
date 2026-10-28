<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

final readonly class RunOptions
{
    public function __construct(
        public bool $dryRun = false,
        public bool $skipGate = false,
        public bool $allowDirty = false,
        public int $maxChanges = 50,
        public ?string $testCommand = null,
        public ?string $proctorCommand = null,
    ) {}
}
