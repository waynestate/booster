<?php

declare(strict_types=1);

namespace Waynestate\Booster\Policy;

use DateTimeImmutable;

interface PolicySource
{
    /**
     * The State:: value of $version of $component on $date.
     *
     * @throws PolicyException if the component or version is not in the policy
     */
    public function stateOf(string $component, string $version, DateTimeImmutable $date): string;

    /**
     * Versions of $component that are active or security-supported on $date,
     * lowest first. These are the only legal upgrade targets.
     *
     * @return list<string>
     */
    public function supported(string $component, DateTimeImmutable $date): array;

    /** Where the policy came from (a path, or "bundled"), for the report. */
    public function origin(): string;
}
