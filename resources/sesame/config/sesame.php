<?php

declare(strict_types=1);

use Waynestate\Sesame\Support\Setting;

/*
| The four shared WSU values (cas.base_url, gateway.issuer, oauth2.issuer
| and oauth2.jwks_url) resolve through Setting::get(): the environment
| wins, and when it is empty the private waynestate/playbook package
| supplies the university's values (see the README, "WSU values
| (Playbook)"). Every other setting stays environment-only. The oidc
| settings also resolve through Setting::get(), except the client secret,
| which is read from the environment and never from the playbook.
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Driver
    |--------------------------------------------------------------------------
    |
    | The protocol SESAME uses to verify identities: "cas" for the
    | university's CAS server, "gateway" for signed assertions from the
    | Drawbridge gateway, "oauth2" for JWT bearer tokens from an identity
    | provider (Microsoft Entra ID first), "oidc" for browser sign-in with
    | OpenID Connect (Microsoft Entra ID first), or "fake" for local
    | development.
    |
    */

    'driver' => env('SESAME_DRIVER', 'cas'),

    /*
    |--------------------------------------------------------------------------
    | CAS
    |--------------------------------------------------------------------------
    |
    | The CAS server's base URL (no trailing slash, including the path the
    | CAS server lives under, for Academica /cas) and the URL the server
    | sends the user back to after verifying them. When service_url is not
    | set, the adapter uses the app's /login route.
    |
    | protocol is 2.0 by default: Academica is believed to redirect
    | cookieless /p3/serviceValidate calls to the login page. Set it to 3.0
    | when a CAS server releases attributes on /p3/serviceValidate.
    |
    */

    'cas' => [
        'base_url' => Setting::get('SESAME_CAS_BASE_URL'),
        'service_url' => env('SESAME_CAS_SERVICE_URL'),
        'protocol' => env('SESAME_CAS_PROTOCOL', '2.0'),
        // Leave off against Academica: it issues no ticket for renew=true.
        'renew' => env('SESAME_CAS_RENEW', false),
        // On only when the CAS server enforces MFA for every sign-in: a
        // successful validation then counts as an MFA sign-in.
        'assume_mfa' => env('SESAME_CAS_ASSUME_MFA', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fake driver
    |--------------------------------------------------------------------------
    |
    | The identity the fake driver returns, for local development.
    |
    */

    'fake' => [
        'username' => env('SESAME_FAKE_USERNAME'),
        'attributes' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Gateway driver
    |--------------------------------------------------------------------------
    |
    | The Drawbridge gateway signs each request with a JWT assertion and
    | sends it in one header. The driver verifies the signature against the
    | gateway's public key and checks the issuer, audience and time claims.
    | It never reads a plain identity header, and the request must come from
    | one of the trusted proxies.
    |
    | public_key is the gateway's public key: a PEM string (or a path to one)
    | for ES256 and RS256, or a base64-encoded raw key (or a path to one) for
    | EdDSA. trusted_proxies is a comma-separated list of CIDR ranges (or
    | single IPs) the gateway proxy sits behind.
    |
    | Every assertion is single-use. The driver requires a jti claim, refuses
    | assertions that live longer than max_lifetime (seconds), and remembers
    | each used jti in a cache so a captured assertion cannot be replayed.
    | replay_store names the cache store the jtis are kept in (null uses the
    | app's default store). allow_without_replay_guard skips the replay guard
    | entirely: local development only, never in production.
    |
    */

    'gateway' => [
        'header' => env('SESAME_GATEWAY_HEADER', 'X-Sesame-Assertion'),
        'algorithm' => env('SESAME_GATEWAY_ALGORITHM', 'ES256'),
        'public_key' => env('SESAME_GATEWAY_PUBLIC_KEY'),
        'issuer' => Setting::get('SESAME_GATEWAY_ISSUER'),
        'audience' => env('SESAME_GATEWAY_AUDIENCE'),
        'leeway_seconds' => env('SESAME_GATEWAY_LEEWAY_SECONDS', 30),
        'trusted_proxies' => array_filter(array_map('trim', explode(',', (string) env('SESAME_GATEWAY_TRUSTED_PROXIES', '')))),
        'max_lifetime' => env('SESAME_GATEWAY_MAX_LIFETIME', 300),
        'replay_store' => env('SESAME_GATEWAY_REPLAY_STORE'),
        'allow_without_replay_guard' => env('SESAME_GATEWAY_ALLOW_WITHOUT_REPLAY_GUARD', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | OAuth2 driver
    |--------------------------------------------------------------------------
    |
    | The oauth2 driver signs the user in from a JWT bearer token in the
    | Authorization header, verified against the issuer's key set (JWKS).
    | Only RS256 tokens are accepted.
    |
    | Microsoft Entra ID is the first planned issuer: point issuer and
    | jwks_url at the tenant's v2.0 endpoints (the discovery document's
    | "issuer" and "jwks_uri" values) and audience at the app's client id.
    | Any other RS256 issuer works with the same settings.
    |
    | required_scopes and required_roles are lists the token must cover:
    | scopes against the scp claim (a space-separated list), roles against
    | the roles claim (an array). An empty list checks nothing.
    | identity_claim names the claim the username comes from:
    | preferred_username (the default) or accessid.
    |
    | leeway_seconds is the clock skew allowed on the exp and nbf checks.
    | The fetched key set is kept for jwks_cache_ttl_seconds (default one
    | hour) and refetched at most once a minute when a token carries an
    | unknown key id. jwks_store names the cache store the key set is kept
    | in (null uses the app's default store).
    |
    */

    'oauth2' => [
        'issuer' => Setting::get('SESAME_OAUTH2_ISSUER'),
        'jwks_url' => Setting::get('SESAME_OAUTH2_JWKS_URL'),
        'audience' => env('SESAME_OAUTH2_AUDIENCE'),
        'leeway_seconds' => env('SESAME_OAUTH2_LEEWAY_SECONDS', 60),
        'required_scopes' => [],
        'required_roles' => [],
        'identity_claim' => env('SESAME_OAUTH2_IDENTITY_CLAIM', 'preferred_username'),
        'jwks_cache_ttl_seconds' => env('SESAME_OAUTH2_JWKS_CACHE_TTL_SECONDS', 3600),
        'jwks_store' => env('SESAME_OAUTH2_JWKS_STORE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | OIDC driver
    |--------------------------------------------------------------------------
    |
    | Browser sign-in with OpenID Connect: the authorization code flow with
    | PKCE, the ID token checked against the issuer's key set. Needs
    | league/oauth2-client (composer require league/oauth2-client).
    |
    | issuer falls back to SESAME_OAUTH2_ISSUER when SESAME_OIDC_ISSUER is
    | unset. redirect_uri defaults to the app's /login/callback route, and
    | must be registered on the Entra app registration as a Web redirect
    | URI. client_secret is read from the environment only.
    |
    | No prompt and no max_age are sent by default, so an existing Entra
    | session signs the user in silently. A login request with step_up=1
    | adds the step_up parameters below.
    |
    | username_fallback (off by default) uses the preferred_username prefix
    | when username_claim is missing. preferred_username is user-changeable,
    | so key accounts on iss plus sub, or tid plus oid, if you turn it on.
    |
    | cache_ttl_seconds (default and maximum 3600) is how long the discovery
    | document and key set are cached.
    |
    | claims lists the ID token claims passed through to the user resolver
    | as identity attributes (comma-separated). post_logout_redirect_uri,
    | when set, sends the user through the provider's end-session endpoint
    | on logout.
    |
    */

    'oidc' => [
        'issuer' => Setting::get('SESAME_OIDC_ISSUER') ?? Setting::get('SESAME_OAUTH2_ISSUER'),
        'client_id' => Setting::get('SESAME_OIDC_CLIENT_ID'),
        'client_secret' => Setting::env('SESAME_OIDC_CLIENT_SECRET'),
        'redirect_uri' => Setting::get('SESAME_OIDC_REDIRECT_URI'),
        'scopes' => Setting::get('SESAME_OIDC_SCOPES') ?? 'openid profile email',
        'client_auth' => Setting::get('SESAME_OIDC_CLIENT_AUTH') ?? 'client_secret_post',
        'username_claim' => Setting::get('SESAME_OIDC_USERNAME_CLAIM') ?? 'accessid',
        'username_fallback' => env('SESAME_OIDC_USERNAME_FALLBACK', false),
        'roles_claim' => Setting::get('SESAME_OIDC_ROLES_CLAIM') ?? 'wsu_roles',
        'claims' => Setting::get('SESAME_OIDC_CLAIMS') ?? 'wsu_roles,accessid',
        'assume_mfa' => env('SESAME_OIDC_ASSUME_MFA', false),
        'leeway_seconds' => env('SESAME_OIDC_LEEWAY_SECONDS', 60),
        'post_logout_redirect_uri' => Setting::get('SESAME_OIDC_POST_LOGOUT_REDIRECT_URI'),
        'authorization_endpoint' => Setting::get('SESAME_OIDC_AUTHORIZATION_ENDPOINT'),
        'token_endpoint' => Setting::get('SESAME_OIDC_TOKEN_ENDPOINT'),
        'jwks_uri' => Setting::get('SESAME_OIDC_JWKS_URI'),
        'end_session_endpoint' => Setting::get('SESAME_OIDC_END_SESSION_ENDPOINT'),
        'cache_ttl_seconds' => env('SESAME_OIDC_CACHE_TTL_SECONDS', 3600),
        'cache_store' => Setting::get('SESAME_OIDC_CACHE_STORE'),
        'step_up' => [
            'prompt' => env('SESAME_OIDC_STEP_UP_PROMPT', 'login'),
            'max_age' => env('SESAME_OIDC_STEP_UP_MAX_AGE'),
            'acr_values' => env('SESAME_OIDC_STEP_UP_ACR_VALUES'),
            'claims' => env('SESAME_OIDC_STEP_UP_CLAIMS'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Session timeouts
    |--------------------------------------------------------------------------
    |
    | How long a signed-in session may live, in minutes. The idle timeout is
    | the quiet time after which the session is dropped; the absolute
    | lifetime is the longest a session may live, no matter how active it
    | is.
    |
    */

    'session' => [
        'idle_minutes' => env('SESAME_SESSION_IDLE_MINUTES', 120),
        'absolute_minutes' => env('SESAME_SESSION_ABSOLUTE_MINUTES', 720),
    ],

    /*
    |--------------------------------------------------------------------------
    | MFA
    |--------------------------------------------------------------------------
    |
    | How long a verified MFA sign-in stays good, in seconds. The default is
    | eight hours. on_failure is what the sesame.mfa middleware does when the
    | check fails: "deny" answers 403, and "reauth" sends the user back to
    | the login route.
    |
    */

    'mfa' => [
        'max_age' => env('SESAME_MFA_MAX_AGE', 28800),
        'on_failure' => env('SESAME_MFA_ON_FAILURE', 'deny'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | When true, the adapter registers GET /login (sesame.login) and
    | POST /logout (sesame.logout), and, for the oidc driver,
    | GET /login/callback (sesame.callback). Set to false to keep the routes in the
    | app's own route file; the auth.sesame middleware still points at the
    | sesame.login route name.
    |
    */

    'routes' => env('SESAME_ROUTES', true),

    /*
    |--------------------------------------------------------------------------
    | User resolver
    |--------------------------------------------------------------------------
    |
    | The class that turns a verified identity into the app's user. It must
    | implement Waynestate\Sesame\UserResolver.
    |
    */

    'user_resolver' => env('SESAME_USER_RESOLVER'),

];
