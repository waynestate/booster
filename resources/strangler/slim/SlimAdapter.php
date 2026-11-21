<?php

declare(strict_types=1);

namespace App\Http\Controllers\Legacy\Support;

use Closure;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionFunction;
use RuntimeException;
use Symfony\Component\HttpFoundation\Cookie;
use Throwable;

/**
 * Runs a moved Slim handler inside Laravel, unchanged (Booster's strangler,
 * slim-to-laravel). App-owned: Booster copied it here on the first Slim move.
 *
 * The handler gets what Slim gave it: Slim's own PSR-7 request built from
 * the globals (raw cookies, so Laravel's cookie encryption never hides a
 * legacy cookie), a fresh response, and `$args`, also set as request
 * attributes. What it echoes is added to the body the way the legacy app's
 * output buffering did. Its answer goes through the legacy response emitter
 * (copied with a parent that only captures), and errors through the legacy
 * error handler, so headers and error bodies stay byte for byte.
 */
final class SlimAdapter
{
    /** @var array<string, mixed>|null */
    private static ?array $manifest = null;

    public function handle(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $route = $request->route();
        $spec = is_object($route) ? (string) ($route->defaults['booster_slim'] ?? '') : '';
        $args = [];
        if (is_object($route)) {
            foreach ($route->parameterNames() as $name) {
                $args[$name] = (string) $route->parameter($name);
            }
        }
        $manifest = self::manifest();
        // A Slim-hosted route (design 9.5) runs the copied front controller;
        // every other moved route runs its handler right here, inside the
        // legacy app's own middleware when the app builds its own container.
        if ($spec === 'bootstrap') {
            return self::bootstrap((array) ($manifest['hosted'] ?? []));
        }
        if (($manifest['container'] ?? 'none') === 'app') {
            return self::inApp((array) ($manifest['hosted'] ?? []), $spec);
        }
        $container = app('legacy.container');
        self::pointSesameAtLaravel();
        $responseFactory = self::responseFactory();
        $psrRequest = self::psrRequest((bool) ($manifest['body_parsing'] ?? false));
        foreach ($args as $key => $value) {
            $psrRequest = $psrRequest->withAttribute($key, $value);
        }
        $level = ob_get_level();
        ob_start();
        try {
            $callable = self::callable($spec, $container);
            $result = $callable($psrRequest, $responseFactory->createResponse(), $args);
            $echoed = (string) ob_get_clean();
            if (!$result instanceof ResponseInterface) {
                throw new RuntimeException('The moved Slim handler ' . $spec . ' did not return a response');
            }
            $result = self::withEchoed($result, $echoed, (string) ($manifest['output_buffering'] ?? 'none'));
        } catch (Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            $result = self::error($e, $psrRequest, $container, $responseFactory, $manifest);
        }

        return self::toLaravel(self::emitted($result, $manifest));
    }

    /** @return array<string, mixed> */
    public static function manifest(): array
    {
        if (self::$manifest === null) {
            $file = app_path('Http/Controllers/Legacy/booster-slim.php');
            $data = is_file($file) ? require $file : [];
            self::$manifest = is_array($data) ? $data : [];
        }

        return self::$manifest;
    }

    /**
     * The legacy container, rebuilt in Laravel the way the legacy front
     * controller built it, or null when the app had none.
     *
     * @param array<string, mixed> $manifest
     */
    public static function buildContainer(array $manifest): mixed
    {
        if (($manifest['container'] ?? 'none') === 'app') {
            return self::appSetup((array) ($manifest['hosted'] ?? []))['container'];
        }
        if (($manifest['container'] ?? 'none') !== 'php-di') {
            return null;
        }
        $builder = new \DI\ContainerBuilder();
        foreach ((array) ($manifest['files'] ?? []) as $file) {
            $definitions = require app_path('Http/Controllers/Legacy/' . $file);
            if (is_callable($definitions)) {
                $definitions($builder);
            }
        }

        return $builder->build();
    }

    /**
     * Bootstrap mode (design 10.2): run the copy of the legacy front
     * controller (boot/public/index.php), whose routes script registers only
     * the moved routes, as the legacy web server would (see legacyRun()).
     *
     * @param array<string, mixed> $manifest
     */
    private static function bootstrap(array $manifest): \Symfony\Component\HttpFoundation\Response
    {
        self::pointSesameAtLaravel();
        $front = self::front($manifest);
        $run = self::legacyRun(static function () use ($front): void {
            (static function (string $file): void {
                require $file;
            })($front);
        }, null);

        return self::captured($run['body'], $run['status'], $run['lines']);
    }

