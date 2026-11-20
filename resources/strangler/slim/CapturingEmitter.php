<?php

declare(strict_types=1);

namespace App\Http\Controllers\Legacy\Support;

use Psr\Http\Message\ResponseInterface;

/**
 * The parent Booster gives a copied legacy response emitter (Booster's
 * strangler). App-owned. The legacy emitter adds its headers (the Slim
 * skeleton adds CORS and cache headers) and calls parent::emit(); here that
 * only keeps the response, so Laravel sends it with those headers.
 */
class CapturingEmitter
{
    public ?ResponseInterface $captured = null;

    public function __construct(protected int $responseChunkSize = 4096) {}

    public function emit(ResponseInterface $response): void
    {
        $this->captured = $response;
    }
}
