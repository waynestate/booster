<?php

declare(strict_types=1);

namespace Waynestate\Booster\Strangler;

/**
 * What SignInStep::inspect() found: how the app signs people in today, and
 * whether the strangler can carry it (design sections 3, 9.6 and 10.5).
 *
 * The shapes:
 * - `none`: no sign-in at all; Step 0 has nothing to do.
 * - `sesame`: the legacy half already signs in through SESAME; Step 0 only adds the shared sign-out store.
 * - `validuser`: the old validUser cookie check (Cake: an AuthController or the six apps' SsoAuthenticator;
 *   Slim: the WSUCookie family); Step 0 moves it onto SESAME.
 * - `form`: CakePHP's own form login (`Authentication.Form`); Step 0 moves it onto SESAME.
 * - `custom`: sign-in Booster cannot map; a MANUAL line, Step 0 stays pending.
 * - `lti`: an LTI app; a stop sign, no strangler yet.
 */
final class SignInReport
{
    /**
     * @param list<string>                                     $stops    stop signs: non-empty means Step 0 must not run
     * @param list<array{file: string, line: int, what: string}> $findings what the shape was read from, app-relative
     * @param list<string>                                     $manual   MANUAL lines for the report
     */
    public function __construct(
        public readonly string $framework,     // 'cakephp' | 'slim'
        public readonly bool $mounted,         // legacy/ and artisan are there: the Laravel half exists
        public readonly string $shape,         // see above
        public readonly bool $step0Done,       // the legacy half signs in through SESAME with the shared store
        public readonly bool $laravelWired,    // the Laravel half signs in under /_laravel/ with the shared store
        public readonly ?string $sessionName,  // the legacy session cookie's name, when the app sets one
        public readonly array $stops,
        public readonly array $findings,
        public readonly array $manual,
    ) {}

    public function blocked(): bool
    {
        return $this->stops !== [];
    }
}
