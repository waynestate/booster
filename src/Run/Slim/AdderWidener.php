<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run\Slim;

use PhpToken;

/**
 * Widens `\Slim\App $p` parameters of route adders to Slim 4's
 * RouteCollectorProxyInterface across the whole app, so an adder a route
 * group calls (the group hands it a RouteCollectorProxy, not the app) keeps
 * working. RouteRewriter only widens adders that open a group in a file where
 * it rewrote one; this pass covers the rest, for example a controller's
 * `routes(\Slim\App $app)` that only calls `$app->get(...)` and is called
 * from a group in another file.
 *
 * It widens only when that is safe on its face: every use of the parameter
 * in the body is a call of a RouteCollectorProxyInterface method (at least
 * one of them a route method), or a closure's `use` capture whose own uses
 * pass the same test. \Slim\App implements that interface, so callers that
 * pass the app keep working. A function or method name that also has a
 * \Slim\App parameter it cannot widen (or a bodyless declaration, as in an
 * interface) is left alone everywhere, so a child class never ends up
 * narrower than its parent. What it leaves stays a MANUAL line.
 *
 * Slim 3 said "you're with the band". Slim 4 checks the wristband. This
 * class hands out wristbands, but only to people already on the list.
 */
final class AdderWidener
{
    private const PROXY = '\\Slim\\Interfaces\\RouteCollectorProxyInterface';

    /** RouteCollectorProxyInterface's methods (slim/slim 4), lower case. */
    private const PROXY_METHODS = [
        'getresponsefactory', 'getcallableresolver', 'getcontainer', 'getroutecollector', 'getbasepath', 'setbasepath',
        'get', 'post', 'put', 'patch', 'delete', 'options', 'any', 'map', 'group', 'redirect',
    ];

