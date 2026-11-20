<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waynestate\Booster\Strangler\SlimPattern;

/** Slim route patterns as Laravel URIs (design 10.4). */
final class StranglerSlimPatternTest extends TestCase
{
    /** @return iterable<string, array{string, string, array<string, string>}> */
    public static function converted(): iterable
    {
        yield 'root' => ['/', '/', []];
        yield 'plain' => ['/users', 'users', []];
        yield 'placeholder' => ['/users/{id}', 'users/{id}', []];
        yield 'spaces in a placeholder are trimmed' => ['/users/{ id }', 'users/{id}', []];
        yield 'trailing optional slash is dropped' => ['/users[/]', 'users', []];
        yield 'regex becomes a where' => ['/items/{ id:[0-9]+ }[/]', 'items/{id}', ['id' => '[0-9]+']];
        yield 'the default segment regex is no where' => ['/u/{name:[^/]+}', 'u/{name}', []];
    }

    /** @param array<string, string> $wheres */
    #[DataProvider('converted')]
    public function testConvertsPatterns(string $pattern, string $uri, array $wheres): void
    {
        $p = SlimPattern::convert($pattern);

        self::assertNull($p->refusal);
        self::assertSame($uri, $p->uri);
        self::assertSame($wheres, $p->wheres);
    }

    /** @return iterable<string, array{string}> */
    public static function refused(): iterable
    {
        yield 'optional segment' => ['/page[/{n}]'];
        yield 'optional group' => ['/[login[/]]'];
        yield 'nameless placeholder' => ['/user/[{:uid}[/]]'];
        yield 'duplicate name' => ['/a/{id}/b/{id}'];
    }

    #[DataProvider('refused')]
    public function testRefusesWhatLaravelCannotSay(string $pattern): void
    {
        self::assertNotNull(SlimPattern::convert($pattern)->refusal);
    }

    public function testCatchAll(): void
    {
        self::assertTrue(SlimPattern::isCatchAll('/{routes:.*}'));
        self::assertTrue(SlimPattern::isCatchAll('/{ any:.+ }'));
        self::assertFalse(SlimPattern::isCatchAll('/{id:[0-9]+}'));
    }

    public function testRegexMatchesLikeTheLaravelRoute(): void
    {
        $regex = SlimPattern::regex('items/{id}', ['id' => '[0-9]+']);

        self::assertSame(1, preg_match($regex, '/items/12'));
        self::assertSame(0, preg_match($regex, '/items/abc'));
        self::assertSame(0, preg_match($regex, '/items/12/more'));
        self::assertSame(1, preg_match(SlimPattern::regex('/', []), '/'));
    }

    public function testIdPath(): void
    {
        self::assertSame('/', SlimPattern::idPath('/'));
        self::assertSame('/users/{id}', SlimPattern::idPath('users/{id}'));
    }
}