    /**
     * An app that builds its own container (an app class like App\App, a
     * Pimple container, the Slim 3 to 4 hop's LegacyApp): the legacy app
     * builds itself as legacy does, then the moved handler answers inside
     * its middleware, never Slim's dispatch (see LegacyDispatch). What the
     * setup echoed or sent with header() comes first, as it did in legacy.
     *
     * @param array<string, mixed> $manifest the copied front controller part
     */
    private static function inApp(array $manifest, string $spec): \Symfony\Component\HttpFoundation\Response
    {
        self::pointSesameAtLaravel();
        $setup = self::appSetup($manifest);
        $request = $setup['request'];
        if (!$request instanceof ServerRequestInterface) {
            // The front controller answered before its dispatch (a redirect,
            // a disabled-app page): that is the answer, as in legacy.
            return self::captured($setup['body'], $setup['status'], $setup['lines']);
        }
        $container = $setup['container'] instanceof ContainerInterface ? $setup['container'] : null;
        $recorder = $setup['recorder'];
        $run = self::legacyRun(static function () use ($setup, $recorder, $request, $container, $spec): void {
            echo $setup['body'];
            foreach ($setup['lines'] as $line) {
                header($line, false);
            }
            if ($setup['status'] !== 200) {
                http_response_code($setup['status']);
            }
            $response = $recorder->run($request, static fn(): callable => self::callable($spec, $container));
            (new \Slim\ResponseEmitter())->emit($response);
        }, $setup['legacy']);

        return self::captured($run['body'], $run['status'], $run['lines']);
    }

    /**
     * The legacy app's setup, once per request: the copied front controller
     * runs until its dispatch, with LegacyDispatch recording the middleware
     * it adds. legacy.container is the container it built.
     *
     * @param array<string, mixed> $manifest the copied front controller part
     *
     * @return array{recorder: LegacyDispatch, request: ?ServerRequestInterface, container: mixed, body: string, status: int, lines: list<string>, legacy: array<string, mixed>}
     */
    private static function appSetup(array $manifest): array
    {
        if (self::$setup !== null) {
            return self::$setup;
        }
        $front = self::front($manifest);
        $recorder = LegacyDispatch::install();
        $request = null;
        try {
            $run = self::legacyRun(static function () use ($front, &$request): void {
                try {
                    (static function (string $file): void {
                        require $file;
                    })($front);
                } catch (LegacyDispatched $dispatched) {
                    $request = $dispatched->request;
                }
            }, null);
        } finally {
            LegacyDispatch::uninstall();
        }
        if (!$recorder->seeded() && $run['body'] === '' && $run['lines'] === []) {
            throw new RuntimeException('The copied legacy front controller never built its Slim app through AppFactory::create, so Booster could not record its middleware; move the unit with --hand');
        }
        $container = $recorder->app()?->getContainer();
        if ($container !== null) {
            self::bindContainer($container);
        }
        self::$setup = ['recorder' => $recorder, 'request' => $request, 'container' => $container, 'body' => $run['body'], 'status' => $run['status'], 'lines' => $run['lines'], 'legacy' => $run['legacy']];

        return self::$setup;
    }

    /** @var array{recorder: LegacyDispatch, request: ?ServerRequestInterface, container: mixed, body: string, status: int, lines: list<string>, legacy: array<string, mixed>}|null */
    private static ?array $setup = null;

    /** @param array<string, mixed> $manifest */
    private static function front(array $manifest): string
    {
        return base_path((string) ($manifest['front'] ?? 'app/Http/Controllers/Legacy/boot/public/index.php'));
    }

