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
     * @return list<array{unit: Unit, after: list<string>, entries: list<array{controller: string|null, method: string|null, action: string, path: string, uri: string, name: string|null, get: bool, writes: list<string>, identity: bool, guarded: bool, signIn: bool, json: bool, session: bool, file: string, line: int, seq: int, edges: list<string>, forms: list<string>}>}>
     */
    public function plan(CakeApp $app): array
    {
        $entries = $this->entries($app);

        // Which action needs which (design 9.2): a flash message and the page
        // it shows on need each other (the message never crosses sessions);
        // an action a form posts to needs every page that renders that form
        // (a form from the other half carries the wrong CSRF token). A page
        // that renders a form for an action that stays legacy does not need
        // it: the moved page prints the legacy token (CakeForm's bridge).
        $needs = [];
        foreach ($entries as $entry) {
            $needs[$entry['action']] ??= [];
        }
        foreach ($entries as $entry) {
            foreach ($entry['edges'] as $target) {
                if (isset($needs[$target]) && $target !== $entry['action']) {
                    $needs[$entry['action']][$target] = true;
                    $needs[$target][$entry['action']] = true;
                }
            }
            foreach ($entry['forms'] as $target) {
                if (isset($needs[$target]) && $target !== $entry['action']) {
                    $needs[$target][$entry['action']] = true;
                }
            }
        }
        $component = self::components($needs);
        $groups = [];
        foreach ($entries as $entry) {
            $groups[$component[$entry['action']]][] = $entry;
        }

        $units = [];
        foreach ($groups as $key => $group) {
            $actions = array_unique(array_column($group, 'action'));
            $hasWrite = false;
            $hasGet = false;
            foreach ($group as $entry) {
                $hasWrite = $hasWrite || $entry['writes'] !== [];
                $hasGet = $hasGet || $entry['get'];
            }
            if (count($actions) > 1 || ($hasWrite && $hasGet)) {
                $units[] = [...$this->form($group), $group, $key];
                continue;
            }
            foreach ($group as $entry) {
                $units[] = [...$this->route($entry, $entry['get'] ? ['GET'] : $entry['writes']), [$entry], $key];
            }
        }
        usort($units, static function (array $a, array $b): int {
            return [self::RANK[$a[0]->order], $a[1]] <=> [self::RANK[$b[0]->order], $b[1]];
        });

        // The components each component needs moved first (a DAG: needs
        // inside a cycle are inside one component).
        $after = [];
        foreach ($needs as $action => $targets) {
            foreach (array_keys($targets) as $target) {
                if ($component[$target] !== $component[$action]) {
                    $after[$component[$action]][$component[$target]] = true;
                }
            }
        }
        // Move order: a unit after the units it needs, then by rank and route order.
        $ordered = [];
        $done = [];
        while (count($ordered) < count($units)) {
            foreach ($units as $i => $u) {
                if (isset($done[$i])) {
                    continue;
                }
                $ready = true;
                foreach (array_keys($after[$u[3]] ?? []) as $needed) {
                    foreach ($units as $j => $v) {
                        if ($v[3] === $needed && !isset($done[$j])) {
                            $ready = false;
                        }
                    }
                }
                if ($ready) {
                    $done[$i] = true;
                    $ordered[] = $u;
                    continue 2;
                }
            }
            break;
        }

        return array_map(static function (array $u) use ($units, $after): array {
            $ids = [];
            foreach (array_keys($after[$u[3]] ?? []) as $needed) {
                foreach ($units as $v) {
                    if ($v[3] === $needed) {
                        $ids[] = $v[0]->id;
                    }
                }
            }

            return ['unit' => $u[0], 'entries' => $u[2], 'after' => array_values(array_unique($ids))];
        }, $ordered);
    }

    /**
     * Strongly connected components of the needs graph (Tarjan): action to
     * the component's first action.
     *
     * @param array<string, array<string, true>> $needs
     *
     * @return array<string, string>
     */
    private static function components(array $needs): array
    {
        $index = [];
        $low = [];
        $stack = [];
        $on = [];
        $out = [];
        $n = 0;
        $visit = function (string $v) use (&$visit, &$index, &$low, &$stack, &$on, &$out, &$n, $needs): void {
            $index[$v] = $low[$v] = $n++;
            $stack[] = $v;
            $on[$v] = true;
            foreach (array_keys($needs[$v] ?? []) as $w) {
                if (!isset($index[$w])) {
                    $visit($w);
                    $low[$v] = min($low[$v], $low[$w]);
                } elseif (isset($on[$w])) {
                    $low[$v] = min($low[$v], $index[$w]);
                }
            }
            if ($low[$v] === $index[$v]) {
                $members = [];
                do {
                    $w = (string) array_pop($stack);
                    unset($on[$w]);
                    $members[] = $w;
                } while ($w !== $v);
                foreach ($members as $m) {
                    $out[$m] = $v;
                }
            }
        };
        foreach (array_keys($needs) as $v) {
            if (!isset($index[$v])) {
                $visit((string) $v);
            }
        }

        return $out;
    }

    /**
     * Every (action, path) the routes reach, in route order.
     *
     * @return list<array{controller: string|null, method: string|null, action: string, path: string, uri: string, name: string|null, get: bool, writes: list<string>, identity: bool, guarded: bool, signIn: bool, json: bool, session: bool, file: string, line: int, seq: int, edges: list<string>, forms: list<string>}>
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
                $entries[] = ['controller' => null, 'method' => null, 'action' => $actionKey, 'path' => $path, 'uri' => self::uri($path), 'name' => $name, 'get' => $verbs === null || in_array('GET', $verbs, true), 'writes' => $verbs === null ? [] : array_values(array_diff($verbs, ['GET', 'HEAD'])), 'identity' => false, 'guarded' => false, 'signIn' => false, 'json' => false, 'session' => false, 'file' => 'legacy/config/routes.php', 'line' => $routeLine, 'seq' => $seq, 'edges' => [], 'forms' => []];

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
            foreach ($facts['flashTargets'] as $target) {
                $edges[] = strtolower(($target['prefix'] ?? '') . '/' . $target['controller'] . '::' . $target['action']);
            }
            $forms = [];
            foreach ($facts['targets'] as $target) {
                $forms[] = strtolower(($target['prefix'] ?? '') . '/' . $target['controller'] . '::' . $target['action']);
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
                'forms' => $forms,
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
     * @param array{controller: string|null, method: string|null, action: string, path: string, uri: string, name: string|null, get: bool, writes: list<string>, identity: bool, guarded: bool, signIn: bool, json: bool, session: bool, file: string, line: int, seq: int, edges: list<string>, forms: list<string>} $entry
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
     * @param list<array{controller: string|null, method: string|null, action: string, path: string, uri: string, name: string|null, get: bool, writes: list<string>, identity: bool, guarded: bool, signIn: bool, json: bool, session: bool, file: string, line: int, seq: int, edges: list<string>, forms: list<string>}> $group
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
