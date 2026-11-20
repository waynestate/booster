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

    /**
     * Every path shape an optional-segment pattern answers, shortest first
     * (FastRoute's rule: `a[/b[/c]]`, nested at the end only):
     * `/[login[/]]` gives "/", "/login" and "/login/". Null when the brackets
     * are not FastRoute's (one in the middle, unbalanced).
     *
     * @return list<string>|null
     */
    public static function expand(string $pattern): ?array
    {
        $outside = '';
        $depth = 0;
        $positions = [];
        $length = strlen($pattern);
        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];
            if ($char === '{') {
                $end = self::placeholderEnd($pattern, $i);
                if ($end === null) {
                    return null;
                }
                $outside .= substr($pattern, $i, $end - $i + 1);
                $i = $end;

                continue;
            }
            if ($char === '[') {
                $positions[] = strlen($outside);
                $depth++;

                continue;
            }
            if ($char === ']') {
                $depth--;
                if ($depth < 0) {
                    return null;
                }
                // FastRoute: optional segments only at the end, closing together.
                $rest = substr($pattern, $i + 1);
                if (trim($rest, ']') !== '') {
                    return null;
                }

                continue;
            }
            $outside .= $char;
        }
        if ($depth !== 0) {
            return null;
        }
        $out = [];
        foreach ($positions as $cut) {
            $out[] = substr($outside, 0, $cut);
        }
        $out[] = $outside;
        foreach ($out as $n => $shape) {
            $out[$n] = $shape === '' ? '/' : $shape;
        }

        return array_values(array_unique($out));
    }

    /**
     * The Laravel URIs a pattern answers (optional segments expanded), each
     * with its wheres and the route-table form: the URI with an inline
     * `{x:regex}` and a trailing `[/]` when the legacy route also answers the
     * path with its trailing slash. Null with the reason when one shape
     * cannot be said in Laravel.
     *
     * @return array{list<array{uri: string, wheres: array<string, string>, table: string}>, ?string}
     */
    public static function variants(string $pattern): array
    {
        $shapes = self::expand($pattern);
        if ($shapes === null) {
            return [[], 'the pattern ' . $pattern . ' has optional segments FastRoute itself would refuse (only at the end, nested)'];
        }
        $byUri = [];
        foreach ($shapes as $shape) {
            $p = self::convert($shape);
            if ($p->refusal !== null) {
                return [[], $p->refusal];
            }
            $slash = $shape !== '/' && str_ends_with($shape, '/');
            $entry = $byUri[$p->uri] ?? ['uri' => $p->uri, 'wheres' => $p->wheres, 'plain' => false, 'slash' => false];
            $entry[$slash ? 'slash' : 'plain'] = true;
            $byUri[$p->uri] = $entry;
        }
        $out = [];
        foreach ($byUri as $entry) {
            $table = (string) preg_replace_callback('/\{(\w+)\}/', static fn(array $m): string => isset($entry['wheres'][$m[1]]) ? '{' . $m[1] . ':' . $entry['wheres'][$m[1]] . '}' : $m[0], $entry['uri']);
            if ($entry['slash'] && $entry['uri'] !== '/') {
                $table .= '[/]';
            }
            $out[] = ['uri' => $entry['uri'], 'wheres' => $entry['wheres'], 'table' => $table];
        }

        return [$out, null];
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