    /**
     * Run legacy code as the legacy web server would: PHP's own error
     * handling and ini settings (or the ones the legacy code set in an
     * earlier run, $legacy), the legacy public/ folder as the working
     * directory, and everything it echoes and every header() it sends
     * captured. Laravel's own handlers come back afterwards. An uncaught
     * error goes to the legacy exception handler, as in legacy. A legacy
     * exit() ends the request as it does in legacy.
     *
     * @param array<string, mixed>|null $legacy what an earlier legacyRun() returned as 'legacy'
     *
     * @return array{body: string, status: int, lines: list<string>, legacy: array<string, mixed>}
     */
    private static function legacyRun(Closure $code, ?array $legacy): array
    {
        $keys = ['display_errors', 'error_reporting', 'log_errors', 'error_log', 'html_errors'];
        $previousError = set_error_handler($legacy['error'] ?? null);
        $previousException = set_exception_handler($legacy['exception'] ?? null);
        $ini = [];
        foreach ($keys as $key) {
            $ini[$key] = ini_get($key);
            ini_restore($key);
            if (is_string($legacy['ini'][$key] ?? null)) {
                ini_set($key, $legacy['ini'][$key]);
            }
        }
        $reporting = error_reporting();
        error_reporting(is_int($legacy['reporting'] ?? null) ? $legacy['reporting'] : (int) ini_get('error_reporting'));
        $cwd = getcwd();
        @chdir(base_path('legacy/public'));
        $level = ob_get_level();
        ob_start();
        $thrown = null;
        try {
            $code();
        } catch (Throwable $e) {
            $thrown = $e;
        }
        $legacyException = set_exception_handler(null);
        if ($thrown !== null && is_callable($legacyException)) {
            // Uncaught in legacy: its own exception handler answers.
            try {
                $legacyException($thrown);
                $thrown = null;
            } catch (Throwable $e) {
                $thrown = $e;
            }
        }
        $body = '';
        while (ob_get_level() > $level) {
            $body = (string) ob_get_clean() . $body;
        }
        $status = http_response_code();
        $lines = headers_list();
        header_remove();
        $after = ['ini' => [], 'reporting' => error_reporting(), 'exception' => $legacyException];
        foreach ($keys as $key) {
            $after['ini'][$key] = ini_get($key);
        }
        if ($cwd !== false) {
            @chdir($cwd);
        }
        foreach ($ini as $key => $value) {
            if ($value !== false) {
                ini_set($key, $value);
            }
        }
        error_reporting($reporting);
        $after['error'] = set_error_handler($previousError);
        set_exception_handler($previousException);
        if ($thrown !== null) {
            throw $thrown;
        }

        return ['body' => $body, 'status' => is_int($status) ? $status : 200, 'lines' => array_values($lines), 'legacy' => $after];
    }

    /**
     * A Laravel response from what the legacy run sent.
     *
     * @param list<string> $lines header() lines as headers_list() gives them
     */
    public static function captured(string $body, int $status, array $lines): LegacyResponse
    {
        $out = new LegacyResponse($body, $status);
        $cookies = [];
        $cache = [];
        $typed = false;
        foreach ($lines as $line) {
            [$name, $value] = array_map('trim', explode(':', $line, 2)) + [1 => ''];
            if (strcasecmp($name, 'Set-Cookie') === 0) {
                $cookies[] = $value;

                continue;
            }
            if (strcasecmp($name, 'Cache-Control') === 0) {
                $cache[] = $value;
            }
            $typed = $typed || strcasecmp($name, 'Content-Type') === 0;
            $out->headers->set($name, $value, false);
        }
        if (!$typed) {
            // PHP's default_mimetype, as the legacy server sends it.
            $type = (string) ini_get('default_mimetype');
            $charset = (string) ini_get('default_charset');
            if ($type !== '') {
                $out->headers->set('Content-Type', $type . ($charset !== '' && str_starts_with($type, 'text/') ? '; charset=' . $charset : ''));
            }
        }
        $out->keepContentType();
        $out->keepCacheControl($cache);
        $out->keepRawCookies($cookies);

        return $out;
    }

    /** The generated routes script calls this with the legacy container, so legacy.<key> resolves. */
    public static function bindContainer(mixed $container): void
    {
        app()->instance('legacy.container', $container);
    }

    /** A legacy service by key, from the legacy container (ArrayAccess, as Pimple, or PSR-11). */
    public static function service(string $key): mixed
    {
        $container = app('legacy.container');
        if ($container instanceof \ArrayAccess) {
            return $container[$key];
        }

        return $container instanceof ContainerInterface ? $container->get($key) : null;
    }

    /** The placeholder handler for an unmoved named route; no request reaches it. */
    public static function notMoved(): never
    {
        throw new RuntimeException('This legacy route has not moved to Laravel; Booster registered it only for its name');
    }

    private static function pointSesameAtLaravel(): void
    {
        $class = 'Waynestate\\Sesame\\Sesame';
        if (function_exists('sesame') && class_exists($class) && app()->bound($class)) {
            \sesame(app($class));
        }
    }

