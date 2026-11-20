<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run\Slim;

use PhpToken;

/**
 * Rewrites Slim 3 route and middleware code in one PHP file for Slim 4:
 * route group callables take the group's RouteCollectorProxyInterface (so the
 * routes inside keep their prefix), route adders that open groups accept it,
 * middleware goes through the generated LegacyDoublePass adapter, and
 * `$request->getAttribute('route')` reads through RouteContext. What has no
 * mechanical Slim 4 form becomes a MANUAL line with its line number. It works
 * on tokens, so strings and comments are never touched, and a second run
 * changes nothing.
 *
 * In Slim 3 a route inside a group just knew where it lived. In Slim 4 it has
 * to be told, like a cat that moved house. This class writes the address on
 * the collar.
 */
final class RouteRewriter
{
    private const PROXY = '\\Slim\\Interfaces\\RouteCollectorProxyInterface';

    /** The receivers that are the Slim app. */
    private const APP_EXPRESSIONS = ['$app', '$this->app', '$this->slim'];

    /** Methods whose result is a route, a group or the app, so `->add(` on it is middleware. */
    private const CHAIN_METHODS = [
        'get', 'post', 'put', 'patch', 'delete', 'options', 'any', 'map', 'group', 'redirect',
        'add', 'addmiddleware', 'setname', 'setargument', 'setarguments',
    ];

