<?php

/*
 * Slim 1 bridge: the Slim 1.6 API this app was written against, running on
 * Slim 4.
 *
 * Booster's vendored-to-composer hop wrote this file when it replaced the
 * hand-copied Slim 1 under vendor/Slim with slim/slim 4 from Composer. The
 * app's routes did not change: `$app->get('/batches/:id', function ($id) use
 * ($app) {...})` still works, because the classes below keep Slim 1's names
 * and behavior and hand the routing, the PSR-7 request and the sending of the
 * answer to Slim 4. Composer loads this file (autoload.classmap), so nothing
 * requires it by hand.
 *
 * It is the app's code now, not Booster's. To leave it behind, move one route
 * at a time to plain Slim 4 (or to Laravel) with the replay gate green after
 * each, and delete this file when nothing calls `new Slim(...)` any more.
 *
 * What it keeps from Slim 1 (ported from Slim 1.6.7, MIT, (c) Josh Lockhart):
 * route patterns with :params and conditions, first registered route wins,
 * parameters passed to the callable in pattern order and url-decoded, echoed
 * output as the body, Content-Type text/html by default, the 404, 405 and 500
 * pages byte for byte, the 301 to a trailing slash, _METHOD and
 * X-HTTP-Method-Override, render() through the app's own Slim_View subclass,
 * halt(), stop(), redirect(), notFound(), error(), urlFor(), getMode().
 *
 * What it does not do, on purpose: turn PHP notices into error pages (Slim 1
 * did that in development mode; on a new PHP every deprecation would become a
 * 500), hooks, application middleware, flash messages, cookies through the
 * app object, HTTP caching helpers and pass(). Booster refused the move if
 * the app used any of them, so none of them is missing here.
 *
 * Written for PHP 7.4 and up, the oldest PHP Slim 4 runs on.
 */

use Psr\Http\Message\ResponseInterface;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;
use Slim\ResponseEmitter;

/** Thrown by halt(), stop() and redirect(); the bridge catches it and sends the answer. */
class Slim_Exception_Stop extends Exception
{
}

/** Thrown when a route whose pattern ends in "/" is asked for without it; answered with a 301. */
class Slim_Exception_RequestSlash extends Exception
{
}

#[AllowDynamicProperties]
class Slim
{
    /** The characters a :param matches when the route sets no condition for it (Slim 1.6.7). */
    const PARAM_CHARACTERS = '[a-zA-Z0-9_\-\.\!\~\*\\\'\(\)\:\@\&\=\$\+,%]+';

    protected $settings = array();
    protected $mode;
    protected $view;
    protected $environment = array();
    protected $request;
    protected $response;
    /** @var Slim_Route[] */
    protected $routes = array();
    protected $notFoundHandler;
    protected $errorHandler;

    public function __construct($userSettings = array())
    {
        $this->settings = array_merge(self::getDefaultSettings(), $userSettings);
        $this->bridgeEnvironment(Slim_Http_Request::environmentFromGlobals());
        $this->getMode();
        $this->view($this->config('view'));
    }

    public static function getDefaultSettings()
    {
        return array(
            'mode' => 'development',
            'debug' => true,
            'templates.path' => './templates',
            'view' => 'Slim_View',
            'http.version' => '1.1',
        );
    }

    /**
     * Not Slim 1: swaps in a request environment (the shape Slim 1's
     * Slim_Environment built from $_SERVER) and starts a fresh response.
     * Tests and replays use it; the app never needs to.
     */
    public function bridgeEnvironment(array $environment)
    {
        $this->environment = $environment;
        $this->request = new Slim_Http_Request($this->environment);
        $this->response = new Slim_Http_Response();
    }

    public function config($name, $value = null)
    {
        if (func_num_args() === 1) {
            if (is_array($name)) {
                $this->settings = array_merge($this->settings, $name);

                return null;
            }

            return isset($this->settings[$name]) ? $this->settings[$name] : null;
        }
        $this->settings[$name] = $value;

        return null;
    }

    public function getMode()
    {
        if (!isset($this->mode)) {
            if (isset($_ENV['SLIM_MODE'])) {
                $this->mode = $_ENV['SLIM_MODE'];
            } else {
                $envMode = getenv('SLIM_MODE');
                $this->mode = $envMode !== false ? $envMode : $this->config('mode');
            }
        }

        return $this->mode;
    }

