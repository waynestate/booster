<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

use Closure;

final readonly class RunOptions
{
    public function __construct(
        public bool $dryRun = false,
        public bool $skipGate = false,
        public bool $allowDirty = false,
        public bool $allowFailingBaseline = false,
        /** Hard limit on files changed per hop: null = not given (the review threshold decides), 0 = no limit. */
        public ?int $maxChanges = null,
        /** A hop that changes more files than this asks first when it can, and stops otherwise. */
        public int $reviewThreshold = 200,
        /** Asks the person before an over-threshold hop is applied. Gets the file count and the per-rule summary lines; true applies the hop. */
        public ?Closure $confirm = null,
        public ?string $testCommand = null,
        /** When set, the gate runs in this image (one name, a {php} template or a version map) instead of building booster-target. It needs php and composer on PATH. */
        public ?GateImage $gateImage = null,
        public ?string $proctorCommand = null,
        public string $memoryLimit = RectorRunner::DEFAULT_MEMORY_LIMIT,
        public bool $proctorAdvisory = false,
    ) {}
}
