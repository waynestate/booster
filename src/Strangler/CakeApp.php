<?php

declare(strict_types=1);

namespace Waynestate\Booster\Strangler;

/**
 * The legacy CakePHP app under `<appRoot>/legacy`, read from source: its
 * routes, its controllers and their actions, the hooks that run before an
 * action, which actions need a signed-in user, the template each action
 * renders, and the edges that tie actions together for a move (a form
 * rendered by one action and posted to another, a flash message written by
 * one action and shown by the page it redirects to).
 *
 * Nothing here runs the app.
 */
final class CakeApp
{
    /** Controller methods that are CakePHP's hooks or its own API, never actions. */
    public const HOOKS = ['initialize', 'beforefilter', 'beforerender', 'afterfilter', 'beforeredirect', 'implementedevents', 'isauthorized', 'viewclasses', 'startupprocess', 'shutdownprocess', 'redirect', 'render', 'set', 'paginate', 'loadcomponent', 'isaction', 'invokeaction'];

    /** Action names that are a sign-in or sign-out by their role. */
    private const SIGNIN_NAMES = ['login', 'logout', 'signin', 'signout', 'callback', 'sso', 'cas'];

    public readonly string $legacy;

    public readonly CakeRoutes $routes;

    /** @var array<string, array{key: string, name: string, prefix: string|null, file: string, code: CakeCode, class: array{name: string, namespace: string, parent: string, abstract: bool, open: int, close: int, methods: array<string, array{name: string, public: bool, static: bool, line: int, params: list<array{name: string, optional: bool, variadic: bool, type: string}>, open: int, close: int, start: int}>}}> keyed "Prefix/Name" or "Name" */
    private array $controllers = [];

    /** @var array<string, string> unauthenticatedRedirect or loginAction target, "Controller::action" lowercased */
    private array $signInTargets = [];

    public function __construct(public readonly string $appRoot)
    {
        $this->legacy = rtrim($appRoot, '/\\') . '/legacy';
        $routes = $this->legacy . '/config/routes.php';
        $this->routes = CakeRoutes::read(is_file($routes) ? (string) file_get_contents($routes) : '');
        $this->readControllers($this->legacy . '/src/Controller', null);
        $this->readSignInTargets();
    }

    public static function isCakePhp(string $appRoot): bool
    {
        $legacy = rtrim($appRoot, '/\\') . '/legacy';
        if (!is_file($legacy . '/config/routes.php')) {
            return false;
        }
        $composer = is_file($legacy . '/composer.json') ? json_decode((string) file_get_contents($legacy . '/composer.json'), true) : null;

        return is_array($composer) && (isset($composer['require']['cakephp/cakephp']) || isset($composer['require-dev']['cakephp/cakephp']));
    }

