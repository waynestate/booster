<?php

declare(strict_types=1);

namespace Waynestate\Booster\Update;

/**
 * The real update fetcher: one GET over the bundled streams, no cURL
 * dependency, ~1.5 s total. Any failure (DNS, timeout, HTTP error, bad JSON)
 * is a null, never an exception.
 */
final class HttpUpdateFetcher implements UpdateFetcher
{
    /** Seconds to wait for the connection to open. */
    private const CONNECT_TIMEOUT = 1.0;

    /** Seconds to wait for the whole response. */
    private const READ_TIMEOUT = 1.5;

    public function __construct(private readonly string $userAgent = 'waynestate-booster-update-check') {}

    public function fetch(string $url): ?array
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => self::READ_TIMEOUT,
                'connect_timeout' => self::CONNECT_TIMEOUT,
                'header' => 'User-Agent: ' . $this->userAgent . "\r\n",
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
