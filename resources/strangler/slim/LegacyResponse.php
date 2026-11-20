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

    public function keepContentType(): void
    {
        $this->hadType = $this->headers->has('Content-Type');
        $this->legacyType = $this->headers->get('Content-Type');
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
