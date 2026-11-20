<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Run\Slim\TwigNameRewriter;

/**
 * Bug S8 (testeval): the Slim 3 to 4 hop moves Twig to 3, which has no
 * Twig_Environment, Twig_SimpleFilter or Twig_Extension, and Twig-View 3,
 * whose Twig constructor takes a loader, so every page died.
 */
final class SlimTwigNameRewriterTest extends TestCase
{
    public function test_rectors_twig_map_is_read_from_the_rector_booster_ships(): void
    {
        $map = TwigNameRewriter::rectorMap();

        self::assertGreaterThan(100, count($map), 'rector-symfony\'s Twig set must still list the underscore names');
        self::assertSame('Twig\\Environment', $map['twig_environment'] ?? null);
        self::assertSame('Twig\\Loader\\FilesystemLoader', $map['twig_loader_filesystem'] ?? null);
        self::assertSame('Twig\\Extension\\AbstractExtension', $map['twig_extension'] ?? null);
        self::assertSame('Twig\\TwigFunction', $map['twig_simplefunction'] ?? null);
        self::assertSame('Twig\\TwigFilter', $map['twig_simplefilter'] ?? null);
        self::assertSame('Twig\\TwigFunction', $map['twig_function'] ?? null, 'a namespaced target wins over a Twig 1 detour');
        self::assertSame([], TwigNameRewriter::rectorMap($this->tempDir() . '/missing.php'));
    }

    public function test_imports_keep_their_names_and_global_names_move(): void
    {
        $extension = <<<'PHP'
            <?php
            namespace App;

            use Twig_SimpleFilter;
            use Twig_SimpleFunction;

            /** Twig_Extension in a comment stays. */
            class TwigExtension extends \Twig_Extension implements \Twig_Extension_GlobalsInterface
            {
                public function getFunctions()
                {
                    return [new Twig_SimpleFunction('dtformat', [$this, 'dtformat'])];
                }

                public function getFilters()
                {
                    return [new Twig_SimpleFilter('implode', 'implode'), 'Twig_SimpleFilter'];
                }
            }
            PHP;
        $result = (new TwigNameRewriter())->rewrite($extension);

        \PhpToken::tokenize($result['code'], TOKEN_PARSE);
        self::assertStringContainsString("use Twig\\TwigFilter as Twig_SimpleFilter;\nuse Twig\\TwigFunction as Twig_SimpleFunction;\n", $result['code']);
        self::assertStringContainsString('class TwigExtension extends \\Twig\\Extension\\AbstractExtension implements \\Twig\\Extension\\GlobalsInterface', $result['code']);
        self::assertStringContainsString("new Twig_SimpleFunction('dtformat'", $result['code']);
        self::assertStringContainsString("'Twig_SimpleFilter'];", $result['code'], 'strings stay');
        self::assertStringContainsString('/** Twig_Extension in a comment stays. */', $result['code']);
        self::assertSame(4, $result['renamed']);
        self::assertSame([], $result['unknown']);
        self::assertSame($result['code'], (new TwigNameRewriter())->rewrite($result['code'])['code'], 'a second run changes nothing');
    }

    public function test_a_file_with_no_namespace_and_twig_views_constructor(): void
    {
        // testeval's config/core.php: container entries in a plain PHP file.
        $core = <<<'PHP'
            <?php
            return [
                'notifier' => function ($c) {
                    $view = new Twig_Environment(new Twig_Loader_Filesystem(TEMPLATE_PATH));
                    $view->addExtension(new App\TwigExtension());
                    if ($view instanceof Twig_Environment) {
                        Twig_Unknown_Thing::make();
                    }

                    return $view;
                },
                'view' => function ($c) {
                    $view = new Slim\Views\Twig(TEMPLATE_PATH, ['cache' => CACHE_PATH]);

                    return $view;
                },
            ];
            PHP;
        $result = (new TwigNameRewriter())->rewrite($core);

        \PhpToken::tokenize($result['code'], TOKEN_PARSE);
        self::assertStringContainsString('$view = new \\Twig\\Environment(new \\Twig\\Loader\\FilesystemLoader(TEMPLATE_PATH));', $result['code']);
        self::assertStringContainsString('if ($view instanceof \\Twig\\Environment) {', $result['code']);
        self::assertStringContainsString('$view = Slim\\Views\\Twig::create(TEMPLATE_PATH, [\'cache\' => CACHE_PATH]);', $result['code']);
        self::assertStringContainsString('new App\\TwigExtension()', $result['code']);
        self::assertSame([7], $result['unknown']);
        self::assertSame(4, $result['renamed']);

        // Through an import, in a namespaced file.
        $namespaced = "<?php\nnamespace App;\n\nuse Slim\\Views\\Twig;\n\n\$view = new Twig('templates', []);\n\$other = new Twig_Environment();\n";
        $moved = (new TwigNameRewriter())->rewrite($namespaced);
        self::assertStringContainsString("\$view = Twig::create('templates', []);", $moved['code']);
        self::assertStringContainsString('new Twig_Environment();', $moved['code'], 'a bare name in a namespace is the app\'s own class');
    }
}
