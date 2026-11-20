<?php

declare(strict_types=1);

namespace Waynestate\Booster\Strangler;

/**
 * The move units of a CakePHP app in `<appRoot>/legacy` (design section 4
 * and 9.2): every route `config/routes.php` connects, plus the controller
 * actions its `fallbacks()` reach, spelled out one per action.
 *
 * Actions that share state move together as one form unit: an action whose
 * template renders a form (`Form->create`, `postLink`, `postButton`) joins
 * the action it posts to, and an action that writes a flash message joins
 * the page it redirects to, because a CSRF token or a flash message never
 * crosses from one half's session to the other's.
 *
 * Move order (section 6): stateless GETs (JSON, no session, no user), then
 * pages, then units that write, then sign-in last.
 */
final class CakeUnitSource implements UnitSource
{
    private const RANK = ['stateless' => 0, 'page' => 1, 'write' => 2, 'signin' => 3];

    public function framework(): string
    {
        return 'cakephp';
    }

    public function units(string $appRoot): array
    {
        return array_map(static fn(array $p): Unit => $p['unit'], $this->plan(new CakeApp($appRoot)));
    }

    /**
     * Every unit with the route entries it is made of, in move order.
     *
     * @return list<array{unit: Unit, entries: list<array{controller: string|null, method: string|null, action: string, path: string, uri: string, name: string|null, get: bool, writes: list<string>, identity: bool, guarded: bool, signIn: bool, json: bool, session: bool, file: string, line: int, seq: int, edges: list<string>}>}>
     */
    public function plan(CakeApp $app): array
    {
        $entries = $this->entries($app);

        // Union-find over actions: a form and its target, a flash and its page.
        $parent = [];
        $find = function (string $x) use (&$parent, &$find): string {
            if (($parent[$x] ?? $x) === $x) {
                return $x;
            }
            $parent[$x] = $find($parent[$x]);

            return $parent[$x];
        };
        $known = [];
        foreach ($entries as $entry) {
            $known[$entry['action']] = true;
            $parent[$entry['action']] ??= $entry['action'];
        }
        foreach ($entries as $entry) {
            foreach ($entry['edges'] as $target) {
                if (isset($known[$target]) && $target !== $entry['action']) {
                    $parent[$find($target)] = $find($entry['action']);
                }
            }
        }
        $groups = [];
        foreach ($entries as $entry) {
            $groups[$find($entry['action'])][] = $entry;
        }

        $units = [];
        foreach ($groups as $group) {
            $actions = array_unique(array_column($group, 'action'));
            $hasWrite = false;
            $hasGet = false;
            foreach ($group as $entry) {
                $hasWrite = $hasWrite || $entry['writes'] !== [];
                $hasGet = $hasGet || $entry['get'];
            }
            if (count($actions) > 1 || ($hasWrite && $hasGet)) {
                $units[] = [...$this->form($group), $group];
                continue;
            }
            foreach ($group as $entry) {
                $units[] = [...$this->route($entry, $entry['get'] ? ['GET'] : $entry['writes']), [$entry]];
            }
        }
        usort($units, static function (array $a, array $b): int {
            return [self::RANK[$a[0]->order], $a[1]] <=> [self::RANK[$b[0]->order], $b[1]];
        });

        return array_map(static fn(array $u): array => ['unit' => $u[0], 'entries' => $u[2]], $units);
    }

