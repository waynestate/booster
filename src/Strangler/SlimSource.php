<?php

declare(strict_types=1);

namespace Waynestate\Booster\Strangler;

/**
 * One PHP file of the legacy app as tokens, with what the strangler needs to
 * know about names in it: its namespace, its `use` imports, its classes and
 * their methods, and the matching parentheses and braces. It lexes with
 * token_get_all and never runs the code.
 */
final class SlimSource
{
    /** @var list<array{int|null, string, int}> id, text, line */
    public readonly array $t;

    /** @var array<int, int> index of each "(" to its ")" */
    public readonly array $parens;

    /** @var array<int, int> index of each "{" to its "}" */
    public readonly array $braces;

    public readonly string $namespace;

    /** @var array<string, string> lower-cased alias to the fully qualified class name */
    public readonly array $uses;

    /** @var list<array{string, string}> each class import as written: alias, fully qualified name */
    public readonly array $imports;

    /** @var list<array{int, int, string, ?string}> body "{", body "}", fully qualified name, parent class (resolved) */
    public readonly array $classes;

    /** @var array<string, array{int, int}> lower-cased "fqcn::method" to its body "{" and "}" */
    public readonly array $methods;

    public function __construct(public readonly string $code)
    {
        $tokens = [];
        $line = 1;
        foreach (token_get_all($code) as $token) {
            if (is_array($token)) {
                $tokens[] = [$token[0], $token[1], $token[2]];
                $line = $token[2] + substr_count($token[1], "\n");
            } else {
                $tokens[] = [null, $token, $line];
            }
        }
        $this->t = $tokens;
        $this->parens = $this->match('(', ')');
        $this->braces = $this->braceMap();
        [$this->namespace, $this->uses, $this->imports] = $this->readImports();
        $this->classes = $this->readClasses();
        $this->methods = $this->readMethods();
    }

