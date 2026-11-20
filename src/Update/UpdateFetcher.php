<?php

declare(strict_types=1);

namespace Waynestate\Booster\Update;

/**
 * Loads one Packagist p2 document. The seam that keeps the update notice off
 * the network in tests: a fake answers with a fixed array or null.
 */
interface UpdateFetcher
{
    /**
     * Fetch the document at $url.
     *
     * @return array<string, mixed>|null the decoded JSON, or null when the fetch failed
     */
    public function fetch(string $url): ?array;
}
