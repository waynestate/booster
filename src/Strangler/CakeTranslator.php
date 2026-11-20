<?php

declare(strict_types=1);

namespace Waynestate\Booster\Strangler;

use ReflectionFunction;

/**
 * Rewrites CakePHP code for the Laravel half, keeping bytes, not idioms
 * (design section 4): a controller action into a Laravel controller method,
 * a template, element or layout into a Blade file that renders the same
 * HTML. It maps only what it can prove; everything else is a refusal with
 * the file and line, so the unit stays legacy until a person ports it.
 *
 * Controller statements that map:
 * - `$this->request->getData/getQuery/getParam/is/allowMethod/getAttribute('identity')`
 * - `$this->set(...)` (into the view data) and the template it renders
 * - `return $this->redirect([...])` (the path CakePHP's router builds)
 * - `$this->Flash->success/error/...('message')` (into Laravel's session)
 * - `$this->Authentication->getIdentity()/getIdentityData()` (SESAME's user,
 *   `$request->user()`), `Hash::get` on it, `Authorization->skipAuthorization()`
 * - plain PHP (internal functions and global classes), copied as is
 *
 * Template helpers that map: `Html->charset/css/script/meta('icon')/link`,
 * `Url->build`, `fetch`, `assign('title')`, `Flash->render`, `element`,
 * `h()` and a plain `__('text')`.
 */
final class CakeTranslator
{
    /** The session key the Laravel half keeps CakePHP-style flash messages under. */
    public const FLASH_KEY = 'booster_flash';

    /** @var list<array{file: string, line: int, reason: string}> */
    public array $refusals = [];

    /** @var list<string> */
    public array $manual = [];

    /** Whether the code wrote a flash message. */
    public bool $flashes = false;

    /** Whether the code read the signed-in user. */
    public bool $readsUser = false;

    /** @var list<array{controller: string, action: string, prefix: string|null}> redirect targets that carry a flash */
    public array $redirects = [];

    /** @var list<string> view names the template pulls in (elements, the flash partial) */
    public array $includes = [];

    /** The template a `render()` or `setTemplate()` call named, if any. */
    public ?string $template = null;

    /** The layout a `viewBuilder()->setLayout()` call named, if any. */
    public ?string $layout = null;

    /** @var array<string, string> import alias (lowercased) => fully qualified name */
    private array $imports = [];

    private string $namespace = '';

    /**
     * @param 'controller'|'template' $mode
     * @param array{name: string, prefix: string|null}|null $controller
     * @param string $renderCall the PHP that renders the action's template, for `return;`
     * @param array{css: string, js: string, encoding: string, timestamp: bool} $assets
     */
    public function __construct(
        private readonly CakeApp $app,
        private readonly string $mode,
        private readonly string $file,
        private readonly ?array $controller = null,
        private readonly string $renderCall = '',
        private readonly array $assets = ['css' => 'css/', 'js' => 'js/', 'encoding' => 'utf-8', 'timestamp' => false],
    ) {}

    /**
     * The `use` imports and namespace of a file, so class names resolve.
     */
    public function readImports(CakeCode $c): void
    {
        foreach ($c->t as $i => $token) {
            if ($token[0] === T_NAMESPACE) {
                $j = $c->next($i);
                if ($j !== null && in_array($c->t[$j][0], [T_NAME_QUALIFIED, T_STRING], true)) {
                    $this->namespace = $c->t[$j][1];
                }
            }
            if ($token[0] !== T_USE) {
                continue;
            }
            $prev = $c->prev($i);
            if ($prev !== null && $c->t[$prev][1] === ')') {
                continue;
            }
            $end = $i;
            while (isset($c->t[$end]) && $c->t[$end][1] !== ';' && $c->t[$end][1] !== '{') {
                $end++;
            }
            $text = trim($c->text($i + 1, $end - 1));
            if (str_starts_with($text, 'function ') || str_starts_with($text, 'const ') || $c->t[$end][1] === '{') {
                continue;
            }
            foreach (explode(',', $text) as $part) {
                if (preg_match('/^\\\\?([\w\\\\]+)(?:\s+as\s+(\w+))?$/i', trim($part), $m) === 1) {
                    $alias = $m[2] ?? substr($m[1], (int) strrpos('\\' . $m[1], '\\'));
                    $this->imports[strtolower($alias)] = $m[1];
                }
            }
        }
    }

