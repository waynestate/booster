<?php

declare(strict_types=1);

namespace App\Http\Controllers\Legacy\Support;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;
use Slim\Interfaces\MiddlewareDispatcherInterface;
use Slim\Interfaces\RouteInterface;
use Slim\Middleware\RoutingMiddleware;
use Slim\MiddlewareDispatcher;
use Slim\Routing\RouteContext;
use Slim\Routing\RoutingResults;

/**
 * Runs a moved Slim handler inside the legacy app's own middleware, with
 * nothing of Slim's dispatch (Booster's strangler, slim-to-laravel, design
 * 10.2). App-owned: Booster copied it here on the first Slim move.
 *
 * The legacy app builds itself as it always did (its container, session,
 * role checks and `$app->add(...)` middleware), through AppFactory::create,
 * which hands it this recorder as its middleware dispatcher. Every
 * middleware the app adds is recorded, in order. When the app reaches its
 * dispatch, the recorder stops it (LegacyDispatched) with Slim's request.
 * run() then answers that request: the recorded middleware in Slim's order
 * (the last added runs first; each Slim 3 double-pass one through the
 * app's own double-pass adapter, as the app added it), then routing as
 * Slim's RouteRunner does it (so a route the app's role checks never
 * registered gets the legacy not-found answer, where legacy gave it), then
 * the moved handler through the app's invocation strategy. Slim's emitter
 * sends the answer, as Slim's run() did.
 *
 * Think of it as Creed Bratton's approach to identity: keep the whole
 * legacy life intact, just swap the guy at the very center.
 */
final class LegacyDispatch implements MiddlewareDispatcherInterface
{
    /** @var list<mixed> middleware in the order the app added it */
    private array $middleware = [];

    private ?RequestHandlerInterface $kernel = null;

    /** Hand the next AppFactory::create() a fresh recorder. */
    public static function install(): self
    {
        $recorder = new self();
        AppFactory::setMiddlewareDispatcher($recorder);

        return $recorder;
    }

    /** AppFactory builds with its own dispatcher again. */
    public static function uninstall(): void
    {
        Closure::bind(static function (): void {
            AppFactory::$middlewareDispatcher = null;
        }, null, AppFactory::class)();
    }

    public function seedMiddlewareStack(RequestHandlerInterface $kernel): void
    {
        $this->kernel = $kernel;
        $this->middleware = [];
    }

    public function add($middleware): self
    {
        $this->middleware[] = $middleware;

        return $this;
    }

    public function addMiddleware(MiddlewareInterface $middleware): self
    {
        $this->middleware[] = $middleware;

        return $this;
    }

    /** Slim's dispatch: stop here, with the request Slim built. */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        throw new LegacyDispatched($request);
    }

    /** Whether a Slim app was built with this recorder. */
    public function seeded(): bool
    {
        return $this->kernel !== null;
    }

    /** @return list<mixed> the recorded middleware, first added first */
    public function middleware(): array
    {
        return $this->middleware;
    }

    /** The Slim app, read from the route runner it seeded this recorder with. */
    public function app(): ?\Slim\App
    {
        if ($this->kernel === null) {
            return null;
        }
        $proxy = (fn(): mixed => $this->routeCollectorProxy ?? null)->call($this->kernel);

        return $proxy instanceof \Slim\App ? $proxy : null;
    }

    /**
     * Answer the request as the legacy app would, with the moved handler at
     * the center. $resolve gives the handler; it is called where Slim
     * resolved the route's callable, after the middleware ran.
     *
     * @param Closure(): callable $resolve
     */
    public function run(ServerRequestInterface $request, Closure $resolve): ResponseInterface
    {
        $app = $this->app();
        if ($app === null) {
            throw new RuntimeException('Booster could not find the legacy Slim app behind its middleware dispatcher');
        }
        $core = new class ($app, $resolve) implements RequestHandlerInterface {
            /** @param Closure(): callable $resolve */
            public function __construct(private readonly \Slim\App $app, private readonly Closure $resolve) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                // Slim's RouteRunner: route now when no middleware did.
                if ($request->getAttribute(RouteContext::ROUTING_RESULTS) === null) {
                    $routing = new RoutingMiddleware($this->app->getRouteResolver(), $this->app->getRouteCollector()->getRouteParser());
                    $request = $routing->performRouting($request);
                }
                $request = $request->withAttribute(RouteContext::BASE_PATH, $this->app->getBasePath());
                $results = $request->getAttribute(RouteContext::ROUTING_RESULTS);
                if ($results instanceof RoutingResults && $results->getRouteStatus() === RoutingResults::METHOD_NOT_ALLOWED) {
                    $miss = new HttpMethodNotAllowedException($request);
                    $miss->setAllowedMethods($results->getAllowedMethods());

                    throw $miss;
                }
                if ($results instanceof RoutingResults && $results->getRouteStatus() !== RoutingResults::FOUND) {
                    throw new HttpNotFoundException($request);
                }
                $route = $request->getAttribute(RouteContext::ROUTE);
                $args = $route instanceof RouteInterface ? $route->getArguments() : ($results instanceof RoutingResults ? $results->getRouteArguments() : []);
                $strategy = $this->app->getRouteCollector()->getDefaultInvocationStrategy();
                $answer = $strategy(($this->resolve)(), $request, $this->app->getResponseFactory()->createResponse(), $args);
                if (!$answer instanceof ResponseInterface) {
                    throw new RuntimeException('The moved Slim handler did not return a response');
                }

                return $answer;
            }
        };
        // Slim's own middleware queue, replayed in the app's order around
        // Booster's core instead of Slim's route runner.
        $queue = new MiddlewareDispatcher($core, $app->getCallableResolver(), $app->getContainer());
        foreach ($this->middleware as $middleware) {
            $queue->add($middleware);
        }
        $response = $queue->handle($request);
        if (strtoupper($request->getMethod()) === 'HEAD') {
            // As Slim\App::handle: a HEAD answer has no body.
            $response = $response->withBody($app->getResponseFactory()->createResponse()->getBody());
        }

        return $response;
    }
}
