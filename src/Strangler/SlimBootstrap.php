<?php

declare(strict_types=1);

namespace Waynestate\Booster\Strangler;

/**
 * Bootstrap mode's routes scripts (design sections 9.2 and 10.2).
 *
 * A Slim app that builds its container and middleware in its own way (a
 * Pimple container filled from arrays of closures, double-pass middleware,
 * its own CSRF check, routes registered inside role checks) runs in the
 * Laravel half as a copy of its own front controller. The copy loads every
 * script the legacy one loads, except the routes script: Booster writes a
 * replacement of the same shape that registers only the moved routes, each
 * exactly as legacy registers it:
 *
 * - inside the same if blocks, with the assignments the condition reads, so
 *   the condition is evaluated on every request against the same legacy
 *   services, and a false one leaves the route unregistered, which Slim
 *   answers with the legacy 404 or 405 page through the legacy pipeline;
 * - with the same middleware (its own ->add() and its groups'), so route
 *   middleware runs through the same double-pass adapter;
 * - at its full pattern, optional segments and all.
 *
 * Every other named legacy route is registered for a method no request has,
 * so url_for() and is_current_url() in the copied Twig views know every
 * legacy name while no unmoved route can answer.
 */
final class SlimBootstrap
{
    /** The method name the placeholder routes use: no request ever has it. */
    public const NOT_MOVED = 'BOOSTER_NOT_MOVED';

    private const VERBS = '(?:get|post|put|patch|delete|options|any|map|group)';

    /** @param array<string, SlimSource> $sources */
    public function __construct(private readonly array $sources, private readonly SlimCodeCopier $copier) {}