    /**
     * Every (action, path) the routes reach, in route order.
     *
     * @return list<array{controller: string|null, method: string|null, action: string, path: string, uri: string, name: string|null, get: bool, writes: list<string>, identity: bool, guarded: bool, signIn: bool, json: bool, session: bool, file: string, line: int, seq: int, edges: list<string>}>
     */
    private function entries(CakeApp $app): array
    {
        $entries = [];
        $seen = [];
        $seq = 0;
        /**
         * @param array{key: string, name: string, prefix: string|null, file: string, code: CakeCode, class: array{name: string, namespace: string, parent: string, abstract: bool, open: int, close: int, methods: array<string, mixed>}}|null $controller
         * @param array{name: string, line: int, open: int, close: int}|null $method
         * @param list<string>|null $verbs
         */
        $add = function (?array $controller, ?array $method, string $path, ?array $verbs, ?string $name, int $routeLine, bool $greedyPages = false) use ($app, &$entries, &$seen, &$seq): void {
            $seq++;
            $actionKey = $controller !== null && $method !== null ? strtolower(($controller['prefix'] ?? '') . '/' . $controller['name'] . '::' . $method['name']) : 'route:' . $path;
            if (isset($seen[$actionKey . ' ' . $path])) {
                return;
            }
            $seen[$actionKey . ' ' . $path] = true;
            if ($controller === null || $method === null) {
                $entries[] = ['controller' => null, 'method' => null, 'action' => $actionKey, 'path' => $path, 'uri' => self::uri($path), 'name' => $name, 'get' => $verbs === null || in_array('GET', $verbs, true), 'writes' => $verbs === null ? [] : array_values(array_diff($verbs, ['GET', 'HEAD'])), 'identity' => false, 'guarded' => false, 'signIn' => false, 'json' => false, 'session' => false, 'file' => 'legacy/config/routes.php', 'line' => $routeLine, 'seq' => $seq, 'edges' => []];

                return;
            }
            /** @var array{key: string, name: string, prefix: string|null, file: string, code: CakeCode, class: array{name: string, namespace: string, parent: string, abstract: bool, open: int, close: int, methods: array<string, array{name: string, public: bool, static: bool, line: int, params: list<array{name: string, optional: bool, variadic: bool, type: string}>, open: int, close: int, start: int}>}} $controller */
            /** @var array{name: string, public: bool, static: bool, line: int, params: list<array{name: string, optional: bool, variadic: bool, type: string}>, open: int, close: int, start: int} $method */
            $facts = $app->analyse($controller, $method);
            $chain = $app->chain($controller);
            $get = $facts['get'];
            $writes = $facts['writes'];
            if ($verbs !== null) {
                $get = in_array('GET', $verbs, true);
                $writes = array_values(array_diff($verbs, ['GET', 'HEAD']));
            }
            $edges = [];
            foreach (array_merge($facts['targets'], $facts['flashTargets']) as $target) {
                $edges[] = strtolower(($target['prefix'] ?? '') . '/' . $target['controller'] . '::' . $target['action']);
            }
            $entries[] = [
                'controller' => $controller['key'],
                'method' => $method['name'],
                'action' => $actionKey,
                'path' => $path,
                'uri' => self::uri($path),
                'name' => $name,
                'get' => $get,
                'writes' => $writes,
                'identity' => $app->requiresIdentity($chain, $method['name']) || $facts['identity'],
                'guarded' => $app->requiresIdentity($chain, $method['name']),
                'signIn' => $facts['signIn'],
                'json' => $facts['json'],
                'session' => $facts['session'],
                'file' => $controller['file'],
                'line' => $method['line'],
                'seq' => $seq,
                'edges' => $edges,
            ];
        };

        foreach ($app->routes->routes as $route) {
            if ($route['redirect']) {
                $add(null, null, $route['path'], $route['methods'] ?? ['GET'], $route['name'], $route['line']);
                continue;
            }
            $controller = $route['controller'] === null ? null : $app->controller($route['controller'], $route['prefix']);
            $method = $controller['class']['methods'][strtolower($route['action'])] ?? null;
            // `/pages/*` to Pages::display: one unit per page template.
            if ($controller !== null && $method !== null && $route['controller'] === 'Pages' && $route['action'] === 'display' && preg_match('#/\*{1,2}$#', $route['path']) === 1) {
                $base = (string) preg_replace('#/\*{1,2}$#', '', $route['path']);
                $pages = glob($app->legacy . '/templates/Pages/*.php') ?: [];
                sort($pages, SORT_STRING);
                foreach ($pages as $page) {
                    $add($controller, $method, rtrim($base, '/') . '/' . basename($page, '.php'), ['GET'], $route['name'], $route['line']);
                }
                continue;
            }
            $add($controller, $method, $route['path'], $route['methods'], $route['name'], $route['line']);
        }
        foreach ($app->routes->fallbacks as $fallback) {
            foreach ($app->controllers() as $controller) {
                if ($controller['prefix'] !== $fallback['prefix'] || in_array($controller['name'], ['Pages'], true)) {
                    continue;
                }
                foreach ($app->actions($controller) as $method) {
                    $path = rtrim($fallback['path'], '/') . '/' . CakeRoutes::inflect($controller['name'], $app->routes->dashed);
                    $required = array_filter($method['params'], static fn(array $p): bool => !$p['optional'] && !$p['variadic']);
                    if ($method['name'] !== 'index' || $required !== []) {
                        $path .= '/' . CakeRoutes::inflect($method['name'], $app->routes->dashed);
                    }
                    foreach ($method['params'] as $param) {
                        if ($param['variadic'] || $param['type'] === 'array') {
                            $path .= '/*';
                            break;
                        }
                        $path .= '/{' . $param['name'] . ($param['optional'] ? '?' : '') . '}';
                    }
                    $add($controller, $method, $path, null, null, $fallback['line']);
                }
            }
        }

        return $entries;
    }

