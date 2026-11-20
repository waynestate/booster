<?php

declare(strict_types=1);

namespace Waynestate\Booster\Strangler;

use Waynestate\Booster\Run\SignInPlan;

/**
 * The Laravel half's sign-in, after the mount (design sections 9.1 and 10.6),
 * and its return to the root at the finish.
 *
 * While the old app still owns /login and /logout, Laravel signs in through
 * SESAME's own routes under /_laravel/ (SESAME_ROUTE_PREFIX), on its own
 * session, and reads the user through SESAME's guard. Both halves share one
 * FileRevocationStore directory, storage/sesame-revocations, so a logout on
 * either half ends both. At the finish the prefix and the shared store go,
 * and SESAME's defaults (/login, POST /logout) are Laravel's.
 */
final class SignInLaravelHalf
{
    public const MARK = 'Booster strangler: the Laravel half';

    private const CONFIG_BLOCK = <<<'PHP'

    /*
    |--------------------------------------------------------------------------
    | Booster strangler: the Laravel half
    |--------------------------------------------------------------------------
    |
    | The old app under legacy/ still owns /login and /logout, so this half
    | signs in under /_laravel/ until `booster run --to-laravel --finish`
    | removes this block. Both halves share one sign-out store, so a logout on
    | either half ends both sessions; the legacy half finds the same
    | directory by itself.
    |
    */

    'route_prefix' => env('SESAME_ROUTE_PREFIX', '_laravel'),

    // The same user array the legacy half keeps, so moved routes read the same keys.
    'user_resolver' => \App\Sesame\StranglerUserResolver::class,

    'revocation' => [
        'path' => env('SESAME_REVOCATION_PATH', storage_path('sesame-revocations')),
        'ttl_seconds' => env('SESAME_REVOCATION_TTL', 2592000),
    ],
    // End of the Booster strangler block.

PHP;

    private const ENV_BLOCK = <<<'ENV'

# Booster strangler: the Laravel half signs in under /_laravel/ (SESAME) while
# legacy/ owns /login. Both halves share storage/sesame-revocations for logout.
# Behind a proxy, set the CAS service URL to the public address:
# SESAME_CAS_SERVICE_URL="${APP_URL}/_laravel/login"
# End of the Booster strangler block.

ENV;

    /** Whether the Laravel half already signs in under the prefix with the shared store. */
    public static function wired(string $root): bool
    {
        $config = @file_get_contents($root . '/config/sesame.php');

        return is_string($config) && str_contains($config, self::MARK);
    }