    /**
     * The `use` lines the rewritten code needs: imports of PHP's own classes.
     *
     * @return list<string>
     */
    public function globalImports(): array
    {
        $out = [];
        foreach ($this->imports as $alias => $fq) {
            if (!str_contains($fq, '\\') && (class_exists($fq) || interface_exists($fq))) {
                $out[] = strcasecmp($alias, $fq) === 0 ? 'use ' . $fq . ';' : 'use ' . $fq . ' as ' . $alias . ';';
            }
        }
        sort($out);

        return $out;
    }

    private function refuse(int $line, string $reason): void
    {
        $this->refusals[] = ['file' => $this->file, 'line' => $line, 'reason' => $reason];
    }

    /**
     * The source between two tokens, rewritten.
     */
    public function rewrite(CakeCode $c, int $from, int $to): string
    {
        $out = '';
        for ($i = $from; $i <= $to; $i++) {
            [$id, $text, $line] = $c->t[$i];
            if ($id === T_VARIABLE && $text === '$this') {
                [$replacement, $end] = $this->onThis($c, $i, $to);
                $out .= $replacement;
                $i = $end;
                continue;
            }
            if ($id === T_RETURN && $this->mode === 'controller') {
                $n = $c->next($i, $to);
                if ($n !== null && $c->t[$n][1] === ';') {
                    $out .= 'return ' . $this->renderCall;
                    $i = $n - 1;
                    continue;
                }
            }
            if ($id === T_STATIC || ($id === T_STRING && in_array(strtolower($text), ['parent', 'self'], true))) {
                $n = $c->next($i, $to);
                if ($n !== null && $c->t[$n][0] === T_DOUBLE_COLON) {
                    $this->refuse($line, sprintf('`%s::` reaches the legacy controller class, which the Laravel half does not have', $text));
                }
            }
            if (in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                $handled = $this->onName($c, $i, $to);
                if ($handled !== null) {
                    $out .= $handled[0];
                    $i = $handled[1];
                    continue;
                }
            }
            if ($id === T_INLINE_HTML || $id === T_OPEN_TAG || $id === T_CLOSE_TAG || $id === T_OPEN_TAG_WITH_ECHO) {
                $out .= $text;
                continue;
            }
            if ($id === T_EXIT || $id === T_EVAL || $id === T_INCLUDE || $id === T_INCLUDE_ONCE || $id === T_REQUIRE || $id === T_REQUIRE_ONCE) {
                $this->refuse($line, sprintf('`%s` has no mechanical Laravel equivalent here', strtolower($text)));
            }
            if ($id === T_GLOBAL || ($id === T_VARIABLE && in_array($text, ['$_SESSION', '$_COOKIE', '$GLOBALS'], true))) {
                $this->refuse($line, sprintf('`%s` reads state the Laravel half does not share with legacy', $text));
            }
            $out .= $text;
        }

        return $out;
    }

    /**
     * A function call, a class name, or a constant at $i.
     *
     * @return array{string, int}|null replacement and last token, or null to copy the token
     */
    private function onName(CakeCode $c, int $i, int $to): ?array
    {
        [$id, $text, $line] = $c->t[$i];
        $prev = $c->prev($i);
        $prevText = $prev === null ? '' : $c->t[$prev][1];
        $prevId = $prev === null ? null : $c->t[$prev][0];
        if (in_array($prevId, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST], true)) {
            return null;
        }
        $next = $c->next($i, $to);
        $nextText = $next === null ? '' : $c->t[$next][1];
        $lower = strtolower(ltrim($text, '\\'));
        if (in_array($lower, ['true', 'false', 'null', 'array', 'list', 'self', 'parent', 'static', 'int', 'string', 'bool', 'float', 'mixed', 'void', 'iterable', 'object', 'callable', 'never'], true)) {
            return null;
        }
        // A function call.
        if ($nextText === '(' && $prevId !== T_NEW) {
            if ($lower === '__' && isset($c->close[$next])) {
                return $this->translate($c, $i, $next, $line);
            }
            if ($lower === 'h' && $this->mode === 'template' && isset($c->close[$next])) {
                return ['e(' . $this->rewrite($c, $next + 1, $c->close[$next] - 1) . ')', $c->close[$next]];
            }
            if ($lower === 'compact' || $lower === 'isset' || $lower === 'empty' || $lower === 'unset' || $lower === 'list') {
                return null;
            }
            $name = str_contains($lower, '\\') ? $lower : $lower;
            if (!function_exists($name) || !(new ReflectionFunction($name))->isInternal()) {
                $this->refuse($line, sprintf('`%s()` is not a PHP function the Laravel half has (a CakePHP or app helper)', $text));
            }

            return null;
        }
        // A class name: after new, before ::, after instanceof, in a catch.
        $isClass = $prevId === T_NEW || $prevId === T_INSTANCEOF || $nextText === '::' || ($prevText === '(' && $c->t[(int) $c->prev($prev)][0] === T_CATCH);
        if (!$isClass) {
            return null;
        }
        $fq = $this->resolve($text);
        if ($nextText === '::' && strcasecmp($fq, 'Cake\\Utility\\Hash') === 0) {
            $method = $c->next((int) $next, $to);
            $open = $method === null ? null : $c->next($method, $to);
            if ($method !== null && $open !== null && strcasecmp($c->t[$method][1], 'get') === 0 && $c->t[$open][1] === '(' && isset($c->close[$open])) {
                return ['data_get(' . $this->rewrite($c, $open + 1, $c->close[$open] - 1) . ')', $c->close[$open]];
            }
        }
        if (str_contains($fq, '\\') || !class_exists($fq) && !interface_exists($fq)) {
            $this->refuse($line, sprintf('`%s` is a class from the legacy app or its vendor/ (%s), which the Laravel half does not load', $text, $fq));
        }

