<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Strangler\SignInSource;
use Waynestate\Booster\Strangler\SignInStep;

/**
 * Strangler Step 0 and the Laravel half's sign-in (design sections 3, 9.1,
 * 9.6, 10.5 and 10.6). The fixtures are small synthetic apps in the shapes
 * the fleet survey found, never fleet code. The two-session sign-in and the
 * shared logout against a CAS test double are proven in SESAME itself
 * (tests/Laravel/StranglerTwoHalvesTest.php), where the store lives.
 */
final class StranglerSignInTest extends TestCase
{
    private const AUTH_CONTROLLER = <<<'PHP'
<?php

namespace App\Controller;

class AuthController extends AppController
{
    public function login()
    {
        $url = $this->appUrl . $this->request->getQuery('redirect');
        if ($user = $this->__setUser()) {
            return $this->redirect($url);
        } else {
            return $this->redirect("{$this->authUrl}?destination_url={$url}&need_validuser=1");
        }
    }

    public function logout()
    {
        $cookie = $this->__parseSSOCookie();
        $this->Authentication->logout();
        return $this->redirect("{$this->authUrl}/?logged_out=1");
    }

    private function __setUser()
    {
        $cookie = $this->__parseSSOCookie();
        if (!$cookie) {
            return false;
        }
        $this->Authentication->setIdentity(['id' => $cookie['accessid']]);
        return true;
    }

    private function __parseSSOCookie() {
        if (array_key_exists('validUser', $_COOKIE)) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_POSTFIELDS, "cookie=" . urlencode($_COOKIE['validUser']));
            $response = json_decode(curl_exec($ch));
            if ($response->valid) {
                return ["accessid" => $response->uid, "timeout" => 1, "verified" => true];
            }
        }
        return false;
    }
}
PHP;

    private const APPLICATION = <<<'PHP'
<?php

namespace App;

use Authentication\Middleware\AuthenticationMiddleware;

class Application extends BaseApplication
{
    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        $middlewareQueue
            ->add(new RoutingMiddleware($this))
            ->add(new BodyParserMiddleware())
            ->add(new AuthenticationMiddleware($this));

        return $middlewareQueue;
    }
}
PHP;

    private const SSO_AUTHENTICATOR = <<<'PHP'
<?php
namespace App\Authentication;

use Psr\Http\Message\ServerRequestInterface;

class SsoAuthenticator extends AbstractAuthenticator
{
    public function authenticate(ServerRequestInterface $request): ResultInterface
    {
        $user = $request->getAttribute('session')->read('Auth');
        if ($user) {
            return new Result($user, ResultInterface::SUCCESS);
        }
        $sso_url         = Configure::read('Wsu.urls.sso.login');
        $callback_url    = Router::url(Configure::read('Wsu.urls.callback'), true);
        $destination_url = $callback_url . urlEncode('?') . 'target_url=' . $request->getUri()->getPath();
        $target_url      = "{$sso_url}?need_validuser=1&destination_url={$destination_url}";
        throw new RedirectException($target_url, 302);
    }
}
PHP;

    private const FORM_APPLICATION = <<<'PHP'
<?php

namespace App;

class Application extends BaseApplication
{
    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        return $middlewareQueue->add(new AuthenticationMiddleware($this));
    }

    public function getAuthenticationService(ServerRequestInterface $request): AuthenticationServiceInterface
    {
        $service = new AuthenticationService(['unauthenticatedRedirect' => '/users/login']);
        $service->loadAuthenticator('Authentication.Session', ['identifier' => $passwordIdentifier]);
        $service->loadAuthenticator('Authentication.Form', [
            'identifier' => $passwordIdentifier,
            'loginUrl' => Router::url(['controller' => 'Users', 'action' => 'login']),
        ]);

        return $service;
    }
}
PHP;

    private const USERS_CONTROLLER = <<<'PHP'
<?php

namespace App\Controller;

