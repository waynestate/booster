<?php

declare(strict_types=1);

namespace Waynestate\Booster\Strangler;

/**
 * Moves one Slim unit to the Laravel half (design sections 4, 9.2 and 10.1
 * to 10.4). Files only: the caller gates the move and then adds the unit to
 * the route table.
 *
 * What a move writes:
 * - the route in routes/web.php, name and `where()` constraints kept, behind
 *   a `// booster:strangler <unit id>` marker, sent to SlimAdapter;
 * - the handler: a handler class (and every app class it reaches) copied
 *   under app/Http/Controllers/Legacy/ with its name kept below that
 *   namespace; a closure as a small class there whose handler() returns the
 *   closure exactly as written;
 * - once per app: the adapter and its helpers (resources/strangler/slim/),
 *   app/Providers/LegacyServiceProvider.php (registered in
 *   bootstrap/providers.php), and the manifest booster-slim.php the adapter
 *   reads: how the legacy app builds its container (PHP-DI definition files
 *   are copied and rebuilt in Laravel, and each key is bound as
 *   `legacy.<key>`), its response emitter, error handler, body parsing and
 *   output buffering;
 * - the Composer packages the copied code names, into the root composer.json
 *   at the legacy constraint (lastComposerAdds() lists them for the gate's
 *   `composer update`).
 *
 * The handler code runs unchanged: SlimAdapter gives it the PSR-7 request,
 * response and `$args` Slim would, appends what it echoes (10.1), and sends
 * its response through the legacy emitter. A moved signed-in unit reads the
 * user through sesame_user(), which the adapter points at the Laravel half's
 * SESAME instance, the one SESAME's Laravel guard reads.
 */
final class SlimMover implements Mover
{
    public const MANIFEST = SlimCodeCopier::DIR . 'booster-slim.php';

    public const PROVIDER = 'app/Providers/LegacyServiceProvider.php';

    /** The runtime files every Slim move needs, from resources/strangler/slim/. */
    private const SUPPORT = ['SlimAdapter.php', 'LegacyClosure.php', 'LegacyContainer.php', 'CapturingEmitter.php', 'LegacyResponse.php', 'LegacyTwigExtension.php'];

    /** Paths the Laravel half always owns (section 10.6); a legacy route there cannot move. */
    private const LARAVEL_OWNED = ['_laravel', 'build', 'up'];

    /** @var array<string, string> */
    private array $composerAdds = [];

    public function framework(): string
    {
        return 'slim';
    }

    /**
     * The packages the last move added to the root composer.json, with their
     * constraints: the gate runs `composer update` for them before it serves.
     *
     * @return array<string, string>
     */
    public function lastComposerAdds(): array
    {
        return $this->composerAdds;
    }

    public function move(Unit $unit, string $appRoot): MoveResult
    {
        $this->composerAdds = [];
        $appRoot = rtrim($appRoot, '/\\');
        $scan = (new SlimUnitSource())->scan($appRoot);
        $routes = $scan['routes'][$unit->id] ?? null;
        if ($routes === null) {
            return self::refused($unit->sourceFile, $unit->sourceLine, 'no Slim route with the id ' . $unit->id . ' is left in legacy/');
        }
        $web = $appRoot . '/routes/web.php';
        if (!is_file($web) || !is_dir($appRoot . '/app')) {
            return self::refused($unit->sourceFile, $unit->sourceLine, 'the Laravel half is not mounted (no routes/web.php); run the mount step first');
        }
        $webCode = (string) file_get_contents($web);
        if (str_contains($webCode, self::marker($unit->id))) {
            return self::refused($unit->sourceFile, $unit->sourceLine, $unit->id . ' is already in routes/web.php');
        }
        $ledger = SlimCodeCopier::json($appRoot . '/booster-strangler.json');
        $refusals = [];
        if ($unit->needsSignIn && ($ledger['step0'] ?? 'pending') !== 'done') {
            $refusals[] = ['file' => $unit->sourceFile, 'line' => $unit->sourceLine, 'reason' => $unit->id . ' needs a signed-in user; it moves once Step 0 (sign-in on SESAME) is done'];
        }
        $legacyComposer = SlimCodeCopier::json($appRoot . '/legacy/composer.json');
        if (!isset($legacyComposer['require']['slim/psr7'])) {
            $refusals[] = ['file' => 'legacy/composer.json', 'line' => 1, 'reason' => 'the app does not use slim/psr7; the adapter builds Slim\'s own PSR-7 request and response, so another PSR-7 implementation would change what the handler sees'];
        }
        $front = $this->frontController($appRoot, $scan['sources']);
        $movedIds = self::movedIds($webCode, $unit->id);
        $csrf = self::csrf($legacyComposer, $scan['sources']);
        $bootstrap = isset($scan['sources']['public/index.php'])
            && ($front['container'] !== 'php-di' || $front['refusal'] !== null || $csrf !== null || self::needsBootstrap($movedIds, $scan));
        foreach ($routes as $route) {
            $refusals = array_merge($refusals, $this->routeRefusals($route, $appRoot, $bootstrap));
        }
        $refusals = array_merge($refusals, $this->shadowRefusals($unit, $scan, $ledger));
        if ($front['refusal'] !== null && !$bootstrap) {
            $refusals[] = $front['refusal'];
        }
        if ($refusals !== []) {
            return new MoveResult(false, [], self::unique($refusals), []);
        }

        $copier = new SlimCodeCopier($appRoot, $scan['sources']);
        if ($bootstrap) {
            return $this->moveBootstrap($unit, $appRoot, $scan, $routes, $movedIds, $copier, $csrf, $webCode, $web);
        }
        $manual = [];
        $blocks = [];
        foreach ($routes as $route) {
            $spec = $this->handler($route, $copier, $scan['sources'], $appRoot);
            if ($spec === null) {
                $copier->refusals[] = ['file' => 'legacy/' . $route->file, 'line' => $route->handlerLine, 'reason' => 'the handler ' . trim($route->handlerText) . ' is not a class in the app\'s own code'];

                continue;
            }
            $blocks[] = $this->routeLine($route, $spec);
            if (array_diff($route->methods, ['GET', 'HEAD', 'OPTIONS']) !== []) {
                $manual[] = 'legacy/' . $route->file . ':' . $route->line . ' ' . implode('|', $route->methods) . ' ' . $route->pattern . ' had no CSRF check in Slim and has none in Laravel either (the route skips PreventRequestForgery so the legacy form still posts); add one when its form is ported';
            }
        }
        $manifest = $this->manifest($front, $copier, $scan['sources'], $appRoot, self::twigManifest($scan));
        if ($copier->refusals !== []) {
            return new MoveResult(false, [], self::unique($copier->refusals), []);
        }
        $changed = [];
        foreach (self::SUPPORT as $file) {
            $copier->write(SlimCodeCopier::DIR . 'Support/' . $file, (string) file_get_contents(self::resource($file)));
        }
        $copier->write(self::PROVIDER, (string) file_get_contents(self::resource('LegacyServiceProvider.php')));
        $manifestCode = "<?php\n\n// Written by Booster's strangler (slim-to-laravel): how the moved Slim handlers run. App-owned.\n\nreturn " . self::export($manifest) . ";\n";
        $manifestFile = $appRoot . '/' . self::MANIFEST;
        if (!is_file($manifestFile) || file_get_contents($manifestFile) !== $manifestCode) {
            if (!is_dir(dirname($manifestFile))) {
                mkdir(dirname($manifestFile), 0o777, true);
            }
            file_put_contents($manifestFile, $manifestCode);
            $changed[] = self::MANIFEST;
        }
        $changed = array_merge($changed, $copier->flush());
        if ($this->registerProvider($appRoot)) {
            $changed[] = 'bootstrap/providers.php';
        }
        $block = "\n" . self::marker($unit->id) . $unit->sourceFile . ':' . $unit->sourceLine . "\n" . implode("\n", $blocks) . "\n";
        file_put_contents($web, rtrim($webCode, "\n") . "\n" . $block);
        $changed[] = 'routes/web.php';
        if ($this->requirePackages($appRoot, $copier, $front, $manual)) {
            $changed[] = 'composer.json';
        }
        $manual = array_merge($manual, $copier->manual, $front['manual']);
        sort($changed);

        return new MoveResult(true, array_values(array_unique($changed)), [], array_values(array_unique($manual)));
    }

