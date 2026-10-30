<?php

declare(strict_types=1);

namespace Waynestate\Booster\Hop;

/**
 * Laravel 9 ships its own TrustProxies middleware, so the 8 to 9 hop can drop
 * fideloper/proxy. This moves the stock middleware class from the Fideloper
 * package to the framework parent and replaces the removed
 * HEADER_X_FORWARDED_ALL constant with the individual header constants. A file
 * the app customised gets left alone with a manual note instead, because its
 * proxy list may carry addresses no rule should guess at.
 */
final class TrustProxiesRewriter
{
    public const FILE = 'app/Http/Middleware/TrustProxies.php';

    private const OLD_PARENT = 'Fideloper\Proxy\TrustProxies as Middleware';

    private const NEW_PARENT = 'Illuminate\Http\Middleware\TrustProxies as Middleware';

    private const OLD_HEADERS = 'Request::HEADER_X_FORWARDED_ALL';

    private const NEW_HEADERS = 'Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_AWS_ELB';

    public function rewrite(string $appPath, bool $dryRun): TrustProxiesResult
    {
        $file = $appPath . '/' . self::FILE;
        if (!is_file($file)) {
            return new TrustProxiesResult([], [], []);
        }
        $raw = file_get_contents($file);
        if ($raw === false) {
            return new TrustProxiesResult([], [], []);
        }

        $hasParent = str_contains($raw, self::OLD_PARENT);
        $hasHeaders = str_contains($raw, self::OLD_HEADERS);
        if (!$hasParent && !$hasHeaders) {
            return new TrustProxiesResult([], [], [sprintf(
                '%s is not the stock Laravel 8 middleware, so Booster left it alone. Point it at the Illuminate\\Http\\Middleware\\TrustProxies parent and replace %s with the individual HEADER_X_FORWARDED_* constants by hand.',
                self::FILE,
                self::OLD_HEADERS,
            )]);
        }

        $new = $raw;
        if ($hasParent) {
            $new = str_replace(self::OLD_PARENT, self::NEW_PARENT, $new);
        }
        if ($hasHeaders) {
            $new = str_replace(self::OLD_HEADERS, self::NEW_HEADERS, $new);
        }
        if (!$dryRun) {
            file_put_contents($file, $new);
        }

        $change = sprintf(
            '%s %s to the framework TrustProxies middleware.',
            $dryRun ? 'Dry run: would rewrite' : 'Rewrote',
            self::FILE,
        );

        return new TrustProxiesResult(
            [$change],
            [['file' => self::FILE, 'diff' => 'rewritten from the fideloper/proxy package to the framework middleware']],
            [],
        );
    }
}
