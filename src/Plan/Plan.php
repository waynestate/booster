<?php

declare(strict_types=1);

namespace Waynestate\Booster\Plan;

use Waynestate\Booster\Project\Project;
use Waynestate\Booster\Scan\VendoredLibrary;

/**
 * An ordered list of steps from where the app is to where the policy says it
 * should be, plus warnings the person reading the plan should see first.
 */
final readonly class Plan
{
    /**
     * @param list<PlanStep>        $steps
     * @param list<string>          $warnings
     * @param list<PackageCheck>    $packageChecks
     * @param list<VendoredLibrary> $vendoredLibraries hand-copied libraries found under the app's vendor directories
     */
    public function __construct(
        public Project $project,
        public string $today,
        public string $policyOrigin,
        public ?string $targetPhp,
        public ?string $targetLaravel,
        public ?string $targetPhpunit,
        public array $steps,
        public array $warnings,
        public array $packageChecks = [],
        public bool $stopsEarly = false,
        public array $vendoredLibraries = [],
        public ?string $fromPhp = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = [
            'project' => $this->project->toArray(),
            'today' => $this->today,
            'policy' => $this->policyOrigin,
            'target_php' => $this->targetPhp,
            'target_laravel' => $this->targetLaravel,
            'target_phpunit' => $this->targetPhpunit,
            'steps' => array_map(static fn(PlanStep $s): array => $s->toArray(), $this->steps),
            'warnings' => $this->warnings,
            'package_checks' => array_map(static fn(PackageCheck $c): array => $c->toArray(), $this->packageChecks),
            'stops_early' => $this->stopsEarly,
        ];
        if ($this->vendoredLibraries !== []) {
            $array['vendored_libraries'] = array_map(static fn(VendoredLibrary $l): array => $l->toArray(), $this->vendoredLibraries);
        }
        if ($this->fromPhp !== null && $this->fromPhp !== $this->project->phpVersion) {
            $array['from_php'] = $this->fromPhp;
        }

        return $array;
    }
}
