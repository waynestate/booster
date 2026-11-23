---
title: "Strangler sign-in | Booster docs"
description: "What Booster's strangler changes to move an app's sign-in onto SESAME (Step 0), how the Laravel half signs in under /_laravel/, how one logout ends both halves, and what the finish puts back."
url: https://booster.apps.wayne.edu/docs/reference/strangler-sign-in.html
llms: https://booster.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Strangler sign-in [#](https://booster.apps.wayne.edu/docs/reference/strangler-sign-in.html#strangler-sign-in)

The strangler (Booster's move of a CakePHP or Slim app onto Laravel) runs the old app and Laravel side by side, so both halves must agree on who is signed in. Booster does this with SESAME on both halves, each with its own session, and one shared sign-out store. This page lists exactly what it writes. Every file it writes is app-owned afterwards: edit it freely.

Two halves, one user, one logout. Think of it as a joint account with a single "close account" button.

For the whole run (Step 0, the mount, moves, `--hand` and `finish`), read [CakePHP and Slim to Laravel](https://booster.apps.wayne.edu/docs/guides/cakephp-and-slim-to-laravel.html). This page is the sign-in detail behind it.

## Step 0: the old app signs in through SESAME [#](https://booster.apps.wayne.edu/docs/reference/strangler-sign-in.html#step-0-the-old-app-signs-in-through-sesame)

Step 0 runs before the mount. It reads how the app signs people in today and picks one shape:

| Shape | What Booster finds | What Step 0 does |
| --- | --- | --- |
| none | no sign-in at all | nothing; Step 0 is done |
| sesame | `new Sesame(...)`, `WayneAuth::boot(...)` or `SesameAuthenticator` | adds the shared sign-out store as a named `revocations:` argument |
| validuser, CakePHP | the old validUser cookie check: an `AuthController`, or the shared `SsoController` plus `SsoAuthenticator` | the method that posted the cookie to the old validator asks SESAME instead and keeps its return shape; the hand-off to the old SSO page goes to CAS through SESAME, back to the same destination; `TicketMiddleware` runs before `AuthenticationMiddleware`; the logout signs SESAME out too |
| form, CakePHP | `Authentication.Form` | it becomes SESAME's `SesameAuthenticator`; the login action hands a guest to SESAME instead of showing the form |
| validuser, Slim | the WSUCookie family (a `CheckWSUCookie()` that sets `$this->user->id($uid)`) | that method asks SESAME, then runs the app's own authz check; the login page hands a guest to CAS instead of showing the LDAP form; the logout signs SESAME out too |
| custom | sign-in Booster cannot map (an LDAP form on its own, a hand-rolled session check) | a MANUAL line; Step 0 stays pending until the owner moves it by hand |

The files it writes for CakePHP: `src/Sesame/LegacySignIn.php`, `src/Sesame/TicketMiddleware.php`, `src/Sesame/UserResolver.php` and `config/sesame.php`. For Slim: `Sesame/LegacySignIn.php` in the folder `composer.json` maps `App\` to. It requires `waynestate/sesame` `^0.11` and `guzzlehttp/guzzle` `^7.9`.

Every edit is planned first and written only when all of them are known; if any step cannot be placed exactly, or an edited file would not parse, Step 0 writes nothing and names the file and line.

The user both halves keep is the array `['username' => <AccessID>]`. The app's own code still builds its profile and roles from that username, as it did from the old cookie.

### Stop signs [#](https://booster.apps.wayne.edu/docs/reference/strangler-sign-in.html#stop-signs)

- An LTI app: "No strangler yet for LTI apps, see the guide."
- A legacy session named `laravel_session`, the Laravel half's default cookie: rename it first.

### The replay after Step 0 [#](https://booster.apps.wayne.edu/docs/reference/strangler-sign-in.html#the-replay-after-step-0)

Step 0 writes the replay's fake settings into `booster-replay.json`'s `serve.env`, and lists the request paths whose sign-in hand-off changed on purpose (`*` for an authenticator, which answers every guarded path). An app on the validUser cookie hands guests to `https://cas.replay.invalid/cas`, a CAS that is never reached, so the Location is stable; the others sign in on the spot with SESAME's fake driver as `replay.user`.

## The Laravel half [#](https://booster.apps.wayne.edu/docs/reference/strangler-sign-in.html#the-laravel-half)

After the mount, the same step signs the Laravel half in through SESAME's Laravel guard:

- `config/sesame.php` gets a marked block: `route_prefix` `_laravel` (so `/_laravel/login` and `POST /_laravel/logout`; the old app keeps `/login`), the shared store on `storage/sesame-revocations`, and `user_resolver` set to `App\Sesame\StranglerUserResolver`.
- `app/Sesame/StranglerUser.php` holds the same array the old half keeps, so moved routes read the same keys (`$user['username']`), and it is an `Authenticatable` for `auth()->user()`.
- `config/auth.php`: the web guard's driver is `sesame`.
- `bootstrap/app.php`: guests go to `route('sesame.login')`.
- `.env.example` gets a commented `SESAME_CAS_SERVICE_URL="${APP_URL}/_laravel/login"` for an app behind a proxy.

Each half keeps its own session and cookie. The old half finds the shared directory on its own once it sits under `legacy/` next to `artisan`; set `SESAME_REVOCATION_PATH` on both halves to use another directory. The web server must be able to write it: that directory is how a logout on either half ends both.

## Finish [#](https://booster.apps.wayne.edu/docs/reference/strangler-sign-in.html#finish)

The strangler's finish removes the marked block from `config/sesame.php` and the notes from `.env.example`, so SESAME's own routes are back at `/login` and `POST /logout` and the app's own resolver is back. The MANUAL lines name the environment keys to drop on Citadel (`SESAME_ROUTE_PREFIX`, `SESAME_REVOCATION_PATH`, `SESAME_REVOCATION_TTL`) and the service URL to set.

## MANUAL lines it adds [#](https://booster.apps.wayne.edu/docs/reference/strangler-sign-in.html#manual-lines-it-adds)

- Set `SESAME_CAS_BASE_URL` unless Playbook supplies it.
- Everyone signed in before the Step 0 deploy signs in once more.
- The CakePHP logout still redirects to the old SSO logout page afterwards; SESAME has already ended both sessions by then.
- For a form login: the form template and the password identifier are unused; code that reads `getIdentifier()` needs the resolver to return the user's row.
- For Slim: the middleware that ignores `CheckWSUCookie()`'s answer lands the browser on the base path after sign-in; the app's session cookie flags, then SESAME's session check turned back on.
- The first moved page signs in once more on the Laravel half: a silent CAS round trip.