    public function configureMode($mode, $callable)
    {
        if ($mode === $this->getMode() && is_callable($callable)) {
            call_user_func($callable);
        }
    }

    /***** ROUTES *****/

    public function map()
    {
        return $this->mapRoute(func_get_args());
    }

    public function get()
    {
        return $this->mapRoute(func_get_args())->via('GET', 'HEAD');
    }

    public function post()
    {
        return $this->mapRoute(func_get_args())->via('POST');
    }

    public function put()
    {
        return $this->mapRoute(func_get_args())->via('PUT');
    }

    public function delete()
    {
        return $this->mapRoute(func_get_args())->via('DELETE');
    }

    public function options()
    {
        return $this->mapRoute(func_get_args())->via('OPTIONS');
    }

    protected function mapRoute(array $args)
    {
        $pattern = array_shift($args);
        $callable = array_pop($args);
        $route = new Slim_Route($pattern, $callable, $args);
        $this->routes[] = $route;

        return $route;
    }

    public function notFound($callable = null)
    {
        if (!is_null($callable)) {
            $this->notFoundHandler = $callable;

            return;
        }
        ob_start();
        if (is_callable($this->notFoundHandler)) {
            call_user_func($this->notFoundHandler);
        } else {
            $this->defaultNotFound();
        }
        $this->halt(404, ob_get_clean());
    }

    public function error($argument = null)
    {
        if (is_callable($argument)) {
            $this->errorHandler = $argument;

            return;
        }
        $this->response->status(500);
        $this->response->body('');
        $this->response->write($this->callErrorHandler($argument));
        $this->stop();
    }

    protected function callErrorHandler($argument = null)
    {
        ob_start();
        if (is_callable($this->errorHandler)) {
            call_user_func_array($this->errorHandler, array($argument));
        } else {
            $this->defaultError();
        }

        return ob_get_clean();
    }

    /***** ACCESSORS *****/

    public function request()
    {
        return $this->request;
    }

    public function response()
    {
        return $this->response;
    }

    public function view($viewClass = null)
    {
        if (!is_null($viewClass)) {
            $existingData = is_null($this->view) ? array() : $this->view->getData();
            $this->view = $viewClass instanceof Slim_View ? $viewClass : new $viewClass();
            $this->view->appendData($existingData);
            $this->view->setTemplatesDirectory($this->config('templates.path'));
        }

        return $this->view;
    }

    public function render($template, $data = array(), $status = null)
    {
        if (!is_null($status)) {
            $this->response->status($status);
        }
        $this->view->setTemplatesDirectory($this->config('templates.path'));
        $this->view->appendData($data);
        $this->view->display($template);
    }

    /***** FLOW *****/

    public function stop()
    {
        throw new Slim_Exception_Stop();
    }

    public function halt($status, $message = '')
    {
        if (ob_get_level() !== 0) {
            ob_clean();
        }
        $this->response->status($status);
        $this->response->body($message);
        $this->stop();
    }

    public function pass()
    {
        throw new LogicException('pass() is not supported by the Slim 1 bridge: Slim 4 picks one route per request.');
    }

    public function contentType($type)
    {
        $this->response['Content-Type'] = $type;
    }

    public function status($code)
    {
        $this->response->status($code);
    }

    public function urlFor($name, $params = array())
    {
        $route = null;
        foreach ($this->routes as $candidate) {
            if ($candidate->getName() === $name) {
                $route = $candidate;
                break;
            }
        }
        if ($route === null) {
            throw new RuntimeException('Named route not found for name: ' . $name);
        }
        $search = $replace = array();
        foreach ($params as $key => $value) {
            $search[] = ':' . $key;
            $replace[] = $value;
        }
        $pattern = str_replace($search, $replace, $route->getPattern());

        return preg_replace(array('@\(\/?:.+\/??\)\??@', '@\?|\(|\)@'), '', $this->request->getRootUri() . $pattern);
    }

    public function redirect($url, $status = 302)
    {
        $this->response->redirect($url, $status);
        $this->halt($status, $url);
    }

    /***** RUN *****/