    /** The ones that register routes, so the function is a route adder. */
    private const ROUTE_METHODS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'any', 'map', 'group', 'redirect'];

    /** @var array<int, PhpToken> */
    private array $tokens = [];

    /**
     * Returns the changed files only, and per file the widened parameters.
     *
     * @param array<string, string> $files relative path to PHP source
     *
     * @return array{files: array<string, string>, widened: array<string, list<array{line: int, variable: string}>>}
     */
    public function widen(array $files): array
    {
        $found = [];
        $blocked = [];
        foreach ($files as $file => $php) {
            foreach ($this->parameters($php) as $parameter) {
                if ($parameter['widen']) {
                    $found[$file][] = $parameter;
                } elseif ($parameter['function'] !== null) {
                    $blocked[$parameter['function']] = true;
                }
            }
        }

        $changed = [];
        $widened = [];
        foreach ($found as $file => $parameters) {
            $edits = array_values(array_filter(
                $parameters,
                static fn(array $parameter): bool => $parameter['function'] === null || !isset($blocked[$parameter['function']]),
            ));
            if ($edits === []) {
                continue;
            }
            usort($edits, static fn(array $a, array $b): int => $b['offset'] <=> $a['offset']);
            $php = $files[$file];
            foreach ($edits as $edit) {
                $php = substr_replace($php, self::PROXY, $edit['offset'], $edit['length']);
            }
            $changed[$file] = $php;
            $widened[$file] = array_reverse(array_map(
                static fn(array $edit): array => ['line' => $edit['line'], 'variable' => $edit['variable']],
                $edits,
            ));
        }

        return ['files' => $changed, 'widened' => $widened];
    }

    /**
     * Every `\Slim\App`-typed parameter in $php.
     *
     * @return list<array{function: ?string, variable: string, offset: int, length: int, line: int, widen: bool}>
     */
    private function parameters(string $php): array
    {
        $this->tokens = PhpToken::tokenize($php);
        $aliases = $this->aliases();
        $found = [];
        foreach ($this->tokens as $i => $token) {
            if (!$token->is(T_FUNCTION)) {
                continue;
            }
            $open = $this->next($i);
            if ($open !== null && $this->tokens[$open]->text === '&') {
                $open = $this->next($open);
            }
            $function = null;
            if ($open !== null && $this->tokens[$open]->is(T_STRING)) {
                $function = strtolower($this->tokens[$open]->text);
                $open = $this->next($open);
            }
            if ($open === null || $this->tokens[$open]->text !== '(') {
                continue;
            }
            $close = $this->matching($open);
            $body = $this->body($close);
            for ($j = $open + 1; $j < $close; $j++) {
                if (!$this->namesSlimApp($this->tokens[$j], $aliases)) {
                    continue;
                }
                // A plain `(\Slim\App $p` or `, \Slim\App $p`; nullable, union,
                // variadic and by-reference forms are never widened.
                $before = $this->previous($j);
                $variable = $this->next($j);
                $plain = $before !== null && $variable !== null
                    && in_array($this->tokens[$before]->text, ['(', ','], true)
                    && $this->tokens[$variable]->is(T_VARIABLE);
                $name = $plain ? $this->tokens[$variable]->text : '';
                $found[] = [
                    'function' => $function,
                    'variable' => $name,
                    'offset' => $this->tokens[$j]->pos,
                    'length' => strlen($this->tokens[$j]->text),
                    'line' => $this->tokens[$j]->line,
                    'widen' => $plain && $body !== null && $this->onlyProxyCalls($body[0], $body[1], $name),
                ];
            }
        }

        return $found;
    }

    /**
     * Whether every use of $variable between $open and $close is a
     * RouteCollectorProxyInterface call, a closure capture or a nested
     * parameter of the same name, with at least one route method call.
     */
    private function onlyProxyCalls(int $open, int $close, string $variable): bool
    {
        $routes = false;
        for ($j = $open + 1; $j < $close; $j++) {
            $token = $this->tokens[$j];
            if (!$token->is(T_VARIABLE) || $token->text !== $variable) {
                continue;
            }
            $arrow = $this->next($j);
            if ($arrow !== null && $this->tokens[$arrow]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                $method = $this->next($arrow);
                $paren = $method === null ? null : $this->next($method);
                if ($method === null || $paren === null || !$this->tokens[$method]->is(T_STRING) || $this->tokens[$paren]->text !== '(') {
                    return false;
                }
                $name = strtolower($this->tokens[$method]->text);
                if (!in_array($name, self::PROXY_METHODS, true)) {
                    return false;
                }
                $routes = $routes || in_array($name, self::ROUTE_METHODS, true);

                continue;
            }
            if (!$this->declaredHere($j)) {
                return false;
            }
        }

        return $routes;
    }

    /**
     * Whether the variable at $i sits in a closure's `use (...)` list or in a
     * function's parameter list (a new binding; its uses are still checked).
     */
    private function declaredHere(int $i): bool
    {
        $after = $this->next($i);
        if ($after === null || !in_array($this->tokens[$after]->text, [',', ')', '='], true)) {
            return false;
        }
        $depth = 0;
        for ($j = $i - 1; $j >= 0; $j--) {
            if ($this->closes($j)) {
                $depth++;
            } elseif ($this->opens($j)) {
                if ($depth > 0) {
                    $depth--;

                    continue;
                }
                if ($this->tokens[$j]->text !== '(') {
                    return false;
                }
                $before = $this->previous($j);
                if ($before === null) {
                    return false;
                }
                if ($this->tokens[$before]->is([T_USE, T_FUNCTION, T_FN])) {
                    return true;
                }
                if ($this->tokens[$before]->is(T_STRING)) {
                    $keyword = $this->previous($before);
                    if ($keyword !== null && $this->tokens[$keyword]->text === '&') {
                        $keyword = $this->previous($keyword);
                    }

                    return $keyword !== null && $this->tokens[$keyword]->is(T_FUNCTION);
                }

                return false;
            }
        }

        return false;
    }

    /**
     * The body braces of the function whose parameter list closes at $close,
     * or null when it has none (an interface or abstract method).
     *
     * @return array{int, int}|null
     */
    private function body(int $close): ?array
    {
        $j = $this->next($close);
        if ($j !== null && $this->tokens[$j]->is(T_USE)) {
            $useOpen = $this->next($j);
            $j = $useOpen === null ? null : $this->next($this->matching($useOpen));
        }
        while ($j !== null && !in_array($this->tokens[$j]->text, ['{', ';'], true)) {
            $j = $this->next($j);
        }
        if ($j === null || $this->tokens[$j]->text !== '{') {
            return null;
        }

        return [$j, $this->matching($j)];
    }

    /**
     * The short names a `use Slim\App;` or `use Slim\App as X;` import gives.
     *
     * @return list<string>
     */
    private function aliases(): array
    {
        $aliases = [];
        foreach ($this->tokens as $i => $token) {
            if (!$token->is(T_USE)) {
                continue;
            }
            $name = $this->next($i);
            if ($name === null || !$this->tokens[$name]->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                continue;
            }
            if (strtolower(ltrim($this->tokens[$name]->text, '\\')) !== 'slim\\app') {
                continue;
            }
            $next = $this->next($name);
            if ($next !== null && $this->tokens[$next]->text === ';') {
                $aliases[] = 'app';
            } elseif ($next !== null && $this->tokens[$next]->is(T_AS)) {
                $alias = $this->next($next);
                if ($alias !== null && $this->tokens[$alias]->is(T_STRING)) {
                    $aliases[] = strtolower($this->tokens[$alias]->text);
                }
            }
        }

        return $aliases;
    }

    /**
     * @param list<string> $aliases
     */
    private function namesSlimApp(PhpToken $token, array $aliases): bool
    {
        if ($token->is([T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED])) {
            return strtolower(ltrim($token->text, '\\')) === 'slim\\app';
        }

        return $token->is(T_STRING) && in_array(strtolower($token->text), $aliases, true);
    }

    private function opens(int $i): bool
    {
        return in_array($this->tokens[$i]->text, ['(', '[', '{'], true)
            || $this->tokens[$i]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE]);
    }

    private function closes(int $i): bool
    {
        return in_array($this->tokens[$i]->text, [')', ']', '}'], true);
    }

    /** Index of the bracket closing the one at $open (or the last token). */
    private function matching(int $open): int
    {
        $depth = 0;
        $count = count($this->tokens);
        for ($j = $open; $j < $count; $j++) {
            if ($this->opens($j)) {
                $depth++;
            } elseif ($this->closes($j)) {
                $depth--;
                if ($depth === 0) {
                    return $j;
                }
            }
        }

        return $count - 1;
    }

    private function next(int $i): ?int
    {
        $count = count($this->tokens);
        for ($j = $i + 1; $j < $count; $j++) {
            if (!$this->tokens[$j]->isIgnorable()) {
                return $j;
            }
        }

        return null;
    }

    private function previous(int $i): ?int
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (!$this->tokens[$j]->isIgnorable()) {
                return $j;
            }
        }

        return null;
    }
}
