<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use App\Http\Controllers\Legacy\Support\LegacyDispatch;
use App\Http\Controllers\Legacy\Support\LegacyDispatched;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Routing\RouteContext;

/**
 * LegacyDispatch on real Slim 4 (design 10.2): the app builds itself and
 * adds its middleware as it always did, the recorder stops it at its
 * dispatch, and the moved handler answers inside that middleware, in Slim's
 * order, with Slim's routing deciding what reaches it.
 */
final class StranglerSlimDispatchTest extends TestCase
{
    /** @var list<string> */
    public static array $order = [];

    private int $calls = 0;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/resources/strangler/slim/LegacyDispatched.php';
        require_once dirname(__DIR__) . '/resources/strangler/slim/LegacyDispatch.php';
    }

    protected function setUp(): void
    {
        self::$order = [];
    }

    protected function tearDown(): void
    {
        LegacyDispatch::uninstall();
    }

    /** @return array{LegacyDispatch, ServerRequestInterface} the recorder and the request the app stopped with */
    private function dispatch(string $method, string $path, bool $admin = false): array
    {
        $recorder = LegacyDispatch::install();
        $app = AppFactory::create();
        $app->setBasePath('/app');
        // Added first, so it runs last, right before the handler.
        $app->add(static function (ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
            StranglerSlimDispatchTest::$order[] = 'first-added';

            return $handler->handle($request)->withHeader('X-First', 'yes');
        });
        $app->add(new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                StranglerSlimDispatchTest::$order[] = 'last-added';

                return $handler->handle($request->withAttribute('seen', 'outer'));
            }
        });
        $app->get('/items/{id}', 'NeverCalled:show');
        if ($admin) {
            // A role check: the route exists only while it holds.
            $app->get('/admin', 'NeverCalled:admin');
        }
        $app->addErrorMiddleware(false, false, false);
        $request = (new ServerRequestFactory())->createServerRequest($method, 'http://example.test/app' . $path);
        try {
            $app->handle($request);
            self::fail('the recorder lets Slim dispatch');
        } catch (LegacyDispatched $stopped) {
            return [$recorder, $stopped->request];
        } finally {
            LegacyDispatch::uninstall();
        }
    }

    private function handler(): \Closure
    {
        return function (): callable {
            return function (ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface {
                $this->calls++;
                $response->getBody()->write('item ' . ($args['id'] ?? '-') . ' attr ' . (string) $request->getAttribute('id') . ' base ' . (string) $request->getAttribute(RouteContext::BASE_PATH) . ' seen ' . (string) $request->getAttribute('seen'));

                return $response;
            };
        };
    }

    public function testTheAppStopsAtItsDispatchWithItsMiddlewareRecorded(): void
    {
        [$recorder, $request] = $this->dispatch('GET', '/items/7');

        self::assertTrue($recorder->seeded());
        self::assertInstanceOf(\Slim\App::class, $recorder->app());
        self::assertCount(3, $recorder->middleware(), 'two app middleware and the error middleware');
        self::assertSame('/app/items/7', $request->getUri()->getPath());
        self::assertSame([], self::$order, 'nothing ran yet');
    }

    public function testTheMovedHandlerAnswersInsideTheMiddlewareInSlimsOrder(): void
    {
        [$recorder, $request] = $this->dispatch('GET', '/items/7');

        $response = $recorder->run($request, $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('item 7 attr 7 base /app seen outer', (string) $response->getBody());
        self::assertSame(['last-added', 'first-added'], self::$order, 'the last added runs first, as in Slim');
        self::assertSame('yes', $response->getHeaderLine('X-First'));
    }

    public function testARouteTheRoleCheckNeverRegisteredGetsTheLegacyNotFoundAnswer(): void
    {
        [$recorder, $request] = $this->dispatch('GET', '/admin');

        $response = $recorder->run($request, $this->handler());

        self::assertSame(404, $response->getStatusCode(), 'the error middleware answers the miss');
        self::assertSame(0, $this->calls, 'the handler never runs');
        self::assertSame(['last-added', 'first-added'], self::$order, 'the app middleware still ran first, as in legacy');

        [$recorder, $request] = $this->dispatch('GET', '/admin', true);
        self::assertSame(200, $recorder->run($request, $this->handler())->getStatusCode(), 'with the check holding, the route answers');
    }

    public function testAWrongMethodIsAnsweredAsSlimAnswersIt(): void
    {
        [$recorder, $request] = $this->dispatch('POST', '/items/7');

        self::assertSame(405, $recorder->run($request, $this->handler())->getStatusCode());
        self::assertSame(0, $this->calls);
    }

    public function testAHeadAnswerHasNoBody(): void
    {
        [$recorder, $request] = $this->dispatch('HEAD', '/items/7');

        $response = $recorder->run($request, $this->handler());

        self::assertSame(1, $this->calls);
        self::assertSame('', (string) $response->getBody());
    }

    public function testAfterUninstallAppFactoryBuildsWithSlimsOwnDispatcher(): void
    {
        $this->dispatch('GET', '/items/7');

        $app = AppFactory::create();
        $app->get('/x', static function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
            $response->getBody()->write('slim');

            return $response;
        });
        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/x'));

        self::assertSame('slim', (string) $response->getBody());
    }
}
