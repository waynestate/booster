<?php

declare(strict_types=1);

namespace App\Sesame;

use Cake\Http\Response;
use Cake\Http\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Waynestate\Sesame\Exception\AuthenticationFailed;

/**
 * SESAME's side of every request, written by Booster's strangler Step 0.
 * App-owned: edit it freely. It runs before AuthenticationMiddleware.
 *
 * A request carrying CAS's ticket is the end of a sign-in: SESAME checks the
 * ticket and the browser goes on to the same page without it. On every other
 * request, an identity the Authentication plugin still holds while SESAME has
 * nobody (a timeout, or a logout on the Laravel half) is dropped, so the
 * plugin sees a guest and starts a fresh sign-in.
 */
final class TicketMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $ticket = $request->getQueryParams()['ticket'] ?? null;
        if (is_string($ticket) && $ticket !== '') {
            $back = LegacySignIn::takeServiceUrl($request) ?? LegacySignIn::currentUrl($request);
            try {
                LegacySignIn::sesame($request, $back)->login($request);
            } catch (AuthenticationFailed) {
                // A refused or replayed ticket leaves a guest; the page starts a new sign-in.
            }

            return (new Response())->withStatus(302)->withHeader('Location', $back);
        }

        $session = $request->getAttribute('session');
        if ($session instanceof Session && $session->check('Auth') && LegacySignIn::username($request) === null) {
            $session->delete('Auth');
        }

        return $handler->handle($request);
    }
}
