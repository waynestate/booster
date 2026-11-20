<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use App\Http\Controllers\Legacy\Support\LegacyTwigExtension;
use PHPUnit\Framework\TestCase;

/**
 * The strangler's url_for and friends for legacy Twig templates rendered by
 * the Laravel half (design 10.2), answering as slim/twig-view does. Twig is
 * not one of Booster's packages, so its two base classes are stubbed here.
 */
final class StranglerSlimTwigExtensionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!class_exists('Twig\Extension\AbstractExtension')) {
            eval('namespace Twig\Extension; abstract class AbstractExtension {}');
        }
        if (!class_exists('Twig\TwigFunction')) {
            eval('namespace Twig; final class TwigFunction { public function __construct(private string $name, private mixed $callable = null) {} public function getName(): string { return $this->name; } }');
        }
        if (!class_exists(LegacyTwigExtension::class, false)) {
            require_once dirname(__DIR__) . '/resources/strangler/slim/LegacyTwigExtension.php';
        }
    }

    private static function extension(string $path = '/'): LegacyTwigExtension
    {
        return new LegacyTwigExtension([
            'login' => '/[login[/]]',
            'item' => '/items/{id:[0-9]+}[/]',
            'page' => '/page[/{n}]',
        ], '/app', $path, 'https://example.test');
    }

    public function testUrlForTakesTheLongestFormWithData(): void
    {
        $ext = self::extension();

        self::assertSame('/app/login/', $ext->urlFor('login'));
        self::assertSame('/app/items/7/', $ext->urlFor('item', ['id' => 7]));
        self::assertSame('/app/page', $ext->urlFor('page'));
        self::assertSame('/app/page/2', $ext->urlFor('page', ['n' => 2]));
        self::assertSame('/app/page/2?q=a+b', $ext->urlFor('page', ['n' => 2], ['q' => 'a b']));
        self::assertSame('https://example.test/app/login/', $ext->fullUrlFor('login'));
    }

    public function testIsCurrentUrl(): void
    {
        self::assertTrue(self::extension('/login/')->isCurrentUrl('login'));
        self::assertFalse(self::extension('/login')->isCurrentUrl('login'));
        self::assertTrue(self::extension('/items/7/')->isCurrentUrl('item', ['id' => 7]));
    }

    public function testUnknownNamesAndMissingDataFailAsSlimDoes(): void
    {
        try {
            self::extension()->urlFor('nope');
            self::fail('no exception');
        } catch (\RuntimeException $e) {
            self::assertSame('Named route does not exist for name: nope', $e->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        self::extension()->urlFor('item');
    }

    public function testFunctionsAndForms(): void
    {
        $names = array_map(static fn($f): string => $f->getName(), self::extension()->getFunctions());

        self::assertSame(['url_for', 'full_url_for', 'is_current_url', 'current_url', 'get_uri', 'base_path'], $names);
        self::assertSame(['/', '/login', '/login/'], LegacyTwigExtension::forms('/[login[/]]'));
    }
}
