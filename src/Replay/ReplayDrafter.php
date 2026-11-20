<?php

declare(strict_types=1);

namespace Waynestate\Booster\Replay;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Waynestate\Booster\Run\ProcessRunner;

/**
 * Drafts a request file from what the app shows about its routes: Laravel's
 * own route list when the app can print it (else its routes/ files), a
 * CakePHP app's config/routes.php (scopes, prefixes, connects, and the
 * controller actions its fallbacks reach), route calls in the style of Slim
 * and similar micro-frameworks (with their group prefixes), and the PHP entry
 * points of a raw-PHP app. Whatever a person must decide (an id from the fake
 * data, the fields a form posts, how the fake data is built) is marked with a
 * "todo" key, and Booster will not record until every todo is gone.
 */
final class ReplayDrafter
{
    /** The most requests a draft lists; a person adds the rest on purpose. */
    public const MAX_REQUESTS = 60;

    /** Folders never scanned for routes or entry points. */
    private const SKIP_DIRS = ['vendor', 'node_modules', '.git', 'storage', 'cache', 'tmp', 'temp', 'tests', 'test', 'bootstrap', 'database', 'resources'];

    /** Controller methods that are CakePHP's own hooks, never actions. */
    private const CAKE_HOOKS = ['initialize', 'beforefilter', 'beforerender', 'afterfilter', 'beforeredirect', 'implementedevents', 'isauthorized', 'viewclasses', 'startupprocess', 'shutdownprocess'];

    /** The folders a CakePHP app writes to as it serves, which git does not keep. */
    public const CAKE_SETUP = 'mkdir -p logs tmp/cache/models tmp/cache/persistent tmp/cache/views tmp/sessions tmp/tests';

    /** Raw-PHP folders that hold includes, not pages. */
    private const INCLUDE_DIRS = ['inc', 'include', 'includes', 'lib', 'libs', 'library', 'classes', 'class', 'config', 'conf', 'src', 'app', 'templates', 'views', 'partials'];

    public function __construct(private readonly ?ProcessRunner $processes = null) {}

