<?php

declare(strict_types=1);

namespace Waynestate\Booster\Strangler;

/**
 * Moves one CakePHP unit to the Laravel half (design sections 4 and 9.2).
 * Files only: the route table is the caller's job after the replay gate.
 *
 * A unit moves only when every statement of every action in it maps (see
 * CakeTranslator for the list), including the statements its controllers'
 * `initialize()` and `beforeFilter()` run first. Otherwise nothing is
 * written and each statement that did not map comes back as a refusal with
 * its file and line: a person ports the unit and runs the `--hand` move.
 *
 * What a move writes:
 * - one invokable controller per action in app/Http/Controllers/Legacy/Cake/,
 *   with the action's code rewritten for Laravel's request;
 * - its template, elements and (on the first page move) the layout as Blade
 *   files under resources/views/legacy/, PHP kept as PHP and the helpers
 *   mapped, so the HTML is the same bytes (a MANUAL line asks for the Blade
 *   rewrite later; a layout helper that does not map is a MANUAL line);
 * - the CakePHP runtime the moved code runs on (CakeView, CakeForm with the
 *   legacy FormHelper's own templates, CakeTable, CakeQuery, CakeEntity,
 *   CakeValidator, CakeAuth; see CakeRuntime), the legacy Tables, Entities
 *   and Policies ported onto it, the legacy error pages as Blade, and the
 *   split CakePHP packages (core, utility, collection, i18n) in composer.json;
 * - the Eloquent model skeletons from the Table and Entity classes;
 * - the routes in routes/web.php after a `// booster:strangler <unit> from` line,
 *   names kept, signed-in units behind SESAME's `auth.sesame`. When the legacy
 *   app checks CSRF tokens, a moved write keeps Laravel's PreventRequestForgery
 *   (the moved form prints Laravel's `_token`) and a bad token gets the legacy
 *   403 page (design 9.2); when it checks none, the route skips it as before.
 *
 * A unit that posts to or flashes into another unit moves after it (the
 * plan's `after` list), so a form and the action it posts to always agree on
 * whose token they carry.
 */
final class CakeMover implements Mover, AddsComposerPackages
{
    public const DIR = 'app/Http/Controllers/Legacy/Cake/';

    public const NS = 'App\\Http\\Controllers\\Legacy\\Cake';

    /** The routes/web.php lines that keep Laravel's input cleaners off moved CakePHP actions. */
    public const SKIP_INPUT = "\\Illuminate\\Foundation\\Http\\Middleware\\TrimStrings::skipWhen(static fn (\\Illuminate\\Http\\Request \$request): bool => \\App\\Http\\Controllers\\Legacy\\Cake\\CakeView::moved(\$request));\n\\Illuminate\\Foundation\\Http\\Middleware\\ConvertEmptyStringsToNull::skipWhen(static fn (\\Illuminate\\Http\\Request \$request): bool => \\App\\Http\\Controllers\\Legacy\\Cake\\CakeView::moved(\$request));";

    /** The flash partial: CakePHP's FlashHelper::render() over Laravel's session. */
    private const FLASH = "<?php foreach (session()->pull('" . CakeTranslator::FLASH_KEY . ".flash', []) as \$__message): ?><?= \$__env->make('legacy.element.' . str_replace('/', '.', \$__message['element']), array_merge(\\Illuminate\\Support\\Arr::except(get_defined_vars(), ['__data', '__path', '__message']), \$__message))->render() ?><?php endforeach; ?>";

    /** @var array<string, string> app-relative file => code, for this move */
    private array $files = [];

    /** @var list<array{file: string, line: int, reason: string}> */
    private array $refusals = [];

    /** @var list<string> */
    private array $manual = [];

    /** @var list<string> the Table methods the moved code reaches (CakeTranslator::$reached) */
    private array $reached = [];

    /** Whether a moved view prints a form, or a moved action can end in the legacy error page. */
    private bool $usesForm = false;

    private bool $canFail = false;

    /** @var array<string, string> package => constraint, the last move's composer.json adds */
    private array $adds = [];

