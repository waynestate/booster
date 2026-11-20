<?php

declare(strict_types=1);

namespace App\Sesame;

use GuzzleHttp\Client;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Waynestate\Sesame\Cake\CakeSession;
use Waynestate\Sesame\Config;
use Waynestate\Sesame\DriverFactory;
use Waynestate\Sesame\Redirect;
use Waynestate\Sesame\Revocation\FileRevocationStore;
use Waynestate\Sesame\RevocationStore;
use Waynestate\Sesame\Sesame;

/**
 * This app's sign-in through SESAME, written by Booster's strangler Step 0.
 * App-owned: edit it freely.
 *
 * It replaced the old validUser cookie check. The app's own code still decides
 * who a signed-in username is (its profile and roles); SESAME only answers who
 * signed in, keeps the session's idle and absolute timeouts, and shares
 * sign-outs with the Laravel half through one revocation store, so a logout on
 * either half ends both. The ticket CAS sends back is checked by
 * TicketMiddleware, on whatever page the sign-in started from.
 */
final class LegacySignIn
{
    private const SERVICE_KEY = 'booster_sesame_service';

    /** SESAME for this request; the CAS service URL is the page that started the sign-in. */
    public static function sesame(ServerRequestInterface $request, ?string $serviceUrl = null): Sesame
    {
        $settings = require CONFIG . 'sesame.php';
        $settings['cas']['service_url'] = $serviceUrl ?? self::currentUrl($request);
        $config = Config::fromArray($settings);
        $session = new CakeSession($request->getAttribute('session'));

        return new Sesame(
            fn() => DriverFactory::make(
                $config,
                new Client(['allow_redirects' => false, 'timeout' => 10]),
                new Psr17Factory(),
                session: $session,
            ),
            new UserResolver(),
            $session,
            $config,
            revocations: self::revocations(),
        );
    }

    /**
     * Where to send a guest so they come back to $serviceUrl signed in: the CAS
     * login, or $serviceUrl itself when the driver signs in on the spot (fake).
     */
    public static function handOff(ServerRequestInterface $request, string $serviceUrl): string
    {
        // The old hand-off URL-encoded the "?" of its destination; CAS needs a real URL.
        if (! str_contains($serviceUrl, '?') && stripos($serviceUrl, '%3F') !== false) {
            $serviceUrl = (string) preg_replace('/%3F/i', '?', $serviceUrl, 1);
        }

        $step = self::sesame($request, $serviceUrl)->login($request);
        if ($step instanceof Redirect) {
            $request->getAttribute('session')?->write(self::SERVICE_KEY, $serviceUrl);

            return $step->url;
        }

        return $serviceUrl;
    }

    /** The signed-in username, or null; it also ends a session that timed out or was signed out on the other half. */
    public static function username(ServerRequestInterface $request): ?string
    {
        $user = self::sesame($request)->user();
        $username = is_array($user) ? ($user['username'] ?? null) : null;

        return is_string($username) && $username !== '' ? $username : null;
    }

    /** Seconds left before the session's idle timeout, 0 when nobody is signed in. */
    public static function secondsLeft(ServerRequestInterface $request): int
    {
        $status = self::sesame($request)->sessionStatus();
        if (! $status['signedIn']) {
            return 0;
        }

        return max(0, strtotime($status['idleExpiresAt']) - time());
    }

    /** Ends the local SESAME session and records the sign-out for the other half. */
    public static function signOut(ServerRequestInterface $request): void
    {
        self::sesame($request)->signOutLocal();
    }

    /** The service URL the last hand-off used, taken once. */
    public static function takeServiceUrl(ServerRequestInterface $request): ?string
    {
        $session = $request->getAttribute('session');
        $url = $session?->consume(self::SERVICE_KEY);

        return is_string($url) && $url !== '' ? $url : null;
    }

    /** The URL of this request without CAS's ticket parameter. */
    public static function currentUrl(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        parse_str($uri->getQuery(), $query);
        unset($query['ticket']);

        return (string) $uri->withQuery(http_build_query($query, '', '&', PHP_QUERY_RFC3986))->withFragment('');
    }

    /**
     * The shared sign-out store: SESAME_REVOCATION_PATH when it is set, else,
     * once Booster has mounted this app under legacy/, the Laravel half's
     * storage/sesame-revocations, the directory Laravel's half uses by default.
     */
    private static function revocations(): ?RevocationStore
    {
        $store = FileRevocationStore::fromEnvironment();
        if ($store !== null) {
            return $store;
        }

        $repo = dirname(ROOT);

        return is_file($repo . '/artisan') && is_dir($repo . '/storage')
            ? new FileRevocationStore($repo . '/storage/sesame-revocations')
            : null;
    }
}
