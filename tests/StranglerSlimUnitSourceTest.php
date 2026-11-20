<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use PHPUnit\Framework\TestCase;
use Waynestate\Booster\Strangler\SlimRouteScanner;
use Waynestate\Booster\Strangler\SlimUnitSource;
use Waynestate\Booster\Strangler\Unit;

/** The Slim UnitSource: routes, groups, forms and move order (design 4, 9.2). */
final class StranglerSlimUnitSourceTest extends TestCase
{
    private const APP = __DIR__ . '/fixtures/strangler/slim-small';

    /** @return array<string, Unit> */
    private static function units(): array
    {
        $byId = [];
        foreach ((new SlimUnitSource())->units(self::APP) as $unit) {
            $byId[$unit->id] = $unit;
        }

        return $byId;
    }

    public function testFramework(): void
    {
        self::assertSame('slim', (new SlimUnitSource())->framework());
    }

    public function testListsEveryRouteAsAUnit(): void
    {
        self::assertSame(
            ['GET /items', 'GET /page', 'GET /page/{n}', 'GET /', 'GET /items/{id}', 'FORM /contact', 'GET /admin/users', 'OPTIONS /{routes}'],
            array_keys(self::units()),
        );
    }

    public function testMoveOrderIsStatelessPageWriteSignInWithTheCatchAllLast(): void
    {
        $units = array_values(self::units());
        $orders = array_map(static fn(Unit $u): string => $u->order, $units);

        self::assertSame(['stateless', 'stateless', 'stateless', 'page', 'page', 'write', 'signin', 'page'], $orders);
        self::assertSame('OPTIONS /{routes}', end($units)->id);
    }

    public function testGroupPrefixAndNameAndSource(): void
    {
        $unit = self::units()['GET /items'];

        self::assertSame(['GET'], $unit->methods);
        self::assertSame('items', $unit->uri);
        self::assertSame('items', $unit->name);
        self::assertSame('legacy/app/routes.php', $unit->sourceFile);
        self::assertFalse($unit->needsSignIn);
    }

    public function testRegexPlaceholderWithSpacesAndTrailingSlash(): void
    {
        // The route table's form: the regex inline, the trailing [/] kept.
        self::assertSame('items/{id:[0-9]+}[/]', self::units()['GET /items/{id}']->uri);
    }

    public function testAnOptionalSegmentIsOneUnitPerPathNamedOnTheLongest(): void
    {
        $units = self::units();

        self::assertSame('page', $units['GET /page']->uri);
        self::assertSame('page/{n}', $units['GET /page/{n}']->uri);
        self::assertSame('legacy/app/routes.php', $units['GET /page']->sourceFile);
        self::assertSame($units['GET /page']->sourceLine, $units['GET /page/{n}']->sourceLine);
    }

    public function testAFormGetAndPostMoveTogether(): void
    {
        $form = self::units()['FORM /contact'];

        self::assertSame(['GET', 'POST'], $form->methods);
        self::assertSame(['GET /contact', 'POST /contact'], array_map(static fn(Unit $u): string => $u->id, $form->parts));
        self::assertSame('write', $form->order);
    }

    public function testRoutesBehindARoleCheckThroughAStaticAddRoutesNeedSignIn(): void
    {
        $unit = self::units()['GET /admin/users'];

        self::assertTrue($unit->needsSignIn);
        self::assertSame('signin', $unit->order);
        self::assertSame('legacy/src/Routes/AdminRoutes.php', $unit->sourceFile);
    }

    public function testScannerReadsHandlersOfEveryShape(): void
    {
        $routes = (new SlimRouteScanner())->scan(SlimUnitSource::legacyCodes(self::APP));
        $byPattern = [];
        foreach ($routes as $route) {
            $byPattern[implode('|', $route->methods) . ' ' . $route->pattern] = $route;
        }

        self::assertSame('closure', $byPattern['GET /']->handlerKind);
        self::assertSame('home', $byPattern['GET /']->name);
        self::assertSame(['App\\Action\\ViewItem', 'show'], [$byPattern['GET /items/{ id:[0-9]+ }[/]']->handlerClass, $byPattern['GET /items/{ id:[0-9]+ }[/]']->handlerMethod]);
        self::assertSame('App\\Action\\ListItems', $byPattern['GET /items']->handlerClass);
        self::assertNull($byPattern['GET /items']->handlerMethod);
        self::assertSame(["(\$_SESSION['role'] ?? '') === 'admin'"], $byPattern['GET /admin/users']->guards);
    }

    public function testStatefulAndJsonHeuristics(): void
    {
        self::assertSame('stateless', SlimUnitSource::order(['GET'], false, 'echo json_encode($rows);'));
        self::assertSame('page', SlimUnitSource::order(['GET'], false, '$_SESSION["x"] = 1; return $response->withJson($x);'));
        self::assertSame('write', SlimUnitSource::order(['POST'], false, ''));
        self::assertSame('signin', SlimUnitSource::order(['GET'], true, ''));
    }

    public function testAnAppWithoutLegacyHasNoUnits(): void
    {
        self::assertSame([], (new SlimUnitSource())->units(sys_get_temp_dir() . '/booster-no-such-app-' . getmypid()));
    }
}
