<?php

declare(strict_types=1);

namespace Waynestate\Booster\Replay;

/**
 * Reads route declarations out of PHP source for `replay init`, with the
 * prefixes the enclosing groups add: a Slim `group('/admin', ...)` or a
 * CakePHP `scope('/api', ...)` / `prefix('Admin', ...)`. It lexes with
 * token_get_all and never runs or parses the code, so a file that would
 * not load on this PHP still drafts. A group's prefix applies to every call
 * inside the group call's own parentheses, which is where its closure is.
 *
 * Without the prefixes a drafted request for a route inside a group hits the
 * wrong URL (the proof run's bug 10): the one route per group a Slim 4 move
 * needs most, because Slim 4 drops a prefix the Slim 3 way of writing groups
 * relied on.
 */
final class RouteCallScanner
{
    private const SLIM_VERBS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'any'];

    private const CAKE_VERBS = ['connect', 'get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'redirect'];

    /**
     * Slim-style route calls in one file; see slimFiles().
     *
     * @return list<array{string, string}> method and full path
     */
    public static function slim(string $code): array
    {
        return self::slimFiles([$code]);
    }

    /**
     * Slim-style route calls (`$app->get('/x', ...)`, `->map(['GET', 'POST'],
     * '/x', ...)`) across an app's files, each with the prefix of every group
     * around it. Slim concatenates a group's pattern with its routes', so
     * the prefix is joined as written. A static call inside a group
     * (`StaffController::addRoutes($app)`, the fleet's way of splitting
     * routes over classes) carries the group's prefix into that method's
     * body, in whichever file declares it. Outside a group only paths that
     * start with "/" count, so `$c->get('settings')` is not a route, and no
     * call inside a route's own handler counts.
     *
     * @param list<string> $codes
     *
     * @return list<array{string, string}> method and full path
     */
    public static function slimFiles(array $codes): array
    {
        $files = [];
        $methods = [];
        foreach ($codes as $f => $code) {
            $t = self::tokens($code);
            $braces = self::braces($t);
            $files[$f] = [$t, self::parens($t), $braces, self::classRanges($t, $braces)];
            foreach (self::methodBodies($t, $braces, $files[$f][3]) as $key => $body) {
                $methods[$key][] = [$f, $body[0], $body[1]];
            }
        }
        // A method some static call reaches is scanned through that call,
        // with the caller's prefix, and not again on its own.
        $mounted = [];
        foreach ($files as $f => [$t]) {
            foreach ($t as $i => $token) {
                $key = $token[0] === T_DOUBLE_COLON ? self::staticTarget($t, $i, $files[$f][3]) : null;
                if ($key !== null && isset($methods[$key])) {
                    foreach ($methods[$key] as [$mf, $from]) {
                        $mounted[$mf][$from] = true;
                    }
                }
            }
        }
        $routes = [];
        $seen = [];
        foreach ($files as $f => [$t]) {
            self::scanSlim($files, $methods, $mounted, $f, 0, count($t) - 1, '', $routes, $seen, 0);
        }

        return $routes;
    }

    /**
     * @param array<int, array{list<array{int|null, string}>, array<int, int>, array<int, int>, list<array{int, int, string}>}> $files
     * @param array<string, list<array{int, int, int}>>                                         $methods
     * @param array<int, array<int, bool>>                                                      $mounted
     * @param list<array{string, string}>                                                       $routes
     * @param array<string, bool>                                                               $seen
     */
    private static function scanSlim(array $files, array $methods, array $mounted, int $f, int $from, int $to, string $base, array &$routes, array &$seen, int $depth): void
    {
        [$t, $close, $braces, $classes] = $files[$f];
        $stack = [];
        $handlerEnd = -1;
        for ($i = $from; $i <= $to; $i++) {
            while ($stack !== [] && $i > $stack[count($stack) - 1][0]) {
                array_pop($stack);
            }
            // A mounted method's body is scanned through its call, never here.
            if ($depth === 0 && $t[$i][1] === '{' && isset($mounted[$f][$i])) {
                $i = $braces[$i] ?? $i;
                continue;
            }
            if ($i < $handlerEnd) {
                continue;
            }
            $prefix = $stack === [] ? $base : $stack[count($stack) - 1][1];
            if ($t[$i][0] === T_DOUBLE_COLON && $depth < 8) {
                $key = self::staticTarget($t, $i, $classes);
                foreach ($key !== null ? $methods[$key] ?? [] : [] as [$mf, $bodyFrom, $bodyTo]) {
                    $visit = $mf . ':' . $bodyFrom . ':' . $prefix;
                    if (!isset($seen[$visit])) {
                        $seen[$visit] = true;
                        self::scanSlim($files, $methods, $mounted, $mf, $bodyFrom + 1, $bodyTo - 1, $prefix, $routes, $seen, $depth + 1);
                    }
                }
                continue;
            }
            $call = self::callAt($t, $i, $close);
            if ($call === null) {
                continue;
            }
            [$name, $open, $end] = $call;
            $args = self::args($t, $open, $end);
            if ($name === 'group') {
                $path = self::stringArg($t, $args[0] ?? null);
                if ($path !== null && ($prefix !== '' || str_starts_with($path, '/'))) {
                    $stack[] = [$end, $prefix . $path];
                }
                continue;
            }
            $verbs = [];
            $path = null;
            if ($name === 'map') {
                $verbs = self::stringList($t, $args[0] ?? null);
                $path = self::stringArg($t, $args[1] ?? null);
            } elseif (in_array($name, self::SLIM_VERBS, true)) {
                $verbs = [$name === 'any' ? 'GET' : strtoupper($name)];
                $path = self::stringArg($t, $args[0] ?? null);
            }
            if ($path === null || $verbs === [] || ($prefix === '' && !str_starts_with($path, '/'))) {
                continue;
            }
            $handlerEnd = $end;
            $full = $prefix . $path;
            foreach ($verbs as $verb) {
                $routes[] = [$verb, $full === '' ? '/' : $full];
            }
        }
    }

    /**
     * The method a static call at the "::" at $i names, as "class::method"
     * lowercased, the class by its short name (self and static are the
     * enclosing class), or null when it is not a call.
     *
     * @param list<array{int|null, string}> $t
     * @param list<array{int, int, string}>  $classes
     */
    private static function staticTarget(array $t, int $i, array $classes): ?string
    {
        $back = $i - 1;
        while ($back >= 0 && in_array($t[$back][0], [T_WHITESPACE, T_COMMENT], true)) {
            $back--;
        }
        $j = self::next($t, $i);
        $k = $j === null ? null : self::next($t, $j);
        if ($back < 0 || $j === null || $k === null || $t[$j][0] !== T_STRING || $t[$k][1] !== '(') {
            return null;
        }
        $class = $t[$back][1];
        if (in_array(strtolower($class), ['self', 'static'], true)) {
            $class = self::classAt($classes, $i) ?? '';
        } elseif (!in_array($t[$back][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
            return null;
        }
        $short = substr($class, (int) strrpos('\\' . $class, '\\'));

        return $short === '' ? null : strtolower($short . '::' . $t[$j][1]);
    }

    /**
     * Every method body in a file: "class::method" lowercased to the "{"
     * and "}" around it.
     *
     * @param list<array{int|null, string}> $t
     * @param array<int, int>               $braces
     * @param list<array{int, int, string}>  $classes
     *
     * @return array<string, array{int, int}>
     */
    private static function methodBodies(array $t, array $braces, array $classes): array
    {
        $bodies = [];
        foreach ($t as $i => $token) {
            if ($token[0] !== T_FUNCTION) {
                continue;
            }
            $name = self::next($t, $i);
            if ($name !== null && $t[$name][1] === '&') {
                $name = self::next($t, $name);
            }
            if ($name === null || $t[$name][0] !== T_STRING) {
                continue;
            }
            $class = self::classAt($classes, $i);
            if ($class === null) {
                continue;
            }
            for ($j = $name + 1, $n = count($t); $j < $n; $j++) {
                if ($t[$j][1] === ';') {
                    break;
                }
                if ($t[$j][1] === '{' && isset($braces[$j])) {
                    $bodies[strtolower($class . '::' . $t[$name][1])] = [$j, $braces[$j]];
                    break;
                }
            }
        }

        return $bodies;
    }

    /**
     * The name of the innermost class (or trait) whose body holds token $i.
     *
     * @param list<array{int, int, string}> $classes
     */
    private static function classAt(array $classes, int $i): ?string
    {
        $found = null;
        foreach ($classes as [$open, $close, $name]) {
            if ($open < $i && $close > $i) {
                $found = $name;
            }
        }

        return $found;
    }

    /**
     * Each class and trait body in a file: its "{", its "}" and its name.
     * `X::class` and `new class` are not declarations.
     *
     * @param list<array{int|null, string}> $t
     * @param array<int, int>               $braces
     *
     * @return list<array{int, int, string}>
     */
    private static function classRanges(array $t, array $braces): array
    {
        $ranges = [];
        foreach ($t as $c => $token) {
            if (!in_array($token[0], [T_CLASS, T_TRAIT], true)) {
                continue;
            }
            $prev = $c - 1;
            while ($prev >= 0 && in_array($t[$prev][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $prev--;
            }
            if ($prev >= 0 && in_array($t[$prev][0], [T_DOUBLE_COLON, T_NEW], true)) {
                continue;
            }
            $name = self::next($t, $c);
            if ($name === null || $t[$name][0] !== T_STRING) {
                continue;
            }
            for ($j = $name + 1, $n = count($t); $j < $n; $j++) {
                if ($t[$j][1] === '{') {
                    if (isset($braces[$j])) {
                        $ranges[] = [$j, $braces[$j], $t[$name][1]];
                    }
                    break;
                }
            }
        }

        return $ranges;
    }

    /**
     * The index of the "}" that closes each "{" (and each "{$" in a string).
     *
     * @param list<array{int|null, string}> $t
     *
     * @return array<int, int>
     */
    private static function braces(array $t): array
    {
        $open = [];
        $close = [];
        foreach ($t as $i => [$id, $text]) {
            if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES || ($id === null && $text === '{')) {
                $open[] = $i;
            } elseif ($id === null && $text === '}' && $open !== []) {
                $close[array_pop($open)] = $i;
            }
        }

        return $close;
    }

    /**
     * A CakePHP `config/routes.php`: every `connect()` and verb route with
     * its scope, prefix and plugin path, `resources()` as their index URL,
     * and where `fallbacks()` was called (the `/{controller}/{action}` routes
     * most CakePHP 4 apps lean on), with the controller folder the prefix
     * maps to. A plugin's fallbacks have no folder in the app, so they are
     * left out.
     *
     * @return array{routes: list<array{string, string, string}>, fallbacks: list<array{string, string}>, dashed: bool} method, path and the route's target as written; prefix path and controller subfolder
     */
    public static function cake(string $code): array
    {
        $t = self::tokens($code);
        $close = self::parens($t);
        $dashed = !(str_contains($code, 'InflectedRoute') && !str_contains($code, 'DashedRoute'));
        $stack = [];
        $routes = [];
        $fallbacks = [];
        $count = count($t);
        for ($i = 0; $i < $count; $i++) {
            while ($stack !== [] && $i > $stack[count($stack) - 1][0]) {
                array_pop($stack);
            }
            $call = self::callAt($t, $i, $close);
            if ($call === null) {
                continue;
            }
            [$name, $open, $end] = $call;
            [$prefix, $folder] = $stack === [] ? ['/', ''] : [$stack[count($stack) - 1][1], $stack[count($stack) - 1][2]];
            $args = self::args($t, $open, $end);
            $first = self::stringArg($t, $args[0] ?? null);
            if ($name === 'scope' && $first !== null) {
                $stack[] = [$end, self::join($prefix, $first), $folder];
            } elseif (($name === 'prefix' || $name === 'plugin') && $first !== null) {
                $path = self::pathOption(self::text($t, $open + 1, $end - 1)) ?? '/' . self::inflect($first, true);
                $sub = $name === 'plugin' || $folder === null ? null : ltrim($folder . '/' . self::camelize($first), '/');
                $stack[] = [$end, self::join($prefix, $path), $sub];
            } elseif (in_array($name, self::CAKE_VERBS, true) && $first !== null) {
                $method = in_array($name, ['connect', 'redirect'], true) ? 'GET' : strtoupper($name);
                $target = isset($args[1]) ? self::text($t, $args[1][0], $args[1][1]) : '';
                $routes[] = [$method, self::join($prefix, $first), $target];
            } elseif ($name === 'resources' && $first !== null) {
                $routes[] = ['GET', self::join($prefix, self::inflect($first, $dashed)), ''];
            } elseif ($name === 'fallbacks' && $folder !== null) {
                $fallbacks[] = [$prefix, $folder];
            }
        }

        return ['routes' => $routes, 'fallbacks' => $fallbacks, 'dashed' => $dashed];
    }

    /**
     * A CakePHP name as its URL segment: DashedRoute (the skeleton's) makes
     * `UserProfiles` and `viewAll` into `user-profiles` and `view-all`;
     * InflectedRoute uses underscores.
     */
    public static function inflect(string $name, bool $dashed): string
    {
        $joiner = $dashed ? '-' : '_';

        return strtolower(str_replace(['_', '-'], $joiner, (string) preg_replace('/(?<=[a-z0-9])([A-Z])/', $joiner . '$1', $name)));
    }

    private static function camelize(string $name): string
    {
        return str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $name)));
    }

    private static function join(string $prefix, string $path): string
    {
        $joined = rtrim($prefix, '/') . '/' . ltrim($path, '/');

        return $joined === '/' ? '/' : (rtrim($joined, '/') === '' ? '/' : rtrim($joined, '/'));
    }

    /** The `'path' => '/x'` option of a prefix() or plugin() call, if any. */
    private static function pathOption(string $args): ?string
    {
        return preg_match('/[\'"]path[\'"]\s*=>\s*[\'"]([^\'"]*)[\'"]/', $args, $m) === 1 ? $m[1] : null;
    }

    /**
     * @return list<array{int|null, string}>
     */
    private static function tokens(string $code): array
    {
        $out = [];
        foreach (token_get_all($code) as $token) {
            $out[] = is_array($token) ? [$token[0], $token[1]] : [null, $token];
        }

        return $out;
    }

    /**
     * The index of the ")" that closes each "(".
     *
     * @param list<array{int|null, string}> $t
     *
     * @return array<int, int>
     */
    private static function parens(array $t): array
    {
        $open = [];
        $close = [];
        foreach ($t as $i => [$id, $text]) {
            if ($id === null && $text === '(') {
                $open[] = $i;
            } elseif ($id === null && $text === ')' && $open !== []) {
                $close[array_pop($open)] = $i;
            }
        }
        foreach ($open as $i) {
            $close[$i] = count($t) - 1;
        }

        return $close;
    }

    /**
     * A method or static call at $i (`->name(`, `?->name(` or `::name(`):
     * the lowercased name, the "(" and the ")".
     *
     * @param list<array{int|null, string}> $t
     * @param array<int, int>               $close
     *
     * @return array{string, int, int}|null
     */
    private static function callAt(array $t, int $i, array $close): ?array
    {
        if (!in_array($t[$i][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
            return null;
        }
        $j = self::next($t, $i);
        if ($j === null || $t[$j][0] !== T_STRING) {
            return null;
        }
        $k = self::next($t, $j);
        if ($k === null || $t[$k][0] !== null || $t[$k][1] !== '(' || !isset($close[$k])) {
            return null;
        }

        return [strtolower($t[$j][1]), $k, $close[$k]];
    }

    /**
     * @param list<array{int|null, string}> $t
     */
    private static function next(array $t, int $i): ?int
    {
        for ($j = $i + 1, $n = count($t); $j < $n; $j++) {
            if (!in_array($t[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $j;
            }
        }

        return null;
    }

    /**
     * The token ranges of a call's arguments, split at the commas at its own
     * depth.
     *
     * @param list<array{int|null, string}> $t
     *
     * @return list<array{int, int}> first and last token of each argument
     */
    private static function args(array $t, int $open, int $end): array
    {
        $args = [];
        $depth = 0;
        $start = $open + 1;
        for ($i = $open + 1; $i < $end; $i++) {
            [$id, $text] = $t[$i];
            if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES || ($id === null && in_array($text, ['(', '[', '{'], true)) || (defined('T_ATTRIBUTE') && $id === T_ATTRIBUTE)) {
                $depth++;
            } elseif ($id === null && in_array($text, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($id === null && $text === ',' && $depth === 0) {
                $args[] = [$start, $i - 1];
                $start = $i + 1;
            }
        }
        if ($start <= $end - 1) {
            $args[] = [$start, $end - 1];
        }

        return $args;
    }

    /**
     * The value of an argument that is one plain string literal, else null
     * (a variable, a concatenation, an interpolated string).
     *
     * @param list<array{int|null, string}> $t
     * @param array{int, int}|null          $range
     */
    private static function stringArg(array $t, ?array $range): ?string
    {
        if ($range === null) {
            return null;
        }
        $found = null;
        for ($i = $range[0]; $i <= $range[1]; $i++) {
            if (in_array($t[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if ($found !== null || $t[$i][0] !== T_CONSTANT_ENCAPSED_STRING) {
                return null;
            }
            $found = $t[$i][1];
        }
        if ($found === null) {
            return null;
        }
        $body = substr($found, 1, -1);

        return $found[0] === '"' ? stripcslashes($body) : str_replace(['\\\\', "\\'"], ['\\', "'"], $body);
    }

    /**
     * The string literals of an array argument (`['GET', 'POST']`), upper-cased.
     *
     * @param list<array{int|null, string}> $t
     * @param array{int, int}|null          $range
     *
     * @return list<string>
     */
    private static function stringList(array $t, ?array $range): array
    {
        if ($range === null) {
            return [];
        }
        $values = [];
        for ($i = $range[0]; $i <= $range[1]; $i++) {
            if ($t[$i][0] === T_CONSTANT_ENCAPSED_STRING) {
                $values[] = strtoupper(substr($t[$i][1], 1, -1));
            }
        }

        return $values;
    }

    /**
     * @param list<array{int|null, string}> $t
     */
    private static function text(array $t, int $from, int $to): string
    {
        $text = '';
        for ($i = $from; $i <= $to; $i++) {
            $text .= $t[$i][1];
        }

        return $text;
    }
}