    /** The routes/web.php lines, written once, that give a bad CSRF token on a moved action the legacy 403 page and leave the legacy CSRF cookie unencrypted. */
    public const CSRF_BRIDGE = "app(\Illuminate\Contracts\Debug\ExceptionHandler::class)->map(static fn (\Illuminate\Session\TokenMismatchException \$e): \Throwable => \App\Http\Controllers\Legacy\Cake\CakeView::csrfFailure(\$e));";

    public function composerAdds(): array
    {
        return $this->adds;
    }

    public function framework(): string
    {
        return 'cakephp';
    }

    /** The routes/web.php comment that marks a moved unit (the finish and --hand steps find it). */
    public static function marker(string $unitId): string
    {
        return '// booster:strangler ' . $unitId . ' from ';
    }

    public function move(Unit $unit, string $appRoot): MoveResult
    {
        $this->files = [];
        $this->refusals = [];
        $this->manual = [];
        $this->adds = [];
        $this->reached = [];
        $this->usesForm = false;
        $this->canFail = false;
        $appRoot = rtrim($appRoot, '/\\');
        if (!CakeApp::isCakePhp($appRoot)) {
            return self::refused($unit, 'legacy/ is not a CakePHP app (no config/routes.php, or composer.json does not require cakephp/cakephp)');
        }
        $app = new CakeApp($appRoot);
        $entries = null;
        $after = [];
        foreach ((new CakeUnitSource())->plan($app) as $planned) {
            if ($planned['unit']->id === $unit->id) {
                $entries = $planned['entries'];
                $after = $planned['after'];
            }
        }
        if ($entries === null) {
            return self::refused($unit, 'no CakePHP unit with the id ' . $unit->id . ' is left in legacy/');
        }
        $web = $appRoot . '/routes/web.php';
        if (!is_file($web) || !is_dir($appRoot . '/app')) {
            return self::refused($unit, 'the Laravel half is not mounted (no routes/web.php); run the mount step first');
        }
        $webCode = (string) file_get_contents($web);
        if (str_contains($webCode, self::marker($unit->id))) {
            return self::refused($unit, $unit->id . ' is already in routes/web.php');
        }
        $ledger = is_file($appRoot . '/booster-strangler.json') ? json_decode((string) file_get_contents($appRoot . '/booster-strangler.json'), true) : [];
        if ($unit->needsSignIn && (is_array($ledger) ? ($ledger['step0'] ?? 'pending') : 'pending') !== 'done') {
            $this->refusals[] = ['file' => $unit->sourceFile, 'line' => $unit->sourceLine, 'reason' => $unit->id . ' needs a signed-in user; it moves once Step 0 (sign-in on SESAME) is done'];
        }

        foreach ($after as $needed) {
            if (!str_contains($webCode, self::marker($needed))) {
                $this->refusals[] = ['file' => $unit->sourceFile, 'line' => $unit->sourceLine, 'reason' => sprintf('%s shares a form or a flash message with %s, which has not moved yet; move %s first', $unit->id, $needed, $needed)];
            }
        }

        $assets = $this->assets($app);
        $runtime = new CakeRuntime($app);
        $csrfSettings = $runtime->csrfSettings();
        $csrf = $csrfSettings !== null;
        if ($csrfSettings !== null && $csrfSettings['session']) {
            $this->refusals[] = ['file' => $csrfSettings['file'], 'line' => 1, 'reason' => 'SessionCsrfProtectionMiddleware keeps the token in the CakePHP session, which the Laravel half cannot read'];
        }
        $moving = [];
        foreach ($entries as $entry) {
            if ($entry['controller'] !== null && $entry['method'] !== null) {
                $moving[strtolower($entry['controller'] . '::' . $entry['method'])] = true;
            }
        }
        $routeLines = [];
        foreach ($entries as $entry) {
            $line = $this->action($app, $unit, $entry, $moving, $assets, $csrf);
            if ($line !== null) {
                $routeLines[] = $line;
            }
        }
        if ($this->refusals === []) {
            $this->runtime($app, $runtime, $assets);
        }
        if ($this->refusals !== []) {
            return new MoveResult(false, [], self::unique($this->refusals), []);
        }

        $changed = [];
        $composer = $this->composer($appRoot, $runtime);
        if ($composer !== null) {
            $this->files['composer.json'] = $composer;
        }
        foreach ($this->files as $file => $code) {
            $path = $appRoot . '/' . $file;
            if (is_file($path) && (string) file_get_contents($path) === $code) {
                continue;
            }
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0o777, true);
            }
            file_put_contents($path, $code);
            $changed[] = $file;
        }
        $models = new CakeModels();
        $changed = array_merge($changed, $models->write($appRoot));
        $this->manual = array_merge($this->manual, $models->manual);
        if (!str_contains($webCode, self::SKIP_INPUT)) {
            $webCode = rtrim($webCode, "\n") . "\n\n// booster:strangler CakePHP code reads its input untouched: Laravel's input cleaners skip moved actions.\n" . self::SKIP_INPUT . "\n";
            $this->manual[] = 'routes/web.php tells TrimStrings and ConvertEmptyStringsToNull to skip moved CakePHP actions (CakePHP never trimmed input); with `php artisan route:cache` routes/web.php is not loaded, so move those two lines to AppServiceProvider::boot() before caching routes';
        }
        if ($csrfSettings !== null && !str_contains($webCode, self::CSRF_BRIDGE)) {
            $webCode = rtrim($webCode, "\n") . "\n\n// booster:strangler CakePHP's CSRF: a bad token on a moved action gets the legacy 403 page; the legacy cookie stays as CakePHP wrote it.\n"
                . self::CSRF_BRIDGE . "\n\\Illuminate\\Cookie\\Middleware\\EncryptCookies::except(" . var_export($csrfSettings['cookie'], true) . ");\n";
        }
        $block = "\n" . self::marker($unit->id) . $unit->sourceFile . ':' . $unit->sourceLine . "\n" . implode("\n", $routeLines) . "\n";
        file_put_contents($web, rtrim($webCode, "\n") . "\n" . $block);
        $changed[] = 'routes/web.php';
        if (HelmetHold::scopeToWeb($appRoot)) {
            $changed[] = HelmetHold::FILE;
            $this->manual[] = HelmetHold::MANUAL;
        }
        sort($changed);

        return new MoveResult(true, array_values(array_unique($changed)), [], array_values(array_unique($this->manual)));
    }

    /**
     * One action: its controller class and views into $this->files, and its
     * route line; null when it refused.
     *
     * @param array{controller: string|null, method: string|null, action: string, path: string, uri: string, name: string|null, get: bool, writes: list<string>, identity: bool, guarded: bool, signIn: bool, json: bool, session: bool, file: string, line: int, seq: int, edges: list<string>, forms: list<string>} $entry
     * @param array<string, true> $moving
     * @param array{css: string, js: string, encoding: string, timestamp: bool} $assets
     */
    private function action(CakeApp $app, Unit $unit, array $entry, array $moving, array $assets, bool $csrf): ?string
    {
        if ($entry['controller'] === null || $entry['method'] === null) {
            $this->refusals[] = ['file' => $entry['file'], 'line' => $entry['line'], 'reason' => 'a redirect route in config/routes.php; add it to routes/web.php by hand and run the --hand move'];

            return null;
        }
        $controller = $app->controllers()[strtolower($entry['controller'])] ?? null;
        $method = $controller['class']['methods'][strtolower($entry['method'])] ?? null;
        if ($controller === null || $method === null) {
            $this->refusals[] = ['file' => $entry['file'], 'line' => $entry['line'], 'reason' => 'the action ' . $entry['controller'] . '::' . $entry['method'] . ' was not found'];

            return null;
        }
        $file = $controller['file'];
        $refusalsBefore = count($this->refusals);
        if (str_contains($entry['uri'], '{pass?}')) {
            $this->refusals[] = ['file' => $file, 'line' => $method['line'], 'reason' => 'a greedy `/*` route passes any number of arguments, which a Laravel route does not'];
        }
        foreach ($method['params'] as $param) {
            if ($param['variadic'] || in_array($param['name'], ['request', 'viewData', '__blocks'], true)) {
                $this->refusals[] = ['file' => $file, 'line' => $method['line'], 'reason' => sprintf('the action parameter $%s cannot keep its meaning in a Laravel controller', $param['name'])];
            }
        }
        $writes = array_values(array_diff($entry['writes'], ['GET', 'HEAD']));
        $facts = $app->analyse($controller, $method);
        $chain = $app->chain($controller);
        $prefix = $controller['prefix'];
        $templatePath = ($prefix === null ? '' : $prefix . '/') . $controller['name'];
        $title = implode(' ', array_map(static fn(string $w): string => mb_strtoupper(mb_substr($w, 0, 1)) . mb_substr($w, 1), explode(' ', str_replace('_', ' ', $templatePath))));

        // Hooks first (root class first), then the action.
        $translators = [];
        $prelude = '';
        foreach (array_reverse($chain) as $ctl) {
            foreach ($ctl['class']['methods'] as $hook => $hookMethod) {
                if (!in_array($hook, CakeApp::HOOKS, true) || $hookMethod['static'] || !in_array($hook, ['initialize', 'beforefilter', 'beforerender', 'afterfilter', 'beforeredirect'], true)) {
                    continue;
                }
                $t = $this->translator($app, $ctl, 'controller', '');
                $translators[] = $t;
                foreach ($ctl['code']->statements($hookMethod['open']) as [$s, $e]) {
                    $text = trim($ctl['code']->code($s, $e));
                    if ($text === '' || preg_match('/^parent::\w+\s*\(/i', $text) === 1) {
                        continue;
                    }
                    if ($hook !== 'initialize' && $hook !== 'beforefilter') {
                        $this->refusals[] = ['file' => $ctl['file'], 'line' => $ctl['code']->line($s), 'reason' => sprintf('%s::%s() runs code around the action, which a moved action does not keep', $ctl['name'] . 'Controller', $hookMethod['name'])];
                        continue;
                    }
                    $prelude .= '        ' . trim($t->rewrite($ctl['code'], $s, $e)) . "\n";
                }
            }
        }

        $view = null;
        $layout = null;
        $template = $facts['template'];
        if ($template !== null && is_file($app->legacy . '/' . $template)) {
            $view = self::viewName($template);
        }
        $renderCall = 'CakeView::render(__VIEW__, __LAYOUT__, $viewData, ' . var_export($title, true) . ')';
        $t = $this->translator($app, $controller, 'controller', $renderCall);
        $translators[] = $t;
        $c = $controller['code'];
        $body = $t->rewrite($c, $method['open'] + 1, $method['close'] - 1);
        $statements = $c->statements($method['open']);
        $last = $statements === [] ? null : $statements[count($statements) - 1];
        $returns = $last !== null && $c->t[$last[0]][0] === T_RETURN;
        if (!$returns) {
            $body = rtrim($body) . "\n\n        return " . $renderCall . ";\n    ";
        }
        if ($t->layout !== null) {
            $layout = $t->layout;
        }
        $rendersView = str_contains($body, '__VIEW__');
        if ($rendersView && $view === null) {
            $this->refusals[] = ['file' => $file, 'line' => $method['line'], 'reason' => 'the action renders a template that is not in legacy/templates (or one this reader cannot name)'];
        }
        if ($t->flashes) {
            foreach ($t->redirects as $target) {
                $key = strtolower((($target['prefix'] ?? null) === null ? '' : $target['prefix'] . '/') . $target['controller'] . '::' . $target['action']);
                if (!isset($moving[$key]) && !is_file($app->appRoot . '/' . self::classFile(self::className($target['prefix'] ?? null, $target['controller'], $target['action'])))) {
                    $this->refusals[] = ['file' => $file, 'line' => $method['line'], 'reason' => sprintf('the action writes a flash message and redirects to %s::%s, which stays legacy, so the message would never show', $target['controller'], $target['action'])];
                }
            }
        }
        foreach ($translators as $tr) {
            $this->refusals = array_merge($this->refusals, $tr->refusals);
            $this->reached = array_merge($this->reached, $tr->reached);
        }
        $this->canFail = $this->canFail || $this->reached !== [] || self::authorizes($app) || ($csrf && array_diff($entry['writes'], ['GET', 'HEAD']) !== []);

        $layoutView = 'null';
        if ($rendersView && $view !== null) {
            $this->view($app, $template, false, $assets, $controller);
            $layoutName = $layout ?? 'default';
            if ($prefix !== null && is_file($app->legacy . '/templates/' . $prefix . '/layout/' . $layoutName . '.php')) {
                $this->refusals[] = ['file' => $file, 'line' => $method['line'], 'reason' => 'the prefix has its own layout folder, which this mover does not follow'];
            }
            $layoutFile = 'templates/layout/' . $layoutName . '.php';
            if (!is_file($app->legacy . '/' . $layoutFile)) {
                $this->refusals[] = ['file' => $file, 'line' => $method['line'], 'reason' => 'the layout legacy/' . $layoutFile . ' is missing'];
            } else {
                $this->view($app, $layoutFile, true, $assets, $controller);
                $layoutView = var_export(self::viewName($layoutFile), true);
            }
        }
        if (count($this->refusals) > $refusalsBefore) {
            return null;
        }
        $body = str_replace(['__VIEW__', '__LAYOUT__'], [var_export((string) $view, true), $layoutView], $body);

        $params = ['Request $request'];
        foreach ($method['params'] as $param) {
            $params[] = $this->paramText($c, $method, $param['name']);
        }
        $class = self::className($prefix, $controller['name'], $method['name']);
        $ns = substr($class, 0, (int) strrpos($class, '\\'));
        $short = substr($class, (int) strrpos($class, '\\') + 1);
        $imports = array_merge(['use ' . self::NS . '\\CakeAuth;', 'use ' . self::NS . '\\CakeTable;', 'use ' . self::NS . '\\CakeView;', 'use Illuminate\\Http\\Request;'], $t->globalImports());
        $imports = array_values(array_unique($imports));
        sort($imports);
        $inner = "        \$viewData = [];\n" . $prelude . rtrim($body) . "\n";
        if (self::authorizes($app)) {
            // AuthorizationMiddleware's check: an action that neither authorized nor skipped authorization ends in the error page.
            $args = ['$request'];
            foreach ($method['params'] as $param) {
                $args[] = '$' . $param['name'];
            }
            $inner = "        \$response = \$this->action(" . implode(', ', $args) . ");\n        CakeAuth::ensure(\$request);\n\n        return \$response;\n    }\n\n"
                . '    private function action(' . implode(', ', $params) . "): mixed\n    {\n" . $inner;
        }
        $code = "<?php\n\nnamespace " . $ns . ";\n\n" . implode("\n", $imports) . "\n\n"
            . "/**\n * Written by Booster's strangler (cakephp-to-laravel) from " . $file . ':' . $method['line']
            . ' (' . $controller['name'] . 'Controller::' . $method['name'] . "). App-owned.\n */\nfinal class " . $short . "\n{\n"
            . '    public function __invoke(' . implode(', ', $params) . "): mixed\n    {\n"
            . $inner . "    }\n}\n";
        $this->files[self::classFile($class)] = $code;

        $methods = array_values(array_unique(array_merge($entry['get'] ? ['GET'] : [], $writes)));
        $route = '\\Illuminate\\Support\\Facades\\Route::match(' . self::export($methods) . ', ' . var_export($entry['uri'], true) . ', \\' . $class . '::class)';
        if ($entry['guarded']) {
            $route .= "\n    ->middleware('auth.sesame')";
        }
        if ($writes !== [] && $csrf) {
            $this->manual[] = sprintf('%s:%d %s /%s keeps Laravel\'s CSRF check (PreventRequestForgery; ValidateCsrfToken before Laravel 13): the moved form prints Laravel\'s _token, and a bad token gets the legacy 403 page', $file, $method['line'], implode('|', $writes), ltrim($entry['uri'], '/'));
        } elseif ($writes !== []) {
            // PreventRequestForgery is Laravel 13's name for ValidateCsrfToken.
            $route .= "\n    ->withoutMiddleware([\\Illuminate\\Foundation\\Http\\Middleware\\PreventRequestForgery::class, \\Illuminate\\Foundation\\Http\\Middleware\\ValidateCsrfToken::class])";
            $this->manual[] = sprintf('%s:%d %s /%s had no CSRF check in CakePHP and has none in Laravel either (the route skips PreventRequestForgery so the legacy form still posts); add one when its form is ported', $file, $method['line'], implode('|', $writes), ltrim($entry['uri'], '/'));
        }
        $route .= HelmetHold::SKIP;
        if ($entry['name'] !== null) {
            $route .= "\n    ->name(" . var_export($entry['name'], true) . ')';
        }

        return $route . ';';
    }

    /**
     * A parameter's declaration text, default and type kept.
     *
     * @param array{start: int} $method
     */
    private function paramText(CakeCode $c, array $method, string $name): string
    {
        $open = $c->next($method['start']);
        while ($open !== null && $c->t[$open][1] !== '(') {
            $open = $c->next($open);
        }
        if ($open === null) {
            return '$' . $name;
        }
        foreach ($c->items($open) as $item) {
            $text = trim($c->code($item[0], $item[1]));
            if (preg_match('/\$' . preg_quote($name, '/') . '\b/', $text) === 1) {
                return $text;
            }
        }

        return '$' . $name;
    }

    /**
     * Convert a template, element or layout (and the elements it pulls in)
     * into $this->files. A layout is written only when it is not there yet,
     * and its helpers that do not map are MANUAL lines, not refusals.
     *
     * @param array{css: string, js: string, encoding: string, timestamp: bool} $assets
     * @param array{name: string, prefix: string|null} $controller
     */
    private function view(CakeApp $app, string $template, bool $isLayout, array $assets, array $controller, int $depth = 0, ?string $source = null): void
    {
        $target = 'resources/views/legacy/' . substr($template, strlen('templates/'), -strlen('.php')) . '.blade.php';
        if (isset($this->files[$target]) || $depth > 8) {
            return;
        }
        if ($isLayout && is_file($app->appRoot . '/' . $target)) {
            return;
        }
        $source ??= (string) file_get_contents($app->legacy . '/' . $template);
        $c = new CakeCode($source);
        $t = new CakeTranslator($app, 'template', 'legacy/' . $template, ['name' => $controller['name'], 'prefix' => $controller['prefix']], '', $assets);
        $t->readImports($c);
        $code = $t->rewrite($c, 0, count($c->t) - 1);
        $this->usesForm = $this->usesForm || $t->usesForm;
        $safe = self::bladeSafe($code);
        if ($safe === null) {
            $t->refusals[] = ['file' => 'legacy/' . $template, 'line' => 1, 'reason' => 'the template\'s PHP code holds text Blade would compile ({{--, <x-, @php or @verbatim)'];
        }
        if ($isLayout) {
            foreach ($t->refusals as $refusal) {
                $this->manual[] = sprintf('%s:%d %s (in the Blade layout %s)', $refusal['file'], $refusal['line'], $refusal['reason'], $target);
            }
            if ($safe === null) {
                return;
            }
        } else {
            $this->refusals = array_merge($this->refusals, $t->refusals);
        }
        $this->files[$target] = (string) $safe;
        $this->manual[] = sprintf('%s is legacy/%s with its CakePHP helpers mapped (the PHP stays PHP, so the HTML is the same bytes); rewrite it as idiomatic Blade by hand when you like', $target, $template);
        foreach ($t->includes as $include) {
            if ($include === 'flash') {
                $this->files['resources/views/legacy/flash.blade.php'] = self::FLASH;
                foreach (glob($app->legacy . '/templates/element/flash/*.php') ?: [] as $element) {
                    $this->view($app, 'templates/element/flash/' . basename($element), false, $assets, $controller, $depth + 1);
                }
                continue;
            }
            $element = 'templates/' . $include . '.php';
            if (!is_file($app->legacy . '/' . $element)) {
                $this->refusals[] = ['file' => 'legacy/' . $template, 'line' => 1, 'reason' => 'the element legacy/' . $element . ' is missing'];
                continue;
            }
            $this->view($app, $element, false, $assets, $controller, $depth + 1);
        }
    }

    /**
     * Code Blade compiles to itself: inline HTML that holds Blade syntax
     * becomes a PHP echo with that syntax escaped. Null when the PHP code
     * itself holds text Blade would compile.
     */
    public static function bladeSafe(string $code): ?string
    {
        $out = '';
        foreach (\PhpToken::tokenize($code) as $token) {
            if ($token->id !== T_INLINE_HTML) {
                if (preg_match('/\{\{--|<x[-:]|@php\b|@endphp\b|@verbatim\b|@endverbatim\b/i', $token->text) === 1) {
                    return null;
                }
                $out .= $token->text;
                continue;
            }
            if (preg_match('/\{\{|\{!!|@|<x[-:]|<\/x[-:]/i', $token->text) !== 1) {
                $out .= $token->text;
                continue;
            }
            $escaped = strtr($token->text, ['\\' => '\\\\', '"' => '\\"', '$' => '\\$', '{' => '\\x7b', '@' => '\\x40', '<' => '\\x3c']);
            $out .= '<?php echo "' . $escaped . '"; ?>';
        }

        return $out;
    }

    /** A template path relative to legacy/ as a Laravel view name. */
    public static function viewName(string $template): string
    {
        return 'legacy.' . str_replace('/', '.', substr($template, strlen('templates/'), -strlen('.php')));
    }

    public static function className(?string $prefix, string $controller, string $action): string
    {
        $parts = [self::NS];
        if ($prefix !== null) {
            foreach (explode('/', $prefix) as $part) {
                $parts[] = CakeRoutes::camelize($part);
            }
        }
        $parts[] = CakeRoutes::camelize($controller);
        $parts[] = ucfirst(CakeRoutes::camelize($action)) . 'Action';

        return implode('\\', $parts);
    }

    public static function classFile(string $class): string
    {
        return self::DIR . str_replace('\\', '/', substr($class, strlen(self::NS) + 1)) . '.php';
    }

    /**
     * @param array{key: string, name: string, prefix: string|null, file: string, code: CakeCode} $controller
     */
    private function translator(CakeApp $app, array $controller, string $mode, string $renderCall): CakeTranslator
    {
        $t = new CakeTranslator($app, $mode === 'template' ? 'template' : 'controller', $controller['file'], ['name' => $controller['name'], 'prefix' => $controller['prefix']], $renderCall);
        $t->readImports($controller['code']);

        return $t;
    }

    /**
     * App.encoding, App.cssBaseUrl, App.jsBaseUrl and Asset.timestamp from config/app.php.
     *
     * @return array{css: string, js: string, encoding: string, timestamp: bool}
     */
    private function assets(CakeApp $app): array
    {
        $out = ['css' => 'css/', 'js' => 'js/', 'encoding' => 'utf-8', 'timestamp' => false];
        $file = $app->legacy . '/config/app.php';
        if (!is_file($file)) {
            return $out;
        }
        $c = new CakeCode((string) file_get_contents($file));
        $code = $c->code(0, count($c->t) - 1);
        if (preg_match('/[\'"]encoding[\'"]\s*=>\s*(?:env\(\s*[\'"]APP_ENCODING[\'"]\s*,\s*)?[\'"]([\w-]+)[\'"]/', $code, $m) === 1) {
            $out['encoding'] = strtolower($m[1]);
        }
        foreach (['css' => 'cssBaseUrl', 'js' => 'jsBaseUrl'] as $key => $setting) {
            if (preg_match('/[\'"]' . $setting . '[\'"]\s*=>\s*[\'"]([^\'"]*)[\'"]/', $code, $m) === 1) {
                $out[$key] = $m[1];
            }
        }
        $out['timestamp'] = preg_match('/[\'"]timestamp[\'"]\s*=>\s*(?!false)/', $code) === 1;

        return $out;
    }

    /**
     * The runtime, the ported Tables, Entities and Policies, and the legacy
     * error pages (debug block dropped) with the error layout, into $this->files.
     *
     * @param array{css: string, js: string, encoding: string, timestamp: bool} $assets
     */
    private function runtime(CakeApp $app, CakeRuntime $runtime, array $assets): void
    {
        $ports = $this->reached !== [] || self::authorizes($app) || $this->usesForm ? $runtime->ports(array_values(array_unique($this->reached))) : [];
        foreach ($runtime->files($this->usesForm) + $ports as $file => $code) {
            $this->files[$file] = $code;
        }
        $this->refusals = array_merge($this->refusals, $runtime->refusals);
        $error = ['name' => 'Error', 'prefix' => null];
        foreach (['templates/Error/error400.php', 'templates/Error/error500.php'] as $template) {
            $path = $app->legacy . '/' . $template;
            $source = is_file($path) ? CakeRuntime::errorTemplate((string) file_get_contents($path)) : null;
            if ($source === null && !$this->canFail) {
                continue;
            }
            if ($source === null) {
                $this->refusals[] = ['file' => 'legacy/' . $template, 'line' => 1, 'reason' => 'the error page is missing or not in the shape the app skeleton ships (one `if (Configure::read(\'debug\'))` block), so a moved action could not end in the same error page'];
                continue;
            }
            $this->view($app, $template, false, $assets, $error, 0, $source);
        }
        if (is_file($app->legacy . '/templates/layout/error.php')) {
            $this->view($app, 'templates/layout/error.php', true, $assets, $error);
        } elseif ($this->canFail) {
            $this->refusals[] = ['file' => 'legacy/templates/layout/error.php', 'line' => 1, 'reason' => 'the error layout is missing'];
        }
    }

    /**
     * composer.json with the split CakePHP packages the runtime needs, at the
     * legacy app's CakePHP minor; null when nothing is missing.
     */
    private function composer(string $appRoot, CakeRuntime $runtime): ?string
    {
        $file = $appRoot . '/composer.json';
        $json = is_file($file) ? json_decode((string) file_get_contents($file)) : null;
        if (!$json instanceof \stdClass) {
            $this->manual[] = 'composer.json could not be read, so add ' . implode(', ', CakeRuntime::PACKAGES) . ' (' . $runtime->constraint() . ') to the Laravel half by hand';

            return null;
        }
        if (!isset($json->require) || !$json->require instanceof \stdClass) {
            $json->require = new \stdClass();
        }
        foreach (CakeRuntime::PACKAGES as $package) {
            if (!isset($json->require->{$package})) {
                $json->require->{$package} = $runtime->constraint();
                $this->adds[$package] = $runtime->constraint();
            }
        }
        if ($this->adds === []) {
            return null;
        }
        $this->manual[] = sprintf('composer.json requires %s (%s): the CakePHP pieces the moved code runs on (strings, collections, dates, translations); the finish step drops them with the last CakePHP idiom', implode(', ', array_keys($this->adds)), $runtime->constraint());

        return json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    /** Whether the legacy app runs AuthorizationMiddleware (every action must authorize or skip). */
    private static function authorizes(CakeApp $app): bool
    {
        $file = $app->legacy . '/src/Application.php';

        return is_file($file) && preg_match('/new\s+(?:\\\\?Authorization\\\\Middleware\\\\)?AuthorizationMiddleware\b/', (string) file_get_contents($file)) === 1;
    }

    /** @param list<string> $list */
    private static function export(array $list): string
    {
        return '[' . implode(', ', array_map(static fn(string $s): string => var_export($s, true), $list)) . ']';
    }

    private static function refused(Unit $unit, string $reason): MoveResult
    {
        return new MoveResult(false, [], [['file' => $unit->sourceFile, 'line' => $unit->sourceLine, 'reason' => $reason]], []);
    }

    /**
     * @param list<array{file: string, line: int, reason: string}> $refusals
     *
     * @return list<array{file: string, line: int, reason: string}>
     */
    private static function unique(array $refusals): array
    {
        $out = [];
        foreach ($refusals as $refusal) {
            $out[$refusal['file'] . ':' . $refusal['line'] . ' ' . $refusal['reason']] = $refusal;
        }

        return array_values($out);
    }
}