    /** Answers the current request and sends it, as Slim 1's run() did. */
    public function run()
    {
        $this->bridgeEmit($this->bridgeHandle());
    }

    /**
     * Not Slim 1: answers the current request without sending it, as
     * array(status, headers, body). Tests and replays read it directly.
     */
    public function bridgeHandle()
    {
        $this->overrideMethod();
        $level = ob_get_level();
        try {
            ob_start();
            $allowed = $this->dispatch();
            if ($allowed !== true) {
                if ($allowed !== array()) {
                    $this->response['Allow'] = implode(' ', $allowed);
                    $this->halt(405, 'HTTP method not allowed for the requested resource. Use one of these instead: ' . implode(', ', $allowed));
                }
                $this->notFound();
            }
            $this->response->write(ob_get_clean());
        } catch (Slim_Exception_Stop $e) {
            $this->response->write($this->closeBuffers($level));
        } catch (Slim_Exception_RequestSlash $e) {
            $this->closeBuffers($level);
            $this->response->redirect($this->request->getPath() . '/', 301);
        } catch (Exception $e) {
            $this->closeBuffers($level);
            if ($this->config('debug')) {
                $this->response->status(500);
                $this->response->body(self::prettyException($e));
            } else {
                try {
                    $this->error($e);
                } catch (Slim_Exception_Stop $stop) {
                }
            }
        }
        $this->closeBuffers($level);

        return $this->response->finalize();
    }

    /** Ends every output buffer above $level and returns what they held, oldest first. */
    private function closeBuffers($level)
    {
        $output = '';
        while (ob_get_level() > $level) {
            $output = ob_get_clean() . $output;
        }

        return $output;
    }

    /** Slim 1's MethodOverride middleware: X-HTTP-Method-Override, or _METHOD in a POST body. */
    private function overrideMethod()
    {
        if (isset($this->environment['X_HTTP_METHOD_OVERRIDE'])) {
            $this->environment['slim.method_override.original_method'] = $this->environment['REQUEST_METHOD'];
            $this->environment['REQUEST_METHOD'] = strtoupper($this->environment['X_HTTP_METHOD_OVERRIDE']);
        } elseif (isset($this->environment['REQUEST_METHOD']) && $this->environment['REQUEST_METHOD'] === 'POST') {
            $method = $this->request->post('_METHOD');
            if ($method) {
                $this->environment['slim.method_override.original_method'] = 'POST';
                $this->environment['REQUEST_METHOD'] = strtoupper($method);
            }
        }
        $this->request->bridgeEnvironment($this->environment);
    }

    /**
     * Routes the request with Slim 4. True when a route ran; otherwise the
     * methods the path allows (empty when nothing matched the path at all).
     */
    private function dispatch()
    {
        $slim = AppFactory::create();
        $self = $this;
        // FastRoute refuses two shapes Slim 1 allowed: the same pattern twice
        // for one method, and a static route after a variable route that
        // already matches it. In Slim 1 the later route never ran (the first
        // match won), so the bridge leaves it out rather than crash.
        $registered = array();
        $earlier = array();
        foreach ($this->routes as $route) {
            foreach ($route->bridgePatterns() as $pattern => $addSlash) {
                $shape = preg_replace('/\{\w+:/', '{:', $pattern);
                $static = strpos($pattern, '{') === false;
                $methods = array();
                foreach ($route->getHttpMethods() as $method) {
                    if (isset($registered[$method . ' ' . $shape]) || ($static && self::shadowed($earlier, $method, $pattern))) {
                        continue;
                    }
                    $registered[$method . ' ' . $shape] = true;
                    $methods[] = $method;
                }
                if ($methods === array()) {
                    continue;
                }
                $slim->map($methods, $pattern, function ($request, $response, $args) use ($self, $route, $addSlash) {
                    $self->bridgeDispatch($route, $args, $addSlash);

                    return $response;
                });
            }
            $earlier[] = $route;
        }

        // Slim 1 matched the raw path and url-decoded each parameter once.
        // Slim 4 decodes the path before it routes, so the % signs are
        // escaped here and the routes see the raw path, as they always did.
        $uri = 'http://localhost' . str_replace('%', '%25', $this->request->getPathInfo());
        $request = (new ServerRequestFactory())->createServerRequest($this->request->getMethod(), $uri);
        try {
            $slim->handle($request);
        } catch (HttpNotFoundException $e) {
            return array();
        } catch (HttpMethodNotAllowedException $e) {
            return array_values($e->getAllowedMethods());
        }

        return true;
    }

