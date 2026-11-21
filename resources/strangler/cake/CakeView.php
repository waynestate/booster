<?php

namespace App\Http\Controllers\Legacy\Cake;

use ArrayObject;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Cookie;
use Throwable;

/**
 * Written by Booster's strangler (cakephp-to-laravel): renders a moved
 * CakePHP template the way CakePHP's View does (the template first, then
 * the layout with the `content` and `title` blocks), so the HTML is the
 * same; renders the legacy app's error page for an error a moved action
 * ends in; and keeps the legacy app's CSRF cookie for a form that posts to
 * an action that is still legacy. Removed by the finish step once every
 * view is idiomatic Blade.
 */
final class CakeView
{
    /** @var array{cookie: string, field: string, httponly: bool, guest: bool}|null the legacy app's CSRF cookie and field (`guest`: its check ran before sign-in, so a failure rendered as a guest's error), null when it checks none */
    public const LEGACY_CSRF = /*BOOSTER:LEGACY_CSRF*/ null;

    /** @var array{guest: string, user: string} the error template a guest and a signed-in user saw (`code` picks error400 or error500 by status) */
    public const ERROR_TEMPLATES = /*BOOSTER:ERROR_TEMPLATES*/ ['guest' => 'code', 'user' => 'code'];

    /** @var array<string, mixed> the view variables of the template being rendered */
    private static array $viewVars = [];

    private static ?string $mintedCsrf = null;

    /** @var array<string, mixed>|null the last paginate() call's paging data, for the paginator helper */
    public static ?array $paging = null;

    /**
     * @param array<string, mixed> $viewData
     */
    public static function render(string $template, ?string $layout, array $viewData, string $title, int $status = 200): Response
    {
        $request = request();
        self::$viewVars = $viewData;
        $blocks = new ArrayObject(['content' => '', 'title' => '', 'meta' => '', 'css' => '', 'script' => '']);
        $helpers = ['__blocks' => $blocks, '__form' => new CakeForm($request)];
        $content = view($template, $helpers + $viewData)->render();
        if ($layout !== null) {
            $blocks['content'] = $content;
            if ((string) $blocks['title'] === '') {
                $blocks['title'] = $title;
            }
            $content = view($layout, $helpers + $viewData)->render();
        }

        // CakePHP's header, byte for byte.
        $response = response($content, $status)->header('Content-Type', 'text/html; charset=UTF-8');
        if (self::$mintedCsrf !== null && self::LEGACY_CSRF !== null) {
            $response->headers->setCookie(Cookie::create(self::LEGACY_CSRF['cookie'], self::$mintedCsrf, 0, '/', null, null, self::LEGACY_CSRF['httponly'], true, null));
            self::$mintedCsrf = null;
        }

        return $response;
    }

    /**
     * An action that ends without a response and has no template: CakePHP
     * threw MissingTemplateException, which debug off prints as the 500 page.
     */
    public static function missingTemplate(string $template): never
    {
        throw new CakeHttpException(500, 'Template file `' . $template . '` could not be found.', false);
    }

    /** A view variable of the template being rendered (FormHelper reads `$tags` for `tags._ids`). */
    public static function viewVar(string $name): mixed
    {
        return self::$viewVars[$name] ?? null;
    }

    /**
     * CakePHP's redirect: an empty body and the full URL in Location
     * (scheme, host, base path, then the path its router built).
     */
    public static function redirect(Request $request, string $path, int $status = 302): Response
    {
        $url = preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) === 1 ? $path : $request->getSchemeAndHttpHost() . $request->getBasePath() . $path;