        return null;
    }

    private function resolve(string $name): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }
        $first = strtolower(explode('\\', $name)[0]);
        if (isset($this->imports[$first])) {
            $rest = substr($name, strlen($first));

            return $this->imports[$first] . $rest;
        }

        return $this->namespace === '' ? $name : $this->namespace . '\\' . $name;
    }

    /**
     * `__('plain text')`: the text itself, when the legacy app has no
     * translations and the text has no placeholders.
     *
     * @return array{string, int}
     */
    private function translate(CakeCode $c, int $i, int $open, int $line): array
    {
        $args = $c->items($open);
        $text = count($args) === 1 ? $c->stringAt($args[0]) : null;
        if ($text === null || preg_match('/[{}\'#|]/', $text) === 1) {
            $this->refuse($line, '`__()` with arguments or ICU placeholders needs CakePHP\'s message formatter');

            return [$c->text($i, $c->close[$open]), $c->close[$open]];
        }
        if ((glob($this->app->legacy . '/resources/locales/*/*.{po,mo}', GLOB_BRACE) ?: []) !== []) {
            $this->refuse($line, '`__()` in an app with translations (resources/locales) needs CakePHP\'s translator');
        }

        return [var_export($text, true), $c->close[$open]];
    }

    /**
     * `$this->...` at $i.
     *
     * @return array{string, int} replacement and last token consumed
     */
    private function onThis(CakeCode $c, int $i, int $to): array
    {
        $line = $c->line($i);
        $chain = $this->chain($c, $i, $to);
        if ($chain === []) {
            $this->refuse($line, '`$this` is the legacy controller or view, which the Laravel half does not have');

            return ['$this', $i];
        }

        return $this->mode === 'controller' ? $this->controllerCall($c, $i, $chain, $line) : $this->viewCall($c, $i, $chain, $line);
    }

    /**
     * The member chain after `$this`: each link's name, its "(" when it is a
     * call, and its last token.
     *
     * @return list<array{name: string, open: int|null, end: int, nullsafe: bool}>
     */
    private function chain(CakeCode $c, int $i, int $to): array
    {
        $links = [];
        $at = $i;
        while (true) {
            $arrow = $c->next($at, $to);
            if ($arrow === null || !in_array($c->t[$arrow][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                break;
            }
            $name = $c->next($arrow, $to);
            if ($name === null || $c->t[$name][0] !== T_STRING) {
                break;
            }
            $open = $c->next($name, $to);
            if ($open !== null && $c->t[$open][1] === '(' && isset($c->close[$open])) {
                $links[] = ['name' => $c->t[$name][1], 'open' => $open, 'end' => $c->close[$open], 'nullsafe' => $c->t[$arrow][0] === T_NULLSAFE_OBJECT_OPERATOR];
                $at = $c->close[$open];
                continue;
            }
            $links[] = ['name' => $c->t[$name][1], 'open' => null, 'end' => $name, 'nullsafe' => $c->t[$arrow][0] === T_NULLSAFE_OBJECT_OPERATOR];
            $at = $name;
        }

        return $links;
    }

    /**
     * @param list<array{name: string, open: int|null, end: int, nullsafe: bool}> $chain
     *
     * @return array{string, int}
     */
    private function controllerCall(CakeCode $c, int $i, array $chain, int $line): array
    {
        $first = strtolower($chain[0]['name']);
        $second = isset($chain[1]) ? strtolower($chain[1]['name']) : '';
        $args = fn(int $k): string => $chain[$k]['open'] === null ? '' : $this->rewrite($c, $chain[$k]['open'] + 1, $chain[$k]['end'] - 1);
        $items = fn(int $k): array => $chain[$k]['open'] === null ? [] : $c->items($chain[$k]['open']);
        $statement = $this->isStatement($c, $i, $chain[count($chain) - 1]['end']);
        $returned = $this->isReturned($c, $i);

        // $this->request->...
        if (($first === 'request' && $chain[0]['open'] === null) || ($first === 'getrequest' && $chain[0]['open'] !== null)) {
            if (!isset($chain[1]) || $chain[1]['open'] === null) {
                $this->refuse($line, '`$this->request` used as an object; only its getData/getQuery/getParam/is/allowMethod calls map');

                return ['$request', $chain[0]['end']];
            }
            $end = $chain[1]['end'];
            switch ($second) {
                case 'getdata':
                    return [$items(1) === [] ? '$request->post()' : 'data_get($request->post(), ' . $args(1) . ')', $end];
                case 'getquery':
                    return [$items(1) === [] ? '$request->query()' : 'data_get($request->query(), ' . $args(1) . ')', $end];
                case 'getparam':
                    $key = $c->stringAt($items(1)[0] ?? null);
                    if ($key === 'controller' && $this->controller !== null) {
                        return [var_export($this->controller['name'], true), $end];
                    }
                    if ($key === null || str_starts_with($key, '_') || in_array($key, ['pass', 'action', 'prefix', 'plugin', 'controller'], true)) {
                        $this->refuse($line, '`getParam()` of a routing key the Laravel route does not carry');

                        return ['$request', $end];
                    }

                    return ['$request->route(' . var_export($key, true) . ')', $end];
                case 'is':
                    return [$this->isCall($c, $items(1), $line), $end];
                case 'allowmethod':
                    // The Laravel route answers only the methods the unit
                    // moved with, which are these, so the check always holds.
                    if ($statement) {
                        return ['/* allowMethod: the route answers only these methods */ null', $end];
                    }
                    $this->refuse($line, '`allowMethod()` used as a value');

                    return ['null', $end];
                case 'getattribute':
                    if ($c->stringAt($items(1)[0] ?? null) === 'identity') {
                        $this->readsUser = true;

                        return $this->identityChain($c, $chain, 1, '$request->user()', $line);
                    }
                    break;
            }
            $this->refuse($line, sprintf('`$this->request->%s()` has no mapping to the Laravel request', $chain[1]['name']));

            return ['$request', $end];
        }

        // $this->set(...)
        if ($first === 'set' && $chain[0]['open'] !== null && count($chain) === 1) {
            if (!$statement) {
                $this->refuse($line, '`$this->set()` used as a value');

                return ['null', $chain[0]['end']];
            }
            $list = $items(0);
            if (count($list) === 2) {
                return ['$viewData[' . $this->rewrite($c, $list[0][0], $list[0][1]) . '] =' . $this->rewrite($c, $list[1][0], $list[1][1]), $chain[0]['end']];
            }
            if (count($list) === 1) {
                $key = $c->stringAt($list[0]);
                if ($key !== null) {
                    $this->refuse($line, '`$this->set()` with one name and no value');
                }

                return ['$viewData = array_merge($viewData, ' . trim($this->rewrite($c, $list[0][0], $list[0][1])) . ')', $chain[0]['end']];
            }
            $this->refuse($line, '`$this->set()` with no arguments');

            return ['null', $chain[0]['end']];
        }

        // return $this->redirect(...)
        if ($first === 'redirect' && $chain[0]['open'] !== null && count($chain) === 1) {
            $list = $items(0);
            $path = isset($list[0]) ? $this->urlPath($c, $list[0], $line, true) : null;
            if (!$returned) {
                $this->refuse($line, '`$this->redirect()` without `return` keeps running the action in CakePHP; port it by hand');
            }
            if ($path === null) {
                return ['null', $chain[0]['end']];
            }
            $status = isset($list[1]) ? ', ' . trim($this->rewrite($c, $list[1][0], $list[1][1])) : '';
            if (str_starts_with($path, 'away:')) {
                $path = substr($path, 5);
            }

            return ['CakeView::redirect($request, ' . $path . $status . ')', $chain[0]['end']];
        }

        // return $this->render('template')
        if ($first === 'render' && $chain[0]['open'] !== null && count($chain) === 1) {
            $name = $c->stringAt($items(0)[0] ?? null);
            if (!$returned || $name === null || count($items(0)) > 1) {
                $this->refuse($line, '`$this->render()` other than `return $this->render(\'template\')`');

                return ['null', $chain[0]['end']];
            }
            if ($this->template !== null && $this->template !== $name) {
                $this->refuse($line, 'the action renders more than one template');
            }
            $this->template = $name;

            return [$this->renderCall === '' ? 'null' : $this->renderCall, $chain[0]['end']];
        }

        // $this->viewBuilder()->setLayout()/setTemplate()
        if ($first === 'viewbuilder' && $chain[0]['open'] !== null && count($chain) === 2 && in_array($second, ['setlayout', 'settemplate'], true) && $statement) {
            $name = $c->stringAt($items(1)[0] ?? null);
            if ($name === null) {
                $this->refuse($line, sprintf('`viewBuilder()->%s()` with a name that is not a literal', $chain[1]['name']));
            } elseif ($second === 'setlayout') {
                $this->layout = $name;
            } else {
                $this->template = $name;
            }

            return ['null', $chain[1]['end']];
        }

        // $this->Flash->success('message')
        if ($first === 'flash' && $chain[0]['open'] === null && isset($chain[1]) && $chain[1]['open'] !== null && count($chain) === 2) {
            $list = $items(1);
            if (!$statement || count($list) !== 1) {
                $this->refuse($line, '`$this->Flash` with options or used as a value');

                return ['null', $chain[1]['end']];
            }
            $element = $second === 'set' ? 'default' : strtolower((string) preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', $chain[1]['name']));
            $this->flashes = true;

            return [sprintf("session()->push('%s.flash', ['message' => %s, 'key' => 'flash', 'element' => 'flash/%s', 'params' => []])", self::FLASH_KEY, trim($this->rewrite($c, $list[0][0], $list[0][1])), $element), $chain[1]['end']];
        }

        // $this->Authentication->getIdentity() / getIdentityData()
        if ($first === 'authentication' && $chain[0]['open'] === null && isset($chain[1]) && $chain[1]['open'] !== null) {
            if ($second === 'getidentity') {
                $this->readsUser = true;

                return $this->identityChain($c, $chain, 1, '$request->user()', $line);
            }
            if ($second === 'getidentitydata') {
                $this->readsUser = true;

                return ['data_get($request->user(), ' . $args(1) . ')', $chain[1]['end']];
            }
            if (in_array($second, ['addunauthenticatedactions', 'allowunauthenticated'], true) && $statement) {
                return ['/* the route middleware decides who may reach the action */ null', $chain[1]['end']];
            }
        }
        if ($first === 'auth' && $chain[0]['open'] === null && $second === 'allow' && $statement) {
            return ['/* the route middleware decides who may reach the action */ null', $chain[1]['end']];
        }

        // $this->Authorization->skipAuthorization()
        if ($first === 'authorization' && $second === 'skipauthorization' && $statement) {
            return ['/* skipAuthorization: the Laravel half has no authorization check to skip */ null', $chain[1]['end']];
        }

        // $this->loadComponent() of a component the move maps.
        if ($first === 'loadcomponent' && $chain[0]['open'] !== null && $statement) {
            $name = $c->stringAt($items(0)[0] ?? null);
            if (in_array($name, ['Flash', 'Authentication.Authentication', 'Authorization.Authorization'], true)) {
                return [sprintf('/* loadComponent(%s): mapped */ null', var_export($name, true)), $chain[0]['end']];
            }
            $this->refuse($line, sprintf('the controller loads the `%s` component, which the Laravel half has no mapping for', $name ?? '?'));

            return ['null', $chain[0]['end']];
        }

        $this->refuse($line, sprintf('`$this->%s` has no mapping to Laravel (component, model, controller API or property)', $chain[0]['name'] . (isset($chain[1]) ? '->' . $chain[1]['name'] : '')));
        $last = $chain[count($chain) - 1]['end'];

        return [$c->text($i, $last), $last];
    }

    /**
     * The user, and the calls on it that map: getIdentifier(),
     * get('field'), getOriginalData().
     *
     * @param list<array{name: string, open: int|null, end: int, nullsafe: bool}> $chain
     *
     * @return array{string, int}
     */
    private function identityChain(CakeCode $c, array $chain, int $k, string $user, int $line): array
    {
        $end = $chain[$k]['end'];
        $next = $chain[$k + 1] ?? null;
        if ($next === null) {
            return [$user, $end];
        }
        $name = strtolower($next['name']);
        $op = $next['nullsafe'] ? '?->' : '->';
        if ($name === 'getidentifier' && $next['open'] !== null) {
            return [$user . $op . 'getAuthIdentifier()', $next['end']];
        }
        if ($name === 'getoriginaldata' && $next['open'] !== null) {
            return [$user, $next['end']];
        }
        if ($name === 'get' && $next['open'] !== null) {
            return ['data_get(' . $user . ', ' . $this->rewrite($c, $next['open'] + 1, $next['end'] - 1) . ')', $next['end']];
        }
        if ($next['open'] === null) {
            return ['data_get(' . $user . ', ' . var_export($next['name'], true) . ')', $next['end']];
        }
        $this->refuse($line, sprintf('`%s()` on the CakePHP identity has no mapping to the Laravel user', $next['name']));

        return [$user, $next['end']];
    }

    /**
     * `is('post')` and `is(['post', 'put'])`.
     *
     * @param list<array{int, int}> $items
     */
    private function isCall(CakeCode $c, array $items, int $line): string
    {
        $types = [];
        if (count($items) === 1) {
            $single = $c->stringAt($items[0]);
            $types = $single !== null ? [$single] : array_values(array_filter($c->arrayAt($items[0]) ?? [], 'is_string'));
        }
        $methods = [];
        foreach ($types as $type) {
            $lower = strtolower($type);
            if (in_array($lower, ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'], true)) {
                $methods[] = strtoupper($lower);
            } elseif ($lower === 'ajax' && count($types) === 1) {
                return '$request->ajax()';
            } else {
                $this->refuse($line, sprintf('`is(%s)` is a CakePHP request detector with no exact Laravel twin', var_export($type, true)));

                return 'false';
            }
        }
        if ($methods === []) {
            $this->refuse($line, '`is()` with an argument that is not a literal');

            return 'false';
        }

        return count($methods) === 1
            ? '$request->isMethod(' . var_export($methods[0], true) . ')'
            : 'in_array($request->getMethod(), ' . var_export($methods, true) . ', true)';
    }

    /** Whether the chain from $i to $end is a whole statement. */
    private function isStatement(CakeCode $c, int $i, int $end): bool
    {
        $p = $c->prev($i);
        $n = $c->next($end);

        return ($p === null || in_array($c->t[$p][1], [';', '{', '}', ':'], true) || $c->t[$p][0] === T_OPEN_TAG) && $n !== null && in_array($c->t[$n][1], [';'], true);
    }

    private function isReturned(CakeCode $c, int $i): bool
    {
        $p = $c->prev($i);

        return $p !== null && $c->t[$p][0] === T_RETURN;
    }

    /**
     * The path a URL argument (an array, a literal path, or a full URL)
     * leads to, as a PHP expression without the base path; "away:<expr>"
     * for a full URL; null (refused) when the router cannot be followed.
     *
     * @param array{int, int} $range
     */
    private function urlPath(CakeCode $c, array $range, int $line, bool $full): ?string
    {
        $string = $c->stringAt($range);
        if ($string !== null) {
            if (preg_match('#^https?://#', $string) === 1) {
                return 'away:' . var_export($string, true);
            }
            if (str_starts_with($string, '/') && !str_starts_with($string, '//')) {
                return var_export($string, true);
            }
            $this->refuse($line, sprintf('the URL %s is not a path the router turns into the same link', var_export($string, true)));

            return null;
        }
        $first = $c->firstIn($range);
        $open = $first;
        if ($first !== null && $c->t[$first][0] === T_ARRAY) {
            $open = $c->next($first);
        }
        if ($open === null || $c->t[$open][1] !== '[' && $c->t[$open][1] !== '(' || !isset($c->close[$open]) || $c->lastIn($range) !== $c->close[$open]) {
            $this->refuse($line, 'a URL that is not a literal array or path');

            return null;
        }
        $controller = $this->controller['name'] ?? null;
        $prefix = $this->controller['prefix'] ?? null;
        $action = 'index';
        $pass = [];
        foreach ($c->items($open) as $item) {
            $arrow = null;
            for ($j = $item[0]; $j <= $item[1]; $j++) {
                if (isset($c->close[$j])) {
                    $j = $c->close[$j];
                    continue;
                }
                if ($c->t[$j][0] === T_DOUBLE_ARROW) {
                    $arrow = $j;
                    break;
                }
            }
            if ($arrow === null) {
                $pass[] = trim($this->rewrite($c, $item[0], $item[1]));
                continue;
            }
            $key = $c->stringAt([$item[0], $arrow - 1]);
            $value = $c->scalarAt([$arrow + 1, $item[1]]);
            if ($key === 'controller' && is_string($value)) {
                $controller = CakeRoutes::camelize($value);
            } elseif ($key === 'action' && is_string($value)) {
                $action = $value;
            } elseif ($key === 'prefix' && ($value === false || $value === null || is_string($value))) {
                $prefix = is_string($value) ? $value : null;
            } elseif ($key === 'plugin' && ($value === false || $value === null)) {
                continue;
            } elseif ($key === '_full' && $value === true && $full) {
                continue;
            } else {
                $this->refuse($line, sprintf('the URL array key %s (or its value) is one this reader cannot route', var_export($key, true)));

                return null;
            }
        }
        if ($controller === null) {
            $this->refuse($line, 'a URL array outside a controller with no controller key');

            return null;
        }
        $path = $this->app->routes->reverse($controller, $action, $prefix, $pass);
        if ($path === null) {
            $this->refuse($line, sprintf('no route in config/routes.php builds a URL for %s::%s', $controller, $action));

            return null;
        }
        $this->redirects[] = ['controller' => $controller, 'action' => $action, 'prefix' => $prefix];

        return $path;
    }

    /**
     * `$this->...` in a template, element or layout.
     *
     * @param list<array{name: string, open: int|null, end: int, nullsafe: bool}> $chain
     *
     * @return array{string, int}
     */
    private function viewCall(CakeCode $c, int $i, array $chain, int $line): array
    {
        $first = strtolower($chain[0]['name']);
        $second = isset($chain[1]) ? strtolower($chain[1]['name']) : '';
        $items = fn(int $k): array => $chain[$k]['open'] === null ? [] : $c->items($chain[$k]['open']);
        $last = $chain[count($chain) - 1]['end'];
        $webroot = "e(request()->getBasePath()) . '/'";

        if ($first === 'fetch' && count($chain) === 1) {
            $name = $c->stringAt($items(0)[0] ?? null);
            if ($name !== null && count($items(0)) === 1) {
                return ['($__blocks[' . var_export($name, true) . "] ?? '')", $last];
            }
        }
        if ($first === 'assign' && count($chain) === 1 && count($items(0)) === 2 && $c->stringAt($items(0)[0]) !== null) {
            $list = $items(0);

            return ['$__blocks[' . var_export($c->stringAt($list[0]), true) . '] = (string) (' . trim($this->rewrite($c, $list[1][0], $list[1][1])) . ')', $last];
        }
        if ($first === 'element' && count($chain) === 1 && count($items(0)) === 1 && ($name = $c->stringAt($items(0)[0])) !== null && preg_match('#^[\w/-]+$#', $name) === 1) {
            $this->includes[] = 'element/' . $name;

            return ["\$__env->make('legacy.element." . str_replace('/', '.', $name) . "', \\Illuminate\\Support\\Arr::except(get_defined_vars(), ['__data', '__path']))->render()", $last];
        }
        if ($first === 'flash' && $second === 'render' && count($chain) === 2 && $items(1) === []) {
            $this->includes[] = 'flash';

            return ["\$__env->make('legacy.flash', \\Illuminate\\Support\\Arr::except(get_defined_vars(), ['__data', '__path']))->render()", $last];
        }
        if ($first === 'html' && count($chain) === 2 && $chain[1]['open'] !== null) {
            $list = $items(1);
            switch ($second) {
                case 'charset':
                    if ($list === []) {
                        return [var_export('<meta charset="' . $this->assets['encoding'] . '">', true), $last];
                    }
                    break;
                case 'css':
                case 'script':
                    if (count($list) === 1 && !$this->assets['timestamp']) {
                        $paths = $c->stringAt($list[0]) !== null ? [$c->stringAt($list[0])] : $c->arrayAt($list[0]);
                        if (is_array($paths) && $paths !== [] && count(array_filter($paths, 'is_string')) === count($paths)) {
                            $tags = [];
                            foreach ($paths as $path) {
                                $url = $this->assetPath((string) $path, $second === 'css' ? $this->assets['css'] : $this->assets['js'], $second === 'css' ? '.css' : '.js');
                                if ($url === null) {
                                    break 2;
                                }
                                $tags[] = $second === 'css'
                                    ? "'<link rel=\"stylesheet\" href=\"' . " . $url . " . '\">'"
                                    : "'<script src=\"' . " . $url . " . '\"></script>'";
                            }
                            if ($c->stringAt($list[0]) !== null) {
                                return ['(' . $tags[0] . ')', $last];
                            }

                            return ["(\"\\n\\t\" . " . implode(" . \"\\n\\t\" . ", $tags) . " . \"\\n\")", $last];
                        }
                    }
                    break;
                case 'meta':
                    if (count($list) === 1 && $c->stringAt($list[0]) === 'icon') {
                        return ["('<link href=\"' . " . $webroot . " . 'favicon.ico\" type=\"image/x-icon\" rel=\"icon\">')", $last];
                    }
                    break;
                case 'link':
                    if (count($list) >= 2 && count($list) <= 3) {
                        $url = $this->urlPath($c, $list[1], $line, false);
                        if ($url === null) {
                            return ["''", $last];
                        }
                        if (str_starts_with($url, 'away:')) {
                            $href = 'e(' . substr($url, 5) . ')';
                        } else {
                            $href = 'e(request()->getBasePath()) . ' . $this->escapedPath($url);
                        }
                        $attrs = '';
                        if (isset($list[2])) {
                            $options = $c->arrayAt($list[2]);
                            if ($options === null) {
                                $this->refuse($line, '`Html->link()` options that are not a literal array');

                                return ["''", $last];
                            }
                            foreach ($options as $key => $value) {
                                if (!is_string($key) || !is_string($value) || in_array($key, ['escape', 'escapeTitle', 'confirm', 'fullBase', 'block'], true) || preg_match('/^[\w.-]+$/', $key) !== 1) {
                                    $this->refuse($line, sprintf('`Html->link()` option %s needs CakePHP\'s helper', var_export($key, true)));

                                    return ["''", $last];
                                }
                                $attrs .= ' ' . $key . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
                            }
                        }
                        $title = trim($this->rewrite($c, $list[0][0], $list[0][1]));

                        return ["('<a href=\"' . " . $href . ' . ' . var_export('"' . $attrs . '>', true) . ' . e(' . $title . ") . '</a>')", $last];
                    }
                    break;
            }
        }
        if ($first === 'url' && $second === 'build' && count($chain) === 2 && count($items(1)) === 1) {
            $url = $this->urlPath($c, $items(1)[0], $line, false);
            if ($url !== null && !str_starts_with($url, 'away:')) {
                return ['(e(request()->getBasePath()) . ' . $this->escapedPath($url) . ')', $last];
            }
            if ($url !== null) {
                return ['e(' . substr($url, 5) . ')', $last];
            }

            return ["''", $last];
        }
        $call = '$this->' . $chain[0]['name'] . (isset($chain[1]) ? '->' . $chain[1]['name'] : '');
        $this->refuse($line, sprintf('the view helper call `%s` has no Blade mapping; port it by hand', $call));

        return [sprintf("/* MANUAL: %s was not mapped */ ''", str_replace('*/', '* /', $call)), $last];
    }

    /**
     * A path expression as CakePHP's helpers print it: escaped. A passed
     * argument was already rawurlencoded, which leaves nothing to escape.
     */
    private function escapedPath(string $expr): string
    {
        return (string) preg_replace_callback("/'(?:[^'\\\\]|\\\\.)*'/", static function (array $m): string {
            return var_export(htmlspecialchars(CakeCode::unquote($m[0]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), true);
        }, $expr);
    }

    /**
     * The webroot-relative URL CakePHP's Asset class builds for a literal
     * css or js path (no plugin, no theme, no timestamp), as an escaped PHP
     * expression, or null when it cannot be sure.
     */
    private function assetPath(string $path, string $prefix, string $ext): ?string
    {
        if (str_contains($path, '://') || preg_match('/^[a-z]+:/i', $path) === 1 || str_starts_with($path, '//')) {
            return var_export(htmlspecialchars($path, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), true);
        }
        $dot = strpos($path, '.');
        if ($dot !== false && is_dir($this->app->legacy . '/plugins/' . substr($path, 0, $dot))) {
            return null;
        }
        if (!str_starts_with($path, '/')) {
            $path = $prefix . $path;
        }
        if (!str_contains($path, '?') && !str_ends_with($path, $ext)) {
            $path .= $ext;
        }
        $query = '';
        if (str_contains($path, '?')) {
            [$path, $query] = explode('?', $path, 2);
            $query = '?' . $query;
        }
        $encoded = implode('/', array_map(static fn(string $part): string => rawurlencode(rawurldecode($part)), explode('/', ltrim($path, '/'))));

        return "e(request()->getBasePath()) . " . var_export(htmlspecialchars('/' . $encoded . $query, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), true);
    }
}