    /** True when a route before this one with :params already answers $path for $method. */
    private static function shadowed(array $earlier, $method, $path)
    {
        foreach ($earlier as $route) {
            if ($route->getParamNames() !== array() && in_array($method, $route->getHttpMethods(), true) && $route->bridgeMatches($path)) {
                return true;
            }
        }

        return false;
    }

    /** Runs one matched route the way Slim 1's Slim_Route::dispatch() did. Called by the bridge only. */
    public function bridgeDispatch(Slim_Route $route, array $args, $addSlash)
    {
        if ($addSlash) {
            throw new Slim_Exception_RequestSlash();
        }
        foreach ($route->getMiddleware() as $middleware) {
            if (is_callable($middleware)) {
                call_user_func_array($middleware, array($this->request, $this->response, $route));
            }
        }
        $params = array();
        foreach ($route->getParamNames() as $name) {
            if (isset($args[$name])) {
                $params[] = urldecode($args[$name]);
            }
        }
        call_user_func_array($route->getCallable(), $params);
    }

    private function bridgeEmit(array $answer)
    {
        list($status, $headers, $body) = $answer;
        $response = new Psr7Response($status);
        foreach ($headers as $name => $value) {
            foreach (explode("\n", (string) $value) as $line) {
                $response = $response->withAddedHeader($name, $line);
            }
        }
        $response->getBody()->write($body);
        (new ResponseEmitter())->emit($response);
    }

    /***** PAGES (Slim 1.6.7, byte for byte) *****/

    protected static function generateTemplateMarkup($title, $body)
    {
        return sprintf("<html><head><title>%s</title><style>body{margin:0;padding:30px;font:12px/1.5 Helvetica,Arial,Verdana,sans-serif;}h1{margin:0;font-size:48px;font-weight:normal;line-height:48px;}strong{display:inline-block;width:65px;}</style></head><body><h1>%s</h1>%s</body></html>", $title, $title, $body);
    }

    protected function defaultNotFound()
    {
        echo self::generateTemplateMarkup('404 Page Not Found', '<p>The page you are looking for could not be found. Check the address bar to ensure your URL is spelled correctly. If all else fails, you can visit our home page at the link below.</p><a href="' . $this->request->getRootUri() . '/">Visit the Home Page</a>');
    }

    protected function defaultError()
    {
        echo self::generateTemplateMarkup('Error', '<p>A website error has occured. The website administrator has been notified of the issue. Sorry for the temporary inconvenience.</p>');
    }

    /** Slim 1's PrettyExceptions page, shown in debug mode. */
    protected static function prettyException(Exception $exception)
    {
        $title = 'Slim Application Error';
        $html = sprintf('<h1>%s</h1>', $title);
        $html .= '<p>The application could not run because of the following error:</p>';
        $html .= '<h2>Details</h2>';
        if ($exception->getCode()) {
            $html .= sprintf('<div><strong>Code:</strong> %s</div>', $exception->getCode());
        }
        if ($exception->getMessage()) {
            $html .= sprintf('<div><strong>Message:</strong> %s</div>', $exception->getMessage());
        }
        if ($exception->getFile()) {
            $html .= sprintf('<div><strong>File:</strong> %s</div>', $exception->getFile());
        }
        if ($exception->getLine()) {
            $html .= sprintf('<div><strong>Line:</strong> %s</div>', $exception->getLine());
        }
        if ($exception->getTraceAsString()) {
            $html .= '<h2>Trace</h2>';
            $html .= sprintf('<pre>%s</pre>', $exception->getTraceAsString());
        }

        return sprintf("<html><head><title>%s</title><style>body{margin:0;padding:30px;font:12px/1.5 Helvetica,Arial,Verdana,sans-serif;}h1{margin:0;font-size:48px;font-weight:normal;line-height:48px;}strong{display:inline-block;width:65px;}</style></head><body>%s</body></html>", $title, $html);
    }
}

