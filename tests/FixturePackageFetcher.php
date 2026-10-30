<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Plan\PackageFetchResult;
use Waynestate\Booster\Plan\PackageFetcher;

/**
 * A PackageFetcher that answers from files in tests/fixtures/packagist and
 * records every URL it is asked for, so tests can prove nothing hit the wire.
 */
final class FixturePackageFetcher implements PackageFetcher
{
    /** @var list<string> */
    public array $fetched = [];

    /**
     * @param array<string, string> $fixtures packagist or repository URL => file name in tests/fixtures/packagist
     */
    public function __construct(private readonly array $fixtures) {}

    public function fetch(string $url): PackageFetchResult
    {
        $this->fetched[] = $url;
        $file = $this->fixtures[$url] ?? null;
        if ($file === null) {
            return new PackageFetchResult(PackageFetchResult::FAILED, null);
        }
        $data = json_decode((string) file_get_contents(__DIR__ . '/fixtures/packagist/' . $file), true);
        if (!is_array($data)) {
            return new PackageFetchResult(PackageFetchResult::FAILED, null);
        }

        return new PackageFetchResult(PackageFetchResult::OK, $data);
    }
}