    private function readControllers(string $dir, ?string $prefix): void
    {
        $files = glob($dir . '/*Controller.php') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $name = substr(basename($file), 0, -strlen('Controller.php'));
            $code = new CakeCode((string) file_get_contents($file));
            $class = $code->firstClass();
            if ($class === null) {
                continue;
            }
            $key = $prefix === null ? $name : $prefix . '/' . $name;
            $this->controllers[strtolower($key)] = ['key' => $key, 'name' => $name, 'prefix' => $prefix, 'file' => $this->relative($file), 'code' => $code, 'class' => $class];
        }
        foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
            if (in_array(basename($sub), ['Component'], true)) {
                continue;
            }
            $this->readControllers($sub, $prefix === null ? basename($sub) : $prefix . '/' . basename($sub));
        }
    }

    public function relative(string $path): string
    {
        $root = str_replace('\\', '/', rtrim($this->appRoot, '/\\')) . '/';
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    /**
     * @return array{key: string, name: string, prefix: string|null, file: string, code: CakeCode, class: array{name: string, namespace: string, parent: string, abstract: bool, open: int, close: int, methods: array<string, array{name: string, public: bool, static: bool, line: int, params: list<array{name: string, optional: bool, variadic: bool, type: string}>, open: int, close: int, start: int}>}}|null
     */
    public function controller(string $name, ?string $prefix): ?array
    {
        $key = strtolower($prefix === null ? $name : $prefix . '/' . $name);

        return $this->controllers[$key] ?? null;
    }

    /**
     * Every controller, keyed "Prefix/Name".
     *
     * @return array<string, array{key: string, name: string, prefix: string|null, file: string, code: CakeCode, class: array{name: string, namespace: string, parent: string, abstract: bool, open: int, close: int, methods: array<string, array{name: string, public: bool, static: bool, line: int, params: list<array{name: string, optional: bool, variadic: bool, type: string}>, open: int, close: int, start: int}>}}>
     */
    public function controllers(): array
    {
        return $this->controllers;
    }

    /**
     * The controller a class extends, found in its own folder first and
     * then in src/Controller (how a prefixed controller reaches the app's
     * AppController).
     *
     * @param array{key: string, name: string, prefix: string|null, file: string, code: CakeCode, class: array{name: string, namespace: string, parent: string, abstract: bool, open: int, close: int, methods: array<string, mixed>}} $controller
     *
     * @return list<array{key: string, name: string, prefix: string|null, file: string, code: CakeCode, class: array{name: string, namespace: string, parent: string, abstract: bool, open: int, close: int, methods: array<string, array{name: string, public: bool, static: bool, line: int, params: list<array{name: string, optional: bool, variadic: bool, type: string}>, open: int, close: int, start: int}>}}> the controller and its app parents, nearest first
     */
    public function chain(array $controller): array
    {
        $chain = [];
        $seen = [];
        $current = $this->controllers[strtolower($controller['key'])] ?? null;
        while ($current !== null && !isset($seen[$current['key']])) {
            $seen[$current['key']] = true;
            $chain[] = $current;
            $parent = $current['class']['parent'];
            if ($parent === '' || $parent === 'Controller' || !str_ends_with($parent, 'Controller')) {
                break;
            }
            $short = substr($parent, 0, -strlen('Controller'));
            $current = $this->controller($short, $current['prefix']) ?? $this->controller($short, null);
        }

        return $chain;
    }

    /**
     * The public actions of a controller, in file order, without hooks.
     *
     * @param array{class: array{methods: array<string, array{name: string, public: bool, static: bool, line: int, params: list<array{name: string, optional: bool, variadic: bool, type: string}>, open: int, close: int, start: int}>, abstract: bool}, name: string} $controller
     *
     * @return list<array{name: string, public: bool, static: bool, line: int, params: list<array{name: string, optional: bool, variadic: bool, type: string}>, open: int, close: int, start: int}>
     */
    public function actions(array $controller): array
    {
        if ($controller['class']['abstract'] || in_array($controller['name'], ['App', 'Error'], true)) {
            return [];
        }
        $out = [];
        foreach ($controller['class']['methods'] as $lower => $method) {
            if (!$method['public'] || $method['static'] || str_starts_with($method['name'], '_') || in_array($lower, self::HOOKS, true)) {
                continue;
            }
            $out[] = $method;
        }

        return $out;
    }

    /**
     * The components a controller chain loads, by the name in
     * loadComponent() ('Flash', 'Authentication.Authentication', ...).
     *
     * @param list<array{code: CakeCode, class: array{methods: array<string, array{open: int, close: int}>}}> $chain
     *
     * @return list<string>
     */
    public function components(array $chain): array
    {
        $out = [];
        foreach ($chain as $controller) {
            $c = $controller['code'];
            foreach ($c->t as $i => $token) {
                if ($token[0] === T_STRING && strcasecmp($token[1], 'loadComponent') === 0) {
                    $open = $c->next($i);
                    if ($open !== null && $c->t[$open][1] === '(') {
                        $name = $c->stringAt($c->items($open)[0] ?? null);
                        if ($name !== null) {
                            $out[] = $name;
                        }
                    }
                }
            }
            if (preg_match('/\$components\s*=\s*\[([^\]]*)\]/', $c->code, $m) === 1 && preg_match_all('/[\'"]([A-Za-z.]+)[\'"]/', $m[1], $names) > 0) {
                foreach ($names[1] as $name) {
                    $out[] = $name;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Whether an action needs a signed-in user: the chain loads the
     * Authentication component (or CakePHP 4's Auth) and no hook in the
     * chain opens the action to guests.
     *
     * @param list<array{code: CakeCode, class: array{methods: array<string, array{open: int, close: int}>}}> $chain
     */
    public function requiresIdentity(array $chain, string $action): bool
    {
        $components = $this->components($chain);
        $authn = in_array('Authentication.Authentication', $components, true);
        $auth = in_array('Auth', $components, true);
        if (!$authn && !$auth) {
            return false;
        }
        foreach ($chain as $controller) {
            $c = $controller['code'];
            foreach ($c->t as $i => $token) {
                if ($token[0] !== T_STRING || !in_array(strtolower($token[1]), ['addunauthenticatedactions', 'allowunauthenticated', 'allow'], true)) {
                    continue;
                }
                $open = $c->next($i);
                if ($open === null || $c->t[$open][1] !== '(') {
                    continue;
                }
                $items = $c->items($open);
                if ($items === [] && strtolower($token[1]) === 'allow') {
                    return false;
                }
                $names = $c->arrayAt($items[0] ?? [$open, $open]) ?? [];
                $single = $c->stringAt($items[0] ?? null);
                if ($single !== null) {
                    $names = [$single];
                }
                foreach ($names as $name) {
                    if (is_string($name) && strcasecmp($name, $action) === 0) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    private function readSignInTargets(): void
    {
        foreach ([$this->legacy . '/src/Application.php', $this->legacy . '/src/Controller/AppController.php'] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $tokens = new CakeCode((string) file_get_contents($file));
            $code = $tokens->code(0, count($tokens->t) - 1);
            if (preg_match_all('/[\'"](?:unauthenticatedRedirect|loginUrl|loginAction)[\'"]\s*=>\s*(?:Router::url\()?\s*(\[[^\]]*\]|[\'"][^\'"]*[\'"])/', $code, $m) > 0) {
                foreach ($m[1] as $target) {
                    if (preg_match('/[\'"]controller[\'"]\s*=>\s*[\'"](\w+)[\'"]/', $target, $c) === 1) {
                        $action = preg_match('/[\'"]action[\'"]\s*=>\s*[\'"](\w+)[\'"]/', $target, $a) === 1 ? $a[1] : 'index';
                        $this->signInTargets[strtolower($c[1] . '::' . $action)] = $target;
                    } elseif (preg_match('#^[\'"]/?([\w-]+)/([\w-]+)#', $target, $p) === 1) {
                        $this->signInTargets[strtolower(CakeRoutes::camelize($p[1]) . '::' . lcfirst(CakeRoutes::camelize($p[2])))] = $target;
                    }
                }
            }
        }
    }

    /**
     * What a move needs to know about one action, read from its body.
     *
     * @param array{key: string, name: string, prefix: string|null, file: string, code: CakeCode} $controller
     * @param array{name: string, line: int, open: int, close: int} $method
     *
     * @return array{writes: list<string>, get: bool, json: bool, identity: bool, session: bool, signIn: bool, template: string|null, targets: list<array{controller: string, action: string, prefix: string|null}>, flashTargets: list<array{controller: string, action: string, prefix: string|null}>}
     */
    public function analyse(array $controller, array $method): array
    {
        $c = $controller['code'];
        $body = $c->code($method['open'], $method['close']);
        $writes = [];
        $get = true;
        if (preg_match_all('/->is\(\s*(\[[^\]]*\]|[\'"]\w+[\'"])/i', $body, $m) > 0) {
            foreach ($m[1] as $arg) {
                preg_match_all('/[\'"](\w+)[\'"]/', $arg, $verbs);
                foreach ($verbs[1] as $verb) {
                    if (in_array(strtolower($verb), ['post', 'put', 'patch', 'delete'], true)) {
                        $writes[] = strtoupper($verb);
                    }
                }
            }
        }
        if (preg_match('/->allowMethod\(\s*(\[[^\]]*\]|[\'"]\w+[\'"])/i', $body, $am) === 1) {
            preg_match_all('/[\'"](\w+)[\'"]/', $am[1], $verbs);
            $allowed = array_map('strtoupper', $verbs[1]);
            $get = in_array('GET', $allowed, true);
            foreach ($allowed as $verb) {
                if (!in_array($verb, ['GET', 'HEAD'], true)) {
                    $writes[] = $verb;
                }
            }
        }
        if ($writes === [] && preg_match('/->getData\(/', $body) === 1) {
            $writes[] = 'POST';
        }
        $writes = array_values(array_unique($writes));
        $json = preg_match('/[\'"]_serialize[\'"]|setOption\(\s*[\'"]serialize[\'"]|setClassName\(\s*[\'"](?:Json|Xml)|withType\(\s*[\'"](?:application\/)?json|json_encode\(|withStringBody\(/i', $body) === 1;
        $identity = preg_match('/->getIdentity\(|->getIdentityData\(|getAttribute\(\s*[\'"]identity[\'"]|->Auth->user\(/i', $body) === 1;
        $session = preg_match('/->getSession\(|->Flash->|\$_SESSION/i', $body) === 1;
        $key = strtolower($controller['name'] . '::' . $method['name']);
        $signIn = isset($this->signInTargets[$key])
            || in_array(strtolower($method['name']), self::SIGNIN_NAMES, true)
            || preg_match('/->Authentication->(?:getResult|logout|setIdentity)\(|->Auth->(?:setUser|identify|logout)\(|\bsesame(?:_login|_logout)?\(/i', $body) === 1;
        $template = $this->templateFor($controller, $method['name'], $body);
        $targets = [];
        if ($template !== null && is_file($this->legacy . '/' . $template)) {
            $targets = $this->formTargets((string) file_get_contents($this->legacy . '/' . $template), $controller['name'], $method['name'], $controller['prefix']);
        }
        $flashTargets = [];
        if (preg_match('/->Flash->/', $body) === 1 && preg_match_all('/->redirect\(\s*(\[[^\]]*\])/', $body, $rm) > 0) {
            foreach ($rm[1] as $array) {
                $target = self::urlArrayTarget($array, $controller['name'], $controller['prefix']);
                if ($target !== null) {
                    $flashTargets[] = $target;
                }
            }
        }

        return ['writes' => $writes, 'get' => $get, 'json' => $json, 'identity' => $identity, 'session' => $session, 'signIn' => $signIn, 'template' => $template, 'targets' => $targets, 'flashTargets' => $flashTargets];
    }

    /**
     * The template an action renders, relative to legacy/, or null when it
     * renders none (JSON, autoRender off).
     *
     * @param array{name: string, prefix: string|null} $controller
     */
    public function templateFor(array $controller, string $action, string $body): ?string
    {
        if (preg_match('/disableAutoRender\(|autoRender\s*=\s*false/', $body) === 1) {
            return null;
        }
        $name = $action;
        if (preg_match('/->(?:setTemplate|render)\(\s*[\'"]([\w\/]+)[\'"]/', $body, $m) === 1) {
            $name = $m[1];
        }
        if (str_starts_with($name, '/')) {
            return 'templates' . $name . '.php';
        }
        $dir = 'templates/' . ($controller['prefix'] === null ? '' : $controller['prefix'] . '/') . $controller['name'] . '/';
        $underscored = strtolower((string) preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', $name));
        foreach ([$underscored, $name] as $candidate) {
            if (is_file($this->legacy . '/' . $dir . $candidate . '.php')) {
                return $dir . $candidate . '.php';
            }
        }

        return $dir . $underscored . '.php';
    }

    /**
     * The actions a template posts to: `Form->create()` (its own action
     * unless a `url` option says otherwise), `Form->postLink()` and
     * `Form->postButton()`.
     *
     * @return list<array{controller: string, action: string, prefix: string|null}>
     */
    public function formTargets(string $template, string $controller, string $action, ?string $prefix): array
    {
        $out = [];
        $c = new CakeCode($template);
        foreach ($c->calls('Form', 'create') as $open) {
            $args = $c->items($open);
            $options = isset($args[1]) ? $c->code($args[1][0], $args[1][1]) : '';
            if (preg_match('/[\'"]url[\'"]\s*=>\s*(?:\$this->Url->build\(|Router::url\()?\s*(\[[^\]]*\])/', $options, $u) === 1) {
                $target = self::urlArrayTarget($u[1], $controller, $prefix);
                if ($target !== null) {
                    $out[] = $target;
                }
            } elseif (!str_contains($options, "'url'") && !str_contains($options, '"url"')) {
                $out[] = ['controller' => $controller, 'action' => $action, 'prefix' => $prefix];
            }
        }
        foreach (['postLink', 'postButton'] as $helper) {
            foreach ($c->calls('Form', $helper) as $open) {
                $args = $c->items($open);
                $url = isset($args[1]) ? $c->code($args[1][0], $args[1][1]) : '';
                if (str_starts_with(trim($url), '[')) {
                    $target = self::urlArrayTarget($url, $controller, $prefix);
                    if ($target !== null) {
                        $out[] = $target;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * The controller and action of a URL array's source text, with the
     * current controller and prefix as the defaults.
     *
     * @return array{controller: string, action: string, prefix: string|null}|null
     */
    public static function urlArrayTarget(string $array, string $controller, ?string $prefix): ?array
    {
        if (preg_match('/[\'"]plugin[\'"]\s*=>\s*[\'"]/', $array) === 1) {
            return null;
        }
        $target = $controller;
        if (preg_match('/[\'"]controller[\'"]\s*=>\s*[\'"](\w+)[\'"]/', $array, $c) === 1) {
            $target = CakeRoutes::camelize($c[1]);
        }
        $action = preg_match('/[\'"]action[\'"]\s*=>\s*[\'"](\w+)[\'"]/', $array, $a) === 1 ? $a[1] : 'index';
        if (preg_match('/[\'"]prefix[\'"]\s*=>\s*(false|null|[\'"](\w+)[\'"])/', $array, $p) === 1) {
            $prefix = ($p[2] ?? '') !== '' ? $p[2] : null;
        }

        return ['controller' => $target, 'action' => $action, 'prefix' => $prefix];
    }
}
