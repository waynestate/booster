<?php

declare(strict_types=1);

namespace Waynestate\Booster\Strangler;

/**
 * The move units of a Slim app under `legacy/` (design sections 2, 4, 6, 9.2
 * and 10.3): one unit per route definition, except that a GET and a POST
 * defined on the same pattern are one form unit, because a form rendered by
 * one half and posted to the other fails CSRF in both directions.
 *
 * Units come back in move order: stateless GET JSON first, then GET pages,
 * then units with writes, then the ones that need a signed-in user, and a
 * catch-all (the skeleton's CORS `options('/{routes:.*}')`) at the very end
 * whatever its bucket, so OPTIONS and unknown paths keep their legacy answers
 * until the last move. The list order is the move order.
 */
final class SlimUnitSource implements UnitSource
{
    /** Folders under legacy/ that hold no route definitions. */
    private const SKIP_DIRS = ['vendor', 'node_modules', 'tests', 'test', 'var', 'cache', 'tmp', 'logs', 'log', 'templates', 'views', 'storage', '.git', 'client', 'docs'];

    private const ORDER = ['stateless' => 0, 'page' => 1, 'write' => 2, 'signin' => 3];

    public function framework(): string
    {
        return 'slim';
    }

    public function units(string $appRoot): array
    {
        return $this->scan($appRoot)['units'];
    }

    /**
     * The units, the route definitions behind each unit id, and the parsed
     * legacy files (legacy-relative paths), for the mover.
     *
     * @return array{units: list<Unit>, routes: array<string, list<SlimRoute>>, sources: array<string, SlimSource>}
     */
    public function scan(string $appRoot): array
    {
        $codes = self::legacyCodes($appRoot);
        $sources = [];
        foreach ($codes as $path => $code) {
            $sources[$path] = new SlimSource($code);
        }
        $classFiles = [];
        foreach ($sources as $path => $src) {
            foreach ($src->classes as [, , $fqcn]) {
                $short = substr($fqcn, (int) strrpos('\\' . $fqcn, '\\'));
                if (!isset($classFiles[strtolower($fqcn)]) || strcasecmp(basename($path, '.php'), $short) === 0) {
                    $classFiles[strtolower($fqcn)] = $path;
                }
            }
        }
        $routeUnits = [];
        $byId = [];
        $seq = 0;
        foreach (SlimRouteScanner::scan($codes) as $scanned) {
            // An optional segment answers several paths: one unit per Laravel
            // URI, the route name on the longest (section 10.3). Each unit's
            // uri is the route table's form, `{id:regex}` and a trailing
            // `[/]` inline, so the table is as narrow as the legacy route.
            [$variants, $refusal] = SlimPattern::variants($scanned->pattern);
            $shapes = [];
            if ($refusal !== null || count($variants) < 2) {
                $shapes[] = [$scanned, $refusal === null && $variants !== [] ? $variants[0]['table'] : SlimPattern::convert($scanned->pattern)->uri, $refusal === null && $variants !== [] ? $variants[0]['uri'] : SlimPattern::convert($scanned->pattern)->uri];
            } else {
                foreach ($variants as $n => $variant) {
                    $shapes[] = [$scanned->withPattern($variant['table'] === '/' ? '/' : '/' . $variant['table'], $n === count($variants) - 1), $variant['table'], $variant['uri']];
                }
            }
            foreach ($shapes as [$route, $tableUri, $laravelUri]) {
                $seq++;
                $methods = $route->methods;
                $id = self::methodsLabel($methods) . ' ' . SlimPattern::idPath($laravelUri);
                if (isset($byId[$id])) {
                    continue;
                }
                $text = $this->handlerText($route, $sources, $classFiles);
                $signIn = self::needsSignIn($route, $text);
                $unit = new Unit(
                    $id,
                    'route',
                    $methods,
                    $tableUri,
                    $route->name,
                    self::order($methods, $signIn, $text),
                    $signIn,
                    'legacy/' . $route->file,
                    $route->line,
                );
                $byId[$id] = [$route];
                $routeUnits[] = ['unit' => $unit, 'seq' => $seq, 'catchAll' => SlimPattern::isCatchAll($route->written())];
            }
        }
        $units = $this->pairForms($routeUnits, $byId);
        usort($units, static function (array $a, array $b): int {
            return [$a['catchAll'], self::ORDER[$a['unit']->order], $a['seq']] <=> [$b['catchAll'], self::ORDER[$b['unit']->order], $b['seq']];
        });

        return ['units' => array_map(static fn(array $u): Unit => $u['unit'], $units), 'routes' => $byId, 'sources' => $sources];
    }