    /** Methods that register routes on the app or a group. */
    private const ROUTE_METHODS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'any', 'map', 'group', 'redirect'];

    /** @var array<int, PhpToken> */
    private array $tokens = [];

    /** @var list<array{int, int, string}> byte offset, length, replacement */
    private array $edits = [];

    /** @var array<int, true> token indexes already covered by an edit */
    private array $edited = [];

    /** @var list<string> */
    private array $manual = [];

    /**
     * @return array{code: string, changes: list<string>, manual: list<string>}
     */
    public function rewrite(string $php, string $namespace): array
    {
        $adapter = '\\' . trim($namespace, '\\') . '\\LegacyDoublePass';
        $this->tokens = PhpToken::tokenize($php);
        $this->edits = [];
        $this->edited = [];
        $this->manual = [];
        $aliases = $this->slimAppAliases();

        $counts = [
            'groups' => $this->groups($aliases),
            'widened' => 0,
            'middleware' => 0,
            'route' => 0,
        ];
        $counts['widened'] = $this->widen($aliases, $counts['groups'] > 0);
        $counts['middleware'] = $this->middleware($adapter);
        $counts['route'] = $this->routeAttribute();
        $this->routerUrls();
        $this->removedApis();

        $changes = [];
        if ($counts['groups'] > 0) {
            $changes[] = sprintf('Rewrote %d route group callable(s) to take the group\'s RouteCollectorProxyInterface.', $counts['groups']);
        }
        if ($counts['widened'] > 0) {
            $changes[] = sprintf('Widened %d route adder parameter(s) from \\Slim\\App to RouteCollectorProxyInterface.', $counts['widened']);
        }
        if ($counts['middleware'] > 0) {
            $changes[] = sprintf('Wrapped %d middleware registration(s) in LegacyDoublePass.', $counts['middleware']);
        }
        if ($counts['route'] > 0) {
            $changes[] = sprintf('Replaced %d getAttribute(\'route\') read(s) with RouteContext::fromRequest()->getRoute().', $counts['route']);
        }

        return [
            'code' => $this->apply($php),
            'changes' => $changes,
            'manual' => array_values(array_unique($this->manual)),
        ];
    }

    /**
     * Rule 1: `->group(PATTERN, function () use ($app) {` becomes
     * `->group(PATTERN, function (\Slim\Interfaces\RouteCollectorProxyInterface $app) {`.
     *
     * @param list<string> $aliases
     */
    private function groups(array $aliases): int
    {
        $count = 0;
        foreach ($this->methodCalls(['group']) as [$arrow, $open]) {
            $receiver = $this->chainBase($arrow);
            if ($receiver === null || !in_array($receiver, self::APP_EXPRESSIONS, true)) {
                continue;
            }
            $callable = $this->secondArgument($open);
            if ($callable === null) {
                continue;
            }
            $count += $this->groupCallable($callable, $receiver, $aliases);
        }

        return $count;
    }

    /**
     * @param list<string> $aliases
     */
    private function groupCallable(int $start, string $receiver, array $aliases): int
    {
        $static = null;
        $function = $start;
        if ($this->tokens[$start]->is(T_STATIC)) {
            $static = $start;
            $function = $this->next($start);
        }
        if ($function === null || !$this->tokens[$function]->is([T_FUNCTION, T_FN])) {
            return 0;
        }
        $paramsOpen = $this->next($function);
        if ($paramsOpen !== null && $this->tokens[$paramsOpen]->text === '&') {
            $paramsOpen = $this->next($paramsOpen);
        }
        if ($paramsOpen === null || $this->tokens[$paramsOpen]->text !== '(') {
            return 0;
        }
        $paramsClose = $this->matching($paramsOpen);
        $line = $this->tokens[$function]->line;
        $arrowFn = $this->tokens[$function]->is(T_FN);

        $uses = [];
        $useOpen = null;
        $useClose = null;
        $after = $this->next($paramsClose);
        if (!$arrowFn && $after !== null && $this->tokens[$after]->is(T_USE)) {
            $useOpen = $this->next($after);
            if ($useOpen === null || $this->tokens[$useOpen]->text !== '(') {
                return 0;
            }
            $useClose = $this->matching($useOpen);
            for ($j = $useOpen + 1; $j < $useClose; $j++) {
                if ($this->tokens[$j]->is(T_VARIABLE)) {
                    $uses[] = $j;
                }
            }
        }
        $body = $this->body($useClose ?? $paramsClose, $arrowFn);
        if ($body === null) {
            return 0;
        }
        [$bodyStart, $bodyEnd] = $body;

        // A group callable that already takes a parameter gets the proxy on Slim 4; only a \Slim\App type breaks.
        if ($this->next($paramsOpen) !== $paramsClose) {
            return $this->widenParameters($paramsOpen, $paramsClose, $aliases, null) > 0 ? 1 : 0;
        }

        if ($this->bodyUses($bodyStart, $bodyEnd, '$this')) {
            $this->manual[] = sprintf('MANUAL: route group at line %d uses $this; Slim 4 binds the group callable to the container, review it.', $line);

            return 0;
        }

        $param = null;
        foreach ($this->parameterCandidates($receiver) as $candidate) {
            if ($arrowFn && $this->bodyUses($bodyStart, $bodyEnd, $candidate)) {
                $param = $candidate;
                break;
            }
            foreach ($uses as $use) {
                if ($this->tokens[$use]->text === $candidate) {
                    $param = $candidate;
                    break 2;
                }
            }
        }
        if ($param === null) {
            if ($this->bodyCalls($bodyStart, $bodyEnd, '$app')) {
                $this->manual[] = sprintf('MANUAL: route group at line %d calls $app without use ($app); give the group callable a %s $app parameter by hand.', $line, self::PROXY);
            }

            return 0;
        }

        $kept = [];
        foreach ($uses as $use) {
            if ($this->tokens[$use]->text !== $param) {
                $byReference = $this->previous($use);
                $kept[] = ($byReference !== null && $this->tokens[$byReference]->text === '&' ? '&' : '') . $this->tokens[$use]->text;
            }
        }

        $head = ($arrowFn ? 'fn' : 'function') . ' (' . self::PROXY . ' ' . $param . ')';
        if ($kept !== []) {
            $head .= ' use (' . implode(', ', $kept) . ')';
        }
        // Slim 4 binds a group callable to the container, which a static closure refuses, so `static` goes.
        $from = $static ?? $function;
        $to = $useClose ?? $paramsClose;
        $this->edit($from, $to, $head);

        return 1;
    }

    /**
     * Rule 2: a route adder (any function whose body calls `$p->group(`) with a
     * `\Slim\App $p` parameter takes RouteCollectorProxyInterface instead, when
     * this file rewrote a group callable. Other `\Slim\App` route adders get a
     * MANUAL line.
     *
     * @param list<string> $aliases
     */
    private function widen(array $aliases, bool $groupRewritten): int
    {
        $count = 0;
        foreach ($this->tokens as $i => $token) {
            if (!$token->is(T_FUNCTION)) {
                continue;
            }
            $open = $this->next($i);
            if ($open !== null && $this->tokens[$open]->text === '&') {
                $open = $this->next($open);
            }
            if ($open !== null && $this->tokens[$open]->is(T_STRING)) {
                $open = $this->next($open);
            }
            if ($open === null || $this->tokens[$open]->text !== '(' || isset($this->edited[$open])) {
                continue;
            }
            $close = $this->matching($open);
            $after = $this->next($close);
            if ($after !== null && $this->tokens[$after]->is(T_USE)) {
                $useOpen = $this->next($after);
                $close = $useOpen === null ? $close : $this->matching($useOpen);
            }
            $body = $this->body($close, false);
            if ($body === null) {
                continue;
            }
            $count += $this->widenParameters($open, $this->matching($open), $aliases, [$body[0], $body[1], $groupRewritten]);
        }

        return $count;
    }

    /**
     * Widens `\Slim\App $p` parameters between $open and $close. With $body
     * null (a group callable's own parameter) every one is widened.
     *
     * @param list<string> $aliases
     * @param array{int, int, bool}|null $body
     */
    private function widenParameters(int $open, int $close, array $aliases, ?array $body): int
    {
        $count = 0;
        for ($j = $open + 1; $j < $close; $j++) {
            if (!$this->namesSlimApp($this->tokens[$j], $aliases) || isset($this->edited[$j])) {
                continue;
            }
            $variable = $this->next($j);
            if ($variable === null || !$this->tokens[$variable]->is(T_VARIABLE)) {
                continue;
            }
            $name = $this->tokens[$variable]->text;
            if ($body !== null) {
                [$bodyStart, $bodyEnd, $groupRewritten] = $body;
                $opensGroup = $this->bodyCallsMethod($bodyStart, $bodyEnd, $name, ['group']);
                if (!$groupRewritten || !$opensGroup) {
                    if ($this->bodyCallsMethod($bodyStart, $bodyEnd, $name, self::ROUTE_METHODS)) {
                        $this->manual[] = sprintf('MANUAL: line %d: %s is typed \\Slim\\App in a route adder; inside a Slim 4 group it gets a RouteCollectorProxy, so widen it to %s when a group calls it.', $this->tokens[$j]->line, $name, self::PROXY);
                    }

                    continue;
                }
            }
            $this->edit($j, $j, self::PROXY);
            $count++;
        }

        return $count;
    }

    /**
     * Rule 3: `->add(E)` on the app, a route or a group becomes
     * `->add(new \NS\LegacyDoublePass(E))`.
     */
    private function middleware(string $adapter): int
    {
        $count = 0;
        foreach ($this->methodCalls(['add']) as [$arrow, $open]) {
            $receiver = $this->chainBase($arrow);
            if ($receiver === null || !in_array($receiver, self::APP_EXPRESSIONS, true)) {
                continue;
            }
            $close = $this->matching($open);
            $first = $this->next($open);
            $last = $this->previous($close);
            if ($first === null || $last === null || $first >= $close || $this->hasTopLevelComma($open, $close)) {
                continue;
            }
            if ($first === $last && $this->stringValue($this->tokens[$first]) !== null) {
                // Slim 3 resolved a name through the container; the adapter needs the object, so a person wraps that entry.
                $this->manual[] = sprintf('MANUAL: middleware %s at line %d is added by its container name; Slim 4 resolves that entry as PSR-15, so wrap it in %s by hand.', $this->tokens[$first]->text, $this->tokens[$first]->line, $adapter);

                continue;
            }
            if ($this->tokens[$first]->is(T_NEW)) {
                $class = $this->next($first);
                if ($class !== null && $this->isAdapterName($this->tokens[$class]->text, $adapter)) {
                    continue;
                }
            }
            $this->insert($first, 'new ' . $adapter . '(', true);
            $this->insert($last, ')', false);
            $count++;
        }

        return $count;
    }

    /**
     * Rule 4: `$x->getAttribute('route')` becomes
     * `\Slim\Routing\RouteContext::fromRequest($x)->getRoute()`.
     */
    private function routeAttribute(): int
    {
        $count = 0;
        foreach ($this->methodCalls(['getattribute']) as [$arrow, $open]) {
            $key = $this->next($open);
            $close = $key === null ? null : $this->next($key);
            if ($key === null || $close === null || $this->tokens[$close]->text !== ')' || $this->stringValue($this->tokens[$key]) !== 'route') {
                continue;
            }
            $variable = $this->previous($arrow);
            if ($variable === null || !$this->tokens[$variable]->is(T_VARIABLE) || $this->isMemberAccess($variable)) {
                continue;
            }
            $name = $this->tokens[$variable]->text;
            $this->edit($variable, $close, '\\Slim\\Routing\\RouteContext::fromRequest(' . $name . ')->getRoute()');
            $count++;
        }
        if ($count > 0) {
            $this->manual[] = "MANUAL: the route is only on the request after Slim's routing middleware ran; code that reads it in outer middleware sees null.";
        }

        return $count;
    }

    /**
     * Rule 5: router URL building and `getAttribute('routeInfo')`.
     */
    private function routerUrls(): void
    {
        foreach ($this->methodCalls(['pathfor', 'urlfor', 'relativepathfor']) as [$arrow, $open]) {
            $method = $this->tokens[(int) $this->next($arrow)]->text;
            if (strtolower($method) === 'urlfor' && $this->chainedAfter($arrow, 'getrouteparser')) {
                continue;
            }
            $this->manual[] = sprintf('MANUAL: %s() at line %d has no like-for-like; build the URL with RouteContext::fromRequest($request)->getRouteParser()->urlFor().', $method, $this->tokens[$arrow]->line);
        }
        foreach ($this->methodCalls(['getattribute']) as [$arrow, $open]) {
            $key = $this->next($open);
            if ($key !== null && $this->stringValue($this->tokens[$key]) === 'routeInfo') {
                $this->manual[] = sprintf("MANUAL: getAttribute('routeInfo') at line %d has no Slim 4 equivalent; use RouteContext::fromRequest(\$request)->getRoutingResults().", $this->tokens[$arrow]->line);
            }
        }
    }

    /**
     * Rule 6: Slim 3 APIs that Slim 4 removed or renamed.
     */
    private function removedApis(): void
    {
        foreach ($this->methodCalls(['subrequest']) as [$arrow]) {
            $this->manual[] = sprintf('MANUAL: subRequest() at line %d was removed in Slim 4; call $app->handle() with a new request instead.', $this->tokens[$arrow]->line);
        }
        foreach ($this->methodCalls(['setargument', 'setarguments']) as [$arrow]) {
            $method = $this->tokens[(int) $this->next($arrow)]->text;
            $this->manual[] = sprintf('MANUAL: %s() at line %d: Route::setArgument exists in Slim 4; check the name.', $method, $this->tokens[$arrow]->line);
        }

        foreach ($this->tokens as $i => $token) {
            if ($this->readsRouter($i)) {
                $this->manual[] = sprintf("MANUAL: line %d reads Slim 3's router from the container; Slim 4 has no 'router' entry, use \$app->getRouteCollector() or RouteContext::fromRequest(\$request)->getRouteParser().", $token->line);
            }
        }
    }

    /** `['router']`, `->get('router')` or a `->router` property read on the container. */
    private function readsRouter(int $i): bool
    {
        $token = $this->tokens[$i];
        if ($this->stringValue($token) === 'router') {
            $before = $this->previous($i);
            $after = $this->next($i);
            if ($before === null || $after === null) {
                return false;
            }
            if ($this->tokens[$before]->text === '[' && $this->tokens[$after]->text === ']') {
                return true;
            }
            $method = $this->previous($before);

            return $this->tokens[$before]->text === '(' && $this->tokens[$after]->text === ')' && $method !== null
                && strtolower($this->tokens[$method]->text) === 'get' && $this->isMemberAccess($method);
        }
        if (!$token->is(T_STRING) || $token->text !== 'router' || !$this->isMemberAccess($i)) {
            return false;
        }
        $after = $this->next($i);
        if ($after !== null && $this->tokens[$after]->text === '(') {
            return false;
        }
        $arrow = (int) $this->previous($i);
        $owner = $this->previous($arrow);
        if ($owner === null) {
            return false;
        }
        if ($this->tokens[$owner]->text === ')') {
            return $this->chainedAfter($arrow, 'getcontainer');
        }

        return in_array($this->tokens[$owner]->text, ['$c', '$container'], true)
            || ($this->tokens[$owner]->text === 'container' && $this->isMemberAccess($owner));
    }

    /**
     * Every `->name(` call with name in $names (lower case), as [arrow index, open paren index].
     *
     * @param list<string> $names
     * @return list<array{int, int}>
     */
    private function methodCalls(array $names): array
    {
        $calls = [];
        foreach ($this->tokens as $i => $token) {
            if (!$token->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                continue;
            }
            $method = $this->next($i);
            if ($method === null || !$this->tokens[$method]->is(T_STRING) || !in_array(strtolower($this->tokens[$method]->text), $names, true)) {
                continue;
            }
            $open = $this->next($method);
            if ($open !== null && $this->tokens[$open]->text === '(') {
                $calls[] = [$i, $open];
            }
        }

        return $calls;
    }

    /**
     * The app expression a `->` chain starts from (`$app`, `$this->app`), or
     * null when it starts anywhere else (an array read, another object).
     */
    private function chainBase(int $arrow): ?string
    {
        $last = $this->previous($arrow);
        if ($last === null) {
            return null;
        }
        $token = $this->tokens[$last];
        if ($token->is(T_VARIABLE)) {
            return $this->isMemberAccess($last) ? null : $token->text;
        }
        if ($token->is(T_STRING)) {
            $innerArrow = $this->previous($last);
            $owner = $innerArrow === null ? null : $this->previous($innerArrow);
            if ($innerArrow === null || $owner === null || !$this->tokens[$innerArrow]->is(T_OBJECT_OPERATOR)) {
                return null;
            }
            if ($this->tokens[$owner]->text !== '$this' || $this->isMemberAccess($owner)) {
                return null;
            }

            return '$this->' . $token->text;
        }
        if ($token->text !== ')') {
            return null;
        }
        $open = $this->matchingBackward($last);
        $method = $open === null ? null : $this->previous($open);
        $innerArrow = $method === null ? null : $this->previous($method);
        if ($method === null || $innerArrow === null || !$this->tokens[$innerArrow]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
            return null;
        }
        if (!in_array(strtolower($this->tokens[$method]->text), self::CHAIN_METHODS, true)) {
            return null;
        }

        return $this->chainBase($innerArrow);
    }

    /** Whether the call just before `->` at $arrow is `->$method(...)`. */
    private function chainedAfter(int $arrow, string $method): bool
    {
        $close = $this->previous($arrow);
        if ($close === null || $this->tokens[$close]->text !== ')') {
            return false;
        }
        $open = $this->matchingBackward($close);
        $name = $open === null ? null : $this->previous($open);

        return $name !== null && strtolower($this->tokens[$name]->text) === $method && $this->isMemberAccess($name);
    }

    /** The index of the first token of the second top-level argument of the call opened at $open. */
    private function secondArgument(int $open): ?int
    {
        $close = $this->matching($open);
        $depth = 0;
        for ($j = $open + 1; $j < $close; $j++) {
            if ($this->opens($j)) {
                $depth++;
            } elseif ($this->closes($j)) {
                $depth--;
            } elseif ($depth === 0 && $this->tokens[$j]->text === ',') {
                $next = $this->next($j);

                return $next !== null && $next < $close ? $next : null;
            }
        }

        return null;
    }

    private function hasTopLevelComma(int $open, int $close): bool
    {
        $depth = 0;
        for ($j = $open + 1; $j < $close; $j++) {
            if ($this->opens($j)) {
                $depth++;
            } elseif ($this->closes($j)) {
                $depth--;
            } elseif ($depth === 0 && $this->tokens[$j]->text === ',') {
                $next = $this->next($j);

                // A trailing comma is not a second argument.
                return $next !== null && $next < $close;
            }
        }

        return false;
    }

    /**
     * The body of a function whose parameter (or use) list closes at $close:
     * [first, last] token indexes of `{ ... }`, or of an arrow function's
     * expression.
     *
     * @return array{int, int}|null
     */
    private function body(int $close, bool $arrowFn): ?array
    {
        $j = $this->next($close);
        while ($j !== null && !in_array($this->tokens[$j]->text, ['{', ';', '=>'], true)) {
            $j = $this->next($j);
        }
        if ($j === null) {
            return null;
        }
        if ($arrowFn && $this->tokens[$j]->is(T_DOUBLE_ARROW)) {
            $end = $j;
            $depth = 0;
            $count = count($this->tokens);
            for ($k = $j + 1; $k < $count; $k++) {
                if ($this->opens($k)) {
                    $depth++;
                } elseif ($this->closes($k)) {
                    if ($depth === 0) {
                        break;
                    }
                    $depth--;
                } elseif ($depth === 0 && in_array($this->tokens[$k]->text, [',', ';'], true)) {
                    break;
                }
                $end = $k;
            }

            return [$j, $end];
        }
        if ($this->tokens[$j]->text !== '{') {
            return null;
        }

        return [$j, $this->matching($j)];
    }

    private function bodyUses(int $start, int $end, string $variable): bool
    {
        for ($j = $start; $j <= $end; $j++) {
            if ($this->tokens[$j]->is(T_VARIABLE) && $this->tokens[$j]->text === $variable) {
                return true;
            }
        }

        return false;
    }

    private function bodyCalls(int $start, int $end, string $variable): bool
    {
        for ($j = $start; $j <= $end; $j++) {
            if ($this->tokens[$j]->is(T_VARIABLE) && $this->tokens[$j]->text === $variable) {
                $after = $this->next($j);
                if ($after !== null && $this->tokens[$after]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<string> $methods lower case
     */
    private function bodyCallsMethod(int $start, int $end, string $variable, array $methods): bool
    {
        for ($j = $start; $j <= $end; $j++) {
            if (!$this->tokens[$j]->is(T_VARIABLE) || $this->tokens[$j]->text !== $variable) {
                continue;
            }
            $arrow = $this->next($j);
            $method = $arrow === null ? null : $this->next($arrow);
            $open = $method === null ? null : $this->next($method);
            if ($arrow !== null && $method !== null && $open !== null && $this->tokens[$arrow]->is(T_OBJECT_OPERATOR)
                && in_array(strtolower($this->tokens[$method]->text), $methods, true) && $this->tokens[$open]->text === '(') {
                return true;
            }
        }

        return false;
    }

    /**
     * Which `use` variable becomes the group parameter, in order of preference.
     *
     * @return list<string>
     */
    private function parameterCandidates(string $receiver): array
    {
        $own = str_starts_with($receiver, '$this->') ? '$' . substr($receiver, 7) : $receiver;

        return array_values(array_unique([$own, '$app']));
    }

    /**
     * Lower-cased short names that mean Slim\App through a `use` import.
     *
     * @return list<string>
     */
    private function slimAppAliases(): array
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

    private function isAdapterName(string $name, string $adapter): bool
    {
        $name = strtolower($name);

        return $name === strtolower($adapter) || $name === 'legacydoublepass' || str_ends_with($name, '\\legacydoublepass');
    }

    /** Whether the token at $i follows `->`, `?->` or `::`. */
    private function isMemberAccess(int $i): bool
    {
        $before = $this->previous($i);

        return $before !== null && $this->tokens[$before]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON]);
    }

    private function opens(int $i): bool
    {
        return in_array($this->tokens[$i]->text, ['(', '[', '{'], true) || $this->tokens[$i]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES]);
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

    /** Index of the bracket opening the one that closes at $close. */
    private function matchingBackward(int $close): ?int
    {
        $depth = 0;
        for ($j = $close; $j >= 0; $j--) {
            if ($this->closes($j)) {
                $depth++;
            } elseif ($this->opens($j)) {
                $depth--;
                if ($depth === 0) {
                    return $j;
                }
            }
        }

        return null;
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

    /** The value of a plain quoted string token, else null. */
    private function stringValue(PhpToken $token): ?string
    {
        if (!$token->is(T_CONSTANT_ENCAPSED_STRING) || strlen($token->text) < 2) {
            return null;
        }

        return substr($token->text, 1, -1);
    }

    /** Replaces tokens $from to $to (inclusive) with $text. */
    private function edit(int $from, int $to, string $text): void
    {
        $start = $this->tokens[$from]->pos;
        $end = $this->tokens[$to]->pos + strlen($this->tokens[$to]->text);
        $this->edits[] = [$start, $end - $start, $text];
        for ($j = $from; $j <= $to; $j++) {
            $this->edited[$j] = true;
        }
    }

    /** Inserts $text before (or after) token $at. */
    private function insert(int $at, string $text, bool $before): void
    {
        $offset = $this->tokens[$at]->pos + ($before ? 0 : strlen($this->tokens[$at]->text));
        $this->edits[] = [$offset, 0, $text];
    }

    private function apply(string $code): string
    {
        $edits = $this->edits;
        // Back to front; at one offset, a replacement goes before an insertion so neither eats the other.
        usort($edits, static fn(array $a, array $b): int => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
        foreach ($edits as [$offset, $length, $replacement]) {
            $code = substr_replace($code, $replacement, $offset, $length);
        }

        return $code;
    }
}
