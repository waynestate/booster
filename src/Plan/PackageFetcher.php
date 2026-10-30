<?php

declare(strict_types=1);

namespace Waynestate\Booster\Plan;

/**
 * Answers one Packagist p2 document by URL. The planner takes this as an
 * interface so tests can answer from fixtures instead of the wire.
 */
interface PackageFetcher
{
    public function fetch(string $url): PackageFetchResult;
}