    /**
     * The code that registers one route as legacy does, for the body of the
     * generated routes script, or the reason it cannot be written.
     *
     * @return array{code: ?string, refusal: ?string}
     */
    public function registration(SlimRoute $route): array
    {
        if ($route->links === []) {
            return ['code' => null, 'refusal' => 'Booster lost where the route is registered'];
        }
        $this->copier->qualify = true;
        try {
            $inner = $this->routeCall($route);
            if ($inner === null) {
                return ['code' => null, 'refusal' => 'Booster cannot read the route call'];
            }
            foreach (array_reverse($route->links) as [$file, $at, $from]) {
                $src = $this->sources[$file];
                // Each link is a call (the route call, or a call into the
                // method that registers it); the blocks between where that
                // walk began and the call wrap it.
                $wrapped = $this->wrap($src, $file, $at, $from, $inner);
                if ($wrapped['refusal'] !== null) {
                    return ['code' => null, 'refusal' => $wrapped['refusal']];
                }
                $inner = $wrapped['code'];
                if ($from > 0) {
                    // A method body: the method itself becomes the frame.
                    $method = $src->enclosingFunction($from);
                    if ($method === null) {
                        return ['code' => null, 'refusal' => 'Booster cannot find the method around legacy/' . $file . ':' . $src->t[$at][2]];
                    }
                    $setup = $this->assignments($src, $file, $method['open'], $this->firstBlock($src, $from, $at) ?? $at);
                    $frame = $this->frame($src, $file, $method, ($setup === '' ? '' : $setup . "
") . $inner);
                    if ($frame['refusal'] !== null) {
                        return ['code' => null, 'refusal' => $frame['refusal']];
                    }
                    $inner = $frame['code'];
                }
            }

            return ['code' => $inner, 'refusal' => null];
        } finally {
            $this->copier->qualify = false;
        }
    }

    /**
     * A placeholder for an unmoved named route: url_for() knows its name and
     * pattern, and no request can match it.
     */
    public static function placeholder(SlimRoute $route): string
    {
        return '$booster_app->map([' . var_export(self::NOT_MOVED, true) . '], ' . var_export($route->written(), true)
            . ", [\\App\\Http\\Controllers\\Legacy\\Support\\SlimAdapter::class, 'notMoved'])->setName(" . var_export((string) $route->name, true) . ');';
    }

    /**
     * The generated replacement for a legacy routes script, in the same
     * shape: an array of closures, one closure, or code run in place.
     *
     * @param list<string> $blocks
     */
    public static function script(SlimSource $entry, string $legacyPath, array $blocks): string
    {
        $body = implode("\n\n", array_map(static fn(string $b): string => self::indent($b, '        '), $blocks));
        $bind = "        \\App\\Http\\Controllers\\Legacy\\Support\\SlimAdapter::bindContainer(\$booster_app->getContainer());";
        $head = "<?php\n\n// Written by Booster's strangler (slim-to-laravel) in place of legacy/" . $legacyPath . ":\n"
            . "// only the moved routes, each registered as legacy registers it. Regenerated on every move.\n\n";
        $function = "function (\$booster_app) {\n" . $body . ($body === '' ? '' : "\n\n") . $bind . "\n    }";
        $shape = self::shape($entry);
        if ($shape === 'array') {
            return $head . "return [\n    'booster' => " . $function . ",\n];\n";
        }
        if ($shape === 'closure') {
            return $head . 'return ' . str_replace("\n    ", "\n", $function) . ";\n";
        }

        return $head . '(' . str_replace("\n    ", "\n", $function) . ')($' . $shape . ");\n";
    }

    /**
     * How a routes script hands its routes over: "array" (returns an array
     * of closures), "closure" (returns one) or the name of the variable its
     * top-level route calls use.
     */
    public static function shape(SlimSource $src): string
    {
        $depth = 0;
        foreach ($src->t as $i => [$id, $text]) {
            if ($text === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
            } elseif ($text === '}') {
                $depth--;
            } elseif ($id === T_RETURN && $depth === 0) {
                $next = $src->next($i);
                $n = $next === null ? null : $src->t[$next];
                if ($n !== null && ($n[1] === '[' || $n[0] === T_ARRAY)) {
                    return 'array';
                }
                if ($n !== null && in_array($n[0], [T_FUNCTION, T_FN, T_STATIC], true)) {
                    return 'closure';
                }
            } elseif ($id === T_VARIABLE && $depth === 0) {
                $arrow = $src->next($i);
                $verb = $arrow === null ? null : $src->next($arrow);
                if ($arrow !== null && $verb !== null && $src->t[$arrow][0] === T_OBJECT_OPERATOR && preg_match('/^' . self::VERBS . '$/i', $src->t[$verb][1]) === 1) {
                    return substr($text, 1);
                }
            }
        }

        return 'app';
    }

    /**
     * Wrap $inner in every block between $from and the token $at: an if
     * keeps its condition, a closure becomes a closure called at once with
     * the app (its parameters are the app or a group of it, and a route
     * registered on the app at its full pattern is the same route), and
     * the assignments before each step come along, since a condition reads
     * them.
     *
     * @return array{code: string, refusal: ?string}
     */
    private function wrap(SlimSource $src, string $file, int $at, int $from, string $inner): array
    {
        $blocks = [];
        for ($k = max(0, $from); $k < $at; $k++) {
            if ($src->t[$k][1] === '{' && isset($src->braces[$k]) && $src->braces[$k] > $at) {
                $blocks[] = $k;
            }
        }
        $code = $inner;
        $ends = array_merge(array_slice($blocks, 1), [$at]);
        foreach (array_reverse($blocks, true) as $n => $open) {
            $kind = $this->blockKind($src, $open);
            $line = $src->t[$open][2];
            if ($kind['kind'] === 'class') {
                continue;
            }
            if ($kind['kind'] === 'refuse') {
                return ['code' => $code, 'refusal' => 'the route is registered inside ' . $kind['what'] . ' at legacy/' . $file . ':' . $line . ', which Booster cannot evaluate per request; port it by hand, then run the --hand move'];
            }
            $setup = $this->assignments($src, $file, $open, $ends[$n]);
            $body = ($setup === '' ? '' : $setup . "\n") . $code;
            if ($kind['kind'] === 'if') {
                [$condition] = $this->copier->rewrite($src, $file, $kind['from'], $kind['to']);
                $code = 'if (' . trim($condition) . ") {\n" . self::indent($body, '    ') . "\n}";

                continue;
            }
            $function = $src->enclosingFunction($open + 1);
            if ($function === null || $function['open'] !== $open) {
                return ['code' => $code, 'refusal' => 'Booster cannot read the function at legacy/' . $file . ':' . $line];
            }
            $frame = $this->frame($src, $file, $function, $body);
            if ($frame['refusal'] !== null) {
                return $frame;
            }
            $code = $frame['code'];
        }
        // Top-level assignments before the first block (a routes script's
        // `$container = $app->getContainer();`).
        if ($from === 0) {
            $setup = $this->assignments($src, $file, -1, $blocks[0] ?? $at);
            $code = ($setup === '' ? '' : $setup . "\n") . $code;
        }

        return ['code' => $code, 'refusal' => null];
    }

    /**
     * A function as a closure called at once with the app, its parameters
     * and use list kept.
     *
     * @param array{params: list<string>, uses: list<string>, open: int, close: int} $function
     *
     * @return array{code: string, refusal: ?string}
     */
    private function frame(SlimSource $src, string $file, array $function, string $inner): array
    {
        $line = $src->t[$function['open']][2];
        if (count($function['params']) > 1) {
            return ['code' => $inner, 'refusal' => 'the function at legacy/' . $file . ':' . $line . ' that registers the route takes more than the app; Booster can only call it with the app'];
        }
        if (preg_match('/\$this\b/', $inner) === 1) {
            return ['code' => $inner, 'refusal' => 'the route registration at legacy/' . $file . ':' . $line . ' reads $this'];
        }
        $param = $function['params'] === [] ? '' : '$' . $function['params'][0];
        $uses = array_values(array_unique(array_merge(array_map(static fn(string $u): string => '$' . $u, $function['uses']), ['$booster_app'])));
        $uses = array_values(array_diff($uses, [$param]));

        return ['code' => '(function (' . $param . ') use (' . implode(', ', $uses) . ") {\n" . self::indent($inner, '    ') . "\n})(\$booster_app);", 'refusal' => null];
    }

    /**
     * What a block is: an if (its condition's tokens), a function body, a
     * class body, or something Booster refuses (else, a loop, a switch).
     *
     * @return array{kind: string, what: string, from: int, to: int}
     */
    private function blockKind(SlimSource $src, int $open): array
    {
        $out = ['kind' => 'refuse', 'what' => 'a block', 'from' => 0, 'to' => 0];
        $k = $src->prev($open);
        // Skip a return type: function (...): Type {
        while ($k !== null && in_array($src->t[$k][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_ARRAY, T_CALLABLE, T_STATIC], true)) {
            $p = $src->prev($k);
            if ($p !== null && in_array($src->t[$p][1], [':', '?', '|'], true)) {
                $k = $src->prev($p);
                if ($k !== null && $src->t[$k][1] === '?') {
                    $k = $src->prev($k);
                }

                continue;
            }
            break;
        }
        if ($k === null) {
            return $out;
        }
        if ($src->t[$k][1] !== ')') {
            $id = $src->t[$k][0];
            if ($id === T_ELSE) {
                return ['what' => 'an else block'] + $out;
            }
            if ($id === T_TRY || $id === T_DO || $id === T_FINALLY) {
                return ['what' => 'a ' . strtolower($src->t[$k][1]) . ' block'] + $out;
            }

            return ['kind' => 'class'] + $out;
        }
        $close = $k;
        $openParen = array_search($close, $src->parens, true);
        if (!is_int($openParen)) {
            return $out;
        }
        $before = $src->prev($openParen);
        $id = $before === null ? null : $src->t[$before][0];
        if ($id === T_IF) {
            return ['kind' => 'if', 'what' => 'an if', 'from' => $openParen + 1, 'to' => $close - 1];
        }
        if ($id === T_USE || $id === T_FUNCTION || ($id === T_STRING && ($b = $src->prev($before)) !== null && in_array($src->t[$b][0], [T_FUNCTION, T_FN], true))) {
            return ['kind' => 'function'] + $out;
        }
        $what = $before === null ? 'a block' : 'a ' . strtolower($src->t[$before][1]) . ' block';

        return ['what' => $what] + $out;
    }

    /**
     * The assignments written directly in a block before $before, rewritten:
     * the setup a guard reads (`$user = $app->getContainer()['user'];`).
     * Other statements stay out; one that registers routes would register
     * unmoved ones.
     *
     */
    private function assignments(SlimSource $src, string $file, int $open, int $before): string
    {
        $lines = [];
        foreach ($src->statementsBefore($open, $before) as [$a, $b]) {
            $range = $src->trimRange($a, $b);
            if ($range !== null && $src->t[$range[0]][0] === T_OPEN_TAG) {
                $range = $src->trimRange($range[0] + 1, $range[1]);
            }
            if ($range === null || $src->t[$range[0]][0] !== T_VARIABLE) {
                continue;
            }
            $eq = $src->next($range[0]);
            if ($eq === null || $src->t[$eq][1] !== '=') {
                continue;
            }
            $text = $src->text($range[0], $range[1]);
            if (preg_match('/->\s*' . self::VERBS . '\s*\(|::\s*addroutes\s*\(/i', $text) === 1) {
                continue;
            }
            [$code, $refs] = $this->copier->rewrite($src, $file, $range[0], $range[1]);
            foreach ($refs as $ref) {
                $this->copier->copyClass($ref);
            }
            $lines[] = rtrim(trim($code), ';') . ';';
        }

        return implode("\n", $lines);
    }

    /** The first block opened after $from that holds $at. */
    private function firstBlock(SlimSource $src, int $from, int $at): ?int
    {
        for ($k = max(0, $from); $k < $at; $k++) {
            if ($src->t[$k][1] === '{' && isset($src->braces[$k]) && $src->braces[$k] > $at) {
                return $k;
            }
        }

        return null;
    }

    /**
     * The route call on the app at its full legacy pattern: the handler,
     * the chained ->setName() and ->add() as written, then its groups'
     * middleware, outermost last, as Slim runs them.
     */
    private function routeCall(SlimRoute $route): ?string
    {
        [$file, $arrow] = $route->links[count($route->links) - 1];
        $src = $this->sources[$file];
        $verb = $src->next($arrow);
        $open = $verb === null ? null : $src->next($verb);
        if ($verb === null || $open === null || !isset($src->parens[$open])) {
            return null;
        }
        $close = $src->parens[$open];
        $args = $src->args($open, $close);
        $patternArg = strtolower($src->t[$verb][1]) === 'map' ? 1 : 0;
        if (!isset($args[$patternArg])) {
            return null;
        }
        $parts = [];
        foreach ($args as $n => [$a, $b]) {
            if ($n === $patternArg) {
                $parts[] = var_export($route->written(), true);

                continue;
            }
            [$code, $refs] = $this->copier->rewrite($src, $file, $a, $b);
            foreach ($refs as $ref) {
                $this->copier->copyClass($ref);
            }
            $parts[] = trim($code);
        }
        $call = '$booster_app->' . $src->t[$verb][1] . '(' . implode(', ', $parts) . ')';
        // The chain after the call: ->setName(...), ->add(...).
        $i = $close;
        $own = 0;
        while (($next = $src->next($i)) !== null && in_array($src->t[$next][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
            $name = $src->next($next);
            $paren = $name === null ? null : $src->next($name);
            if ($paren === null || !isset($src->parens[$paren])) {
                break;
            }
            [$code, $refs] = $this->copier->rewrite($src, $file, $next, $src->parens[$paren]);
            foreach ($refs as $ref) {
                $this->copier->copyClass($ref);
            }
            $call .= trim($code);
            if (in_array(strtolower($src->t[$name][1]), ['add', 'addmiddleware'], true)) {
                $own++;
            }
            $i = $src->parens[$paren];
        }
        // Group middleware: the route's middleware list is the groups' first.
        $groupCount = count($route->middleware) - $own;
        for ($n = $groupCount - 1; $n >= 0; $n--) {
            $mwFile = $route->middlewareFiles[$n] ?? $file;
            $mwSrc = $this->sources[$mwFile];
            $code = $this->groupMiddleware($mwSrc, $mwFile, $route->middleware[$n]);
            if ($code === null) {
                return null;
            }
            $call .= '->add(' . $code . ')';
        }

        return $call . ';';
    }

    /** A group's ->add() argument, rewritten in its own file's names. */
    private function groupMiddleware(SlimSource $src, string $file, string $text): ?string
    {
        if (preg_match('/\$(?!this\b)\w+/', $text) === 1) {
            return null;
        }
        $wrapped = new SlimSource("<?php\n" . ($src->namespace === '' ? '' : 'namespace ' . $src->namespace . ";\n") . self::importBlock($src) . "\n(" . $text . ");\n");
        $from = null;
        $to = null;
        foreach ($wrapped->t as $k => [, $tx]) {
            if ($tx === '(' && $from === null && $k > 0 && $wrapped->t[$k - 1][1] === "\n") {
                $from = $k + 1;
                $to = $wrapped->parens[$k] - 1;
            }
        }
        if ($from === null || $to === null) {
            return null;
        }
        [$code, $refs] = $this->copier->rewrite($wrapped, $file, $from, $to);
        foreach ($refs as $ref) {
            $this->copier->copyClass($ref);
        }

        return trim($code);
    }

    private static function importBlock(SlimSource $src): string
    {
        $lines = '';
        foreach ($src->imports as [$alias, $fqcn]) {
            $lines .= 'use ' . $fqcn . ' as ' . $alias . ";\n";
        }

        return $lines;
    }

    private static function indent(string $code, string $indent): string
    {
        return (string) preg_replace('/^(?=.)/m', $indent, $code);
    }
}
