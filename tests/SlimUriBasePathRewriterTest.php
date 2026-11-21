<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Run\Slim\UriBasePathRewriter;

/**
 * Slim 3's Uri::getBasePath() on Slim 4: the call on a request variable moves
 * to the base path the routing glue puts on the request; every other Uri
 * read becomes a MANUAL line; Slim 4's own getBasePath() calls stay.
 */
final class SlimUriBasePathRewriterTest extends TestCase
{
    /** The Slim 3 fleet app shape: a controller that redirects to the base path. */
    private const CONTROLLER = <<<'PHP'
        <?php

        namespace Acme\Controller;

        final class Login
        {
            public function show($request, $response)
            {
                // $request->getUri()->getBasePath() stays in this comment.
                $label = '$request->getUri()->getBasePath()';
                if ($this->user->isAuth()) {
                    return $this->redirect($response, $request->getUri()->getBasePath());
                }

                return $response->withRedirect($req?->getUri()->getBasePath() . '/');
            }
        }

        PHP;

    public function test_the_request_variable_reads_the_glue_attribute(): void
    {
        $result = (new UriBasePathRewriter())->rewrite(self::CONTROLLER);

        self::assertSame(2, $result['rewritten']);
        self::assertSame([], $result['manual']);
        self::assertStringContainsString("return \$this->redirect(\$response, \$request->getAttribute(\\Slim\\Routing\\RouteContext::BASE_PATH, ''));", $result['code']);
        self::assertStringContainsString("return \$response->withRedirect(\$req?->getAttribute(\\Slim\\Routing\\RouteContext::BASE_PATH, '') . '/');", $result['code']);
        // Comments and strings are never touched.
        self::assertStringContainsString('// $request->getUri()->getBasePath() stays in this comment.', $result['code']);
        self::assertStringContainsString("\$label = '\$request->getUri()->getBasePath()';", $result['code']);
        \PhpToken::tokenize($result['code'], TOKEN_PARSE);

        // A second run changes nothing.
        $again = (new UriBasePathRewriter())->rewrite($result['code']);
        self::assertSame($result['code'], $again['code']);
        self::assertSame(0, $again['rewritten']);
    }

    public function test_a_call_it_cannot_place_is_manual(): void
    {
        $php = <<<'PHP'
            <?php
            $a = $this->request->getUri()->getBasePath();
            $b = $container['request']->getUri()->getBasePath();
            $uri = $request->getUri();
            $c = $uri->getBasePath();
            $d = $this->uri->getBasePath();
            $e = $request->getUri()->getBaseUrl();
            $f = $request->getUri(true)->getBasePath();
            PHP;
        $result = (new UriBasePathRewriter())->rewrite($php);

        self::assertSame($php, $result['code']);
        self::assertSame(0, $result['rewritten']);
        self::assertSame([
            "MANUAL: line 2: Slim 4's Uri has no getBasePath(); read \$request->getAttribute(\\Slim\\Routing\\RouteContext::BASE_PATH, '') on the request the route or middleware gets, or \$app->getBasePath().",
            "MANUAL: line 3: Slim 4's Uri has no getBasePath(); read \$request->getAttribute(\\Slim\\Routing\\RouteContext::BASE_PATH, '') on the request the route or middleware gets, or \$app->getBasePath().",
            "MANUAL: line 5: Slim 4's Uri has no getBasePath(); read \$request->getAttribute(\\Slim\\Routing\\RouteContext::BASE_PATH, '') on the request the route or middleware gets, or \$app->getBasePath().",
            "MANUAL: line 6: Slim 4's Uri has no getBasePath(); read \$request->getAttribute(\\Slim\\Routing\\RouteContext::BASE_PATH, '') on the request the route or middleware gets, or \$app->getBasePath().",
            "MANUAL: line 7: Slim 4's Uri has no getBaseUrl(); build it from the request's scheme and host plus \$request->getAttribute(\\Slim\\Routing\\RouteContext::BASE_PATH, '').",
            "MANUAL: line 8: Slim 4's Uri has no getBasePath(); read \$request->getAttribute(\\Slim\\Routing\\RouteContext::BASE_PATH, '') on the request the route or middleware gets, or \$app->getBasePath().",
        ], $result['manual']);
    }

    public function test_slim_4s_own_base_path_calls_stay(): void
    {
        $php = <<<'PHP'
            <?php
            $a = $app->getBasePath();
            $b = \Slim\Routing\RouteContext::fromRequest($request)->getBasePath();
            $c = $routeContext->getBasePath();
            $d = $this->app->getRouteCollector()->getBasePath();
            PHP;
        $result = (new UriBasePathRewriter())->rewrite($php);

        self::assertSame($php, $result['code']);
        self::assertSame([], $result['manual']);
    }
}