    /** The handler as Slim's CallableResolver resolved it. */
    private static function callable(string $spec, ?ContainerInterface $container): callable
    {
        [$kind, $target] = explode(':', $spec, 2) + [1 => ''];
        if ($kind === 'closure') {
            /** @var LegacyClosure $holder */
            $holder = new $target();
            $closure = $holder->handler();
            if ($container !== null && (new ReflectionFunction($closure))->getClosureThis() !== null) {
                $closure = Closure::bind($closure, $container);
            }

            return $closure;
        }
        [$class, $method] = explode('@', $target, 2) + [1 => null];
        $instance = $container !== null && $container->has($class) ? $container->get($class) : new $class($container);
        $callable = $method === null ? $instance : [$instance, $method];
        if (!is_callable($callable)) {
            throw new RuntimeException('The moved Slim handler ' . $spec . ' is not callable');
        }

        return $callable;
    }

    private static function responseFactory(): ResponseFactoryInterface
    {
        $factory = new \Slim\Psr7\Factory\ResponseFactory();
        $decorated = 'Slim\\Http\\Factory\\DecoratedResponseFactory';
        if (class_exists($decorated)) {
            return new $decorated($factory, new \Slim\Psr7\Factory\StreamFactory());
        }

        return $factory;
    }

    private static function psrRequest(bool $bodyParsing): ServerRequestInterface
    {
        $request = \Slim\Psr7\Factory\ServerRequestFactory::createFromGlobals();
        if ($bodyParsing && $request->getParsedBody() === null) {
            $type = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0]));
            $input = (string) $request->getBody();
            if ($type === 'application/json' || str_ends_with($type, '+json')) {
                $parsed = json_decode($input, true);
                $request = $request->withParsedBody(is_array($parsed) ? $parsed : null);
            } elseif ($type === 'application/x-www-form-urlencoded') {
                parse_str($input, $parsed);
                $request = $request->withParsedBody($parsed);
            }
            $request->getBody()->rewind();
        }
        $decorated = 'Slim\\Http\\ServerRequest';
        if (class_exists($decorated)) {
            $request = new $decorated($request);
        }

        return $request;
    }

    private static function withEchoed(ResponseInterface $response, string $echoed, string $mode): ResponseInterface
    {
        if ($echoed === '') {
            return $response;
        }
        if ($mode === 'append') {
            $response->getBody()->write($echoed);

            return $response;
        }
        // No output buffering middleware (or PREPEND): echoed bytes left
        // before the emitted body, so they come first.
        $body = (string) $response->getBody();
        $stream = (new \Slim\Psr7\Factory\StreamFactory())->createStream($echoed . $body);

        return $response->withBody($stream);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private static function error(Throwable $e, ServerRequestInterface $request, ?ContainerInterface $container, ResponseFactoryInterface $factory, array $manifest): ResponseInterface
    {
        if (!($manifest['error_middleware'] ?? false)) {
            throw $e;
        }
        $class = $manifest['error_handler'] ?? null;
        $class = is_string($class) && class_exists($class) ? $class : \Slim\Handlers\ErrorHandler::class;
        $handler = new $class(new \Slim\CallableResolver($container), $factory);

        return $handler($request, $e, false, false, false);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private static function emitted(ResponseInterface $response, array $manifest): ResponseInterface
    {
        $class = $manifest['emitter'] ?? null;
        if (!is_string($class) || !class_exists($class)) {
            return $response;
        }
        $emitter = new $class();
        $level = ob_get_level();
        ob_start();
        try {
            $emitter->emit($response);
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        return $emitter instanceof CapturingEmitter && $emitter->captured !== null ? $emitter->captured : $response;
    }

    private static function toLaravel(ResponseInterface $psr): LegacyResponse
    {
        $out = new LegacyResponse((string) $psr->getBody(), $psr->getStatusCode());
        $out->setStatusCode($psr->getStatusCode(), $psr->getReasonPhrase() !== '' ? $psr->getReasonPhrase() : null);
        $out->setProtocolVersion($psr->getProtocolVersion());
        foreach ($psr->getHeaders() as $name => $values) {
            if (strcasecmp((string) $name, 'Set-Cookie') !== 0) {
                $out->headers->set((string) $name, $values);

                continue;
            }
            foreach ($values as $line) {
                $cookie = Cookie::fromString($line);
                // A legacy cookie stays readable by the legacy half.
                if (method_exists(EncryptCookies::class, 'except')) {
                    EncryptCookies::except($cookie->getName());
                }
                $out->headers->setCookie($cookie);
            }
        }
        $out->keepContentType();
        $out->keepCacheControl(array_values($psr->getHeader('Cache-Control')));

        return $out;
    }
}