    /**
     * @return array{json: string, source: string, requests: int, todos: int}
     */
    public function draft(string $appPath): array
    {
        $appPath = rtrim($appPath, '/\\');
        $docroot = is_file($appPath . '/public/index.php') ? 'public' : '.';
        $routes = [];
        $source = '';
        $cake = self::isCakePhp($appPath);
        if ($cake) {
            $docroot = 'webroot';
            $routes = $this->cakeRoutes($appPath);
            $source = $routes === [] ? '' : 'config/routes.php (its scopes, connects and the controller actions its fallbacks reach)';
        } elseif (is_file($appPath . '/artisan')) {
            $routes = $this->laravelRouteList($appPath);
            $source = $routes === [] ? 'routes/ files (php artisan route:list did not answer here)' : 'php artisan route:list';
            if ($routes === []) {
                $routes = $this->scanRouteCalls($appPath, '/\bRoute::(get|post|put|patch|delete|any)\(\s*[\'"]([^\'"]*)[\'"]/i', ['routes']);
            }
        } else {
            $routes = $this->slimRoutes($appPath);
            $source = $routes === [] ? '' : 'route calls in the code ($app->get(...) and the like, with their group prefixes)';
        }
        $frontController = null;
        if (($routes !== [] || $cake) && is_file($appPath . '/' . ($docroot === '.' ? '' : $docroot . '/') . 'index.php')) {
            $frontController = 'index.php';
        }
        if ($routes === []) {
            $routes = $this->entryPoints($appPath, $docroot);
            $source = $routes === [] ? 'nothing found: add requests by hand' : 'PHP entry points under ' . ($docroot === '.' ? 'the app folder' : $docroot . '/');
        }

        $pages = [];
        $writes = [];
        $seen = [];
        foreach ($routes as [$method, $path, $readsPost]) {
            $key = $method . ' ' . $path;
            // A phpinfo page answers differently on every PHP, so every hop
            // would fail its replay on it.
            if (isset($seen[$key]) || count($seen) >= self::MAX_REQUESTS || stripos($path, 'phpinfo') !== false) {
                continue;
            }
            $seen[$key] = true;
            $request = ['name' => $key, 'method' => $method, 'path' => $path];
            $todo = [];
            if (preg_match('/\{[^}]+\}|:[A-Za-z_]+|<[^>]+>|\[[^\]]*\]/', $path) === 1) {
                $todo[] = 'replace the placeholders in the path with ids from your fake data';
            }
            if ($method !== 'GET') {
                $request['form'] = (object) [];
                // The CSRF field comes from the answer before it (the form
                // page drafted in front of it), whichever framework printed it.
                $request['csrf'] = true;
                $todo[] = 'fill in the form fields this route reads';
                if ($readsPost) {
                    $request['name'] = 'POST ' . $path . ' (the page reads $_POST)';
                }
            }
            if ($todo !== []) {
                $request['todo'] = implode('; ', $todo);
            }
            if ($method === 'GET') {
                $pages[] = $request;
            } else {
                // "csrf": true reads the field from the previous answer, so
                // each write follows the page that shows its form (a form
                // moves as one unit in the strangler: its GET and its POST).
                $formPage = ['name' => 'the form for ' . $key, 'method' => 'GET', 'path' => $path];
                $placeholders = preg_match('/\{[^}]+\}|:[A-Za-z_]+|<[^>]+>|\[[^\]]*\]/', $path) === 1;
                if ($placeholders) {
                    $formPage['todo'] = 'replace the placeholders in the path with ids from your fake data';
                } elseif (!self::hasRoute($routes, 'GET', $path)) {
                    $formPage['todo'] = 'point this at the page that shows the form ' . $key . ' posts';
                }
                $writes[] = $formPage;
                $writes[] = $request;
            }
        }
        $pages[] = ['name' => 'an unknown path', 'method' => 'GET', 'path' => '/booster-replay-no-such-page'];

        $serve = [
            '$comment' => 'How Booster serves the app: PHP\'s built-in server in the gate image, with no network. setup runs inside the container first and builds the fake data (a SQLite file under /tmp, say). env holds fake settings only, never secrets.',
            'docroot' => $docroot,
        ];
        if ($frontController !== null) {
            $serve['front_controller'] = $frontController;
        }
        // A CakePHP app needs its tmp/ and logs/ folders (gitignored, so a
        // fresh checkout has none), and a salt app_local.php would set; DEBUG
        // off keeps stack traces and the debug bar out of every answer.
        $serve['setup'] = $cake ? self::CAKE_SETUP : '';
        $serve['env'] = $cake ? ['DEBUG' => 'false', 'SECURITY_SALT' => 'booster-replay-fake-salt-for-local-replays-only'] : (object) [];
        $serve['todo'] = 'Name the command that builds the fake data in setup (or leave setup empty if the app reads no database), then delete this todo.';

        $sequences = [['name' => 'pages', 'requests' => $pages]];
        if ($writes !== []) {
            $sequences[] = ['name' => 'forms', 'requests' => $writes];
        }
        $draft = [
            '$comment' => 'Booster replay request file, drafted by booster replay init from ' . ($source === '' ? 'nothing' : $source) . '. Each sequence starts with an empty cookie jar; requests in it share cookies like one browser. See the Booster docs, "The replay gate".',
            'version' => 1,
            'serve' => $serve,
            'normalize' => ['csrf', 'session-id', ...self::frontEndPresets($appPath)],
            'sequences' => $sequences,
        ];
        $json = json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        $todos = 1;
        foreach ([...$pages, ...$writes] as $request) {
            $todos += isset($request['todo']) ? 1 : 0;
        }

        return ['json' => $json, 'source' => $source, 'requests' => count($pages) + count($writes), 'todos' => $todos];
    }

    /**
     * @param list<array{0: string, 1: string, 2: bool}> $routes
     */
    private static function hasRoute(array $routes, string $method, string $path): bool
    {
        foreach ($routes as [$m, $p]) {
            if ($m === $method && $p === $path) {
                return true;
            }
        }

        return false;
    }

    /**
     * The presets a built front end needs: a Vite app's asset names and
     * preload hints change with every build, and an Inertia app's page object
     * carries the build's version (and changed shape in Inertia 3). Without
     * them the first front-end hop fails the replay on a sound change.
     *
     * @return list<string>
     */
    private static function frontEndPresets(string $appPath): array
    {
        $package = is_file($appPath . '/package.json') ? json_decode((string) file_get_contents($appPath . '/package.json'), true) : null;
        $composer = is_file($appPath . '/composer.json') ? json_decode((string) file_get_contents($appPath . '/composer.json'), true) : null;
        $npm = is_array($package) ? [...(array) ($package['dependencies'] ?? []), ...(array) ($package['devDependencies'] ?? [])] : [];
        $presets = [];
        if (isset($npm['vite'])) {
            $presets[] = 'asset-hash';
            $presets[] = 'preload-hints';
        }
        if (is_array($composer) && isset($composer['require']['inertiajs/inertia-laravel'])) {
            $presets[] = 'inertia';
        }

        return $presets;
    }