    public static function wire(SignInPlan $plan): void
    {
        $plan->requirePackage('composer.json', 'waynestate/sesame', '^0.10', '0.10');

        // config/sesame.php: the prefix and the shared store.
        $config = $plan->read('config/sesame.php');
        if ($config === null) {
            $plan->failures[] = 'config/sesame.php is missing from the Laravel half (Sourdough writes it). Publish SESAME\'s config, then run the strangler again.';
        } elseif (! str_contains($config, self::MARK)) {
            $close = strrpos($config, '];');
            if ($close === false) {
                $plan->failures[] = 'config/sesame.php does not end in a returned array; Booster cannot add the /_laravel/ prefix to it.';
            } else {
                $before = rtrim(substr($config, 0, $close));
                $comma = str_ends_with($before, ',') || str_ends_with($before, '[') ? '' : ',';
                $plan->put('config/sesame.php', $before . $comma . "\n" . self::CONFIG_BLOCK . substr($config, $close), 'config/sesame.php: SESAME\'s routes under /_laravel/ and the shared sign-out store (storage/sesame-revocations)');
            }
        }

        // app/Sesame: the user both halves agree on.
        foreach (['app/Sesame/StranglerUser.php', 'app/Sesame/StranglerUserResolver.php'] as $relative) {
            $template = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/strangler-signin/laravel/' . $relative);
            $current = $plan->read($relative);
            if ($current !== null && ! str_contains($current, 'written by' . "\n" . ' * Booster') && $current !== $template) {
                $plan->failures[] = sprintf('%s already exists and Booster did not write it; move it aside, then run the strangler again.', $relative);
            } elseif ($current === null) {
                $plan->put($relative, $template, sprintf('%s: written (the Laravel half keeps the legacy half\'s user array)', $relative));
            }
        }
        $plan->manual[] = 'The Laravel half\'s user is App\\Sesame\\StranglerUser: the legacy half\'s user array as an object (`[\'username\']` and `toArray()` work). Moved code that tests is_array() on the user calls toArray() instead; when the legacy resolver keeps more keys, change StranglerUserResolver::attributes() to match.';

        // bootstrap/app.php: a guest Laravel bounces goes to SESAME's route, wherever it lives.
        $bootstrap = $plan->read('bootstrap/app.php');
        if ($bootstrap !== null && preg_match('/redirectGuestsTo\(\s*[\'"]\/login[\'"]\s*\)/', $bootstrap) === 1) {
            $plan->put(
                'bootstrap/app.php',
                (string) preg_replace('/redirectGuestsTo\(\s*[\'"]\/login[\'"]\s*\)/', "redirectGuestsTo(fn () => route('sesame.login'))", $bootstrap),
                'bootstrap/app.php: guests go to route(\'sesame.login\'), /_laravel/login while the strangler runs',
            );
        } elseif ($bootstrap === null || ! str_contains($bootstrap, "route('sesame.login')")) {
            $plan->manual[] = 'bootstrap/app.php: send guests to SESAME with $middleware->redirectGuestsTo(fn () => route(\'sesame.login\')), or Laravel\'s auth middleware looks for a route named login.';
        }

        // config/auth.php: the web guard reads the user through SESAME.
        $auth = $plan->read('config/auth.php');
        $guard = '/(\'web\'\s*=>\s*\[\s*\'driver\'\s*=>\s*)\'session\'/';
        if ($auth !== null && preg_match($guard, $auth) === 1) {
            $plan->put('config/auth.php', (string) preg_replace($guard, "\$1'sesame'", $auth, 1), 'config/auth.php: the web guard reads the user through SESAME (auth()->user() on moved routes)');
        } elseif ($auth === null || preg_match('/\'web\'\s*=>\s*\[\s*\'driver\'\s*=>\s*\'sesame\'/', $auth) !== 1) {
            $plan->manual[] = 'config/auth.php: point the web guard at SESAME (\'driver\' => \'sesame\'), so moved routes read the signed-in user with auth()->user().';
        }

        // .env.example: say what the strangler set.
        $env = $plan->read('.env.example') ?? '';
        if (! str_contains($env, 'Booster strangler: the Laravel half')) {
            $plan->put('.env.example', rtrim($env, "\n") . ($env === '' ? '' : "\n") . self::ENV_BLOCK, '.env.example: the Laravel half\'s sign-in notes');
        }

        $plan->manual[] = 'The web server must be able to write storage/sesame-revocations: that one directory is how a logout on either half ends both.';
        $plan->manual[] = 'Signed-in users sign in once more on the first moved page: a silent CAS round trip to /_laravel/login, since the halves keep separate sessions.';
    }

    /** At the finish: SESAME's own routes at the root again, and no shared store. */
    public static function finish(SignInPlan $plan): void
    {
        $config = $plan->read('config/sesame.php');
        if ($config !== null && str_contains($config, self::MARK)) {
            $mark = (int) strpos($config, '| ' . self::MARK);
            $start = strrpos(substr($config, 0, $mark), "\n    /*");
            $end = strpos($config, "    // End of the Booster strangler block.\n");
            if ($start === false || $end === false || $end < $start) {
                $plan->failures[] = 'config/sesame.php: the Booster strangler block was edited, so Booster cannot remove it. Remove route_prefix and revocation by hand.';
            } else {
                $plan->put(
                    'config/sesame.php',
                    substr($config, 0, $start) . substr($config, $end + strlen("    // End of the Booster strangler block.\n")),
                    'config/sesame.php: SESAME\'s routes back at /login and POST /logout; the shared sign-out store removed',
                );
            }
        }

        $env = $plan->read('.env.example');
        if ($env !== null && str_contains($env, '# Booster strangler: the Laravel half')) {
            $updated = (string) preg_replace('/\n?# Booster strangler: the Laravel half.*?# End of the Booster strangler block\.\n/s', '', $env, 1);
            $plan->put('.env.example', $updated, '.env.example: the strangler sign-in notes removed');
        }

        $plan->manual[] = 'Remove SESAME_ROUTE_PREFIX, SESAME_REVOCATION_PATH and SESAME_REVOCATION_TTL from the real environment (Citadel\'s settings), and set SESAME_CAS_SERVICE_URL to https://<host>/login, or unset it to use the /login default.';
        $plan->manual[] = 'storage/sesame-revocations is no longer read: delete it.';
        $plan->manual[] = 'config/sesame.php\'s own user_resolver is back (Sourdough\'s, a User model looked up by username). Moved code that read $user[\'username\'] still works on the model; app/Sesame/StranglerUser.php and StranglerUserResolver.php are unused now: delete them once nothing names them.';
    }
}