class Slim_Route
{
    protected $pattern;
    protected $callable;
    protected $middleware;
    protected $conditions = array();
    protected $methods = array();
    protected $name;

    public function __construct($pattern, $callable, array $middleware = array())
    {
        $this->pattern = $pattern;
        $this->callable = $callable;
        $this->middleware = $middleware;
    }

    public function getPattern()
    {
        return $this->pattern;
    }

    public function getCallable()
    {
        return $this->callable;
    }

    public function getMiddleware()
    {
        return $this->middleware;
    }

    public function getName()
    {
        return $this->name;
    }

    public function setName($name)
    {
        $this->name = (string) $name;
    }

    public function name($name)
    {
        $this->setName($name);

        return $this;
    }

    public function conditions(array $conditions)
    {
        $this->conditions = array_merge($this->conditions, $conditions);

        return $this;
    }

    public function via()
    {
        $this->methods = array_unique(array_merge($this->methods, func_get_args()));

        return $this;
    }

    public function getHttpMethods()
    {
        return array_values($this->methods);
    }

    /** The :param names in the order the pattern names them. */
    public function getParamNames()
    {
        preg_match_all('@:([\w]+)@', $this->pattern, $names);

        return $names[1];
    }

    /** Slim 1.6's Slim_Route::matches(): whether the route answers $resourceUri. */
    public function bridgeMatches($resourceUri)
    {
        $conditions = $this->conditions;
        $regex = preg_replace_callback('@:([\w]+)@', function ($match) use ($conditions) {
            $condition = array_key_exists($match[1], $conditions) ? $conditions[$match[1]] : Slim::PARAM_CHARACTERS;

            return '(?P<' . $match[1] . '>' . $condition . ')';
        }, $this->pattern);
        if (substr($this->pattern, -1) === '/') {
            $regex .= '?';
        }

        return preg_match('@^' . $regex . '$@', $resourceUri) === 1;
    }

    /**
     * The Slim 4 patterns for this route, mapped to whether a match must be
     * answered with Slim 1's 301 to the trailing slash. A pattern ending in
     * "/" also matched without it in Slim 1, which then redirected.
     */
    public function bridgePatterns()
    {
        $conditions = $this->conditions;
        $translated = preg_replace_callback('@:([\w]+)@', function ($match) use ($conditions) {
            if (array_key_exists($match[1], $conditions)) {
                // FastRoute refuses capturing groups inside a placeholder.
                return '{' . $match[1] . ':' . preg_replace('/(?<!\\\\)\((?!\?)/', '(?:', $conditions[$match[1]]) . '}';
            }

            return '{' . $match[1] . ':' . Slim::PARAM_CHARACTERS . '}';
        }, $this->pattern);

        if (strlen($translated) > 1 && substr($translated, -1) === '/') {
            return array($translated => false, substr($translated, 0, -1) => true);
        }

        return array($translated => false);
    }
}

class Slim_View
{
    protected $data = array();
    protected $templatesDirectory;

    public function __construct()
    {
    }

    public function getData($key = null)
    {
        if (!is_null($key)) {
            return isset($this->data[$key]) ? $this->data[$key] : null;
        }

        return $this->data;
    }

    public function setData()
    {
        $args = func_get_args();
        if (count($args) === 1 && is_array($args[0])) {
            $this->data = $args[0];
        } elseif (count($args) === 2) {
            $this->data[(string) $args[0]] = $args[1];
        } else {
            throw new InvalidArgumentException('Cannot set View data with provided arguments. Usage: `View::setData( $key, $value );` or `View::setData([ key => value, ... ]);`');
        }
    }

    public function appendData(array $data)
    {
        $this->data = array_merge($this->data, $data);
    }

    public function getTemplatesDirectory()
    {
        return $this->templatesDirectory;
    }

    public function setTemplatesDirectory($dir)
    {
        $this->templatesDirectory = rtrim((string) $dir, '/');
    }

    public function display($template)
    {
        echo $this->render($template);
    }

    public function fetch($template)
    {
        return $this->render($template);
    }

    public function render($template)
    {
        extract($this->data);
        $templatePath = $this->getTemplatesDirectory() . '/' . ltrim($template, '/');
        if (!file_exists($templatePath)) {
            throw new RuntimeException('View cannot render template `' . $templatePath . '`. Template does not exist.');
        }
        ob_start();
        require $templatePath;

        return ob_get_clean();
    }
}

