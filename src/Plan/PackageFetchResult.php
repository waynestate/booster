<?php

declare(strict_types=1);

namespace Waynestate\Booster\Plan;

/**
 * One fetch attempt. OK means an HTTP 2xx body that parsed as a JSON object;
 * everything else (no route, 404, timeout, garbage) is FAILED, and the caller
 * falls back to the repositories the app declared.
 */
final readonly class PackageFetchResult
{
    public const OK = 'ok';

    public const FAILED = 'failed';

    /**
     * @param array<string, mixed>|null $data the decoded document when status is OK
     */
    public function __construct(
        public string $status,
        public ?array $data,
    ) {}
}
