<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Plan\PackageFetchResult;
use Waynestate\Booster\Plan\PackageFetcher;

/**
 * A PackageFetcher that answers from files in tests/fixtures/packagist (or
 * from a document built inside the test) and records every URL it is asked
 * for, so tests can prove nothing hit the wire.
 */
final class FixturePackageFetcher implements PackageFetcher
{
    /** @var list<string> */
    public array $fetched = [];

    /**
     * @param array<string, string|array<string, mixed>> $fixtures packagist or repository URL => file name in tests/fixtures/packagist, or a p2 document
     */
    public function __construct(private readonly array $fixtures) {}

    public function fetch(string $url): PackageFetchResult
    {
        $this->fetched[] = $url;
        $fixture = $this->fixtures[$url] ?? null;
        if ($fixture === null) {
            return new PackageFetchResult(PackageFetchResult::FAILED, null);
        }
        $data = is_array($fixture)
            ? $fixture
            : json_decode((string) file_get_contents(__DIR__ . '/fixtures/packagist/' . $fixture), true);
        if (!is_array($data)) {
            return new PackageFetchResult(PackageFetchResult::FAILED, null);
        }

        return new PackageFetchResult(PackageFetchResult::OK, $data);
    }
}