class Slim_Http_Request
{
    protected $env;

    public function __construct($env)
    {
        $this->env = $env;
    }

    /** Slim 1's Slim_Environment: the request as Slim 1 saw it, built from $_SERVER. */
    public static function environmentFromGlobals()
    {
        if (!isset($_SERVER['REQUEST_METHOD'])) {
            // A command-line run (a script that only loads the app): an empty GET for "/".
            return array('REQUEST_METHOD' => 'GET', 'SCRIPT_NAME' => '', 'PATH_INFO' => '/', 'QUERY_STRING' => '', 'SERVER_NAME' => 'localhost', 'SERVER_PORT' => 80, 'slim.url_scheme' => 'http', 'slim.input' => '');
        }
        $env = array();
        $env['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'];
        $env['REMOTE_ADDR'] = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        $requestUri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
        $scriptName = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
        if ($scriptName !== '' && strpos($requestUri, $scriptName) === 0) {
            $env['SCRIPT_NAME'] = $scriptName;
        } else {
            $env['SCRIPT_NAME'] = str_replace('\\', '/', dirname($scriptName));
        }
        $env['PATH_INFO'] = substr_replace($requestUri, '', 0, strlen($env['SCRIPT_NAME']));
        if (strpos($env['PATH_INFO'], '?') !== false) {
            $env['PATH_INFO'] = substr_replace($env['PATH_INFO'], '', strpos($env['PATH_INFO'], '?'));
        }
        $env['SCRIPT_NAME'] = rtrim($env['SCRIPT_NAME'], '/');
        $env['PATH_INFO'] = '/' . ltrim($env['PATH_INFO'], '/');
        $env['QUERY_STRING'] = isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';
        $env['SERVER_NAME'] = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'localhost';
        $env['SERVER_PORT'] = isset($_SERVER['SERVER_PORT']) ? $_SERVER['SERVER_PORT'] : 80;
        $special = array('CONTENT_TYPE', 'CONTENT_LENGTH', 'PHP_AUTH_USER', 'PHP_AUTH_PW', 'PHP_AUTH_DIGEST', 'AUTH_TYPE');
        foreach ($_SERVER as $key => $value) {
            $value = is_string($value) ? trim($value) : $value;
            if (strpos($key, 'HTTP_') === 0) {
                $env[substr($key, 5)] = $value;
            } elseif (strpos($key, 'X_') === 0 || in_array($key, $special)) {
                $env[$key] = $value;
            }
        }
        $env['slim.url_scheme'] = empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off' ? 'http' : 'https';
        $input = @file_get_contents('php://input');
        $env['slim.input'] = $input ? $input : '';

        return $env;
    }

    /** Not Slim 1: the bridge hands the request the environment after the method override. */
    public function bridgeEnvironment(array $env)
    {
        $this->env = $env;
    }

    public function getMethod()
    {
        return $this->env['REQUEST_METHOD'];
    }

    public function isGet()
    {
        return $this->getMethod() === 'GET';
    }

    public function isPost()
    {
        return $this->getMethod() === 'POST';
    }

    public function isPut()
    {
        return $this->getMethod() === 'PUT';
    }

    public function isDelete()
    {
        return $this->getMethod() === 'DELETE';
    }

    public function isHead()
    {
        return $this->getMethod() === 'HEAD';
    }

    public function isOptions()
    {
        return $this->getMethod() === 'OPTIONS';
    }

    public function isAjax()
    {
        if ($this->params('isajax')) {
            return true;
        }

        return isset($this->env['X_REQUESTED_WITH']) && $this->env['X_REQUESTED_WITH'] === 'XMLHttpRequest';
    }

    public function isXhr()
    {
        return $this->isAjax();
    }

    public function params($key = null)
    {
        $union = array_merge($this->get(), $this->post());
        if ($key) {
            return isset($union[$key]) ? $union[$key] : null;
        }

        return $union;
    }

