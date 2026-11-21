<?php

declare(strict_types=1);

namespace App\Http\Controllers\Legacy\Support;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Thrown by LegacyDispatch when the legacy app reaches its dispatch (Slim's
 * run() or handle()), carrying the request Slim built for it. An Error, not
 * an Exception, so an app's own `catch (\Exception $e)` around run() lets it
 * through. App-owned: Booster copied it here on the first Slim move.
 */
final class LegacyDispatched extends \Error
{
    public function __construct(public readonly ServerRequestInterface $request)
    {
        parent::__construct('The legacy Slim app reached its dispatch; Booster answers from here');
    }
}