    /**
     * Laravel's own route list, when the app can boot on this machine's PHP
     * (vendor/ installed). Any failure is quiet: the draft falls back to
     * reading routes/.
     *
     * @return list<array{string, string, bool}>
     */
    private function laravelRouteList(string $appPath): array
    {
        if ($this->processes === null || !is_file($appPath . '/vendor/autoload.php')) {
            return [];
        }
        $result = $this->processes->run([PHP_BINARY, 'artisan', 'route:list', '--json'], $appPath, [], 120);
        $list = $result->successful() ? json_decode(trim($result->stdout), true) : null;
        if (!is_array($list)) {
            return [];
        }
        $routes = [];
        foreach ($list as $route) {
            if (!is_array($route) || !is_string($route['uri'] ?? null) || !is_string($route['method'] ?? null)) {
                continue;
            }
            $uri = '/' . ltrim($route['uri'], '/');
            if (preg_match('#^/(_ignition|_debugbar|telescope|horizon|sanctum|livewire|_boost)\b#', $uri) === 1) {
                continue;
            }
            $method = explode('|', $route['method'])[0];
            $routes[] = [$method === 'HEAD' ? 'GET' : strtoupper($method), $uri, false];
        }

        return $routes;
    }

    /**
     * A CakePHP app: config/routes.php beside a CakePHP composer.json, or the
     * skeleton's webroot/index.php and src/Controller/ when composer.json
     * does not say.
     */
    public static function isCakePhp(string $appPath): bool
    {
        if (!is_file($appPath . '/config/routes.php')) {
            return false;
        }
        $composer = is_file($appPath . '/composer.json') ? json_decode((string) file_get_contents($appPath . '/composer.json'), true) : null;
        if (is_array($composer) && (isset($composer['require']['cakephp/cakephp']) || isset($composer['require-dev']['cakephp/cakephp']))) {
            return true;
        }

        return is_file($appPath . '/webroot/index.php') && is_dir($appPath . '/src/Controller');
    }

    /**
     * The GET pages of a CakePHP app: every route config/routes.php declares
     * (a `/pages/*` route to Pages::display becomes one request per page
     * template), then, where it calls fallbacks(), one request per public
     * action of each controller in that folder (index as the controller's
     * own URL). A required action argument becomes a {placeholder} the
     * person fills in.
     *
     * @return list<array{string, string, bool}>
     */
    private function cakeRoutes(string $appPath): array
    {
        $found = RouteCallScanner::cake((string) file_get_contents($appPath . '/config/routes.php'));
        $routes = [];
        foreach ($found['routes'] as [$method, $path, $target]) {
            $wild = preg_match('#/\*{1,2}$#', $path) === 1;
            $base = $wild ? ((string) preg_replace('#/\*{1,2}$#', '', $path) ?: '/') : $path;
            if ($wild && (str_contains($target, 'Pages::display') || (preg_match('/[\'"]controller[\'"]\s*=>\s*[\'"]Pages[\'"]/', $target) === 1 && str_contains($target, 'display')))) {
                $pages = self::cakePageTemplates($appPath);
                foreach ($pages as $page) {
                    $routes[] = ['GET', rtrim($base, '/') . '/' . $page, false];
                }
                if ($pages !== []) {
                    continue;
                }
            }
            $routes[] = [$method, $base, false];
        }
        foreach ($found['fallbacks'] as [$prefix, $folder]) {
            foreach (self::cakeActions($appPath, $folder) as [$controller, $action, $params]) {
                $path = rtrim($prefix, '/') . '/' . RouteCallScanner::inflect($controller, $found['dashed']);
                if ($action !== 'index' || $params !== []) {
                    $path .= '/' . RouteCallScanner::inflect($action, $found['dashed']);
                }
                foreach ($params as $param) {
                    $path .= '/{' . $param . '}';
                }
                $routes[] = ['GET', $path, false];
            }
        }

        return $routes;
    }

    /**
     * The page names under templates/Pages (CakePHP 4 and 5) or
     * src/Template/Pages (CakePHP 3), at most five.
     *
     * @return list<string>
     */
    private static function cakePageTemplates(string $appPath): array
    {
        $names = [];
        foreach (['/templates/Pages/*.php', '/src/Template/Pages/*.ctp'] as $glob) {
            foreach (glob($appPath . $glob) ?: [] as $file) {
                $names[] = pathinfo($file, PATHINFO_FILENAME);
            }
        }
        sort($names, SORT_STRING);

        return array_slice(array_values(array_unique($names)), 0, 5);
    }