    public function get($key = null)
    {
        if (!isset($this->env['slim.request.query_hash'])) {
            $output = array();
            if (function_exists('mb_parse_str')) {
                mb_parse_str($this->env['QUERY_STRING'], $output);
            } else {
                parse_str($this->env['QUERY_STRING'], $output);
            }
            $this->env['slim.request.query_hash'] = $output;
        }
        if ($key) {
            return isset($this->env['slim.request.query_hash'][$key]) ? $this->env['slim.request.query_hash'][$key] : null;
        }

        return $this->env['slim.request.query_hash'];
    }

    public function post($key = null)
    {
        if (!isset($this->env['slim.request.form_hash'])) {
            $this->env['slim.request.form_hash'] = array();
            if ($this->isFormData()) {
                $output = array();
                if (function_exists('mb_parse_str')) {
                    mb_parse_str($this->env['slim.input'], $output);
                } else {
                    parse_str($this->env['slim.input'], $output);
                }
                $this->env['slim.request.form_hash'] = $output;
            } else {
                $this->env['slim.request.form_hash'] = $_POST;
            }
        }
        if ($key) {
            return isset($this->env['slim.request.form_hash'][$key]) ? $this->env['slim.request.form_hash'][$key] : null;
        }

        return $this->env['slim.request.form_hash'];
    }

    public function put($key = null)
    {
        return $this->post($key);
    }

    public function delete($key = null)
    {
        return $this->post($key);
    }

    public function cookies($key = null)
    {
        $cookies = array();
        if (isset($this->env['COOKIE']) && $this->env['COOKIE'] !== '') {
            foreach (preg_split('@\s*[;,]\s*@', $this->env['COOKIE']) as $pair) {
                $parts = explode('=', $pair, 2);
                if (count($parts) === 2 && !isset($cookies[urldecode($parts[0])])) {
                    $cookies[urldecode($parts[0])] = urldecode($parts[1]);
                }
            }
        }
        if ($key) {
            return isset($cookies[$key]) ? $cookies[$key] : null;
        }

        return $cookies;
    }

    public function isFormData()
    {
        $method = isset($this->env['slim.method_override.original_method']) ? $this->env['slim.method_override.original_method'] : $this->getMethod();

        return ($method === 'POST' && is_null($this->getContentType())) || in_array($this->getMediaType(), array('application/x-www-form-urlencoded', 'multipart/form-data'));
    }

