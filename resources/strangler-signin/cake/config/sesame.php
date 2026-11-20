<?php

declare(strict_types=1);

/*
 * SESAME for this app, written by Booster's strangler Step 0 (Booster docs:
 * guides/cakephp-and-slim-to-laravel.md). App-owned: edit it freely.
 *
 * Every value comes from the environment, with Playbook as the fallback for
 * the shared WSU values. There is no service URL here: App\Sesame\LegacySignIn
 * sends each sign-in back to the page that started it, so the CAS service URL
 * is set per request.
 */

use Waynestate\Sesame\Support\Setting;

return [
    // SESAME_LEGACY_DRIVER first: in Booster's replay the two halves of a strangled app can need different drivers.
    'driver' => Setting::env('SESAME_LEGACY_DRIVER') ?? Setting::env('SESAME_DRIVER') ?? 'cas',
    // The fake driver boots only in local, development and testing.
    'environment' => Setting::env('APP_ENV') ?? 'production',
    'cas' => [
        'base_url' => Setting::get('SESAME_CAS_BASE_URL') ?? '',
    ],
    'fake' => [
        'username' => Setting::env('SESAME_FAKE_USERNAME') ?? '',
    ],
    'session' => [
        'idle_minutes' => (int) (Setting::env('SESAME_SESSION_IDLE_MINUTES') ?? 120),
        'absolute_minutes' => (int) (Setting::env('SESAME_SESSION_ABSOLUTE_MINUTES') ?? 720),
    ],
];