    /**
     * Every PHP file under legacy/ that can hold route definitions, by its
     * legacy-relative path.
     *
     * @return array<string, string>
     */
    public static function legacyCodes(string $appRoot): array
    {
        $root = rtrim($appRoot, '/\\') . '/legacy';
        if (!is_dir($root)) {
            return [];
        }
        $codes = [];
        $pending = [''];
        while ($pending !== []) {
            $rel = array_pop($pending);
            $entries = scandir($root . ($rel === '' ? '' : '/' . $rel)) ?: [];
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $rel === '' ? $entry : $rel . '/' . $entry;
                $full = $root . '/' . $path;
                if (is_dir($full)) {
                    if (!in_array(strtolower($entry), self::SKIP_DIRS, true) && !is_link($full)) {
                        $pending[] = $path;
                    }
                } elseif (str_ends_with($entry, '.php') && filesize($full) < 2_000_000) {
                    $codes[$path] = (string) file_get_contents($full);
                }
            }
        }
        ksort($codes);

        return $codes;
    }

    /** @param list<string> $methods */
    public static function methodsLabel(array $methods): string
    {
        $sorted = $methods;
        sort($sorted);
        $any = SlimRouteScanner::ANY;
        sort($any);

        return $sorted === $any ? 'ANY' : implode('|', $methods);
    }

    /**
     * @param list<array{unit: Unit, seq: int, catchAll: bool}> $routeUnits
     * @param array<string, list<SlimRoute>>                     $byId
     *
     * @return list<array{unit: Unit, seq: int, catchAll: bool}>
     */
    private function pairForms(array $routeUnits, array &$byId): array
    {
        $gets = [];
        foreach ($routeUnits as $k => $u) {
            if ($u['unit']->methods === ['GET']) {
                $gets[$u['unit']->uri] = $k;
            }
        }
        $out = [];
        $taken = [];
        foreach ($routeUnits as $k => $u) {
            if ($u['unit']->methods !== ['POST'] || !isset($gets[$u['unit']->uri]) || isset($taken[$gets[$u['unit']->uri]])) {
                continue;
            }
            $get = $routeUnits[$gets[$u['unit']->uri]];
            $post = $u;
            $taken[$gets[$u['unit']->uri]] = true;
            $taken[$k] = true;
            $signIn = $get['unit']->needsSignIn || $post['unit']->needsSignIn;
            $id = 'FORM ' . substr($get['unit']->id, (int) strpos($get['unit']->id, ' ') + 1);
            $byId[$id] = array_merge($byId[$get['unit']->id], $byId[$post['unit']->id]);
            $out[] = [
                'unit' => new Unit(
                    $id,
                    'form',
                    ['GET', 'POST'],
                    $get['unit']->uri,
                    $get['unit']->name ?? $post['unit']->name,
                    $signIn ? 'signin' : 'write',
                    $signIn,
                    $get['unit']->sourceFile,
                    $get['unit']->sourceLine,
                    [$get['unit'], $post['unit']],
                ),
                'seq' => min($get['seq'], $post['seq']),
                'catchAll' => false,
            ];
        }
        foreach ($routeUnits as $k => $u) {
            if (!isset($taken[$k])) {
                $out[] = $u;
            }
        }

        return $out;
    }

    /**
     * The source a unit's behaviour is judged from: a closure's own text, or
     * a handler class's file with every parent class's file.
     *
     * @param array<string, SlimSource> $sources
     * @param array<string, string>     $classFiles
     */
    private function handlerText(SlimRoute $route, array $sources, array $classFiles): string
    {
        if ($route->handlerKind !== 'class' || $route->handlerClass === null) {
            return $route->handlerText;
        }
        $text = '';
        $class = $route->handlerClass;
        for ($hops = 0; $class !== null && $hops < 8; $hops++) {
            $path = $classFiles[strtolower($class)] ?? null;
            if ($path === null) {
                break;
            }
            $src = $sources[$path];
            $text .= $src->code . "\n";
            $parent = null;
            foreach ($src->classes as [, , $fqcn, $p]) {
                if (strcasecmp($fqcn, $class) === 0) {
                    $parent = $p;
                }
            }
            $class = $parent;
        }

        return $text;
    }

    public static function needsSignIn(SlimRoute $route, string $text): bool
    {
        if ($route->guards !== []) {
            return true;
        }
        foreach ($route->middleware as $middleware) {
            if (preg_match('/auth|login|sesame|signin|sign_in|guard|acl|role|permission/i', $middleware) === 1) {
                return true;
            }
        }

        return preg_match('/\bsesame_(user|require_mfa|require_context)\s*\(|getAttribute\(\s*[\'"](user|identity)[\'"]\s*\)/i', $text) === 1;
    }

    /** @param list<string> $methods */
    public static function order(array $methods, bool $signIn, string $text): string
    {
        if ($signIn) {
            return 'signin';
        }
        if (array_diff($methods, ['GET', 'HEAD', 'OPTIONS']) !== []) {
            return 'write';
        }
        $json = preg_match('/json_encode|withJson|application\/json|respondWithData|->json\(/i', $text) === 1;
        $state = preg_match('/session|\$_SESSION|cookie|flash|sesame_|->render\(|->fetch\(|\$_COOKIE/i', $text) === 1;

        return $json && !$state ? 'stateless' : 'page';
    }
}