    /** The next token that is not whitespace or a comment. */
    public function next(int $i): ?int
    {
        for ($j = $i + 1, $n = count($this->t); $j < $n; $j++) {
            if (!in_array($this->t[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $j;
            }
        }

        return null;
    }

    /** The previous token that is not whitespace or a comment. */
    public function prev(int $i): ?int
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (!in_array($this->t[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $j;
            }
        }

        return null;
    }

    public function text(int $from, int $to): string
    {
        $out = '';
        for ($i = $from; $i <= $to; $i++) {
            $out .= $this->t[$i][1];
        }

        return $out;
    }

    /** A class name as written at this point of the file, fully qualified (no leading backslash). */
    public function resolveClass(string $name, int $at): string
    {
        if ($name === '') {
            return '';
        }
        if ($name[0] === '\\') {
            return ltrim($name, '\\');
        }
        $lower = strtolower($name);
        if (in_array($lower, ['self', 'static', '__class__'], true)) {
            return $this->classAt($at) ?? $name;
        }
        if ($lower === 'parent') {
            return $this->parentAt($at) ?? $name;
        }
        if (str_starts_with($lower, 'namespace\\')) {
            return ltrim($this->namespace . '\\' . substr($name, 10), '\\');
        }
        $first = strtolower(explode('\\', $name)[0]);
        if (isset($this->uses[$first])) {
            $rest = substr($name, strlen($first));

            return $this->uses[$first] . $rest;
        }

        return ltrim($this->namespace . '\\' . $name, '\\');
    }

    /** The fully qualified name of the innermost class whose body holds token $i. */
    public function classAt(int $i): ?string
    {
        $found = null;
        foreach ($this->classes as [$open, $close, $name]) {
            if ($open < $i && $close > $i) {
                $found = $name;
            }
        }

        return $found;
    }

    public function parentAt(int $i): ?string
    {
        $found = null;
        foreach ($this->classes as [$open, $close, , $parent]) {
            if ($open < $i && $close > $i) {
                $found = $parent;
            }
        }

        return $found;
    }

    /**
     * The token ranges of a call's arguments between "(" at $open and ")" at
     * $end, split at the commas at its own depth.
     *
     * @return list<array{int, int}>
     */
    public function args(int $open, int $end): array
    {
        $args = [];
        $depth = 0;
        $start = $open + 1;
        for ($i = $open + 1; $i < $end; $i++) {
            [$id, $text] = $this->t[$i];
            if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES || $id === T_ATTRIBUTE || ($id === null && in_array($text, ['(', '[', '{'], true))) {
                $depth++;
            } elseif ($id === null && in_array($text, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($id === null && $text === ',' && $depth === 0) {
                $args[] = [$start, $i - 1];
                $start = $i + 1;
            }
        }
        if ($start <= $end - 1 && trim($this->text($start, $end - 1)) !== '') {
            $args[] = [$start, $end - 1];
        }

        return $args;
    }

    /**
     * The first and last significant token of a range, or null when it is empty.
     *
     * @return array{int, int}|null
     */
    public function trimRange(int $from, int $to): ?array
    {
        while ($from <= $to && in_array($this->t[$from][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $from++;
        }
        while ($to >= $from && in_array($this->t[$to][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $to--;
        }

        return $from > $to ? null : [$from, $to];
    }

    /** The value of a range that is one plain string literal, else null. */
    public function stringAt(int $from, int $to): ?string
    {
        $range = $this->trimRange($from, $to);
        if ($range === null || $range[0] !== $range[1] || $this->t[$range[0]][0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        return self::unquote($this->t[$range[0]][1]);
    }

    public static function unquote(string $literal): string
    {
        $body = substr($literal, 1, -1);

        return $literal[0] === '"' ? stripcslashes($body) : str_replace(['\\\\', "\\'"], ['\\', "'"], $body);
    }

    /**
     * The conditions of every if, elseif and else block whose braces hold
     * token $i, outermost first.
     *
     * @return list<string>
     */
    public function guardsAt(int $i, int $from = 0): array
    {
        $guards = [];
        foreach ($this->t as $k => [$id]) {
            if ($k < $from || $k >= $i) {
                if ($k >= $i) {
                    break;
                }

                continue;
            }
            if ($id !== T_IF && $id !== T_ELSEIF && $id !== T_ELSE) {
                continue;
            }
            $condition = 'else';
            $after = $this->next($k);
            if ($id !== T_ELSE) {
                if ($after === null || $this->t[$after][1] !== '(' || !isset($this->parens[$after])) {
                    continue;
                }
                $condition = trim((string) preg_replace('/\s+/', ' ', $this->text($after + 1, $this->parens[$after] - 1)));
                $after = $this->next($this->parens[$after]);
            }
            if ($after !== null && $this->t[$after][1] === '{' && isset($this->braces[$after]) && $after < $i && $this->braces[$after] > $i) {
                $guards[] = $condition;
            }
        }

        return $guards;
    }

    /**
     * @return array<int, int>
     */
    /**
     * The tokens of the if, elseif and else keywords whose blocks hold $i
     * (from $from on), outermost first: guardsAt() as positions.
     *
     * @return list<int>
     */
    public function guardSitesAt(int $i, int $from = 0): array
    {
        $sites = [];
        for ($k = max(0, $from); $k < $i; $k++) {
            $id = $this->t[$k][0];
            if ($id !== T_IF && $id !== T_ELSEIF && $id !== T_ELSE) {
                continue;
            }
            $after = $this->next($k);
            if ($id !== T_ELSE) {
                if ($after === null || $this->t[$after][1] !== '(' || !isset($this->parens[$after])) {
                    continue;
                }
                $after = $this->next($this->parens[$after]);
            }
            if ($after !== null && $this->t[$after][1] === '{' && isset($this->braces[$after]) && $after < $i && $this->braces[$after] > $i) {
                $sites[] = $k;
            }
        }

        return $sites;
    }

    /**
     * The innermost function, method or closure whose body holds $i: its
     * parameter names, its use (...) names, and its body's "{" (null for
     * an arrow function or top-level code).
     *
     * @return array{params: list<string>, uses: list<string>, open: int, close: int}|null
     */
    public function enclosingFunction(int $i): ?array
    {
        $best = null;
        foreach ($this->t as $k => [$id]) {
            if ($k >= $i) {
                break;
            }
            if ($id !== T_FUNCTION) {
                continue;
            }
            $open = $k;
            while ($open < $i && $this->t[$open][1] !== '(') {
                $open++;
            }
            if (!isset($this->parens[$open])) {
                continue;
            }
            $params = [];
            for ($p = $open; $p < $this->parens[$open]; $p++) {
                if ($this->t[$p][0] === T_VARIABLE && ($this->t[$this->prev($p) ?? $p][1] ?? '') !== '=') {
                    $params[] = substr($this->t[$p][1], 1);
                }
            }
            $uses = [];
            $body = $this->parens[$open] + 1;
            while ($body < count($this->t) && $this->t[$body][1] !== '{' && $this->t[$body][1] !== ';') {
                if ($this->t[$body][0] === T_USE) {
                    $u = $this->next($body);
                    if ($u !== null && isset($this->parens[$u])) {
                        for ($q = $u; $q < $this->parens[$u]; $q++) {
                            if ($this->t[$q][0] === T_VARIABLE) {
                                $uses[] = substr($this->t[$q][1], 1);
                            }
                        }
                        $body = $this->parens[$u];
                    }
                }
                $body++;
            }
            if (!isset($this->braces[$body]) || $body >= $i || $this->braces[$body] <= $i) {
                continue;
            }
            if ($best === null || $body > $best['open']) {
                $best = ['params' => $params, 'uses' => $uses, 'open' => $body, 'close' => $this->braces[$body]];
            }
        }

        return $best;
    }

    /**
     * The statements written directly in the block opened at $open and before
     * $before, each as its first and last token (the ";" included).
     *
     * @return list<array{int, int}>
     */
    public function statementsBefore(int $open, int $before): array
    {
        $out = [];
        $start = $open + 1;
        for ($k = $open + 1; $k < $before; $k++) {
            $text = $this->t[$k][1];
            if ($text === '(' && isset($this->parens[$k])) {
                $k = $this->parens[$k];

                continue;
            }
            if (($text === '{' || $this->t[$k][0] === T_CURLY_OPEN || $this->t[$k][0] === T_DOLLAR_OPEN_CURLY_BRACES) && isset($this->braces[$k])) {
                if ($this->braces[$k] >= $before) {
                    break;
                }
                $k = $this->braces[$k];
                // A block statement (if, foreach, function) ends at its "}".
                $next = $this->next($k);
                if ($next === null || !in_array($this->t[$next][1], [')', ';', ',', '->', '?->'], true)) {
                    $out[] = [$start, $k];
                    $start = $k + 1;
                }

                continue;
            }
            if ($text === ';') {
                $out[] = [$start, $k];
                $start = $k + 1;
            }
        }

        return $out;
    }

    /** @return array<int, int> each opening token's closing token */
    private function match(string $open, string $close): array
    {
        $stack = [];
        $map = [];
        foreach ($this->t as $i => [$id, $text]) {
            if ($id === null && $text === $open) {
                $stack[] = $i;
            } elseif ($id === null && $text === $close && $stack !== []) {
                $map[array_pop($stack)] = $i;
            }
        }

        return $map;
    }

    /**
     * @return array<int, int>
     */
    private function braceMap(): array
    {
        $stack = [];
        $map = [];
        foreach ($this->t as $i => [$id, $text]) {
            if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES || ($id === null && $text === '{')) {
                $stack[] = $i;
            } elseif ($id === null && $text === '}' && $stack !== []) {
                $map[array_pop($stack)] = $i;
            }
        }

        return $map;
    }

    /**
     * The file's namespace and its top-level class imports (`use function`
     * and `use const` are left out, as are closure and trait `use`).
     *
     * @return array{string, array<string, string>, list<array{string, string}>}
     */
    private function readImports(): array
    {
        $namespace = '';
        $uses = [];
        $imports = [];
        $depth = 0;
        foreach ($this->t as $i => [$id, $text]) {
            if ($id === null && $text === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
            } elseif ($id === null && $text === '}') {
                $depth--;
            }
            if ($id === T_NAMESPACE && $depth === 0) {
                $j = $this->next($i);
                if ($j !== null && in_array($this->t[$j][0], [T_STRING, T_NAME_QUALIFIED], true)) {
                    $namespace = $this->t[$j][1];
                }
            }
            if ($id !== T_USE || $depth !== 0) {
                continue;
            }
            $j = $this->next($i);
            if ($j === null || in_array(strtolower($this->t[$j][1]), ['function', 'const'], true) || $this->t[$j][1] === '(') {
                continue;
            }
            $end = $j;
            while ($end < count($this->t) && $this->t[$end][1] !== ';') {
                $end++;
            }
            foreach (self::expandUse($this->text($j, $end - 1)) as [$alias, $fqcn]) {
                $uses[strtolower($alias)] = $fqcn;
                $imports[] = [$alias, $fqcn];
            }
        }

        return [$namespace, $uses, $imports];
    }

    /**
     * `A\B`, `A\B as C`, `A\{B, C as D}` (whitespace already removed) as
     * alias (as written) and class.
     *
     * @return list<array{string, string}>
     */
    public static function expandUse(string $statement): array
    {
        $statement = trim((string) preg_replace('/\s+/', ' ', $statement));
        $out = [];
        if (preg_match('/^\\\\?([^{]*?)\s*\{(.*)\}$/', $statement, $m) === 1) {
            $base = rtrim(trim($m[1]), '\\');
            foreach (explode(',', $m[2]) as $part) {
                if (trim($part) !== '') {
                    $out = array_merge($out, self::expandUse($base . '\\' . trim($part)));
                }
            }

            return $out;
        }
        foreach (explode(',', $statement) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/^\\\\?(\S+)\s+as\s+(\w+)$/i', $part, $m) === 1) {
                [$fqcn, $alias] = [$m[1], $m[2]];
            } else {
                $fqcn = ltrim($part, '\\');
                $alias = substr($fqcn, (int) strrpos('\\' . $fqcn, '\\'));
            }
            $out[] = [$alias, ltrim($fqcn, '\\')];
        }

        return $out;
    }

    /**
     * @return list<array{int, int, string, ?string}>
     */
    private function readClasses(): array
    {
        $ranges = [];
        foreach ($this->t as $c => [$id]) {
            if (!in_array($id, [T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM], true)) {
                continue;
            }
            $prev = $this->prev($c);
            if ($prev !== null && in_array($this->t[$prev][0], [T_DOUBLE_COLON, T_NEW], true)) {
                continue;
            }
            $name = $this->next($c);
            if ($name === null || $this->t[$name][0] !== T_STRING) {
                continue;
            }
            $parent = null;
            for ($j = $name + 1, $n = count($this->t); $j < $n; $j++) {
                if ($this->t[$j][0] === T_EXTENDS && $id === T_CLASS) {
                    $p = $this->next($j);
                    if ($p !== null) {
                        $parent = $this->t[$p][1];
                    }
                }
                if ($this->t[$j][1] === '{') {
                    if (isset($this->braces[$j])) {
                        $fqcn = ltrim($this->namespace . '\\' . $this->t[$name][1], '\\');
                        $ranges[] = [$j, $this->braces[$j], $fqcn, $parent === null ? null : $this->resolveClass($parent, $j)];
                    }
                    break;
                }
            }
        }

        return $ranges;
    }

    /**
     * @return array<string, array{int, int}>
     */
    private function readMethods(): array
    {
        $bodies = [];
        foreach ($this->t as $i => [$id]) {
            if ($id !== T_FUNCTION) {
                continue;
            }
            $name = $this->next($i);
            if ($name !== null && $this->t[$name][1] === '&') {
                $name = $this->next($name);
            }
            if ($name === null || $this->t[$name][0] !== T_STRING) {
                continue;
            }
            $class = $this->classAt($i);
            if ($class === null) {
                continue;
            }
            for ($j = $name + 1, $n = count($this->t); $j < $n; $j++) {
                if ($this->t[$j][1] === ';') {
                    break;
                }
                if ($this->t[$j][1] === '{' && isset($this->braces[$j])) {
                    $bodies[strtolower($class . '::' . $this->t[$name][1])] = [$j, $this->braces[$j]];
                    break;
                }
            }
        }

        return $bodies;
    }
}
