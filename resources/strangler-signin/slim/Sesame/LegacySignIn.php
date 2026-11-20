<?php

declare(strict_types=1);

namespace App\Sesame;

use GuzzleHttp\Client;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\RequestInterface;
use Waynestate\Sesame\Config;
use Waynestate\Sesame\DriverFactory;
use Waynestate\Sesame\Exception\AuthenticationFailed;
use Waynestate\Sesame\Identity;
use Waynestate\Sesame\Redirect;
use Waynestate\Sesame\Revocation\FileRevocationStore;
use Waynestate\Sesame\RevocationStore;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\Session\NativeSession;
use Waynestate\Sesame\Support\Setting;
use Waynestate\Sesame\UserResolver;

/**
 * This app's sign-in through SESAME, written by Booster's strangler Step 0.
 * App-owned: edit it freely.
 *
 * It replaced the old validUser cookie check. The app's own code still decides
 * whether a signed-in username may use the app (its authz check) and keeps
 * its own user session; SESAME only answers who signed in, keeps the idle and
 * absolute timeouts, and shares sign-outs with the Laravel half through one
 * revocation store, so a logout on either half ends both. It stores into the
 * PHP session the app already started.
 */
final class LegacySignIn
{
    private const SERVICE_KEY = 'booster_sesame_service';

    /** SESAME for this request; the CAS service URL is the page that started the sign-in. */
    public static function sesame(?string $serviceUrl = null): Sesame
    {
        $config = Config::fromArray([
            // SESAME_LEGACY_DRIVER first: in Booster's replay the two halves of a strangled app can need different drivers.
            'driver' => Setting::env('SESAME_LEGACY_DRIVER') ?? Setting::env('SESAME_DRIVER') ?? 'cas',
            // The fake driver boots only in local, development and testing.
            'environment' => Setting::env('APP_ENV') ?? 'production',
            'cas' => [
                'base_url' => Setting::get('SESAME_CAS_BASE_URL') ?? '',
                'service_url' => $serviceUrl ?? self::here(),
            ],
            'fake' => ['username' => Setting::env('SESAME_FAKE_USERNAME') ?? ''],
            'session' => [
                'idle_minutes' => (int) (Setting::env('SESAME_SESSION_IDLE_MINUTES') ?? 120),
                'absolute_minutes' => (int) (Setting::env('SESAME_SESSION_ABSOLUTE_MINUTES') ?? 720),
            ],
        ]);
        // The app's own session settings are left as they were; see the Step 0 report.
        $session = new NativeSession(checkSessionSettings: false);

        return new Sesame(
            fn() => DriverFactory::make(
                $config,
                new Client(['allow_redirects' => false, 'timeout' => 10]),
                new Psr17Factory(),
                session: $session,
            ),
            new class implements UserResolver {
                public function findOrImportUser(Identity $identity): mixed
                {
                    return ['username' => $identity->username];
                }
            },
            $session,
            $config,
            revocations: self::revocations(),
        );
    }

    /**
     * Where to send a guest so they come back to $serviceUrl signed in: the CAS
     * login, or $serviceUrl itself when the driver signs in on the spot (fake).
     */
    public static function handOff(RequestInterface $request, string $serviceUrl): string
    {
        $serviceUrl = self::withoutTicket($serviceUrl);
        $step = self::sesame($serviceUrl)->login(self::psr($request));
        if ($step instanceof Redirect) {
            $_SESSION[self::SERVICE_KEY] = $serviceUrl;

            return $step->url;
        }

        return $serviceUrl;
    }

    /**
     * The signed-in username, or null. A request carrying CAS's ticket finishes
     * the sign-in first. It also ends a session that timed out or was signed
     * out on the other half.
     */
    public static function username(RequestInterface $request): ?string
    {
        parse_str($request->getUri()->getQuery(), $query);
        if (is_string($query['ticket'] ?? null) && $query['ticket'] !== '') {
            $back = $_SESSION[self::SERVICE_KEY] ?? self::withoutTicket((string) $request->getUri());
            unset($_SESSION[self::SERVICE_KEY]);
            try {
                self::sesame(is_string($back) ? $back : null)->login(self::psr($request));
            } catch (AuthenticationFailed) {
                // A refused or replayed ticket leaves a guest; the login page starts a new sign-in.
            }
        }

        $user = self::sesame(self::withoutTicket((string) $request->getUri()))->user();
        $username = is_array($user) ? ($user['username'] ?? null) : null;

        return is_string($username) && $username !== '' ? $username : null;
    }

    /** Ends the local SESAME session and records the sign-out for the other half. */
    public static function signOut(): void
    {
        self::sesame()->signOutLocal();
    }

    /** This request's URL from the server variables, without CAS's ticket. */
    private static function here(): string
    {
        $https = ($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off';
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';

        return self::withoutTicket(($https ? 'https' : 'http') . '://' . $host . ($_SERVER['REQUEST_URI'] ?? '/'));
    }

    private static function withoutTicket(string $url): string
    {
        $parts = explode('?', $url, 2);
        if (count($parts) === 1) {
            return $url;
        }
        parse_str($parts[1], $query);
        unset($query['ticket']);

        return $query === [] ? $parts[0] : $parts[0] . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private static function psr(RequestInterface $request): \Psr\Http\Message\ServerRequestInterface
    {
        return $request instanceof \Psr\Http\Message\ServerRequestInterface
            ? $request
            : (new Psr17Factory())->createServerRequest($request->getMethod(), $request->getUri(), $_SERVER);
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

        $repo = dirname(__DIR__, 2 /* booster:levels */);

        return is_file($repo . '/artisan') && is_dir($repo . '/storage')
            ? new FileRevocationStore($repo . '/storage/sesame-revocations')
            : null;
    }
}