class UsersController extends AppController
{
    public function login() {
        $result = $this->Authentication->getResult();
        if ($result->isValid()) {
            return $this->redirect($this->request->getQuery('redirect', '/articles'));
        }
        if ($this->request->is('post') && !$result->isValid()) {
            $this->Flash->error(__('Invalid username or password'));
        }
    }
}
PHP;

    private const SLIM_LOGIN = <<<'PHP'
<?php
namespace App\Controller\Page;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

Class Login extends \App\Controller\Page
{
    function Login (RequestInterface $request, ResponseInterface $response, $args = [])
    {
        if ($this->user->isAuth()) {
            return $this->redirect($response, $request->getUri()->getBasePath());
        }

        return $this->render($response, 'public/login.twig');
    }

    function CheckWSUCookie (RequestInterface $request, ResponseInterface $response, $args = [])
    {
        $cookie = trim($this->cookies->get($request, 'validUser'));
        $parts = explode(':', urldecode($cookie));
        if (count($parts) === 3) {
            list($setter, $uid, $token) = [$parts[0], $parts[1], $parts[2]];
            if ($this->authz->check($uid) && $this->authnCookie->check($uid, $cookie, 'validUser')) {
                $this->user->id($uid);
                $response = $this->unset_logout_cookie($response);
                return $this->auth_redirect($response, $request);
            }
        }

        return false;
    }

    function Logout (RequestInterface $request, ResponseInterface $response, $args = [])
    {
        $this->user->logout();
        return $this->flash($response, 'Successfully logged out', BASE_PATH, 'success');
    }
}
PHP;

    private const REPLAY = <<<'JSON'
{
    "version": 1,
    "serve": {
        "docroot": "webroot",
        "setup": "",
        "env": {
            "DEBUG": "false"
        },
        "ini": {}
    },
    "requests": []
}
JSON;

    public function testCakeValidUserMovesOntoSesameAndRunsAgainAsANoOp(): void
    {
        $app = $this->app([
            'composer.json' => json_encode(['name' => 'acme/attendance', 'require' => ['php' => '^8.3', 'cakephp/cakephp' => '5.4.*']]),
            'src/Application.php' => self::APPLICATION,
            'src/Controller/AuthController.php' => self::AUTH_CONTROLLER,
            'booster-replay.json' => self::REPLAY,
        ]);

        $report = SignInStep::inspect($app, 'cakephp');
        self::assertSame('validuser', $report->shape);
        self::assertFalse($report->step0Done);
        self::assertFalse($report->blocked());

        $result = SignInStep::migrate($app, 'cakephp');
        self::assertSame([], $result->failures);
        self::assertTrue($result->step0Done);
        self::assertSame(['/auth/login'], $result->replayChanges);

        $controller = $this->read($app, 'src/Controller/AuthController.php');
        self::assertTrue(SignInSource::parses($controller));
        self::assertStringContainsString('return $this->redirect(\App\Sesame\LegacySignIn::handOff($this->request, $url));', $controller);
        self::assertStringContainsString('$username = \App\Sesame\LegacySignIn::username($this->request);', $controller);
        self::assertStringNotContainsString('curl_init', $controller, 'The old validator is gone.');
        self::assertStringContainsString("\\App\\Sesame\\LegacySignIn::signOut(\$this->request);\n        \$this->Authentication->logout();", $controller);
        self::assertStringContainsString('logged_out=1', $controller, 'The old SSO logout redirect is left for the owner (a MANUAL line).');

        $application = $this->read($app, 'src/Application.php');
        self::assertMatchesRegularExpression('/->add\(new \\\\App\\\\Sesame\\\\TicketMiddleware\(\)\)\s*->add\(new AuthenticationMiddleware\(\$this\)\)/', $application);

        foreach (['src/Sesame/LegacySignIn.php', 'src/Sesame/TicketMiddleware.php', 'src/Sesame/UserResolver.php', 'config/sesame.php'] as $file) {
            self::assertFileExists($app . '/' . $file);
            self::assertContains($file, $result->changedFiles);
        }

        $composer = json_decode($this->read($app, 'composer.json'), true);
        self::assertSame('^0.10', $composer['require']['waynestate/sesame']);
        self::assertSame('^7.9', $composer['require']['guzzlehttp/guzzle']);

        $replay = json_decode($this->read($app, 'booster-replay.json'));
        self::assertSame('cas', $replay->serve->env->SESAME_DRIVER);
        self::assertSame(SignInStep::REPLAY_CAS, $replay->serve->env->SESAME_CAS_BASE_URL);
        self::assertSame('local', $replay->serve->env->APP_ENV, 'SESAME takes the replay\'s plain-http service URL on 127.0.0.1 only in a local environment.');
        self::assertSame('false', $replay->serve->env->DEBUG);
        self::assertStringContainsString('"ini": {}', $this->read($app, 'booster-replay.json'), 'Empty objects stay objects.');

        self::assertNotEmpty(array_filter($result->manual, static fn(string $line): bool => str_contains($line, 'SESAME_CAS_BASE_URL')));

        // Run again: already done, nothing written.
        $again = SignInStep::inspect($app, 'cakephp');
        self::assertSame('sesame', $again->shape);
        self::assertTrue($again->step0Done);
        $second = SignInStep::migrate($app, 'cakephp');
        self::assertSame([], $second->failures);
        self::assertSame([], $second->changedFiles);
        self::assertTrue($second->step0Done);
    }

    public function testTheSixAppsSsoAuthenticatorHandsOffWithItsOwnRequest(): void
    {
        $app = $this->app([
            'composer.json' => json_encode(['require' => ['cakephp/cakephp' => '5.4.*']]),
            'src/Application.php' => self::APPLICATION,
            'src/Authentication/SsoAuthenticator.php' => self::SSO_AUTHENTICATOR,
            'src/Controller/SsoController.php' => str_replace('class AuthController', 'class SsoController', self::AUTH_CONTROLLER),
        ]);

        $result = SignInStep::migrate($app, 'cakephp');
        self::assertSame([], $result->failures);

        $authenticator = $this->read($app, 'src/Authentication/SsoAuthenticator.php');
        self::assertTrue(SignInSource::parses($authenticator));
        self::assertStringContainsString('$target_url      = \App\Sesame\LegacySignIn::handOff($request, $destination_url);', $authenticator);
        self::assertContains('*', $result->replayChanges, 'The authenticator answers every guarded path.');
        self::assertContains('/sso/login', $result->replayChanges);
    }

    public function testCakeFormLoginBecomesTheSesameAuthenticator(): void
    {
        $app = $this->app([
            'composer.json' => json_encode(['require' => ['cakephp/cakephp' => '5.4.*']]),
            'src/Application.php' => self::FORM_APPLICATION,
            'src/Controller/UsersController.php' => self::USERS_CONTROLLER,
            'booster-replay.json' => self::REPLAY,
        ]);

        self::assertSame('form', SignInStep::inspect($app, 'cakephp')->shape);
        $result = SignInStep::migrate($app, 'cakephp');
        self::assertSame([], $result->failures);
        self::assertSame(['/users/login'], $result->replayChanges);

        $application = $this->read($app, 'src/Application.php');
        self::assertTrue(SignInSource::parses($application));
        self::assertStringNotContainsString('Authentication.Form', $application);
        self::assertStringContainsString('$service->loadAuthenticator(\Waynestate\Sesame\Cake\SesameAuthenticator::class, [', $application);
        self::assertStringContainsString("'Authentication.Session'", $application, 'The other authenticators stay.');

        $users = $this->read($app, 'src/Controller/UsersController.php');
        self::assertTrue(SignInSource::parses($users));
        self::assertStringContainsString('return $this->redirect(\App\Sesame\LegacySignIn::handOff($this->request, \App\Sesame\LegacySignIn::currentUrl($this->request)));' . "\n    }", $users);

        $replay = json_decode($this->read($app, 'booster-replay.json'));
        self::assertSame('fake', $replay->serve->env->SESAME_DRIVER, 'A public app signs in on the spot in the replay.');
        self::assertSame(SignInStep::REPLAY_USER, $replay->serve->env->SESAME_FAKE_USERNAME);
    }

    public function testSlimValidUserKeepsTheAppsOwnAuthzAndUserSession(): void
    {
        $app = $this->app([
            'composer.json' => json_encode(['require' => ['slim/slim' => '^4.15'], 'autoload' => ['psr-4' => ['App\\' => './app/']]]),
            'app/Controller/Page/Login.php' => self::SLIM_LOGIN,
            'app/Session/NativeSession.php' => "<?php\nnamespace App\\Session;\nclass NativeSession\n{\n    protected \$name = 'foobar';\n}\n",
        ]);

        $report = SignInStep::inspect($app, 'slim');
        self::assertSame('validuser', $report->shape);
        self::assertSame('foobar', $report->sessionName);

        $result = SignInStep::migrate($app, 'slim');
        self::assertSame([], $result->failures);

        $login = $this->read($app, 'app/Controller/Page/Login.php');
        self::assertTrue(SignInSource::parses($login));
        self::assertStringContainsString('$uid = \App\Sesame\LegacySignIn::username($request);', $login);
        self::assertStringContainsString('if ($uid === null || !$this->authz->check($uid)) {', $login);
        self::assertStringContainsString('$response = $this->unset_logout_cookie($response);', $login);
        self::assertStringNotContainsString("'public/login.twig'", $login, 'Guests go to CAS instead of the LDAP form.');
        self::assertStringContainsString("\\App\\Sesame\\LegacySignIn::handOff(\$request, (string) \$request->getUri())", $login);
        self::assertStringContainsString("\\App\\Sesame\\LegacySignIn::signOut();\n        \$this->user->logout();", $login);

        $helper = $this->read($app, 'app/Sesame/LegacySignIn.php');
        self::assertTrue(SignInSource::parses($helper));
        self::assertStringContainsString('dirname(__DIR__, 3)', $helper, 'app/Sesame under legacy/ is three folders below the repository.');
        self::assertStringNotContainsString('booster:levels', $helper);

        self::assertTrue(SignInStep::inspect($app, 'slim')->step0Done);
    }

    public function testAnAppAlreadyOnSesameOnlyGetsTheSharedStore(): void
    {
        $app = $this->app([
            'composer.json' => json_encode(['require' => ['slim/slim' => '^4.15', 'waynestate/sesame' => '^0.8']]),
            'config/auth.php' => "<?php\nuse Waynestate\\Sesame\\Sesame;\nreturn fn (\$c) => new Sesame(\n    DriverFactory::make(\$config),\n    new Resolver(),\n    new NativeSession(),\n    \$config,\n);\n",
            'public/index.php' => "<?php\nuse Waynestate\\Sesame\\WayneAuth\\WayneAuth;\nWayneAuth::boot(\$config, \$http, \$requests, \$resolver);\n",
        ]);

        $report = SignInStep::inspect($app, 'slim');
        self::assertSame('sesame', $report->shape);
        self::assertFalse($report->step0Done);

        $result = SignInStep::migrate($app, 'slim');
        self::assertSame([], $result->failures);

        $config = $this->read($app, 'config/auth.php');
        self::assertTrue(SignInSource::parses($config));
        self::assertStringContainsString("    \$config, revocations: \\Waynestate\\Sesame\\Revocation\\FileRevocationStore::fromEnvironment() ?? (is_file(dirname(__DIR__, 2) . '/artisan')", $config);
        $index = $this->read($app, 'public/index.php');
        self::assertStringContainsString('$resolver, revocations: ', $index);
        self::assertSame('^0.10', json_decode($this->read($app, 'composer.json'), true)['require']['waynestate/sesame']);

        self::assertTrue(SignInStep::inspect($app, 'slim')->step0Done, 'The store is there now.');
        self::assertSame([], SignInStep::migrate($app, 'slim')->changedFiles);
    }

    public function testStopSignsAndManualLines(): void
    {
        $lti = $this->app(['composer.json' => json_encode(['require' => ['packbackbooks/lti-1p3-tool' => '^6.0']])]);
        $report = SignInStep::inspect($lti, 'cakephp');
        self::assertTrue($report->blocked());
        self::assertStringStartsWith('No strangler yet for LTI apps, see the guide', $report->stops[0]);
        self::assertNotSame([], SignInStep::migrate($lti, 'cakephp')->failures);

        $named = $this->app([
            'composer.json' => '{}',
            'config/app.php' => "<?php\nreturn ['Session' => ['defaults' => 'php', 'cookie' => 'laravel_session']];\n",
        ]);
        $report = SignInStep::inspect($named, 'cakephp');
        self::assertTrue($report->blocked());
        self::assertStringContainsString('config/app.php:2 names the legacy session "laravel_session"', $report->stops[0]);

        $custom = $this->app([
            'composer.json' => '{}',
            'app/Auth.php' => "<?php\nfunction signIn(\$u, \$p) { return ldap_bind(\$c, \$u, \$p); }\n",
        ]);
        $result = SignInStep::migrate($custom, 'slim');
        self::assertSame([], $result->failures);
        self::assertFalse($result->step0Done, 'Step 0 stays pending.');
        self::assertStringStartsWith('MANUAL: Booster cannot map', $result->manual[0]);
        self::assertSame([], $result->changedFiles);

        $none = $this->app(['composer.json' => '{}', 'app/routes.php' => "<?php\n\$app->get('/', fn () => 'hi');\n"]);
        $result = SignInStep::migrate($none, 'slim');
        self::assertTrue($result->step0Done);
        self::assertSame([], $result->changedFiles);
    }

    public function testTheLaravelHalfSignsInUnderTheLaravelPrefixAndFinishPutsItBack(): void
    {
        $app = $this->app([
            'composer.json' => json_encode(['require' => ['laravel/framework' => '^13.0', 'waynestate/sesame' => '^0.8']]),
            'artisan' => "#!/usr/bin/env php\n",
            'storage/.gitignore' => "*\n",
            'config/sesame.php' => "<?php\n\nreturn [\n\n    'driver' => env('SESAME_DRIVER', 'cas'),\n\n    'routes' => env('SESAME_ROUTES', true),\n\n];\n",
            'config/auth.php' => "<?php\n\nreturn [\n    'guards' => [\n        'web' => [\n            'driver' => 'session',\n            'provider' => 'users',\n        ],\n    ],\n];\n",
            'bootstrap/app.php' => "<?php\n\n\$x->withMiddleware(function (\$middleware): void {\n    \$middleware->redirectGuestsTo('/login');\n});\n",
            '.env.example' => "APP_NAME=Laravel\n",
            'booster-replay.json' => self::REPLAY,
            'legacy/composer.json' => '{}',
            'legacy/app/Sesame/LegacySignIn.php' => "<?php\n// This app's sign-in through SESAME, written by Booster's strangler Step 0.\n",
        ]);

        $report = SignInStep::inspect($app, 'slim');
        self::assertTrue($report->mounted);
        self::assertTrue($report->step0Done);
        self::assertFalse($report->laravelWired);

        $result = SignInStep::migrate($app, 'slim');
        self::assertSame([], $result->failures);
        self::assertTrue($result->laravelWired);

        $config = $this->read($app, 'config/sesame.php');
        self::assertTrue(SignInSource::parses($config));
        self::assertStringContainsString("'route_prefix' => env('SESAME_ROUTE_PREFIX', '_laravel'),", $config);
        self::assertStringContainsString("'path' => env('SESAME_REVOCATION_PATH', storage_path('sesame-revocations')),", $config);
        self::assertStringContainsString("'user_resolver' => \\App\\Sesame\\StranglerUserResolver::class,", $config);
        self::assertFileExists($app . '/app/Sesame/StranglerUser.php');
        self::assertFileExists($app . '/app/Sesame/StranglerUserResolver.php');
        self::assertStringContainsString("'driver' => 'sesame'", $this->read($app, 'config/auth.php'));
        self::assertStringContainsString("redirectGuestsTo(fn () => route('sesame.login'))", $this->read($app, 'bootstrap/app.php'));
        self::assertStringContainsString('SESAME_CAS_SERVICE_URL="${APP_URL}/_laravel/login"', $this->read($app, '.env.example'));
        self::assertSame('^0.10', json_decode($this->read($app, 'composer.json'), true)['require']['waynestate/sesame']);
        self::assertSame('fake', json_decode($this->read($app, 'booster-replay.json'))->serve->env->SESAME_DRIVER);

        self::assertTrue(SignInStep::inspect($app, 'slim')->laravelWired);
        self::assertSame([], SignInStep::migrate($app, 'slim')->changedFiles, 'Wiring twice changes nothing.');

        $finish = SignInStep::finish($app, 'slim');
        self::assertSame([], $finish->failures);
        $config = $this->read($app, 'config/sesame.php');
        self::assertTrue(SignInSource::parses($config));
        self::assertStringNotContainsString('route_prefix', $config);
        self::assertStringNotContainsString('revocation', $config);
        self::assertStringContainsString("'routes' => env('SESAME_ROUTES', true)", $config);
        self::assertSame("APP_NAME=Laravel\n", $this->read($app, '.env.example'));
        self::assertNotEmpty(array_filter($finish->manual, static fn(string $line): bool => str_contains($line, 'SESAME_ROUTE_PREFIX')));
    }

    public function testAValidUserStepZeroKeepsTheLegacyHalfOnTheReplayCasAfterTheMount(): void
    {
        $replay = json_encode(['version' => 1, 'serve' => ['docroot' => 'public', 'env' => ['SESAME_DRIVER' => 'cas', 'SESAME_CAS_BASE_URL' => SignInStep::REPLAY_CAS, 'APP_ENV' => 'local']], 'sequences' => [['name' => 'g', 'requests' => [['path' => '/']]]]]);
        $app = $this->app([
            'composer.json' => json_encode(['require' => ['laravel/framework' => '^13.0', 'waynestate/sesame' => '^0.8']]),
            'artisan' => "#!/usr/bin/env php\n",
            'storage/.gitignore' => "*\n",
            'config/sesame.php' => "<?php\n\nreturn [\n\n    'driver' => env('SESAME_DRIVER', 'cas'),\n\n    'routes' => env('SESAME_ROUTES', true),\n\n];\n",
            'config/auth.php' => "<?php\n\nreturn [\n    'guards' => [\n        'web' => [\n            'driver' => 'session',\n            'provider' => 'users',\n        ],\n    ],\n];\n",
            'bootstrap/app.php' => "<?php\n\n\$x->withMiddleware(function (\$middleware): void {\n    \$middleware->redirectGuestsTo('/login');\n});\n",
            '.env.example' => "APP_NAME=Laravel\n",
            'booster-replay.json' => (string) $replay,
            'legacy/composer.json' => '{}',
            'legacy/app/Sesame/LegacySignIn.php' => "<?php\n// This app's sign-in through SESAME, written by Booster's strangler Step 0.\n",
        ]);

        $result = SignInStep::migrate($app, 'slim');
        self::assertSame([], $result->failures);

        $env = json_decode($this->read($app, 'booster-replay.json'))->serve->env;
        self::assertSame('fake', $env->SESAME_DRIVER, 'the Laravel half signs in on the spot');
        self::assertSame('cas', $env->SESAME_LEGACY_DRIVER ?? null, 'the legacy half keeps handing guests to the replay CAS, as in Step 0');
        self::assertSame(SignInStep::REPLAY_CAS, $env->SESAME_CAS_BASE_URL);
    }

    public function testAMountedAppWhoseLegacyHalfIsNotOnSesameIsRefused(): void
    {
        $app = $this->app([
            'composer.json' => '{}',
            'artisan' => '',
            'legacy/composer.json' => json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]]),
            'legacy/app/Controller/Page/Login.php' => self::SLIM_LOGIN,
        ]);

        $result = SignInStep::migrate($app, 'slim');
        self::assertStringContainsString('Step 0 is not done', $result->failures[0]);
        self::assertSame(self::SLIM_LOGIN, $this->read($app, 'legacy/app/Controller/Page/Login.php'));
    }

    public function testBothHalvesResolveOneIdentityToEqualUsers(): void
    {
        require_once __DIR__ . '/support/sesame-resolver-stubs.php';
        $resources = dirname(__DIR__) . '/resources/strangler-signin';
        require_once $resources . '/cake/src/Sesame/UserResolver.php';
        require_once $resources . '/laravel/app/Sesame/StranglerUser.php';
        require_once $resources . '/laravel/app/Sesame/StranglerUserResolver.php';

        $identity = new \Waynestate\Sesame\Identity('ab1234', ['cn' => ['A. B.']]);
        $legacy = (new \App\Sesame\UserResolver())->findOrImportUser($identity);
        $laravel = (new \App\Sesame\StranglerUserResolver())->findOrImportUser($identity);

        self::assertSame(['username' => 'ab1234'], $legacy);
        self::assertInstanceOf(\App\Sesame\StranglerUser::class, $laravel);
        self::assertInstanceOf(\Illuminate\Contracts\Auth\Authenticatable::class, $laravel, 'SESAME\'s guard hands it to auth()->user().');
        self::assertSame($legacy, $laravel->toArray(), 'Both halves keep the same user array.');
        self::assertSame('ab1234', $laravel['username'], 'Moved code reads the same keys.');
        self::assertSame('ab1234', $laravel->getAuthIdentifier());

        // Laravel 13 keeps the session as JSON: the stored user comes back as an array.
        $stored = json_decode((string) json_encode($laravel), true);
        $refreshed = (new \App\Sesame\StranglerUserResolver())->refreshUser($stored);
        self::assertInstanceOf(\App\Sesame\StranglerUser::class, $refreshed);
        self::assertSame($legacy, $refreshed->toArray());
        self::assertNull((new \App\Sesame\StranglerUserResolver())->refreshUser(['username' => '']));

        // The Slim half's resolver keeps the same array too.
        $slim = (string) file_get_contents($resources . '/slim/Sesame/LegacySignIn.php');
        self::assertStringContainsString("return ['username' => \$identity->username];", $slim);
    }

    public function testTheTemplatesStepZeroWritesAreValidPhp(): void
    {
        $resources = dirname(__DIR__) . '/resources/strangler-signin';
        foreach (['cake/src/Sesame/LegacySignIn.php', 'cake/src/Sesame/TicketMiddleware.php', 'cake/src/Sesame/UserResolver.php', 'cake/config/sesame.php', 'slim/Sesame/LegacySignIn.php', 'laravel/app/Sesame/StranglerUser.php', 'laravel/app/Sesame/StranglerUserResolver.php'] as $file) {
            $code = (string) file_get_contents($resources . '/' . $file);
            self::assertTrue(SignInSource::parses($code), $file . ' parses.');
            self::assertStringNotContainsString("\u{2014}", $code);
        }
    }

    /**
     * @param array<string, string|false> $files
     */
    private function app(array $files): string
    {
        $dir = str_replace('\\', '/', $this->tempDir());
        foreach ($files as $relative => $content) {
            $path = $dir . '/' . $relative;
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0o777, true);
            }
            file_put_contents($path, (string) $content);
        }

        return $dir;
    }

    private function read(string $app, string $relative): string
    {
        return (string) file_get_contents($app . '/' . $relative);
    }
}
