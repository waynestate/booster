<?php

declare(strict_types=1);

namespace Waynestate\Booster\Strangler;

/**
 * A Slim (FastRoute) route pattern as a Laravel URI (design section 10.3).
 *
 * FastRoute allows spaces inside a placeholder (`{ access_id }`), a regex
 * after a colon (`{id:[0-9]+}`) and optional segments in square brackets.
 * Laravel has none of those, so the move trims the spaces, turns a regex
 * into a `where()` constraint, drops a trailing `[/]` (Laravel matches a
 * path with and without its trailing slash), and refuses every other
 * optional segment: `/[login[/]]` answers three paths, and no one Laravel
 * route says the same.
 */
final class SlimPattern
{
    /**
     * @param array<string, string> $wheres placeholder name to its regex
     */
    private function __construct(
        public readonly string $uri,
        public readonly array $wheres,
        public readonly ?string $refusal,
    ) {}

    public static function convert(string $pattern): self
    {
        $path = $pattern;
        if (str_ends_with($path, '[/]')) {
            $path = substr($path, 0, -3);
        }
        $uri = '';
        $wheres = [];
        $refusal = null;
        $length = strlen($path);
        for ($i = 0; $i < $length; $i++) {
            $char = $path[$i];
            if ($char === '[' || $char === ']') {
                $refusal ??= 'the pattern ' . $pattern . ' has an optional segment; Laravel has no route that answers the same paths (only a trailing [/] maps)';
                $uri .= $char;

                continue;
            }
            if ($char !== '{') {
                $uri .= $char;

                continue;
            }
            $end = self::placeholderEnd($path, $i);
            if ($end === null) {
                $refusal ??= 'the pattern ' . $pattern . ' has a { with no closing }';
                $uri .= substr($path, $i);
                break;
            }
            $inside = substr($path, $i + 1, $end - $i - 1);
            $colon = strpos($inside, ':');
            $name = trim($colon === false ? $inside : substr($inside, 0, $colon));
            $regex = $colon === false ? null : trim(substr($inside, $colon + 1));
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
                $refusal ??= 'the placeholder {' . $inside . '} in ' . $pattern . ' is not a name Laravel accepts';
            }
            if (isset($wheres[$name]) || str_contains($uri, '{' . $name . '}')) {
                $refusal ??= 'the placeholder {' . $name . '} appears twice in ' . $pattern;
            }
            if ($regex !== null && $regex !== '' && $regex !== '[^/]+') {
                $wheres[$name] = $regex;
            }
            $uri .= '{' . $name . '}';
            $i = $end;
        }
        $uri = trim($uri, '/');

        return new self($uri === '' ? '/' : $uri, $wheres, $refusal);
    }

    /** The unit id path for a Laravel URI: "/" or "/users/{id}". */
    public static function idPath(string $uri): string
    {
        return $uri === '/' ? '/' : '/' . $uri;
    }

    /**
     * Whether this pattern catches everything under it (`/{routes:.*}`, the
     * skeleton's CORS pre-flight route): such a unit moves last.
     */
    public static function isCatchAll(string $pattern): bool
    {
        return preg_match('#^/?\{\s*\w+\s*:\s*\.[*+]\s*\}$#', $pattern) === 1;
    }

    /**
     * A regex that matches the request paths this Laravel URI answers, with
     * the wheres applied; used to find a legacy route the move would shadow.
     *
     * @param array<string, string> $wheres
     */
    public static function regex(string $uri, array $wheres): string
    {
        if ($uri === '/') {
            return '#^/?$#';
        }
        $out = '';
        $parts = preg_split('/(\{\w+\})/', $uri, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        foreach ($parts as $part) {
            if (preg_match('/^\{(\w+)\}$/', $part, $m) === 1) {
                $out .= '(' . str_replace('#', '\#', $wheres[$m[1]] ?? '[^/]+') . ')';
            } else {
                $out .= preg_quote($part, '#');
            }
        }

        return '#^/' . $out . '/?$#';
    }

    /**
     * A sample request path for a Laravel URI, with every placeholder filled
     * by a literal that is not a real value ("booster-sample"); a constrained
     * placeholder gets null, since no sample is known to satisfy its regex.
     *
     * @param array<string, string> $wheres
     */
    public static function sample(string $uri, array $wheres): ?string
    {
        if ($uri === '/') {
            return '/';
        }
        $unknown = false;
        $path = preg_replace_callback('/\{(\w+)\}/', static function (array $m) use ($wheres, &$unknown): string {
            if (isset($wheres[$m[1]])) {
                $unknown = true;
            }

            return 'booster-sample';
        }, $uri);

        return $unknown || $path === null ? null : '/' . $path;
    }

    /** The index of the "}" that closes the placeholder opened at $at, minding braces in its regex. */
    private static function placeholderEnd(string $path, int $at): ?int
    {
        $depth = 0;
        for ($i = $at, $n = strlen($path); $i < $n; $i++) {
            if ($path[$i] === '\\') {
                $i++;

                continue;
            }
            if ($path[$i] === '{') {
                $depth++;
            } elseif ($path[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }
}
