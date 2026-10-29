<?php

declare(strict_types=1);

namespace Waynestate\Booster\Policy;

use DateTimeImmutable;
use InvalidArgumentException;
use Waynestate\Booster\Creed\Policy as CreedPolicy;
use Waynestate\Booster\Creed\State;

/**
 * Booster's view of a CREED policy: the generated CREED reader (src/Creed)
 * plus the label the report prints for where the policy came from.
 *
 * Every policy fact (states, supported versions, dates) comes from the
 * CREED copy. This class only adds the origin label and turns the copy's
 * exceptions into PolicyException, so a policy that cannot answer a question
 * is a tool error (exit 2) like any other.
 */
final class Policy
{
    public function __construct(
        private readonly CreedPolicy $creed,
        private readonly string $origin,
    ) {}

    /**
     * The State of $version of $component on $date.
     *
     * @throws PolicyException if the component or version is not in the policy
     */
    public function stateOf(string $component, string $version, DateTimeImmutable $date): State
    {
        try {
            return $this->creed->stateOf($component, $version, $date);
        } catch (InvalidArgumentException $e) {
            throw new PolicyException($e->getMessage());
        }
    }

    /**
     * Versions of $component that are active or security-supported on $date,
     * lowest first. These are the only legal upgrade targets.
     *
     * @return list<string>
     *
     * @throws PolicyException if the component is not in the policy
     */
    public function supported(string $component, DateTimeImmutable $date): array
    {
        try {
            return $this->creed->supported($component, $date);
        } catch (InvalidArgumentException $e) {
            throw new PolicyException($e->getMessage());
        }
    }

    /**
     * Whether $version sorts above every line the policy lists.
     *
     * Such a version is treated as current (stateOf() reports it Active), so a
     * target the policy has not caught up to yet is never refused.
     *
     * @throws PolicyException if the component is not in the policy
     */
    public function isAheadOfPolicy(string $component, string $version): bool
    {
        try {
            return $this->creed->isAheadOfPolicy($component, $version);
        } catch (InvalidArgumentException $e) {
            throw new PolicyException($e->getMessage());
        }
    }

    /** Where the policy came from (a path, or "bundled snapshot"), for the report. */
    public function origin(): string
    {
        return $this->origin;
    }
}
