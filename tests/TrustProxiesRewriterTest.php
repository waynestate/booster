<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Hop\TrustProxiesResult;
use Waynestate\Booster\Hop\TrustProxiesRewriter;

final class TrustProxiesRewriterTest extends TestCase
{
    /** The stock Laravel 8 middleware, as the framework shipped it. */
    private const STOCK = <<<'PHP'
    <?php

    namespace App\Http\Middleware;

    use Fideloper\Proxy\TrustProxies as Middleware;
    use Illuminate\Http\Request;

    class TrustProxies extends Middleware
    {
        /**
         * The trusted proxies for this application.
         *
         * @var array<int, string>|string|null
         */
        protected $proxies;

        /**
         * The headers that should be used to detect proxies.
         *
         * @var int
         */
        protected $headers = Request::HEADER_X_FORWARDED_ALL;
    }
    PHP;

    /** An app customised its proxy list, so no rule may guess at it. */
    private const CUSTOM = <<<'PHP'
    <?php

    namespace App\Http\Middleware;

    use App\Http\Middleware\BaseProxy;
    use Illuminate\Http\Request;

    class TrustProxies extends BaseProxy
    {
        protected $proxies = '10.0.0.0/8';

        protected $headers = Request::HEADER_X_FORWARDED_FOR;
    }
    PHP;

    /** A file the app already moved to the framework parent. */
    private const MIGRATED = <<<'PHP'
    <?php

    namespace App\Http\Middleware;

    use Illuminate\Http\Middleware\TrustProxies as Middleware;
    use Illuminate\Http\Request;

    class TrustProxies extends Middleware
    {
        protected $proxies;

        protected $headers = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_AWS_ELB;
    }
    PHP;

    private function appWith(?string $middleware): string
    {
        $dir = $this->tempDir();
        if ($middleware !== null) {
            mkdir($dir . '/app/Http/Middleware', 0775, true);
            file_put_contents($dir . '/app/Http/Middleware/TrustProxies.php', $middleware);
        }

        return $dir;
    }

    public function test_a_dry_run_reports_the_rewrite_without_touching_the_file(): void
    {
        $dir = $this->appWith(self::STOCK);
        $result = (new TrustProxiesRewriter())->rewrite($dir, true);

        self::assertSame(self::STOCK, (string) file_get_contents($dir . '/app/Http/Middleware/TrustProxies.php'));
        self::assertSame(['Dry run: would rewrite app/Http/Middleware/TrustProxies.php to the framework TrustProxies middleware.'], $result->changes);
        self::assertSame([['file' => 'app/Http/Middleware/TrustProxies.php', 'diff' => 'rewritten from the fideloper/proxy package to the framework middleware']], $result->files);
        self::assertSame([], $result->manual);
        self::assertSame(1, $result->changedCount());
    }

    public function test_an_apply_rewrites_the_parent_and_the_header_mask(): void
    {
        $dir = $this->appWith(self::STOCK);
        $result = (new TrustProxiesRewriter())->rewrite($dir, false);
        $content = (string) file_get_contents($dir . '/app/Http/Middleware/TrustProxies.php');

        self::assertStringContainsString('Illuminate\Http\Middleware\TrustProxies as Middleware', $content);
        self::assertStringContainsString('Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_AWS_ELB', $content);
        self::assertStringNotContainsString('Fideloper', $content);
        self::assertStringNotContainsString('HEADER_X_FORWARDED_ALL', $content);
        self::assertSame(['Rewrote app/Http/Middleware/TrustProxies.php to the framework TrustProxies middleware.'], $result->changes);
        self::assertCount(1, $result->files);
        self::assertSame([], $result->manual);
    }

    public function test_a_customised_middleware_is_left_alone_with_a_manual_note(): void
    {
        $dir = $this->appWith(self::CUSTOM);
        $result = (new TrustProxiesRewriter())->rewrite($dir, false);
        $content = (string) file_get_contents($dir . '/app/Http/Middleware/TrustProxies.php');

        self::assertSame(self::CUSTOM, $content);
        self::assertSame([], $result->changes);
        self::assertSame([], $result->files);
        self::assertCount(1, $result->manual);
        self::assertStringContainsString('app/Http/Middleware/TrustProxies.php is not the stock Laravel 8 middleware, so Booster left it alone.', $result->manual[0]);
        self::assertStringContainsString('Request::HEADER_X_FORWARDED_ALL', $result->manual[0]);
    }

    public function test_an_already_migrated_middleware_is_a_silent_no_op(): void
    {
        $dir = $this->appWith(self::MIGRATED);
        $result = (new TrustProxiesRewriter())->rewrite($dir, false);
        $content = (string) file_get_contents($dir . '/app/Http/Middleware/TrustProxies.php');

        self::assertSame(self::MIGRATED, $content);
        self::assertSame([], $result->changes);
        self::assertSame([], $result->files);
        self::assertSame([], $result->manual);
        self::assertSame(0, $result->changedCount());
    }

    public function test_a_missing_middleware_changes_nothing_and_notes_nothing(): void
    {
        $dir = $this->appWith(null);
        $result = (new TrustProxiesRewriter())->rewrite($dir, false);

        self::assertInstanceOf(TrustProxiesResult::class, $result);
        self::assertSame([], $result->changes);
        self::assertSame([], $result->files);
        self::assertSame([], $result->manual);
        self::assertSame(0, $result->changedCount());
    }

    public function test_a_half_upgraded_file_only_loses_the_removed_constant(): void
    {
        $half = str_replace('Fideloper\Proxy\TrustProxies as Middleware', 'Illuminate\Http\Middleware\TrustProxies as Middleware', self::STOCK);
        $dir = $this->appWith($half);
        $result = (new TrustProxiesRewriter())->rewrite($dir, false);
        $content = (string) file_get_contents($dir . '/app/Http/Middleware/TrustProxies.php');

        self::assertStringContainsString('Illuminate\Http\Middleware\TrustProxies as Middleware', $content);
        self::assertStringContainsString('Request::HEADER_X_FORWARDED_AWS_ELB', $content);
        self::assertStringNotContainsString('HEADER_X_FORWARDED_ALL', $content);
        self::assertCount(1, $result->changes);
    }
}