    public function headers($key = null, $default = null)
    {
        if ($key) {
            $key = preg_replace('@^HTTP_@', '', str_replace('-', '_', strtoupper($key)));

            return isset($this->env[$key]) ? $this->env[$key] : $default;
        }
        $headers = array();
        foreach ($this->env as $name => $value) {
            if (strpos($name, 'slim.') !== 0) {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    public function getBody()
    {
        return $this->env['slim.input'];
    }

    public function getContentType()
    {
        return isset($this->env['CONTENT_TYPE']) ? $this->env['CONTENT_TYPE'] : null;
    }

    public function getMediaType()
    {
        $contentType = $this->getContentType();
        if (!$contentType) {
            return null;
        }
        $parts = preg_split('/\s*[;,]\s*/', $contentType);

        return strtolower($parts[0]);
    }

    public function getMediaTypeParams()
    {
        $params = array();
        $contentType = $this->getContentType();
        if ($contentType) {
            $parts = preg_split('/\s*[;,]\s*/', $contentType);
            for ($i = 1; $i < count($parts); $i++) {
                $pair = explode('=', $parts[$i]);
                $params[strtolower($pair[0])] = isset($pair[1]) ? $pair[1] : '';
            }
        }

        return $params;
    }

    public function getContentCharset()
    {
        $params = $this->getMediaTypeParams();

        return isset($params['charset']) ? $params['charset'] : null;
    }

    public function getContentLength()
    {
        return isset($this->env['CONTENT_LENGTH']) ? (int) $this->env['CONTENT_LENGTH'] : 0;
    }

    public function getHost()
    {
        if (isset($this->env['HOST'])) {
            $parts = explode(':', $this->env['HOST']);

            return $parts[0];
        }

        return $this->env['SERVER_NAME'];
    }

    public function getHostWithPort()
    {
        return sprintf('%s:%s', $this->getHost(), $this->getPort());
    }

    public function getPort()
    {
        return (int) $this->env['SERVER_PORT'];
    }

    public function getScheme()
    {
        return $this->env['slim.url_scheme'];
    }

    public function getScriptName()
    {
        return $this->env['SCRIPT_NAME'];
    }

    public function getRootUri()
    {
        return $this->getScriptName();
    }

    public function getPathInfo()
    {
        return $this->env['PATH_INFO'];
    }

    public function getResourceUri()
    {
        return $this->getPathInfo();
    }

    public function getPath()
    {
        return $this->getScriptName() . $this->getPathInfo();
    }

    public function getUrl()
    {
        $url = $this->getScheme() . '://' . $this->getHost();
        if (($this->getScheme() === 'https' && $this->getPort() !== 443) || ($this->getScheme() === 'http' && $this->getPort() !== 80)) {
            $url .= sprintf(':%s', $this->getPort());
        }

        return $url;
    }

    public function getIp()
    {
        if (isset($this->env['X_FORWARDED_FOR'])) {
            return $this->env['X_FORWARDED_FOR'];
        }
        if (isset($this->env['CLIENT_IP'])) {
            return $this->env['CLIENT_IP'];
        }

        return $this->env['REMOTE_ADDR'];
    }

    public function getReferrer()
    {
        return isset($this->env['REFERER']) ? $this->env['REFERER'] : null;
    }

    public function getReferer()
    {
        return $this->getReferrer();
    }

    public function getUserAgent()
    {
        return isset($this->env['USER_AGENT']) ? $this->env['USER_AGENT'] : null;
    }
}

class Slim_Http_Response implements ArrayAccess, IteratorAggregate, Countable
{
    protected $status = 200;
    protected $header = array();
    protected $body = '';
    protected $length = 0;

    public function __construct($body = '', $status = 200, $header = array())
    {
        $this->status = (int) $status;
        $this->header = array();
        foreach (array_merge(array('Content-Type' => 'text/html'), $header) as $name => $value) {
            $this->header[self::normalize($name)] = $value;
        }
        $this->write($body);
    }

    /** Slim_Http_Headers' spelling of a header name: Content-Type, X-Requested-With. */
    private static function normalize($name)
    {
        return str_replace(' ', '-', ucwords(strtolower(str_replace(array('-', '_'), ' ', (string) $name))));
    }

    public function status($status = null)
    {
        if (!is_null($status)) {
            $this->status = (int) $status;
        }

        return $this->status;
    }

    public function header($name, $value = null)
    {
        if (!is_null($value)) {
            $this[$name] = $value;
        }

        return $this[$name];
    }

    public function headers()
    {
        return $this->header;
    }

    public function body($body = null)
    {
        if (!is_null($body)) {
            $this->write($body, true);
        }

        return $this->body;
    }

    public function length($length = null)
    {
        if (!is_null($length)) {
            $this->length = (int) $length;
        }

        return $this->length;
    }

    public function write($body, $replace = false)
    {
        if ($replace) {
            $this->body = (string) $body;
        } else {
            $this->body .= (string) $body;
        }
        $this->length = strlen($this->body);

        return $this->body;
    }

    public function redirect($url, $status = 302)
    {
        $this->status = (int) $status;
        $this['Location'] = $url;
    }

    public function finalize()
    {
        if (in_array($this->status, array(204, 304))) {
            unset($this['Content-Type'], $this['Content-Length']);

            return array($this->status, $this->header, '');
        }

        return array($this->status, $this->header, $this->body);
    }

    public function isRedirect()
    {
        return in_array($this->status, array(301, 302, 303, 307));
    }

    #[ReturnTypeWillChange]
    public function offsetExists($offset)
    {
        return isset($this->header[self::normalize($offset)]);
    }

    #[ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        $name = self::normalize($offset);

        return isset($this->header[$name]) ? $this->header[$name] : null;
    }

    #[ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
        $this->header[self::normalize($offset)] = $value;
    }

    #[ReturnTypeWillChange]
    public function offsetUnset($offset)
    {
        unset($this->header[self::normalize($offset)]);
    }

    #[ReturnTypeWillChange]
    public function getIterator()
    {
        return new ArrayIterator($this->header);
    }

    #[ReturnTypeWillChange]
    public function count()
    {
        return count($this->header);
    }
}