    /**
     * Bootstrap mode (design 9.2, 10.2): the moved routes run in a copy of
     * the legacy front controller and everything it loads, with the routes
     * script swapped for one that registers only the moved routes (see
     * SlimBootstrap). The container, app middleware, guards, route
     * middleware and CSRF check are the legacy code's own.
     *
     * @param array{units: list<Unit>, routes: array<string, list<SlimRoute>>, sources: array<string, SlimSource>} $scan
     * @param list<SlimRoute> $routes
     * @param list<string>    $movedIds
     */
    private function moveBootstrap(Unit $unit, string $appRoot, array $scan, array $routes, array $movedIds, SlimCodeCopier $copier, ?string $csrf, string $webCode, string $web): MoveResult
    {
        $manual = [];
        $scripts = $this->bootstrapScripts($copier, $scan, $movedIds);
        $blocks = [];
        foreach ($routes as $route) {
            $blocks[] = $this->routeLine($route, 'bootstrap');
            if (array_diff($route->methods, ['GET', 'HEAD', 'OPTIONS']) === []) {
                continue;
            }
            $manual[] = $csrf !== null
                ? 'legacy/' . $route->file . ':' . $route->line . ' ' . implode('|', $route->methods) . ' ' . $route->written() . ' still has the legacy CSRF check (' . $csrf . '): it runs in the copied pipeline, and Laravel\'s PreventRequestForgery stays off on the route because the legacy form posts the legacy token'
                : 'legacy/' . $route->file . ':' . $route->line . ' ' . implode('|', $route->methods) . ' ' . $route->written() . ' had no CSRF check in Slim and has none in Laravel either (the route skips PreventRequestForgery so the legacy form still posts); add one when its form is ported';
        }
        if ($copier->refusals !== []) {
            return new MoveResult(false, [], self::unique($copier->refusals), []);
        }
        $changed = [];
        foreach (self::SUPPORT as $file) {
            $copier->write(SlimCodeCopier::DIR . 'Support/' . $file, (string) file_get_contents(self::resource($file)));
        }
        $copier->write(self::PROVIDER, (string) file_get_contents(self::resource('LegacyServiceProvider.php')));
        $manifest = [
            'mode' => 'bootstrap',
            'front' => SlimCodeCopier::BOOT . 'public/index.php',
            'keys' => self::containerKeys($copier, $scan['sources']),
            'csrf' => $csrf,
            'twig' => self::twigManifest($scan),
        ];
        $generated = $scripts + [self::MANIFEST => "<?php\n\n// Written by Booster's strangler (slim-to-laravel): how the moved Slim handlers run. App-owned.\n\nreturn " . self::export($manifest) . ";\n"];
        foreach ($generated as $relative => $code) {
            $file = $appRoot . '/' . $relative;
            if (is_file($file) && file_get_contents($file) === $code) {
                continue;
            }
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0o777, true);
            }
            file_put_contents($file, $code);
            $changed[] = $relative;
        }
        $changed = array_merge($changed, $copier->flush());
        if ($this->registerProvider($appRoot)) {
            $changed[] = 'bootstrap/providers.php';
        }
        $block = "\n" . self::marker($unit->id) . $unit->sourceFile . ':' . $unit->sourceLine . "\n" . implode("\n", $blocks) . "\n";
        file_put_contents($web, rtrim($webCode, "\n") . "\n" . $block);
        $changed[] = 'routes/web.php';
        $front = ['container' => 'bootstrap', 'error_middleware' => true, 'error_handler' => null, 'emitter' => null];
        if ($this->requirePackages($appRoot, $copier, $front, $manual)) {
            $changed[] = 'composer.json';
        }
        $manual[] = 'the moved routes run in a copy of legacy/public/index.php and what it loads (' . SlimCodeCopier::BOOT . '); its routes script registers only the moved routes, inside the same role checks and with the same middleware, so a guest gets what legacy answers';
        $manual = array_merge($manual, $copier->manual);
        sort($changed);

        return new MoveResult(true, array_values(array_unique($changed)), [], array_values(array_unique($manual)));
    }

    /**
     * The generated routes scripts: app-relative file to code.
     *
     * @param array{units: list<Unit>, routes: array<string, list<SlimRoute>>, sources: array<string, SlimSource>} $scan
     * @param list<string> $movedIds
     *
     * @return array<string, string>
     */
    private function bootstrapScripts(SlimCodeCopier $copier, array $scan, array $movedIds): array
    {
        $copier->bootstrap = true;
        foreach ($scan['routes'] as $list) {
            foreach ($list as $route) {
                $entry = $route->links[0][0] ?? null;
                if ($entry !== null && ($scan['sources'][$entry] ?? null)?->classes === []) {
                    $copier->routeScripts[$entry] = SlimCodeCopier::DIR . 'routes/' . $entry;
                }
            }
        }
        $copier->copyScript('public/index.php');
        $boot = new SlimBootstrap($scan['sources'], $copier);
        $blocks = [];
        $moved = [];
        foreach ($movedIds as $id) {
            foreach ($scan['routes'][$id] ?? [] as $route) {
                $moved[self::routeKey($route)] = true;
                $entry = $route->links[0][0] ?? '';
                $where = ['file' => 'legacy/' . $route->file, 'line' => $route->line];
                if (!isset($copier->routeScripts[$entry]) || !isset($copier->routeHits[$entry])) {
                    $copier->refusals[] = $where + ['reason' => 'the route is registered from legacy/' . $entry . ', which legacy/public/index.php never loads as a routes script; Booster swaps only a routes script the front controller loads'];

                    continue;
                }
                $registration = $boot->registration($route);
                if ($registration['code'] === null) {
                    $copier->refusals[] = $where + ['reason' => (string) $registration['refusal']];

                    continue;
                }
                $blocks[$entry][$registration['code']] = true;
            }
        }
        $names = [];
        foreach ($scan['routes'] as $list) {
            foreach ($list as $route) {
                $entry = $route->links[0][0] ?? '';
                if ($route->name === null || isset($names[$route->name]) || isset($moved[self::routeKey($route)]) || !isset($copier->routeHits[$entry])) {
                    continue;
                }
                $names[$route->name] = true;
                $blocks[$entry][SlimBootstrap::placeholder($route)] = true;
            }
        }
        $out = [];
        foreach ($copier->routeScripts as $entry => $target) {
            if (isset($copier->routeHits[$entry])) {
                $out[$target] = SlimBootstrap::script($scan['sources'][$entry], $entry, array_map('strval', array_keys($blocks[$entry] ?? [])));
            }
        }

        return $out;
    }

    /** One legacy route definition, whatever expansion of it a unit holds. */
    private static function routeKey(SlimRoute $route): string
    {
        return implode('|', $route->methods) . ' ' . $route->written() . ' ' . $route->file . ':' . $route->line;
    }

    /**
     * The unit ids routes/web.php already holds, and this one.
     *
     * @return list<string>
     */
    private static function movedIds(string $webCode, string $unitId): array
    {
        preg_match_all('#^// booster:strangler (.+?) from #m', $webCode, $m);

        return array_values(array_unique(array_merge($m[1], [$unitId])));
    }

    /**
     * The legacy CSRF check, when the app has one: slim/csrf, or a
     * middleware class of its own named for CSRF.
     *
     * @param array<mixed>              $legacyComposer
     * @param array<string, SlimSource> $sources
     */
    private static function csrf(array $legacyComposer, array $sources): ?string
    {
        if (isset($legacyComposer['require']['slim/csrf'])) {
            return 'slim/csrf';
        }
        foreach ($sources as $path => $src) {
            if (preg_match('/\bnew\s+\\\\?((?:\w+\\\\)*\w*Csrf\w*)\s*\(|\b((?:\w+\\\\)*\w*Csrf\w*)::class/i', $src->code, $m) === 1 && !str_starts_with($path, 'vendor/')) {
                return ltrim($m[1] !== '' ? $m[1] : $m[2], '\\');
            }
        }

        return null;
    }

    /**
     * Whether a moved route needs the legacy pipeline: it is registered
     * inside an if, adds middleware, or its closure imports variables.
     *
     * @param list<string> $movedIds
     * @param array{units: list<Unit>, routes: array<string, list<SlimRoute>>, sources: array<string, SlimSource>} $scan
     */
    private static function needsBootstrap(array $movedIds, array $scan): bool
    {
        foreach ($movedIds as $id) {
            foreach ($scan['routes'][$id] ?? [] as $route) {
                if ($route->guards !== [] || $route->middleware !== [] || $route->closureUses !== []) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The legacy container's keys, read from the copied scripts that return
     * `'key' => function ($c) {...}` arrays (core.php, globals.php) and the
     * `$container['key'] = ...` lines: each is bound as legacy.<key>.
     *
     * @param array<string, SlimSource> $sources
     *
     * @return list<string>
     */
    private static function containerKeys(SlimCodeCopier $copier, array $sources): array
    {
        $keys = [];
        foreach (array_keys($copier->scripts) as $path) {
            if (isset($copier->routeScripts[$path])) {
                continue;
            }
            $code = $sources[$path]->code;
            preg_match_all('/^\s*[\'"]([\w.\-]+)[\'"]\s*=>\s*(?:static\s+)?(?:function|fn)\s*\(\s*(?:[\\\\\w]+\s+)?(?:\$(?:c|container|ci)\b)?\s*\)/m', $code, $m);
            $keys = array_merge($keys, $m[1]);
        }
        foreach ($sources as $path => $src) {
            if (str_starts_with($path, 'vendor/')) {
                continue;
            }
            preg_match_all('/\$(?:this->)?(?:container|c|ci)\s*\[\s*[\'"]([\w.\-]+)[\'"]\s*\]\s*=(?!=)/', $src->code, $m);
            $keys = array_merge($keys, $m[1]);
        }
        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    /**
     * Every named legacy route's pattern, for LegacyTwigExtension's url_for()
     * in Laravel-rendered Twig views (TwigBridge).
     *
     * @param array{units: list<Unit>, routes: array<string, list<SlimRoute>>, sources: array<string, SlimSource>} $scan
     *
     * @return array{routes: array<string, string>, templates: string}
     */
    private static function twigManifest(array $scan): array
    {
        $routes = [];
        foreach ($scan['routes'] as $list) {
            foreach ($list as $route) {
                if ($route->name !== null) {
                    $routes[$route->name] ??= $route->written();
                }
            }
        }
        ksort($routes);

        return ['routes' => $routes, 'templates' => 'legacy/templates'];
    }

    /** The routes/web.php comment that marks a moved unit (the finish and --hand steps find it). */
    public static function marker(string $unitId): string
    {
        return '// booster:strangler ' . $unitId . ' from ';
    }

    /**
     * @return list<array{file: string, line: int, reason: string}>
     */
    private function routeRefusals(SlimRoute $route, string $appRoot, bool $bootstrap = false): array
    {
        $out = [];
        $where = 'legacy/' . $route->file;
        $pattern = SlimPattern::convert($route->pattern);
        if ($pattern->refusal !== null) {
            $out[] = ['file' => $where, 'line' => $route->line, 'reason' => $pattern->refusal . '; port it by hand, then run the --hand move'];
        }
        $first = explode('/', $pattern->uri)[0];
        if (in_array($first, self::LARAVEL_OWNED, true)) {
            $out[] = ['file' => $where, 'line' => $route->line, 'reason' => 'the path ' . $route->written() . ' is one the Laravel half always owns (/' . $first . ')'];
        }
        if ($bootstrap) {
            // The copied front controller registers the route as legacy
            // does: guards, middleware and closure imports included.
            return $out;
        }
        foreach ($route->guards as $guard) {
            $out[] = ['file' => $where, 'line' => $route->line, 'reason' => 'the route is registered only when `' . $guard . '` is true, and the Laravel half has no such rule; port the check into the handler or a Laravel middleware, then run the --hand move'];
        }
        foreach ($route->middleware as $middleware) {
            $out[] = ['file' => $where, 'line' => $route->line, 'reason' => 'the route or its group adds the middleware ' . $middleware . ', which does not run in Laravel; port it as Laravel middleware, then run the --hand move'];
        }
        if ($route->handlerKind === 'closure' && $route->closureUses !== []) {
            $out[] = ['file' => $where, 'line' => $route->handlerLine, 'reason' => 'the closure imports $' . implode(', $', $route->closureUses) . ' from the routes file, which the Laravel half does not have'];
        }
        if ($route->handlerKind === 'unknown') {
            $out[] = ['file' => $where, 'line' => $route->handlerLine, 'reason' => 'Booster cannot read the handler ' . trim($route->handlerText) . ' (a closure, X::class, \'X:method\' or [X::class, \'method\'] moves)'];
        }

        return $out;
    }

    /**
     * A unit whose path pattern would also catch a legacy route that stays
     * behind refuses, because the dispatcher would send that route to Laravel
     * too: a static path FastRoute answered first, or a variable route
     * registered before this one.
     *
     * @param array{units: list<Unit>, routes: array<string, list<SlimRoute>>, sources: array<string, SlimSource>} $scan
     * @param array<mixed> $ledger
     *
     * @return list<array{file: string, line: int, reason: string}>
     */
    private function shadowRefusals(Unit $unit, array $scan, array $ledger): array
    {
        $moved = [];
        foreach ((array) ($ledger['units'] ?? []) as $entry) {
            if (is_array($entry) && in_array($entry['state'] ?? '', ['laravel', 'hand'], true)) {
                $moved[(string) ($entry['id'] ?? '')] = true;
            }
        }
        $mine = array_map(static fn(SlimRoute $r): string => spl_object_hash($r), $scan['routes'][$unit->id]);
        $order = [];
        foreach ($scan['routes'] as $id => $list) {
            if (!str_starts_with($id, 'FORM ')) {
                foreach ($list as $r) {
                    $order[spl_object_hash($r)] = count($order);
                }
            }
        }
        $owner = [];
        foreach ($scan['units'] as $u) {
            foreach ($u->parts === [] ? [$u] : $u->parts as $part) {
                $owner[$part->id] = $u->id;
            }
        }
        $out = [];
        foreach ($scan['routes'][$unit->id] as $route) {
            $p = SlimPattern::convert($route->pattern);
            $regex = SlimPattern::regex($p->uri, $p->wheres);
            foreach ($scan['routes'] as $id => $others) {
                if (str_starts_with($id, 'FORM ') || isset($moved[$owner[$id] ?? $id]) || ($owner[$id] ?? $id) === $unit->id) {
                    continue;
                }
                foreach ($others as $other) {
                    if (in_array(spl_object_hash($other), $mine, true) || array_intersect($other->methods, $route->methods) === []) {
                        continue;
                    }
                    $op = SlimPattern::convert($other->pattern);
                    $static = !str_contains($op->uri, '{');
                    $path = $static ? SlimPattern::idPath($op->uri) : SlimPattern::sample($op->uri, $op->wheres);
                    $earlier = ($order[spl_object_hash($other)] ?? PHP_INT_MAX) < ($order[spl_object_hash($route)] ?? 0);
                    if ($path !== null && ($static || $earlier) && preg_match($regex, $path) === 1) {
                        $out[] = ['file' => 'legacy/' . $route->file, 'line' => $route->line, 'reason' => $unit->id . ' would also catch ' . ($owner[$id] ?? $id) . ' (legacy/' . $other->file . ':' . $other->line . '), which stays in legacy; move that unit first'];
                    }
                }
            }
        }

        return $out;
    }

    /**
     * What the legacy front controller (public/index.php) does around the
     * handlers: the container it builds, its error handler, emitter, body
     * parsing and output buffering.
     *
     * @param array<string, SlimSource> $sources
     *
     * @return array{container: string, files: list<string>, error_handler: ?string, error_middleware: bool, emitter: ?string, body_parsing: bool, output_buffering: string, refusal: ?array{file: string, line: int, reason: string}, manual: list<string>}
     */
    private function frontController(string $appRoot, array $sources): array
    {
        $out = ['container' => 'none', 'files' => [], 'error_handler' => null, 'error_middleware' => false, 'emitter' => null, 'body_parsing' => false, 'output_buffering' => 'none', 'refusal' => null, 'manual' => []];
        $path = 'public/index.php';
        $src = $sources[$path] ?? null;
        if ($src === null) {
            $out['refusal'] = ['file' => 'legacy/public', 'line' => 1, 'reason' => 'no legacy/public/index.php: Booster reads how the app builds its container and handles errors from Slim\'s front controller there'];

            return $out;
        }
        $code = $src->code;
        $all = implode("\n", array_map(static fn(SlimSource $s): string => $s->code, $sources));
        if (preg_match('/\$(\w+)\s*=\s*new\s+\\\\?(?:DI\\\\)?ContainerBuilder\b/', $code, $b) === 1) {
            $out['container'] = 'php-di';
            preg_match_all('/\$(\w+)\s*=\s*require(?:_once)?\s*\(?\s*__DIR__\s*\.\s*[\'"]([^\'"]+)[\'"]\s*\)?\s*;/', $code, $requires, PREG_SET_ORDER);
            $files = [];
            foreach ($requires as $m) {
                $files[$m[1]] = self::normalize(dirname($path) . '/' . ltrim($m[2], '/'));
            }
            preg_match_all('/\$(\w+)\s*\(\s*\$' . preg_quote($b[1], '/') . '\s*\)\s*;/', $code, $calls, PREG_SET_ORDER);
            foreach ($calls as $m) {
                if (isset($files[$m[1]])) {
                    $out['files'][] = $files[$m[1]];
                }
            }
            if (preg_match('/\$' . preg_quote($b[1], '/') . '\s*->\s*addDefinitions\s*\(/', $code) === 1) {
                $out['refusal'] = ['file' => 'legacy/' . $path, 'line' => self::lineOf($code, 'addDefinitions'), 'reason' => 'the front controller adds PHP-DI definitions inline; Booster rebuilds the container only from definition files the front controller requires'];
            }
        } elseif (preg_match('/setContainer\s*\(|new\s+\\\\?Pimple|new\s+\\\\?Slim\\\\Container|LegacyApp::create|new\s+\\\\?Slim\\\\App\s*\(/', $code) === 1 || !str_contains($code, 'AppFactory::create')) {
            $out['refusal'] = ['file' => 'legacy/' . $path, 'line' => 1, 'reason' => 'the app builds its Slim app or container in a shape Booster does not rebuild in Laravel yet (it rebuilds PHP-DI definition files, or no container); bind the services in app/Providers/LegacyServiceProvider.php and port the unit, then run the --hand move'];
        }
        $out['body_parsing'] = str_contains($code, 'addBodyParsingMiddleware');
        if (preg_match('/OutputBufferingMiddleware\s*\([^;]*PREPEND/', $all) === 1) {
            $out['output_buffering'] = 'prepend';
        } elseif (str_contains($all, 'OutputBufferingMiddleware')) {
            $out['output_buffering'] = 'append';
        }
        $out['error_middleware'] = str_contains($code, 'addErrorMiddleware');
        if (preg_match('/->\s*setDefaultErrorHandler\s*\(\s*\$(\w+)\s*\)/', $code, $h) === 1
            && preg_match('/\$' . preg_quote($h[1], '/') . '\s*=\s*new\s+([\\\\\w]+)\s*\(/', $code, $n) === 1) {
            $out['error_handler'] = $src->resolveClass($n[1], self::tokenAt($src, $n[1]));
        }
        foreach (self::allMatches('/\$(\w+)\s*=\s*new\s+([\\\\\w]+)\s*\([^;]*\)\s*;/', $code) as $m) {
            if (preg_match('/\$' . preg_quote($m[1], '/') . '\s*->\s*emit\s*\(/', $code) === 1) {
                $out['emitter'] = $src->resolveClass($m[2], self::tokenAt($src, $m[2]));
            }
        }
        if ($out['error_middleware'] && $out['error_handler'] !== null) {
            $out['manual'][] = 'legacy/' . $path . ' moved units render errors through ' . $out['error_handler'] . ' with displayErrorDetails, logError and logErrorDetails off; the legacy half reads them from its settings';
        }
        foreach (self::allMatches('/\$app\s*->\s*add\s*\(\s*([^;]+?)\)\s*;/', $all) as $m) {
            $out['manual'][] = 'legacy app middleware ' . trim($m[1]) . ' does not run for moved units; port what it does to the Laravel half (a Laravel middleware) before the units it affects move';
        }

        return $out;
    }

    /**
     * Copy the handler and return the adapter's spec for it:
     * "class:<fqcn>", "class:<fqcn>@<method>" or "closure:<fqcn>".
     *
     * @param array<string, SlimSource> $sources
     */
    private function handler(SlimRoute $route, SlimCodeCopier $copier, array $sources, string $appRoot): ?string
    {
        if ($route->handlerKind === 'class' && $route->handlerClass !== null) {
            if (!$copier->copyClass($route->handlerClass)) {
                return null;
            }
            $mapped = (string) $copier->map($route->handlerClass);

            return 'class:' . $mapped . ($route->handlerMethod === null ? '' : '@' . $route->handlerMethod);
        }
        $src = $sources[$route->file];
        [$from, $to] = $this->closureRange($src, $route);
        [$closure, $refs] = $copier->rewrite($src, $route->file, $from, $to);
        foreach ($refs as $ref) {
            $copier->copyClass($ref);
        }
        $name = self::className($route);
        $class = SlimCodeCopier::NAMESPACE . 'Closures\\' . $name;
        $strict = str_contains($src->code, 'strict_types=1') ? "\ndeclare(strict_types=1);\n" : '';
        $imports = $copier->importLines($src, $closure);
        $body = '        return ' . self::reindent($closure, '        ') . ';';
        $code = "<?php\n" . $strict . "\nnamespace " . rtrim(SlimCodeCopier::NAMESPACE, '\\') . "\\Closures;\n\n"
            . ($imports === '' ? '' : $imports . "\n\n")
            . "/**\n * The Slim closure from legacy/" . $route->file . ':' . $route->handlerLine . ", moved by Booster's strangler.\n"
            . " * SlimAdapter binds it to the legacy container, as Slim did.\n */\n"
            . 'final class ' . $name . " extends \\App\\Http\\Controllers\\Legacy\\Support\\LegacyClosure\n{\n"
            . "    public function handler(): \\Closure\n    {\n" . $body . "\n    }\n}\n";
        $copier->write(SlimCodeCopier::targetFile($class), $code);

        return 'closure:' . $class;
    }

    /** @return array{int, int} */
    private function closureRange(SlimSource $src, SlimRoute $route): array
    {
        $needle = $route->handlerText;
        foreach ($src->t as $i => [$id, , $line]) {
            if ($line !== $route->handlerLine || !in_array($id, [T_FUNCTION, T_FN, T_STATIC], true)) {
                continue;
            }
            for ($j = $i; $j < count($src->t); $j++) {
                if ($src->text($i, $j) === $needle) {
                    return [$i, $j];
                }
                if (strlen($src->text($i, $j)) > strlen($needle)) {
                    break;
                }
            }
        }

        return [0, -1];
    }

    private function routeLine(SlimRoute $route, string $spec): string
    {
        $p = SlimPattern::convert($route->pattern);
        $line = '\\Illuminate\\Support\\Facades\\Route::match(' . self::export($route->methods) . ', ' . var_export($p->uri, true)
            . ', [\\App\\Http\\Controllers\\Legacy\\Support\\SlimAdapter::class, \'handle\'])'
            . "\n    ->defaults('booster_slim', " . var_export($spec, true) . ')';
        foreach ($p->wheres as $name => $regex) {
            $line .= "\n    ->where(" . var_export($name, true) . ', ' . var_export($regex, true) . ')';
        }
        if (array_diff($route->methods, ['GET', 'HEAD', 'OPTIONS']) !== []) {
            // PreventRequestForgery is Laravel 13's name for ValidateCsrfToken.
            $line .= "\n    ->withoutMiddleware([\\Illuminate\\Foundation\\Http\\Middleware\\PreventRequestForgery::class, \\Illuminate\\Foundation\\Http\\Middleware\\ValidateCsrfToken::class])";
        }
        if ($route->name !== null) {
            $line .= "\n    ->name(" . var_export($route->name, true) . ')';
        }

        return $line . ';';
    }

    /**
     * The adapter's manifest, read afresh from the legacy front controller on
     * every move. A PHP-DI app's definition files are copied (renamed) beside it.
     *
     * @param array{container: string, files: list<string>, error_handler: ?string, error_middleware: bool, emitter: ?string, body_parsing: bool, output_buffering: string, refusal: ?array{file: string, line: int, reason: string}, manual: list<string>} $front
     * @param array<string, SlimSource> $sources
     *
     * @return array<string, mixed>
     */
    private function manifest(array $front, SlimCodeCopier $copier, array $sources, string $appRoot, array $twig = []): array
    {
        $files = [];
        $keys = [];
        foreach ($front['files'] as $file) {
            $src = $sources[$file] ?? null;
            if ($src === null) {
                $copier->refusals[] = ['file' => 'legacy/public/index.php', 'line' => 1, 'reason' => 'the container definitions file legacy/' . $file . ' is missing'];

                continue;
            }
            [$code, $refs] = $copier->rewrite($src, $file, 0, count($src->t) - 1);
            foreach ($refs as $ref) {
                $copier->copyClass($ref);
            }
            $target = 'container/' . str_replace('/', '-', $file);
            $copier->write(SlimCodeCopier::DIR . $target, $code);
            $files[] = $target;
            foreach (self::definitionKeys($src) as $key) {
                $keys[] = $copier->isAppClass($key) ? (string) $copier->map($key) : $key;
            }
        }
        $emitter = null;
        if ($front['emitter'] !== null && $copier->isAppClass($front['emitter'])) {
            $copier->copyClass($front['emitter']);
            $emitter = (string) $copier->map($front['emitter']);
            $target = SlimCodeCopier::targetFile($emitter);
            $staged = $copier->staged($target);
            if ($staged !== null) {
                $short = substr($emitter, (int) strrpos($emitter, '\\') + 1);
                $swapped = preg_replace('/(class\s+' . preg_quote($short, '/') . '\s+extends\s+)[\\\\\w]+/', '$1\\App\\Http\\Controllers\\Legacy\\Support\\CapturingEmitter', $staged, 1);
                $copier->restage($target, (string) $swapped);
            }
        }
        $handler = null;
        if ($front['error_handler'] !== null && $copier->isAppClass($front['error_handler'])) {
            $copier->copyClass($front['error_handler']);
            $handler = (string) $copier->map($front['error_handler']);
        } elseif ($front['error_handler'] !== null) {
            $handler = $front['error_handler'];
        }

        return [
            'container' => $front['container'],
            'files' => $files,
            'keys' => array_values(array_unique($keys)),
            'error_middleware' => $front['error_middleware'],
            'error_handler' => $handler,
            'emitter' => $emitter,
            'body_parsing' => $front['body_parsing'],
            'output_buffering' => $front['output_buffering'],
            'twig' => $twig,
        ];
    }

    /**
     * The keys of a PHP-DI definitions file's addDefinitions([...]) arrays:
     * `X::class` resolved, or a string literal.
     *
     * @return list<string>
     */
    private static function definitionKeys(SlimSource $src): array
    {
        $keys = [];
        foreach ($src->t as $i => [$id, $text]) {
            if ($id !== T_STRING || strtolower($text) !== 'adddefinitions') {
                continue;
            }
            $open = $src->next($i);
            if ($open === null || !isset($src->parens[$open])) {
                continue;
            }
            $depth = 0;
            for ($k = $open + 1; $k < $src->parens[$open]; $k++) {
                $tk = $src->t[$k];
                if ($tk[0] === null && in_array($tk[1], ['[', '(', '{'], true)) {
                    $depth++;
                } elseif ($tk[0] === null && in_array($tk[1], [']', ')', '}'], true)) {
                    $depth--;
                } elseif ($tk[0] === T_DOUBLE_ARROW && $depth === 1) {
                    $before = $src->prev($k);
                    if ($before === null) {
                        continue;
                    }
                    if ($src->t[$before][0] === T_CONSTANT_ENCAPSED_STRING) {
                        $keys[] = SlimSource::unquote($src->t[$before][1]);
                    } elseif ($src->t[$before][0] === T_CLASS) {
                        $colon = $src->prev($before);
                        $name = $colon === null ? null : $src->prev($colon);
                        if ($name !== null) {
                            $keys[] = $src->resolveClass($src->t[$name][1], $name);
                        }
                    }
                }
            }
        }

        return $keys;
    }

    /**
     * Add the packages the copied code needs to the root composer.json.
     *
     * @param array{container: string, error_middleware: bool, error_handler: ?string, emitter: ?string} $front
     * @param list<string> $manual
     */
    private function requirePackages(string $appRoot, SlimCodeCopier $copier, array $front, array &$manual): bool
    {
        $legacy = SlimCodeCopier::json($appRoot . '/legacy/composer.json');
        $needed = $copier->requirements();
        foreach (['slim/psr7', 'slim/http', 'slim/slim'] as $package) {
            if (isset($legacy['require'][$package]) && ($package !== 'slim/slim' || $front['error_middleware'] || $front['error_handler'] !== null || $front['emitter'] !== null || isset($needed[$package]))) {
                $needed[$package] = (string) $legacy['require'][$package];
            }
        }
        if ($front['container'] === 'php-di' && isset($legacy['require']['php-di/php-di'])) {
            $needed['php-di/php-di'] = (string) $legacy['require']['php-di/php-di'];
        }
        $rootFile = $appRoot . '/composer.json';
        $root = SlimCodeCopier::json($rootFile);
        if ($root === []) {
            return false;
        }
        $rootLock = SlimCodeCopier::json($appRoot . '/composer.lock');
        $legacyLock = SlimCodeCopier::json($appRoot . '/legacy/composer.lock');
        $locked = static function (array $lock): array {
            $versions = [];
            foreach (array_merge((array) ($lock['packages'] ?? []), (array) ($lock['packages-dev'] ?? [])) as $p) {
                if (is_array($p) && is_string($p['name'] ?? null)) {
                    $versions[$p['name']] = ltrim((string) ($p['version'] ?? ''), 'v');
                }
            }

            return $versions;
        };
        $rootVersions = $locked($rootLock);
        $legacyVersions = $locked($legacyLock);
        $changed = false;
        foreach ($needed as $package => $constraint) {
            if ($package === 'php' || str_starts_with($package, 'ext-') || isset($root['require'][$package]) || isset($root['require-dev'][$package])) {
                continue;
            }
            if (isset($rootVersions[$package])) {
                $rootMajor = explode('.', $rootVersions[$package])[0];
                $legacyMajor = explode('.', $legacyVersions[$package] ?? $rootVersions[$package])[0];
                if ($rootMajor !== $legacyMajor) {
                    $manual[] = 'legacy runs ' . $package . ' ' . ($legacyVersions[$package] ?? '?') . ' and the Laravel half ' . $rootVersions[$package] . '; the moved code runs against ' . $rootVersions[$package];
                }

                continue;
            }
            $conflict = self::lockConflict($package, $legacyLock, $rootVersions);
            if ($conflict !== null) {
                // PHP-DI and Slim's own packages may take the next major:
                // PHP-DI 6 needs serializable-closure 1, Laravel 13 has 2,
                // and PHP-DI 7 runs the same definitions. Any other package
                // would change under the moved code, so it is left out.
                $widen = $package === 'php-di/php-di' || str_starts_with($package, 'slim/');
                if (!$widen || preg_match('/^\^(\d+)(?:\.\d+)*$/', trim($constraint), $major) !== 1) {
                    $manual[] = 'composer.json does not require ' . $package . ' ' . $constraint . ': the legacy copy needs ' . $conflict . '; the moved code that uses it fails in Laravel until it is ported to what the Laravel half has';

                    continue;
                }
                $constraint .= ' || ^' . ((int) $major[1] + 1) . '.0';
            }
            $root['require'][$package] = $constraint;
            $this->composerAdds[$package] = $constraint;
            $manual[] = 'composer.json now requires ' . $package . ' ' . $constraint . ' for the moved code (' . (isset($legacyVersions[$package]) ? 'legacy runs ' . $legacyVersions[$package] : 'legacy has no composer.lock entry for it') . '); check the version composer installed in the root';
            $changed = true;
        }
        if (!$changed) {
            return false;
        }
        $json = (string) file_get_contents($rootFile);
        $edited = self::withRequires($json, (array) $root['require'], ($root['config']['sort-packages'] ?? false) === true);
        file_put_contents($rootFile, $edited ?? json_encode($root, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

        return true;
    }

    /**
     * What stops a legacy-locked package from installing beside the Laravel
     * half: one of its requires whose allowed majors miss the root's locked
     * version ("league/flysystem ^1.0, the Laravel half has 3.30.0"), or null.
     *
     * @param array<mixed>          $legacyLock
     * @param array<string, string> $rootVersions
     */
    private static function lockConflict(string $package, array $legacyLock, array $rootVersions): ?string
    {
        foreach (array_merge((array) ($legacyLock['packages'] ?? []), (array) ($legacyLock['packages-dev'] ?? [])) as $entry) {
            if (!is_array($entry) || ($entry['name'] ?? null) !== $package) {
                continue;
            }
            foreach ((array) ($entry['require'] ?? []) as $dep => $constraint) {
                $dep = (string) $dep;
                if ($dep === 'php' || str_starts_with($dep, 'ext-') || !isset($rootVersions[$dep])) {
                    continue;
                }
                $root = (int) explode('.', $rootVersions[$dep])[0];
                $majors = [];
                $open = false;
                foreach (preg_split('/\s*\|\|?\s*/', (string) $constraint) ?: [] as $alternative) {
                    if (preg_match('/^[\^~]?v?(\d+)(?:\.[\d*x]+)*$/', trim($alternative), $m) === 1) {
                        $majors[] = (int) $m[1];
                    } else {
                        $open = true;
                    }
                }
                if (!$open && $majors !== [] && !in_array($root, $majors, true)) {
                    return $dep . ' ' . $constraint . ', the Laravel half has ' . $rootVersions[$dep];
                }
            }
        }

        return null;
    }

    /**
     * The composer.json text with its "require" object rewritten to $require
     * and nothing else touched, or null when the object cannot be found.
     *
     * @param array<string, mixed> $require
     */
    public static function withRequires(string $json, array $require, bool $sort): ?string
    {
        if (preg_match('/("require"\s*:\s*\{)([^{}]*)(\})/', $json, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $indent = preg_match('/\n([ \t]+)"/', $m[2][0], $i) === 1 ? $i[1] : '        ';
        $outer = substr($indent, 0, (int) (strlen($indent) / 2));
        if ($sort) {
            uksort($require, static function (string $a, string $b): int {
                $pa = $a === 'php' || str_starts_with($a, 'ext-') ? 0 : 1;
                $pb = $b === 'php' || str_starts_with($b, 'ext-') ? 0 : 1;

                return [$pa, $a] <=> [$pb, $b];
            });
        }
        $lines = [];
        foreach ($require as $package => $constraint) {
            $lines[] = $indent . json_encode((string) $package, JSON_UNESCAPED_SLASHES) . ': ' . json_encode((string) $constraint, JSON_UNESCAPED_SLASHES);
        }
        $block = $m[1][0] . "\n" . implode(",\n", $lines) . "\n" . $outer . $m[3][0];

        return substr($json, 0, $m[0][1]) . $block . substr($json, $m[0][1] + strlen($m[0][0]));
    }

    /** Re-indent a code fragment: strip the indent its later lines share, then add $indent. */
    private static function reindent(string $code, string $indent): string
    {
        $lines = explode("\n", $code);
        $common = null;
        foreach (array_slice($lines, 1) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $lead = strlen($line) - strlen(ltrim($line, ' '));
            $common = $common === null ? $lead : min($common, $lead);
        }
        $common ??= 0;
        $strip = $common;
        foreach ($lines as $n => $line) {
            if ($n > 0 && trim($line) !== '') {
                $lines[$n] = $indent . substr($line, min($strip, strlen($line) - strlen(ltrim($line, ' '))));
            }
        }

        return implode("\n", $lines);
    }

    /** Add LegacyServiceProvider to bootstrap/providers.php (Laravel 11 and later); true when it changed. */
    private function registerProvider(string $appRoot): bool
    {
        $file = $appRoot . '/bootstrap/providers.php';
        if (!is_file($file)) {
            return false;
        }
        $code = (string) file_get_contents($file);
        if (str_contains($code, 'LegacyServiceProvider')) {
            return false;
        }
        $new = preg_replace('/\];\s*$/', "    App\\Providers\\LegacyServiceProvider::class,\n];\n", $code, 1);
        if ($new === null || $new === $code) {
            return false;
        }
        file_put_contents($file, $new);

        return true;
    }

    /** A class name for a moved closure: "GET /users/{id}" becomes GetUsersId. */
    private static function className(SlimRoute $route): string
    {
        $uri = SlimPattern::convert($route->pattern)->uri;
        $words = preg_split('/[^A-Za-z0-9]+/', strtolower(implode(' ', $route->methods) . ' ' . ($uri === '/' ? 'root' : $uri))) ?: [];
        $name = implode('', array_map('ucfirst', array_filter($words, static fn(string $w): bool => $w !== '')));

        return preg_match('/^[A-Za-z]/', $name) === 1 ? $name : 'Route' . $name;
    }

    private static function resource(string $file): string
    {
        return dirname(__DIR__, 2) . '/resources/strangler/slim/' . $file;
    }

    private static function normalize(string $path): string
    {
        $out = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ($part === '..') {
                array_pop($out);
            } elseif ($part !== '.' && $part !== '') {
                $out[] = $part;
            }
        }

        return implode('/', $out);
    }

    private static function lineOf(string $code, string $needle): int
    {
        $at = strpos($code, $needle);

        return $at === false ? 1 : substr_count(substr($code, 0, $at), "\n") + 1;
    }

    private static function tokenAt(SlimSource $src, string $text): int
    {
        foreach ($src->t as $i => $token) {
            if ($token[1] === $text) {
                return $i;
            }
        }

        return 0;
    }

    /** @return list<array<string>> */
    private static function allMatches(string $regex, string $code): array
    {
        preg_match_all($regex, $code, $m, PREG_SET_ORDER);

        return $m;
    }

    private static function export(mixed $value): string
    {
        if (is_array($value)) {
            $list = array_is_list($value);
            $items = [];
            foreach ($value as $k => $v) {
                $items[] = ($list ? '' : var_export($k, true) . ' => ') . self::export($v);
            }
            if ($list && count($items) < 6 && !array_filter($value, 'is_array')) {
                return '[' . implode(', ', $items) . ']';
            }

            return "[\n" . preg_replace('/^/m', '    ', implode(",\n", $items)) . ",\n]";
        }

        return $value === null ? 'null' : var_export($value, true);
    }

    /**
     * @param list<array{file: string, line: int, reason: string}> $refusals
     *
     * @return list<array{file: string, line: int, reason: string}>
     */
    private static function unique(array $refusals): array
    {
        $seen = [];
        $out = [];
        foreach ($refusals as $r) {
            $key = $r['file'] . ':' . $r['line'] . ':' . $r['reason'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $r;
            }
        }

        return $out;
    }

    private static function refused(string $file, int $line, string $reason): MoveResult
    {
        return new MoveResult(false, [], [['file' => $file, 'line' => $line, 'reason' => $reason]], []);
    }
}
