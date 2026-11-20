<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Strangler\CakeRoutes;
use Waynestate\Booster\Strangler\CakeUnitSource;
use Waynestate\Booster\Strangler\Unit;

/**
 * The CakePHP move units (design sections 4 and 9.2): routes from
 * config/routes.php with the fallbacks spelled out, a form and the page its
 * flash message lands on moving as one unit, and the move order.
 */
final class CakeUnitSourceTest extends TestCase
{
    private const APP = __DIR__ . '/fixtures/strangler/cake-app';

    public function test_framework_is_cakephp(): void
    {
        $this->assertSame('cakephp', (new CakeUnitSource())->framework());
    }

    public function test_units_in_move_order_with_the_fallbacks_spelled_out(): void
    {
        $units = (new CakeUnitSource())->units(self::APP);

        $this->assertSame(
            ['GET /notes/hello/{name?}', 'GET /notes/me', 'GET /notes/report', 'FORM /'],
            array_map(static fn(Unit $u): string => $u->id, $units),
        );
        $this->assertSame(['page', 'page', 'page', 'write'], array_map(static fn(Unit $u): string => $u->order, $units));
    }

    public function test_a_page_unit(): void
    {
        $hello = $this->unit('GET /notes/hello/{name?}');

        $this->assertSame('route', $hello->kind);
        $this->assertSame(['GET'], $hello->methods);
        $this->assertSame('notes/hello/{name?}', $hello->uri);
        $this->assertNull($hello->name);
        $this->assertFalse($hello->needsSignIn);
        $this->assertSame('legacy/src/Controller/NotesController.php', $hello->sourceFile);
        $this->assertSame(32, $hello->sourceLine);
    }

    public function test_an_action_outside_the_unauthenticated_list_needs_sign_in(): void
    {
        $this->assertTrue($this->unit('GET /notes/me')->needsSignIn);
        $this->assertFalse($this->unit('GET /notes/report')->needsSignIn);
    }

    public function test_a_form_and_the_page_its_flash_lands_on_are_one_unit(): void
    {
        $form = $this->unit('FORM /');

        $this->assertSame('form', $form->kind);
        $this->assertSame(['GET', 'POST'], $form->methods);
        $this->assertSame('/', $form->uri);
        $this->assertSame('home', $form->name);
        // The index page reads the user, so the unit waits for Step 0.
        $this->assertTrue($form->needsSignIn);
        $this->assertSame(
            ['GET /', 'GET /notes', 'GET /notes/add', 'POST /notes/add'],
            array_map(static fn(Unit $u): string => $u->id, $form->parts),
        );
    }

    public function test_the_routes_reader_expands_resources_and_reverses_urls(): void
    {
        $routes = CakeRoutes::read(<<<'PHP'
<?php
return function ($routes) {
    $routes->scope('/', function ($builder) {
        $builder->connect('/articles/:slug', ['controller' => 'Articles', 'action' => 'view'])->setPass(['slug']);
        $builder->resources('Tags');
        $builder->fallbacks();
    });
};
PHP);

        $this->assertSame(
            ['/articles/:slug', '/tags', '/tags', '/tags/{id}', '/tags/{id}', '/tags/{id}', '/tags/{id}'],
            array_column($routes->routes, 'path'),
        );
        $this->assertSame("'/articles/' . rawurlencode((string) (\$slug))", $routes->reverse('Articles', 'view', null, ['$slug']));
        $this->assertSame("'/users/edit/' . rawurlencode((string) (5))", $routes->reverse('Users', 'edit', null, ['5']));
    }

    public function test_commented_code_is_not_read(): void
    {
        // The fixture's routes.php has a commented-out connect() line.
        $paths = array_map(static fn(Unit $u): string => $u->uri, (new CakeUnitSource())->units(self::APP));

        $this->assertNotContains('old', $paths);
    }

    private function unit(string $id): Unit
    {
        foreach ((new CakeUnitSource())->units(self::APP) as $unit) {
            if ($unit->id === $id) {
                return $unit;
            }
        }
        $this->fail('no unit ' . $id);
    }
}