    /**
     * The public actions of each controller in src/Controller/<folder>, with
     * the arguments they require. AppController, ErrorController and
     * PagesController (routed by /pages/*) are not pages of their own.
     *
     * @return list<array{string, string, list<string>}> controller name, action, required argument names
     */
    private static function cakeActions(string $appPath, string $folder): array
    {
        $dir = $appPath . '/src/Controller' . ($folder === '' ? '' : '/' . $folder);
        $actions = [];
        $files = glob($dir . '/*Controller.php') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $controller = substr(basename($file), 0, -strlen('Controller.php'));
            if ($controller === '' || in_array($controller, ['App', 'Error', 'Pages'], true)) {
                continue;
            }
            $code = (string) file_get_contents($file);
            if (preg_match('/\babstract\s+class\b/i', $code) === 1 || preg_match_all('/^\s*public\s+function\s+([A-Za-z]\w*)\s*\(([^)]*)\)/mi', $code, $m, PREG_SET_ORDER) === false) {
                continue;
            }
            foreach ($m as $match) {
                if (in_array(strtolower($match[1]), self::CAKE_HOOKS, true)) {
                    continue;
                }
                $params = [];
                foreach (explode(',', $match[2]) as $param) {
                    if (preg_match('/^[^=$]*?(\.\.\.)?\s*\$(\w+)\s*(=)?/', trim($param), $p) === 1 && $p[1] === '' && !isset($p[3])) {
                        $params[] = $p[2];
                    }
                }
                $actions[] = [$controller, $match[1], $params];
            }
        }

        return $actions;
    }

    /**
     * Slim-style route calls in every PHP file, with their group prefixes.
     *
     * @return list<array{string, string, bool}>
     */
    private function slimRoutes(string $appPath): array
    {
        $codes = array_map(static fn(string $file): string => (string) @file_get_contents($file), $this->phpFiles($appPath, 400));

        return array_map(static fn(array $route): array => [$route[0], $route[1], false], RouteCallScanner::slimFiles($codes));
    }

    /**
     * @param list<string> $roots folders (relative to the app) to scan
     *
     * @return list<array{string, string, bool}>
     */
    private function scanRouteCalls(string $appPath, string $pattern, array $roots): array
    {
        $routes = [];
        foreach ($roots as $root) {
            foreach ($this->phpFiles($appPath . '/' . $root, 400) as $file) {
                $code = (string) @file_get_contents($file);
                if (preg_match_all($pattern, $code, $m, PREG_SET_ORDER) === false) {
                    continue;
                }
                foreach ($m as $match) {
                    $method = strtoupper($match[1]);
                    $path = '/' . ltrim($match[2], '/');
                    $routes[] = [$method === 'ANY' ? 'GET' : $method, $path, false];
                }
            }
        }

        return $routes;
    }

    /**
     * A raw-PHP app's pages: the .php files a browser can reach under the
     * docroot, leaving out folders that hold includes.
     *
     * @return list<array{string, string, bool}>
     */
    private function entryPoints(string $appPath, string $docroot): array
    {
        $base = $docroot === '.' ? $appPath : $appPath . '/' . $docroot;
        $routes = [];
        foreach ($this->phpFiles($base, 400) as $file) {
            $relative = str_replace('\\', '/', substr($file, strlen($base) + 1));
            $parts = explode('/', $relative);
            if (count($parts) > 3 || str_starts_with((string) end($parts), '_')) {
                continue;
            }
            if (count($parts) > 1 && in_array(strtolower($parts[0]), self::INCLUDE_DIRS, true)) {
                continue;
            }
            $path = '/' . $relative;
            if (basename($relative) === 'index.php') {
                $path = '/' . substr($relative, 0, -strlen('index.php'));
            }
            $code = (string) @file_get_contents($file);
            // A phpinfo() page answers differently on every PHP, so every
            // hop would fail its replay on it.
            if (preg_match('/\bphpinfo\s*\(/i', $code) === 1) {
                continue;
            }
            $routes[] = ['GET', $path, false];
            if (str_contains($code, '$_POST')) {
                $routes[] = ['POST', $path, true];
            }
        }
        usort($routes, static fn(array $a, array $b): int => [$a[1], $a[0]] <=> [$b[1], $b[0]]);

        return $routes;
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $dir, int $limit): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                static fn(\SplFileInfo $f): bool => !$f->isDir() || !in_array(strtolower($f->getFilename()), self::SKIP_DIRS, true),
            ),
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || strtolower($file->getExtension()) !== 'php' || $file->getSize() > 300_000) {
                continue;
            }
            $files[] = $file->getPathname();
            if (count($files) >= $limit) {
                break;
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }
}
