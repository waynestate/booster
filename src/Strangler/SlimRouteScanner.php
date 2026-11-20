<?php

declare(strict_types=1);

namespace Waynestate\Booster\Strangler;

/**
 * Reads every Slim route definition out of a legacy app's PHP files, with
 * what a move needs: the methods, the pattern with every enclosing group's
 * prefix, the route name, the handler, the if blocks the route is
 * registered inside (the fleet registers signed-in routes only when the
 * user has a role) and the middleware added to the route and its groups.
 *
 * Groups nest, and a static call inside a group (`Page\Login::addRoutes($app)`,
 * the fleet's way of splitting routes over controllers) carries the group's
 * prefix, guards and middleware into the method it names, in whichever file
 * declares it. A route call needs a string pattern and a handler; outside a
 * group the pattern must start with "/", so `$c->get('settings')` is no route.
 */
final class SlimRouteScanner
{
    private const VERBS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'any', 'map'];

    /** Slim 4's `any()` methods. */
    public const ANY = ['DELETE', 'GET', 'OPTIONS', 'PATCH', 'POST', 'PUT'];

    /** @var array<string, SlimSource> */
    private array $files = [];

    /** @var array<string, array{string, int, int, bool}> lower-cased fqcn::method to file, "{", "}", file named after the class */
    private array $methods = [];

    /** @var array<string, array<int, bool>> */
    private array $mounted = [];

    /** @var list<SlimRoute> */
    private array $routes = [];

    /** @var array<string, bool> */
    private array $seen = [];

    /**
     * @param array<string, string> $codes legacy-relative path to PHP source
     *
     * @return list<SlimRoute>
     */
    public static function scan(array $codes): array
    {
        $scanner = new self();
        ksort($codes);
        foreach ($codes as $path => $code) {
            $scanner->files[$path] = new SlimSource($code);
            foreach ($scanner->files[$path]->methods as $key => [$open, $close]) {
                // Two files can declare one class (a kept backup such as
                // AdminUsers.0.php); the autoloader loads the one named after it.
                $short = substr($key, (int) strrpos('\\' . $key, '\\'));
                $named = strtolower(basename($path, '.php')) === explode('::', $short)[0];
                if (!isset($scanner->methods[$key]) || ($named && !$scanner->methods[$key][3])) {
                    $scanner->methods[$key] = [$path, $open, $close, $named];
                }
            }
        }
        foreach ($scanner->files as $path => $src) {
            foreach ($src->t as $i => [$id]) {
                if ($id !== T_DOUBLE_COLON) {
                    continue;
                }
                $key = $scanner->staticTarget($src, $i);
                if ($key !== null && isset($scanner->methods[$key])) {
                    [$mf, $from] = $scanner->methods[$key];
                    $scanner->mounted[$mf][$from] = true;
                }
            }
        }
        foreach ($scanner->files as $path => $src) {
            $scanner->walk($path, 0, count($src->t) - 1, '', [], [], 0, [], []);
        }

        return $scanner->routes;
    }

    /**
     * @param list<string>             $guards
     * @param list<string>             $middleware
     * @param list<array{string, int, int}> $links  each step from a file to the route: file, the call token, where that walk began
     * @param list<string>             $mwFiles    the file each middleware is written in
     */
    private function walk(string $path, int $from, int $to, string $base, array $guards, array $middleware, int $depth, array $links, array $mwFiles): void
    {
        $src = $this->files[$path];
        $t = $src->t;
        /** @var list<array{int, string, list<string>, list<string>}> $stack group end, prefix, middleware, their files */
        $stack = [];
        $skipTo = -1;
        for ($i = $from; $i <= $to; $i++) {
            while ($stack !== [] && $i > $stack[count($stack) - 1][0]) {
                array_pop($stack);
            }
            if ($depth === 0 && $t[$i][1] === '{' && isset($this->mounted[$path][$i])) {
                $i = $src->braces[$i] ?? $i;

                continue;
            }
            if ($i < $skipTo) {
                continue;
            }
            [$prefix, $groupMiddleware, $groupFiles] = $stack === [] ? [$base, $middleware, $mwFiles] : [$stack[count($stack) - 1][1], $stack[count($stack) - 1][2], $stack[count($stack) - 1][3]];
            if ($t[$i][0] === T_DOUBLE_COLON && $depth < 8) {
                $key = $this->staticTarget($src, $i);
                if ($key !== null && isset($this->methods[$key])) {
                    [$mf, $bodyFrom, $bodyTo] = $this->methods[$key];
                    $visit = $mf . ':' . $bodyFrom . ':' . $prefix;
                    if (!isset($this->seen[$visit])) {
                        $this->seen[$visit] = true;
                        $here = array_merge($guards, $src->guardsAt($i, $from));
                        $hereLinks = array_merge($links, [[$path, $i, $from]]);
                        $this->walk($mf, $bodyFrom + 1, $bodyTo - 1, $prefix, $here, $groupMiddleware, $depth + 1, $hereLinks, $groupFiles);
                    }
                }

                continue;
            }
            $call = $this->callAt($src, $i);
            if ($call === null) {
                continue;
            }
            [$name, $open, $end] = $call;
            $args = $src->args($open, $end);
            if ($name === 'group') {
                $pattern = isset($args[0]) ? $src->stringAt($args[0][0], $args[0][1]) : null;
                if ($pattern !== null && ($prefix !== '' || str_starts_with($pattern, '/'))) {
                    $added = $this->chain($src, $end)['middleware'];
                    $stack[] = [$end, $prefix . $pattern, array_merge($groupMiddleware, $added), array_merge($groupFiles, array_fill(0, count($added), $path))];
                }

                continue;
            }
            if (!in_array($name, self::VERBS, true)) {
                continue;
            }
            $routeLinks = array_merge($links, [[$path, $i, $from]]);
            $route = $this->route($path, $src, $i, $name, $args, $end, $prefix, array_merge($guards, $src->guardsAt($i, $from)), $groupMiddleware, $routeLinks, $groupFiles);
            if ($route !== null) {
                $this->routes[] = $route;
                $skipTo = $end;
            }
        }
    }

    /**
     * @param list<array{int, int}> $args
     * @param list<string>          $guards
     * @param list<string>          $middleware
     * @param list<array{string, int, int}> $links
     * @param list<string>          $mwFiles
     */
    private function route(string $path, SlimSource $src, int $at, string $verb, array $args, int $end, string $prefix, array $guards, array $middleware, array $links, array $mwFiles): ?SlimRoute
    {
        $patternArg = $verb === 'map' ? 1 : 0;
        if (count($args) < $patternArg + 2) {
            return null;
        }
        $pattern = $src->stringAt($args[$patternArg][0], $args[$patternArg][1]);
        if ($pattern === null || ($prefix === '' && !str_starts_with($pattern, '/'))) {
            return null;
        }
        if ($verb === 'map') {
            $methods = [];
            for ($k = $args[0][0]; $k <= $args[0][1]; $k++) {
                if ($src->t[$k][0] === T_CONSTANT_ENCAPSED_STRING) {
                    $methods[] = strtoupper(SlimSource::unquote($src->t[$k][1]));
                }
            }
            if ($methods === []) {
                return null;
            }
        } else {
            $methods = $verb === 'any' ? self::ANY : [strtoupper($verb)];
        }
        $full = $prefix . $pattern;
        $chain = $this->chain($src, $end);
        [$hFrom, $hTo] = $args[$patternArg + 1];
        $handler = $this->handler($src, $hFrom, $hTo);

        return new SlimRoute(
            $methods,
            $full === '' ? '/' : $full,
            $chain['name'],
            $handler['kind'],
            $handler['class'],
            $handler['method'],
            $handler['text'],
            $handler['uses'],
            $path,
            $src->t[$at][2],
            $handler['line'],
            array_values(array_unique($guards)),
            array_merge($middleware, $chain['middleware']),
            $handler['static'],
            $links,
            array_merge($mwFiles, array_fill(0, count($chain['middleware']), $path)),
        );
    }

    /**
     * The ->setName() and ->add() calls chained after a route or group call.
     *
     * @return array{name: ?string, middleware: list<string>}
     */
    private function chain(SlimSource $src, int $end): array
    {
        $name = null;
        $middleware = [];
        $i = $end;
        while (true) {
            $arrow = $src->next($i);
            if ($arrow === null || !in_array($src->t[$arrow][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                break;
            }
            $call = $this->callAt($src, $arrow);
            if ($call === null) {
                break;
            }
            [$method, $open, $close] = $call;
            $text = trim($src->text($open + 1, $close - 1));
            if ($method === 'setname') {
                $name = $src->stringAt($open + 1, $close - 1) ?? $name;
            } elseif ($method === 'add' || $method === 'addmiddleware') {
                $middleware[] = (string) preg_replace('/\s+/', ' ', $text);
            }
            $i = $close;
        }

        return ['name' => $name, 'middleware' => $middleware];
    }

    /**
     * What a handler argument names: a closure (with its use list), a class
     * and method (`X::class`, `'X:m'`, `X::class . ':m'`, `__CLASS__ . ':m'`,
     * `[X::class, 'm']`), or something Booster cannot read.
     *
     * @return array{kind: string, class: ?string, method: ?string, text: string, uses: list<string>, line: int, static: bool}
     */
    private function handler(SlimSource $src, int $from, int $to): array
    {
        $range = $src->trimRange($from, $to);
        $out = ['kind' => 'unknown', 'class' => null, 'method' => null, 'text' => '', 'uses' => [], 'line' => 0, 'static' => false];
        if ($range === null) {
            return $out;
        }
        [$a, $b] = $range;
        $out['text'] = $src->text($a, $b);
        $out['line'] = $src->t[$a][2];
        $first = $src->t[$a];
        $static = false;
        if ($first[0] === T_STATIC) {
            $n = $src->next($a);
            if ($n !== null && in_array($src->t[$n][0], [T_FUNCTION, T_FN], true)) {
                $first = $src->t[$n];
                $static = true;
            }
        }
        if (in_array($first[0], [T_FUNCTION, T_FN], true)) {
            $out['kind'] = 'closure';
            $out['static'] = $static;
            if (preg_match('/^(?:static\s+)?function\s*\([^)]*\)\s*use\s*\(([^)]*)\)/s', $out['text'], $m) === 1) {
                preg_match_all('/\$(\w+)/', $m[1], $vars);
                $out['uses'] = $vars[1];
            }

            return $out;
        }
        $compact = (string) preg_replace('/\s+/', '', $out['text']);
        if (preg_match('/^\[([\\\\\w]+)::class,([\'"])(\w+)\2\]$/', $compact, $m) === 1) {
            return ['class' => $src->resolveClass($m[1], $a), 'method' => $m[3], 'kind' => 'class'] + $out;
        }
        if (preg_match('/^([\\\\\w]+)::class(?:\.([\'"]):(\w+)\2)?$/', $compact, $m) === 1) {
            return ['class' => $src->resolveClass($m[1], $a), 'method' => $m[3] ?? null, 'kind' => 'class'] + $out;
        }
        if (preg_match('/^__CLASS__\.([\'"]):(\w+)\1$/', $compact, $m) === 1) {
            $class = $src->classAt($a);

            return $class === null ? $out : ['class' => $class, 'method' => $m[2], 'kind' => 'class'] + $out;
        }
        $literal = $src->stringAt($a, $b);
        if ($literal !== null && preg_match('/^\\\\?([A-Za-z_][\\\\\w]*)(?::(\w+))?$/', $literal, $m) === 1) {
            return ['class' => $m[1], 'method' => $m[2] ?? null, 'kind' => 'class'] + $out;
        }

        return $out;
    }

    /**
     * The lower-cased "fqcn::method" a static call at the "::" at $i names.
     */
    private function staticTarget(SlimSource $src, int $i): ?string
    {
        $back = $src->prev($i);
        $j = $src->next($i);
        $k = $j === null ? null : $src->next($j);
        if ($back === null || $j === null || $k === null || $src->t[$j][0] !== T_STRING || $src->t[$k][1] !== '(') {
            return null;
        }
        if (!in_array($src->t[$back][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_STATIC], true)) {
            return null;
        }
        $class = $src->resolveClass($src->t[$back][1], $i);

        return $class === '' ? null : strtolower($class . '::' . $src->t[$j][1]);
    }

    /**
     * A method call at $i (`->name(` or `?->name(`): the lower-cased name,
     * the "(" and the ")".
     *
     * @return array{string, int, int}|null
     */
    private function callAt(SlimSource $src, int $i): ?array
    {
        if (!in_array($src->t[$i][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
            return null;
        }
        $j = $src->next($i);
        if ($j === null || $src->t[$j][0] !== T_STRING) {
            return null;
        }
        $k = $src->next($j);
        if ($k === null || $src->t[$k][1] !== '(' || !isset($src->parens[$k])) {
            return null;
        }

        return [strtolower($src->t[$j][1]), $k, $src->parens[$k]];
    }
}
