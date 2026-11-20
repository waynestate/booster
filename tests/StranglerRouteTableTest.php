<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Strangler\RouteTable;
use Waynestate\Booster\Strangler\Unit;
use Waynestate\Booster\ToolError;

final class StranglerRouteTableTest extends TestCase
{
    private static function route(string $id, array $methods, string $uri): Unit
    {
        return new Unit($id, 'route', $methods, $uri, null, 'page', false, 'app/routes.php', 12);
    }

    public function test_compile_matches_what_the_legacy_routers_matched(): void
    {
        $cases = [
            // uri, path, matches
            ['users', '/users', true],
            ['/users', '/users', true],
            ['users', '/users/', false],
            ['users', '/Users', false],
            ['/', '/', true],
            ['', '/', true],
            ['/', '/x', false],
            ['users/{id}', '/users/7', true],
            ['users/{id}', '/users/', false],
            ['users/{id}', '/users/7/edit', false],
            ['users/{ id }', '/users/7', true],
            ['users/{id:\d+}', '/users/7', true],
            ['users/{id:\d+}', '/users/abc', false],
            ['files/{path:.*}', '/files/a/b/c', true],
            ['files/{path:[a-z]{2}}', '/files/ab', true],
            ['files/{path:[a-z]{2}}', '/files/abc', false],
            ['posts/{slug?}', '/posts', true],
            ['posts/{slug?}', '/posts/hello', true],
            ['posts/{slug?}', '/posts/hello/x', false],
            ['{page?}', '/', true],
            ['{page?}', '/about', true],
            ['users[/]', '/users', true],
            ['users[/]', '/users/', true],
            ['a.b/{x}', '/a.b/1', true],
            ['a.b/{x}', '/aXb/1', false],
            ['hash#tag', '/hash#tag', true],
            ['articles/{id}.{ext}', '/articles/5.json', true],
        ];
        foreach ($cases as [$uri, $path, $matches]) {
            $regex = RouteTable::compile($uri);
            self::assertSame($matches, preg_match($regex, $path) === 1, sprintf('%s against %s (%s)', $uri, $path, $regex));
        }
    }

    public function test_compile_refuses_what_it_cannot_express(): void
    {
        foreach (['posts/{slug?}/edit', 'users/{id', 'users/{1x}', 'users/{id:(}'] as $uri) {
            try {
                RouteTable::compile($uri);
                self::fail('compile accepted ' . $uri);
            } catch (ToolError $e) {
                self::assertStringContainsString($uri, $e->getMessage());
            }
        }
    }

    public function test_a_missing_table_loads_empty_and_saves_a_file_the_dispatcher_can_require(): void
    {
        $dir = $this->tempDir();
        $table = RouteTable::load($dir);
        self::assertSame([], $table->rows());
        $table->save();

        $file = $dir . '/' . RouteTable::FILE;
        self::assertFileExists($file);
        self::assertSame([], require $file);
        self::assertStringContainsString('written by Booster', (string) file_get_contents($file));
    }

    public function test_add_has_remove_and_a_round_trip_through_the_file(): void
    {
        $dir = $this->tempDir();
        $table = RouteTable::load($dir);
        $table->add(self::route('GET /users', ['GET'], 'users'));
        $table->add(self::route("GET /users/{id}", ['get'], "users/{id:\\d+}"));
        $table->add(new Unit('FORM /articles/add', 'form', ['GET', 'POST'], 'articles/add', 'articles.add', 'write', true, 'config/routes.php', 30, [
            self::route('GET /articles/add', ['GET'], 'articles/add'),
            self::route('POST /articles', ['POST'], 'articles'),
        ]));
        self::assertTrue($table->has('GET /users'));
        self::assertTrue($table->has('FORM /articles/add'));
        self::assertFalse($table->has('GET /nope'));
        self::assertSame(['GET /users', 'GET /users/{id}', 'FORM /articles/add'], $table->units());
        self::assertCount(4, $table->rows(), 'a form writes one row per part');
        $table->save();

        $required = require $dir . '/' . RouteTable::FILE;
        $loaded = RouteTable::load($dir);
        self::assertSame($table->rows(), $loaded->rows());
        self::assertSame($required, $loaded->rows(), 'what Booster reads is what the dispatcher requires');
        self::assertSame(['GET'], $loaded->rows()[1]['methods'], 'methods are upper case');
        self::assertSame(1, preg_match($loaded->rows()[1]['regex'], '/users/42'));

        // Adding a unit again replaces its rows; removing drops every part.
        $loaded->add(self::route('GET /users', ['GET', 'HEAD'], 'people'));
        self::assertSame(['GET /users/{id}', 'FORM /articles/add', 'GET /users'], $loaded->units());
        $loaded->remove('FORM /articles/add');
        self::assertFalse($loaded->has('FORM /articles/add'));
        self::assertCount(2, $loaded->rows());
        $loaded->save();
        self::assertSame($loaded->rows(), RouteTable::load($dir)->rows());
    }

    public function test_quotes_and_backslashes_survive_the_file(): void
    {
        $dir = $this->tempDir();
        $table = RouteTable::load($dir);
        $table->add(self::route("GET /it's/{x}", ['GET'], "it's/{x:[\\w']+}"));
        $table->save();
        $rows = RouteTable::load($dir)->rows();
        self::assertSame("GET /it's/{x}", $rows[0]['unit']);
        self::assertSame(1, preg_match($rows[0]['regex'], "/it's/o'k"));
        self::assertSame($rows, require $dir . '/' . RouteTable::FILE);
    }

    public function test_load_never_runs_the_file(): void
    {
        $dir = $this->tempDir();
        mkdir($dir . '/bootstrap');
        $marker = $dir . '/ran';
        file_put_contents($dir . '/' . RouteTable::FILE, "<?php\nreturn [['unit' => 'x', 'methods' => ['GET'], 'regex' => touch('" . $marker . "') ? '#^/$#' : '']];\n");
        try {
            RouteTable::load($dir);
            self::fail('load accepted a table with a function call');
        } catch (ToolError $e) {
            self::assertStringContainsString('booster-moved.php', $e->getMessage());
        }
        self::assertFileDoesNotExist($marker);
    }

    public function test_load_refuses_a_mangled_table(): void
    {
        $dir = $this->tempDir();
        mkdir($dir . '/bootstrap');
        $bad = [
            "<?php\nreturn [['unit' => 'x', 'methods' => ['get'], 'regex' => '#^/$#']];\n",
            "<?php\nreturn [['unit' => 'x', 'methods' => ['GET'], 'regex' => '#^/(']];\n",
            "<?php\nreturn [['unit' => 'x', 'methods' => ['GET']]];\n",
            "<?php\nreturn ['unit' => 'x'];\n",
            "<?php\n\$x = 1;\n",
            "<?php\nreturn [\"x\"];\n",
        ];
        foreach ($bad as $source) {
            file_put_contents($dir . '/' . RouteTable::FILE, $source);
            try {
                RouteTable::load($dir);
                self::fail('load accepted: ' . $source);
            } catch (ToolError) {
                self::addToAssertionCount(1);
            }
        }
    }
}