        return response('', $status)->header('Location', $url)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * The legacy app's error page, as CakePHP's ExceptionRenderer printed
     * it with debug off: the Error template inside the error layout.
     */
    public static function error(Request $request, int $status, string $message, bool $asGuest = false): Response
    {
        $which = $asGuest || $request->user() === null ? self::ERROR_TEMPLATES['guest'] : self::ERROR_TEMPLATES['user'];
        if ($which === 'code') {
            $which = $status >= 500 ? 'error500' : 'error400';
        }
        $viewData = ['message' => $message, 'code' => $status, 'url' => e($request->getRequestUri())];

        return self::render('legacy.Error.' . $which, 'legacy.layout.error', $viewData, 'Error', $status);
    }

    /**
     * Controller::paginate(): the page of results, with CakePHP's defaults
     * (20 a page, `page`, `sort` and `direction` from the query string, a
     * page past the end is a 404).
     *
     * @param array<string, mixed> $settings
     */
    public static function paginate(Request $request, CakeQuery|CakeTable $query, array $settings = []): CakeResultSet
    {
        if ($query instanceof CakeTable) {
            $query = $query->find();
        }
        foreach (array_keys($settings) as $key) {
            if (!in_array($key, ['limit', 'maxLimit', 'order'], true)) {
                throw new \LogicException('Booster: paginate() setting ' . $key . ' is not covered; port this action by hand.');
            }
        }
        $table = $query->getRepository();
        $limit = (int) ($settings['limit'] ?? 20);
        $maxLimit = (int) ($settings['maxLimit'] ?? 100);
        $requested = $request->query('limit');
        if (is_numeric($requested) && (int) $requested > 0) {
            $limit = min((int) $requested, $maxLimit);
        }
        $page = $request->query('page', 1);
        $page = is_numeric($page) ? max(1, (int) $page) : 1;
        $sort = $request->query('sort');
        $direction = strtolower((string) $request->query('direction', 'asc')) === 'desc' ? 'desc' : 'asc';
        if (is_string($sort) && $sort !== '') {
            $field = str_contains($sort, '.') ? substr($sort, strpos($sort, '.') + 1) : $sort;
            if (isset($table->schema()[$field])) {
                $query->orderBy([$field => $direction], true);
            } else {
                $sort = null;
            }
        } elseif (isset($settings['order'])) {
            $query->orderBy((array) $settings['order']);
        }
        $count = $query->count();
        $pageCount = max(1, (int) ceil($count / $limit));
        if ($page > $pageCount && $page > 1) {
            throw new CakeHttpException(404, 'Not Found');
        }
        $results = $query->limit($limit)->offset(($page - 1) * $limit)->all();
        self::$paging = [
            'alias' => $table->getAlias(),
            'count' => $count,
            'current' => count($results->toList()),
            'perPage' => $limit,
            'page' => $page,
            'pageCount' => $pageCount,
            'sort' => is_string($sort) && $sort !== '' ? $sort : null,
            'direction' => is_string($sort) && $sort !== '' ? $direction : null,
            'start' => $count === 0 ? 0 : ($page - 1) * $limit + 1,
            'end' => min($count, $page * $limit),
            'hasPrevPage' => $page > 1,
            'hasNextPage' => $page < $pageCount,
        ];

        return $results;
    }

    /**
     * The legacy app's CSRF token for a form that posts to an action that
     * is still legacy: the browser's legacy CSRF cookie, salted the way
     * CakePHP's FormHelper salts it, or a new one minted with the legacy
     * app's salt (and its cookie set) when the browser has none yet.
     */
    public static function legacyCsrfToken(string $cookie): string
    {
        // EncryptCookies hides cookies it did not write; this one is legacy's.
        $token = isset($_COOKIE[$cookie]) && is_string($_COOKIE[$cookie]) ? $_COOKIE[$cookie] : (self::$mintedCsrf ?? null);
        if ($token === null) {
            $salt = (string) env('SECURITY_SALT', '');
            if ($salt === '') {
                throw new \LogicException('Booster: SECURITY_SALT is not set, so the Laravel half cannot mint the legacy CSRF cookie.');
            }
            $value = random_bytes(16);
            $token = base64_encode($value . hash_hmac('sha1', $value, $salt));
            self::$mintedCsrf = $token;
        }
        if (preg_match('/^[a-f0-9]+$/', $token) === 1 && strlen($token) % 2 === 0) {
            return $token;
        }
        $decoded = (string) base64_decode($token, true);
        $salt = random_bytes(strlen($decoded));
        $salted = '';
        for ($i = 0, $n = strlen($decoded); $i < $n; $i++) {
            $salted .= chr(ord($decoded[$i]) ^ ord($salt[$i]));
        }

        return base64_encode($salted . $salt);
    }

