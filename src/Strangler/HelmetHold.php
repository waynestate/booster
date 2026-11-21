<?php

declare(strict_types=1);

namespace Waynestate\Booster\Strangler;

use Waynestate\Booster\ToolError;

/**
 * Keeps HELMET's response middleware off the moved routes while the
 * strangler runs (design section 4: a moved unit keeps the legacy answer's
 * bytes, headers included). Sourdough's Laravel half runs HELMET globally:
 * SecurityHeaders adds a nonce CSP and X-Frame-Options (which would also
 * block a moved legacy page's inline scripts), InjectRibbon edits HTML, and
 * RequestForgeryGuard (when on) checks a token the legacy form never sent.
 * A route cannot skip global middleware, so the first move puts
 * SecurityHeaders in the `web` group (HELMET's own "scope" setting) and
 * every moved route skips the three. The Laravel half's own pages keep all
 * of it. Turning the headers on for moved pages is the owner's change, tested
 * on its own: HELMET's helmet, held at the door, not thrown away.
 */
final class HelmetHold
{
    /** The route-level skip each moved route carries. */
    public const SKIP = "\n    ->withoutMiddleware([\\Waynestate\\Helmet\\Laravel\\SecurityHeaders::class, \\Waynestate\\Helmet\\Laravel\\InjectRibbon::class, \\Waynestate\\Helmet\\Laravel\\RequestForgeryGuard::class])";

    public const FILE = 'config/helmet.php';

    public const MANUAL = "config/helmet.php sets HELMET's scope to web and moved routes skip SecurityHeaders, InjectRibbon and RequestForgeryGuard, so they answer with the legacy half's headers (no CSP or X-Frame-Options yet); once the units are on Laravel, drop the route skips one at a time and check each page under HELMET's CSP";

    private const SCOPE_LINE = "    // booster:strangler HELMET's headers run in the web group, so moved routes can skip them (HelmetHold).\n    'scope' => 'web',\n";

    /**
     * config/helmet.php names scope "web". Returns whether it changed the
     * file; false when it already does or the app has no HELMET config.
     */
    public static function scopeToWeb(string $appRoot): bool
    {
        $path = rtrim($appRoot, '/\\') . '/' . self::FILE;
        if (!is_file($path)) {
            return false;
        }
        $text = (string) file_get_contents($path);
        $new = self::withWebScope($text);
        if ($new === $text) {
            return false;
        }
        file_put_contents($path, $new);

        return true;
    }

    /** $text (a config/helmet.php) with scope "web". Exposed for tests. */
    public static function withWebScope(string $text): string
    {
        if (preg_match("/^[ \\t]*'scope'\\s*=>\\s*'web'\\s*,/m", $text) === 1) {
            return $text;
        }
        if (preg_match("/^[ \\t]*'scope'\\s*=>[^\\n]*\\n/m", $text) === 1) {
            return (string) preg_replace("/^[ \\t]*'scope'\\s*=>[^\\n]*\\n/m", self::SCOPE_LINE, $text, 1);
        }
        $at = strrpos($text, "\nreturn [");
        $eol = $at === false ? false : strpos($text, "\n", $at + 1);
        if ($eol === false) {
            throw new ToolError(self::FILE . ' has no top-level "return [" line, so the strangler cannot set HELMET\'s scope to web; add \'scope\' => \'web\' to it by hand and run again.');
        }

        return substr($text, 0, $eol + 1) . self::SCOPE_LINE . substr($text, $eol + 1);
    }
}
