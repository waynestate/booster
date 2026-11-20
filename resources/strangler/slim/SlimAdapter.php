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
    public static function buildContainer(array $manifest): ?ContainerInterface
    {
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

    /** sesame_user() in moved code reads the Laravel half's SESAME session, the one its guard reads. */
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
