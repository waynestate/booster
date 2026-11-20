<?php

declare(strict_types=1);

namespace App\Http\Controllers\Legacy\Support;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Request;

/**
 * A moved Slim handler's answer as a Laravel response (Booster's strangler).
 * App-owned. Symfony's prepare() adds "; charset=UTF-8" to a text type that
 * has none, and a type to a response that set none; Slim sent neither, so
 * this keeps the Content-Type exactly as the handler set it (PHP's own
 * default, "text/html; charset=UTF-8", is what Symfony adds when there was none).
 */
final class LegacyResponse extends Response
{
    private ?string $legacyType = null;

    private bool $hadType = false;

    /** @var list<string> */
    private array $cacheControl = [];

    /** @var list<string> */
    private array $rawCookies = [];

    public function keepContentType(): void
    {
        $this->hadType = $this->headers->has('Content-Type');
        $this->legacyType = $this->headers->get('Content-Type');
    }

    /**
     * Symfony rewrites Cache-Control into one sorted line and adds "private";
     * this sends the lines the legacy handler (or its emitter) set, as they were.
     *
     * @param list<string> $lines
     */
    /**
     * Set-Cookie lines sent as legacy wrote them (bootstrap mode): Laravel's
     * EncryptCookies never sees them, so the legacy half still reads them.
     *
     * @param list<string> $lines
     */
    public function keepRawCookies(array $lines): void
    {
        $this->rawCookies = $lines;
    }

    public function keepCacheControl(array $lines): void
    {
        $this->cacheControl = $lines;
    }

    public function sendHeaders(?int $statusCode = null): static
    {
        if (headers_sent()) {
            return $this;
        }
        parent::sendHeaders($statusCode);
        if ($this->cacheControl === []) {
            // Symfony's default "no-cache, private"; the legacy app sent none.
            header_remove('Cache-Control');
        }
        foreach ($this->cacheControl as $n => $line) {
            header('Cache-Control: ' . $line, $n === 0);
        }
        foreach ($this->rawCookies as $line) {
            header('Set-Cookie: ' . $line, false);
        }

        return $this;
    }

    public function prepare(Request $request): static
    {
        parent::prepare($request);
        if ($this->hadType && $this->legacyType !== null) {
            $this->headers->set('Content-Type', $this->legacyType);
        }

        return $this;
    }
}
