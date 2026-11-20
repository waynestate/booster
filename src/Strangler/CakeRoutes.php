<?php

declare(strict_types=1);

namespace Waynestate\Booster\Strangler;

/**
 * A CakePHP app's `config/routes.php`, read as tokens: every connected and
 * verb route with its scope path, prefix, extensions, target, name and line,
 * and every `fallbacks()` call with the controller folder its prefix maps
 * to. `resources()` comes back as its five REST routes. Plugin scopes are
 * skipped: their controllers are not in the app.
 *
 * It also turns a URL array (`['action' => 'view', $slug]`) back into the
 * path CakePHP's router would build for it, as a PHP expression, so a moved
 * redirect or link points where the legacy one did.
 */
final class CakeRoutes
{
    private const VERBS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'head'];

    /**
     * @var list<array{methods: list<string>|null, path: string, controller: string|null, action: string, prefix: string|null, name: string|null, pass: list<string>, line: int, extensions: list<string>, patterns: bool, redirect: bool, resource: bool}>
     */
    public array $routes = [];

    /** @var list<array{path: string, prefix: string|null, line: int, extensions: list<string>}> */
    public array $fallbacks = [];

    public bool $dashed = true;

    public static function read(string $code): self
    {
        $self = new self();
        $c = new CakeCode($code);
        $self->dashed = !(str_contains($code, 'InflectedRoute') && !str_contains($code, 'DashedRoute'));
        $global = [];
        // Each scope: [closing token, path, prefix, extensions, plugin].
        /** @var list<array{int, string, string|null, list<string>, bool}> $stack */
        $stack = [];
        $count = count($c->t);
        for ($i = 0; $i < $count; $i++) {
            while ($stack !== [] && $i > $stack[count($stack) - 1][0]) {
                array_pop($stack);
            }
            $scope = $stack === [] ? [PHP_INT_MAX, '/', null, $global, false] : $stack[count($stack) - 1];
            if ($scope[4]) {
                continue;
            }
            $call = self::callAt($c, $i);
            if ($call === null) {
                continue;
            }
            [$name, $open] = $call;
            $end = $c->close[$open];
            $args = $c->items($open);
            $first = $c->stringAt($args[0] ?? null);
            $line = $c->line($i);
            if ($name === 'extensions' && self::receiverIs($c, $i, 'Router')) {
                $global = self::stringList($c, $args[0] ?? null);
                continue;
            }
            if ($name === 'setextensions') {
                $exts = self::stringList($c, $args[0] ?? null);
                if ($stack === []) {
                    $global = $exts;
                } else {
                    $top = array_pop($stack);
                    $top[3] = $exts;
                    $stack[] = $top;
                }
                continue;
            }
            if ($name === 'scope' && $first !== null) {
                $params = isset($args[1]) ? $c->arrayAt($args[1]) : null;
                $prefix = is_array($params) && is_string($params['prefix'] ?? null) ? $params['prefix'] : $scope[2];
                $stack[] = [$end, self::join($scope[1], $first), $prefix, $scope[3], false];
                continue;
            }
            if ($name === 'prefix' && $first !== null) {
                $options = isset($args[1]) ? $c->arrayAt($args[1]) : null;
                $path = is_array($options) && is_string($options['path'] ?? null) ? $options['path'] : '/' . self::inflect($first, true);
                $prefix = $scope[2] === null ? self::camelize($first) : $scope[2] . '/' . self::camelize($first);
                $stack[] = [$end, self::join($scope[1], $path), $prefix, $scope[3], false];
                continue;
            }
            if ($name === 'plugin') {
                $stack[] = [$end, $scope[1], $scope[2], $scope[3], true];
                continue;
            }
            if ($name === 'fallbacks') {
                $self->fallbacks[] = ['path' => $scope[1], 'prefix' => $scope[2], 'line' => $line, 'extensions' => $scope[3]];
                continue;
            }
            if ($name === 'resources' && $first !== null) {
                $base = self::join($scope[1], self::inflect($first, $self->dashed));
                foreach ([['GET', '', 'index'], ['POST', '', 'add'], ['GET', '/{id}', 'view'], ['PUT', '/{id}', 'edit'], ['PATCH', '/{id}', 'edit'], ['DELETE', '/{id}', 'delete']] as [$verb, $tail, $action]) {
                    $self->routes[] = ['methods' => [$verb], 'path' => $base . $tail, 'controller' => self::camelize($first), 'action' => $action, 'prefix' => $scope[2], 'name' => null, 'pass' => $tail === '' ? [] : ['id'], 'line' => $line, 'extensions' => $scope[3], 'patterns' => false, 'redirect' => false, 'resource' => true];
                }
                continue;
            }
            if ($first === null || ($name !== 'connect' && $name !== 'redirect' && !in_array($name, self::VERBS, true))) {
                continue;
            }
            $target = self::target($c, $args[1] ?? null, $scope[2]);
            $options = $name === 'connect' && isset($args[2]) ? $c->arrayAt($args[2]) : null;
            $routeName = null;
            if (in_array($name, self::VERBS, true)) {
                $routeName = $c->stringAt($args[2] ?? null);
            } elseif (is_array($options) && is_string($options['_name'] ?? null)) {
                $routeName = $options['_name'];
            }
            $methods = in_array($name, self::VERBS, true) ? [strtoupper($name)] : null;
            if ($methods === null && $target['method'] !== null) {
                $methods = $target['method'];
            }
            $pass = is_array($options) && is_array($options['pass'] ?? null) ? array_values(array_filter($options['pass'], 'is_string')) : [];
            $patterns = false;
            // Chained setters on the route this call returns.
            $j = $c->next($end);
            while ($j !== null && $c->t[$j][0] === T_OBJECT_OPERATOR) {
                $setter = $c->next($j);
                $k = $setter === null ? null : $c->next($setter);
                if ($setter === null || $k === null || $c->t[$k][1] !== '(' || !isset($c->close[$k])) {
                    break;
                }
                $j = $c->next($c->close[$k]);
                $setterName = strtolower($c->t[$setter][1]);
                $setterArgs = $c->items($k);
                if ($setterName === 'setpass') {
                    $pass = self::stringList($c, $setterArgs[0] ?? null);
                } elseif ($setterName === 'setmethods') {
                    $methods = array_map('strtoupper', self::stringList($c, $setterArgs[0] ?? null));
                } elseif ($setterName === 'setpatterns') {
                    $patterns = true;
                } elseif ($setterName === 'setextensions') {
                    $scope[3] = self::stringList($c, $setterArgs[0] ?? null);
                }
            }
            if (is_array($options)) {
                foreach ($options as $key => $value) {
                    if (is_string($key) && !str_starts_with($key, '_') && !in_array($key, ['pass', 'routeClass', 'persist'], true)) {
                        $patterns = true;
                    }
                }
            }
            $self->routes[] = [
                'methods' => $methods,
                'path' => self::join($scope[1], $first),
                'controller' => $name === 'redirect' ? null : $target['controller'],
                'action' => $target['action'],
                'prefix' => $target['prefix'],
                'name' => $routeName,
                'pass' => $pass,
                'line' => $line,
                'extensions' => $scope[3],
                'patterns' => $patterns,
                'redirect' => $name === 'redirect',
                'resource' => false,
            ];
            $i = $end;
        }

        return $self;
    }

    /**
     * A route target: `['controller' => 'Articles', 'action' => 'view']` or
     * `'Articles::view'` (with `Admin/` and `Plugin.` parts).
     *
     * @param array{int, int}|null $range
     *
     * @return array{controller: string|null, action: string, prefix: string|null, method: list<string>|null}
     */
    private static function target(CakeCode $c, ?array $range, ?string $prefix): array
    {
        $none = ['controller' => null, 'action' => 'index', 'prefix' => $prefix, 'method' => null];
        if ($range === null) {
            return $none;
        }
        $string = $c->stringAt($range);
        if ($string !== null) {
            if (preg_match('#^(?:([A-Za-z0-9_]+)\.)?(?:([A-Za-z0-9_/]+)/)?([A-Za-z0-9_]+)::([A-Za-z0-9_]+)$#', $string, $m) !== 1) {
                return $none;
            }

            return ['controller' => $m[1] !== '' ? null : $m[3], 'action' => $m[4], 'prefix' => $m[2] !== '' ? $m[2] : $prefix, 'method' => null];
        }
        $array = $c->arrayAt($range);
        if ($array === null || !is_string($array['controller'] ?? null)) {
            return $none;
        }
        if (isset($array['plugin']) && is_string($array['plugin'])) {
            return $none;
        }
        $method = null;
        if (isset($array['_method'])) {
            $method = is_array($array['_method'])
                ? array_values(array_map(static fn(mixed $m): string => strtoupper(is_string($m) ? $m : ''), $array['_method']))
                : [strtoupper(is_string($array['_method']) ? $array['_method'] : '')];
        }

        return [
            'controller' => $array['controller'],
            'action' => is_string($array['action'] ?? null) ? $array['action'] : 'index',
            'prefix' => array_key_exists('prefix', $array) ? (is_string($array['prefix']) ? $array['prefix'] : null) : $prefix,
            'method' => $method,
        ];
    }

    /**
     * A method or static call at $i: its lowercased name and its "(".
     *
     * @return array{string, int}|null
     */
    private static function callAt(CakeCode $c, int $i): ?array
    {
        if (!in_array($c->t[$i][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
            return null;
        }
        $j = $c->next($i);
        if ($j === null || $c->t[$j][0] !== T_STRING) {
            return null;
        }
        $k = $c->next($j);
        if ($k === null || $c->t[$k][1] !== '(' || !isset($c->close[$k])) {
            return null;
        }

        return [strtolower($c->t[$j][1]), $k];
    }

    private static function receiverIs(CakeCode $c, int $i, string $name): bool
    {
        $p = $c->prev($i);

        return $p !== null && strcasecmp(ltrim(substr($c->t[$p][1], (int) strrpos('\\' . $c->t[$p][1], '\\')), '\\'), $name) === 0;
    }

    /**
     * @param array{int, int}|null $range
     *
     * @return list<string>
     */
    private static function stringList(CakeCode $c, ?array $range): array
    {
        if ($range === null) {
            return [];
        }
        $single = $c->stringAt($range);
        if ($single !== null) {
            return [$single];
        }
        $array = $c->arrayAt($range);

        return $array === null ? [] : array_values(array_filter($array, 'is_string'));
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

    public static function camelize(string $name): string
    {
        return str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $name)));
    }

    public static function join(string $prefix, string $path): string
    {
        $joined = rtrim($prefix, '/') . '/' . ltrim($path, '/');

        return $joined === '/' ? '/' : (rtrim($joined, '/') === '' ? '/' : rtrim($joined, '/'));
    }

    /**
     * The path CakePHP builds for a URL array, as a PHP string expression
     * (without the app's base path), or null when this reader cannot be
     * sure: a named key it does not know, a query, or no route that
     * matches. $pass holds the PHP source of each passed argument.
     *
     * @param list<string> $pass
     */
    public function reverse(string $controller, string $action, ?string $prefix, array $pass): ?string
    {
        // CakePHP takes the first route that matches, so a route this reader
        // cannot evaluate, but that could match, ends the search (null).
        foreach ($this->routes as $route) {
            if ($route['redirect'] || $route['controller'] === null) {
                continue;
            }
            if (strcasecmp($route['controller'], $controller) !== 0 || $route['action'] !== $action || $route['prefix'] !== $prefix) {
                continue;
            }
            if ($route['methods'] !== null && !in_array('GET', $route['methods'], true)) {
                continue;
            }
            $path = $route['path'];
            if ($route['resource'] || $route['patterns']) {
                return null;
            }
            if (preg_match_all('#/[:{](\w+)\}?#', $path, $holes) > 0) {
                // Each placeholder filled from a passed argument by setPass().
                if ($holes[1] !== $route['pass'] || count($pass) !== count($route['pass'])) {
                    return null;
                }
                $expr = var_export('', true);
                $rest = $path;
                foreach ($holes[0] as $k => $hole) {
                    $at = (int) strpos($rest, $hole);
                    $expr .= ' . ' . var_export(substr($rest, 0, $at) . '/', true) . ' . rawurlencode((string) (' . $pass[$k] . '))';
                    $rest = substr($rest, $at + strlen($hole));
                }
                if (preg_match('#[*{:]#', $rest) === 1) {
                    return null;
                }
                $expr .= $rest === '' ? '' : ' . ' . var_export($rest, true);

                return (string) preg_replace("/^'' \\. /", '', $expr);
            }
            if ($route['pass'] !== []) {
                return null;
            }
            $greedy = preg_match('#/\*{1,2}$#', $path) === 1;
            if (!$greedy && $pass !== []) {
                continue;
            }
            $base = $greedy ? (string) preg_replace('#/\*{1,2}$#', '', $path) : $path;

            return self::withPass($base === '' ? '/' : $base, $pass, $greedy);
        }
        foreach ($this->fallbacks as $fallback) {
            if ($fallback['prefix'] !== $prefix) {
                continue;
            }
            $path = rtrim($fallback['path'], '/') . '/' . self::inflect($controller, $this->dashed);
            if ($action !== 'index' || $pass !== []) {
                $path .= '/' . self::inflect($action, $this->dashed);
            }

            return self::withPass($path, $pass, true);
        }

        return null;
    }

    /**
     * @param list<string> $pass
     */
    private static function withPass(string $path, array $pass, bool $greedy): string
    {
        $literal = var_export($path, true);
        if ($pass === []) {
            return $literal;
        }
        $parts = [];
        foreach ($pass as $expr) {
            $parts[] = 'rawurlencode((string) (' . $expr . '))';
        }
        $prefix = rtrim($path, '/') . '/';

        return var_export($prefix, true) . ' . ' . implode(" . '/' . ", $parts);
    }
}
