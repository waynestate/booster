<?php

declare(strict_types=1);

namespace Waynestate\Booster\Plan;

/**
 * The real fetcher: plain HTTPS GETs with nothing beyond PHP itself. A
 * missing package, a dead network or a non-2xx status all come back as
 * FAILED, which the checker reads as "not on Packagist".
 */
final class HttpPackageFetcher implements PackageFetcher
{
    public function __construct(private readonly int $timeoutSeconds = 10) {}

    public function fetch(string $url): PackageFetchResult
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => $this->timeoutSeconds,
                'header' => "User-Agent: waynestate-booster package-compat\r\n",
            ],
        ]);
        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            return new PackageFetchResult(PackageFetchResult::FAILED, null);
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return new PackageFetchResult(PackageFetchResult::FAILED, null);
        }

        return new PackageFetchResult(PackageFetchResult::OK, $data);
    }
}