    /**
     * Whether a path and method go to a moved action: the strangler's
     * route table (bootstrap/booster-moved.php), as the dispatcher reads it.
     */
    public static function isMovedPath(string $path, string $method = 'POST'): bool
    {
        static $rows = null;
        if ($rows === null) {
            $file = base_path('bootstrap/booster-moved.php');
            $rows = is_file($file) ? require $file : [];
            $rows = is_array($rows) ? $rows : [];
        }
        foreach ($rows as $row) {
            if (in_array($method, (array) ($row['methods'] ?? []), true) && preg_match((string) $row['regex'], $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * A bad CSRF token on a moved action, as CakePHP's
     * CsrfProtectionMiddleware answered it: a 403 with the legacy error
     * page and its message (routes/web.php maps Laravel's
     * TokenMismatchException here). Other routes keep Laravel's 419.
     */
    public static function csrfFailure(Throwable $e): Throwable
    {
        $request = request();
        if (!self::moved($request)) {
            return $e;
        }
        $cookie = self::LEGACY_CSRF['cookie'] ?? '';
        $hasCookie = ($cookie !== '' && isset($_COOKIE[$cookie])) || $request->cookies->has((string) config('session.cookie'));
        $message = $hasCookie
            ? 'CSRF token from either the request body or request headers did not match or is missing.'
            : 'Missing or incorrect CSRF cookie type.';

        return new CakeHttpException(403, $message, true, $e, self::LEGACY_CSRF['guest'] ?? false);
    }

    /**
     * Registers an exception map on Laravel's handler (routes/web.php calls
     * this for the CSRF and error bridges). Under artisan the bound handler
     * can be Collision's adapter, which has no map(); the console renders
     * no legacy pages, so it is skipped there.
     */
    public static function mapException(\Closure $mapper): void
    {
        $handler = app(\Illuminate\Contracts\Debug\ExceptionHandler::class);
        if ($handler instanceof \Illuminate\Foundation\Exceptions\Handler) {
            $handler->map($mapper);
        }
    }

    /**
     * An error a moved action ends in that is not an HTTP answer (a database
     * error): CakePHP with debug off printed the error500 page with "An
     * Internal Error Has Occurred.", so the moved action does too
     * (routes/web.php maps it here). The cause stays the previous exception,
     * so the log keeps it. Laravel's own exceptions and other routes are
     * left alone.
     */
    public static function serverError(Throwable $e): Throwable
    {
        if ($e instanceof CakeHttpException
            || $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
            || $e instanceof \Illuminate\Session\TokenMismatchException
            || $e instanceof \Illuminate\Http\Exceptions\HttpResponseException
            || $e instanceof \Illuminate\Auth\AuthenticationException
            || $e instanceof \Illuminate\Validation\ValidationException
            || !self::moved(request())) {
            return $e;
        }

        return new CakeHttpException(500, $e->getMessage(), false, $e);
    }

    /**
     * Whether a request goes to a moved CakePHP action. Laravel's
     * TrimStrings and ConvertEmptyStringsToNull skip those, because the
     * CakePHP code read its input untouched (routes/web.php registers this).
     */
    public static function moved(Request $request): bool
    {
        try {
            $route = app('router')->getRoutes()->match($request);
        } catch (Throwable) {
            return false;
        }

        return str_starts_with($route->getActionName(), __NAMESPACE__ . '\\');
    }
}
