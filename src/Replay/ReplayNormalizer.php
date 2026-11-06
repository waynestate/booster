<?php

declare(strict_types=1);

namespace Waynestate\Booster\Replay;

use Waynestate\Booster\ToolError;

/**
 * One rule that hides a volatile part of an answer before the comparison: a
 * regular expression and its replacement, applied to the body, the compared
 * headers, or both. The presets cover what changes on every request in most
 * PHP apps; anything else the request file declares itself.
 */
final readonly class ReplayNormalizer
{
    public const IN_BODY = 'body';

    public const IN_HEADERS = 'headers';

    public const IN_ALL = 'all';

    /**
     * The named presets a request file may list. Each is one or more
     * pattern/replacement pairs. They only ever replace a value with a fixed
     * marker, so two answers that differ in anything else still differ.
     *
     * @var array<string, array{string, list<array{string, string}>}>
     */
    public const PRESETS = [
        'csrf' => ['CSRF tokens in hidden form fields and meta tags', [
            ['/((?:name|id)\s*=\s*["\'](?:_token|csrf_token|_csrf|csrf|csrfmiddlewaretoken|authenticity_token|__RequestVerificationToken)["\'][^>]*?\bvalue\s*=\s*["\'])[^"\']*/i', '$1<CSRF>'],
            ['/(\bvalue\s*=\s*["\'])[^"\']*(["\'][^>]*?(?:name|id)\s*=\s*["\'](?:_token|csrf_token|_csrf|csrf|csrfmiddlewaretoken|authenticity_token|__RequestVerificationToken)["\'])/i', '$1<CSRF>$2'],
            ['/(<meta\s+name\s*=\s*["\'](?:csrf-token|_csrf|csrf)["\']\s+content\s*=\s*["\'])[^"\']*/i', '$1<CSRF>'],
            ['/("(?:_token|csrf_token|csrfToken|_csrf)"\s*:\s*")[^"]*/', '$1<CSRF>'],
        ]],
        'session-id' => ['session ids in links, forms and scripts (PHPSESSID, laravel_session and the like)', [
            ['/\b(PHPSESSID|[A-Za-z_]*session(?:_id|id)?)(=|%3D)[A-Za-z0-9%,\-]{8,}/i', '$1$2<SESSION>'],
        ]],
        'timestamps' => ['dates with a time of day, and Unix times from 2020 to 2033', [
            ['/\b\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?/', '<TIMESTAMP>'],
            ['/\b(?:1[6-9]|20)\d{8}(?:\d{3})?\b/', '<TIMESTAMP>'],
        ]],
        'asset-hash' => ['content hashes in built asset names (app.3f9a1c2b.js, Vite\'s app-L1fqnEWe.js, app.js?id=3f9a1c)', [
            ['/([.\-])[0-9a-f]{8,}(\.(?:js|mjs|css|map|png|jpe?g|gif|svg|webp|woff2?|ttf))\b/i', '$1<HASH>$2'],
            // Vite (Rollup 4, Rolldown) hashes are 8 base64url characters after a
            // dash, and may hold a dash themselves (AppHeader-DK7UwdK-.css). The
            // lookahead wants a digit, a capital, "_" or "-" in the eight, so a
            // plain word such as app-settings.js is left alone (found on WELD,
            // 2026-11-06: the hex-only rule matched no Vite asset at all).
            ['/-(?=[A-Za-z0-9_\-]{0,7}[0-9A-Z_\-])[A-Za-z0-9_\-]{8}(\.(?:js|mjs|css|map|png|jpe?g|gif|svg|webp|woff2?|ttf))\b/', '-<HASH>$1'],
            ['/(\.(?:js|mjs|css)\?(?:id|v|ver|version|h)=)[A-Za-z0-9_\-.]+/i', '$1<HASH>'],
        ]],
        // A bundler may split a shared module into its own chunk after any
        // dependency moves (Inertia 3 split WayneUI's Vue kit out on WELD,
        // 2026-11-06). The hint only warms the cache; the script and
        // stylesheet tags that load the page are still compared.
        'preload-hints' => ['<link rel="modulepreload"> hints, which list the chunks the bundler split out and change with any build', [
            // Quoted values are skipped whole: the server's own address is
            // already "<app>" by now, and its ">" must not end the tag.
            ['/<link\s+rel\s*=\s*["\']modulepreload["\'](?:[^>"\']|"[^"]*"|\'[^\']*\')*>/i', ''],
        ]],
        // The Inertia page object: its asset version changes with every build,
        // and its protocol fields changed between Inertia 2 and 3 (2 always
        // printed clearHistory and encryptHistory, 3 omits them when false and
        // adds sharedProps, the names of the shared props). Local apps on
        // Inertia 3 also print a devtools id that is new on every request.
        // Component, props and url are never touched. Each rule covers the
        // JSON answer and the HTML-escaped data-page attribute.
        'inertia' => ['the Inertia page object\'s asset version, its protocol fields that changed in Inertia 3 (clearHistory and encryptHistory when false, sharedProps) and the devtools id', [
            ['/((&quot;|")version\2:\2)[0-9a-f]{32}/', '$1<VERSION>'],
            ['/,(&quot;|")(?:clearHistory|encryptHistory)\1:false\b/', ''],
            ['/(?<=\{)(&quot;|")(?:clearHistory|encryptHistory)\1:false,/', ''],
            ['/,(&quot;|")sharedProps\1:\[[^\]]*\]/', ''],
            ['/(?<=\{)(&quot;|")sharedProps\1:\[[^\]]*\],/', ''],
            ['/<script\s+data-inertia-devtools-id\b[^>]*>[^<]*<\/script>/i', ''],
        ]],
    ];

    /**
     * @param list<array{string, string}> $rules pattern and replacement pairs, applied in order
     */
    public function __construct(
        public string $name,
        public array $rules,
        public string $in = self::IN_BODY,
    ) {}

    public static function preset(string $name): self
    {
        if (!isset(self::PRESETS[$name])) {
            throw new ToolError(sprintf('Unknown normalizer "%s". The presets are %s; anything else is an object with "pattern" and "replace".', $name, implode(', ', array_keys(self::PRESETS))));
        }

        return new self($name, self::PRESETS[$name][1]);
    }

    /**
     * A rule the request file declares: {"name": ..., "pattern": ..., "replace": ..., "in": "body"}.
     * The pattern is a PCRE body without delimiters, so a file needs no
     * escaped slashes; flags go in front as (?i) and the like.
     *
     * @param array<mixed> $spec
     */
    public static function custom(array $spec, string $where): self
    {
        $pattern = $spec['pattern'] ?? null;
        if (!is_string($pattern) || $pattern === '') {
            throw new ToolError(sprintf('%s needs a "pattern" (a regular expression, no delimiters).', $where));
        }
        $replace = $spec['replace'] ?? '<VOLATILE>';
        if (!is_string($replace)) {
            throw new ToolError(sprintf('%s: "replace" must be a string.', $where));
        }
        $in = $spec['in'] ?? self::IN_BODY;
        if (!in_array($in, [self::IN_BODY, self::IN_HEADERS, self::IN_ALL], true)) {
            throw new ToolError(sprintf('%s: "in" must be body, headers or all.', $where));
        }
        $name = $spec['name'] ?? $where;
        $regex = '~' . str_replace('~', '\~', $pattern) . '~';
        if (@preg_match($regex, '') === false) {
            throw new ToolError(sprintf('%s: "%s" is not a valid regular expression.', $where, $pattern));
        }

        return new self(is_string($name) ? $name : $where, [[$regex, $replace]], $in);
    }

    public function appliesToBody(): bool
    {
        return $this->in !== self::IN_HEADERS;
    }

    public function appliesToHeaders(): bool
    {
        return $this->in !== self::IN_BODY;
    }

    public function apply(string $text): string
    {
        foreach ($this->rules as [$pattern, $replacement]) {
            $replaced = preg_replace($pattern, $replacement, $text);
            if ($replaced !== null) {
                $text = $replaced;
            }
        }

        return $text;
    }
}