    /** A CakePHP path as a Laravel URI: `/articles/{slug}`, `:slug` and a greedy `/*` tail. */
    public static function uri(string $path): string
    {
        $uri = (string) preg_replace('#/:(\w+)#', '/{$1}', $path);
        $uri = (string) preg_replace('#/\*{1,2}$#', '/{pass?}', $uri);
        $uri = trim($uri, '/');

        return $uri === '' ? '/' : $uri;
    }

    /**
     * @param array{controller: string|null, method: string|null, action: string, path: string, uri: string, name: string|null, get: bool, writes: list<string>, identity: bool, guarded: bool, signIn: bool, json: bool, session: bool, file: string, line: int, seq: int, edges: list<string>} $entry
     * @param list<string> $methods
     *
     * @return array{Unit, int}
     */
    private function route(array $entry, array $methods): array
    {
        $methods = $methods === [] ? ['GET'] : array_values(array_unique($methods));
        $order = 'page';
        if ($entry['signIn']) {
            $order = 'signin';
        } elseif ($methods !== ['GET']) {
            $order = 'write';
        } elseif ($entry['json'] && !$entry['session'] && !$entry['identity']) {
            $order = 'stateless';
        }

        return [new Unit(
            id: $methods[0] . ' ' . self::idPath($entry['uri']),
            kind: 'route',
            methods: $methods,
            uri: $entry['uri'],
            name: $entry['name'],
            order: $order,
            needsSignIn: $entry['identity'],
            sourceFile: $entry['file'],
            sourceLine: $entry['line'],
        ), $entry['seq']];
    }

    /**
     * @param list<array{controller: string|null, method: string|null, action: string, path: string, uri: string, name: string|null, get: bool, writes: list<string>, identity: bool, guarded: bool, signIn: bool, json: bool, session: bool, file: string, line: int, seq: int, edges: list<string>}> $group
     *
     * @return array{Unit, int}
     */
    private function form(array $group): array
    {
        $parts = [];
        $methods = [];
        $signIn = false;
        $needs = false;
        foreach ($group as $entry) {
            if ($entry['get']) {
                $parts[] = $this->route($entry, ['GET'])[0];
                $methods[] = 'GET';
            }
            if ($entry['writes'] !== []) {
                $parts[] = $this->route($entry, $entry['writes'])[0];
                array_push($methods, ...$entry['writes']);
            }
            $signIn = $signIn || $entry['signIn'];
            $needs = $needs || $entry['identity'];
        }
        $producer = $group[0];
        foreach ($group as $entry) {
            if ($entry['get']) {
                $producer = $entry;
                break;
            }
        }

        return [new Unit(
            id: 'FORM ' . self::idPath($producer['uri']),
            kind: 'form',
            methods: array_values(array_unique($methods)),
            uri: $producer['uri'],
            name: $producer['name'],
            order: $signIn ? 'signin' : 'write',
            needsSignIn: $needs,
            sourceFile: $producer['file'],
            sourceLine: $producer['line'],
            parts: $parts,
        ), $group[0]['seq']];
    }

    private static function idPath(string $uri): string
    {
        return $uri === '/' ? '/' : '/' . $uri;
    }
}
