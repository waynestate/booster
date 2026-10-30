<?php

declare(strict_types=1);

namespace Waynestate\Booster\Plan;

/**
 * Every per-package result plus the plan warnings they add: one line per
 * blocked hop, one per abandoned package, and one summary line naming the
 * packages the check could not reach.
 */
final readonly class PackageReport
{
    /**
     * @param list<PackageCheck> $checks
     */
    public function __construct(public array $checks) {}

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        $warnings = [];
        $notChecked = [];
        foreach ($this->checks as $check) {
            if ($check->blockWarning !== null) {
                $warnings[] = $check->blockWarning;
            }
            if ($check->abandonedWarning !== null) {
                $warnings[] = $check->abandonedWarning;
            }
            if (!$check->checked) {
                $label = $check->reason === 'vcs' ? 'VCS repository' : 'offline';
                $notChecked[] = sprintf('%s (%s)', $check->package, $label);
            }
        }
        if ($notChecked !== []) {
            $warnings[] = sprintf('Not checked: %s.', implode(', ', $notChecked));
        }

        return $warnings;
    }
}
