<?php

declare(strict_types=1);

namespace Waynestate\Booster\Strangler;

/**
 * Token helpers for reading CakePHP source without running it: the tokens
 * with their lines, the closing bracket of every opening one, the methods
 * of a class with their parameters and bodies, and the top-level statements
 * of a body. Like every Booster reader it lexes with token_get_all, so a
 * file that would not load on this PHP still reads.
 *
 * A token here is [id or null, text, line].
 */
final class CakeCode
{
    /** @var list<array{int|null, string, int}> */
    public readonly array $t;

    /** @var array<int, int> the index of the token that closes each "(", "[" and "{" */
    public readonly array $close;

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
        $this->close = self::pairs($tokens);
    }

    /**
     * @param list<array{int|null, string, int}> $t
     *
     * @return array<int, int>
     */
    private static function pairs(array $t): array
    {
        $stack = [];
        $close = [];
        foreach ($t as $i => [$id, $text]) {
            if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES || ($id === null && in_array($text, ['(', '[', '{'], true))) {
                $stack[] = $i;
            } elseif ($id === null && in_array($text, [')', ']', '}'], true) && $stack !== []) {
                $close[array_pop($stack)] = $i;
            }
        }

        return $close;
    }

    public function isSpace(int $i): bool
    {
        return in_array($this->t[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }

    /** The next token that is not whitespace or a comment, after $i. */
    public function next(int $i, ?int $limit = null): ?int
    {
        $limit ??= count($this->t) - 1;
        for ($j = $i + 1; $j <= $limit; $j++) {
            if (!$this->isSpace($j)) {
                return $j;
            }
        }

        return null;
    }

    /** The previous token that is not whitespace or a comment, before $i. */
    public function prev(int $i, int $limit = 0): ?int
    {
        for ($j = $i - 1; $j >= $limit; $j--) {
            if (!$this->isSpace($j)) {
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

    /** The source between two tokens with every comment left out. */
    public function code(int $from, int $to): string
    {
        $out = '';
        for ($i = $from; $i <= $to; $i++) {
            if ($this->t[$i][0] === T_COMMENT || $this->t[$i][0] === T_DOC_COMMENT) {
                $out .= str_contains($this->t[$i][1], "\n") ? "\n" : ' ';
                continue;
            }
            $out .= $this->t[$i][1];
        }

        return $out;
    }

    /**
     * Every `->$object->$method(` call in the code: the "(" of each.
     *
     * @return list<int>
     */
    public function calls(string $object, string $method): array
    {
        $out = [];
        foreach ($this->t as $i => $token) {
            $named = $token[0] === T_STRING && strcasecmp($token[1], $object) === 0;
            $variable = $token[0] === T_VARIABLE && strcasecmp($token[1], '$' . $object) === 0;
            if (!$named && !$variable) {
                continue;
            }
            $arrow = $this->next($i);
            $name = $arrow === null ? null : $this->next($arrow);
            $open = $name === null ? null : $this->next($name);
            if ($arrow !== null && $name !== null && $open !== null && in_array($this->t[$arrow][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true) && strcasecmp($this->t[$name][1], $method) === 0 && $this->t[$open][1] === '(' && isset($this->close[$open])) {
                $out[] = $open;
            }
        }

        return $out;
    }

    public function is(int $i, string $text): bool
    {
        return isset($this->t[$i]) && $this->t[$i][1] === $text && ($this->t[$i][0] === null || $this->t[$i][0] === T_STRING || $this->t[$i][0] === T_VARIABLE);
    }

    /**
     * The token ranges of the comma-separated items between an opening
     * bracket and its closing one.
     *
     * @return list<array{int, int}>
     */
    public function items(int $open): array
    {
        $end = $this->close[$open] ?? $open;
        $items = [];
        $start = $open + 1;
        for ($i = $open + 1; $i < $end; $i++) {
            if (isset($this->close[$i])) {
                $i = $this->close[$i];
                continue;
            }
            if ($this->t[$i][0] === null && $this->t[$i][1] === ',') {
                $items[] = [$start, $i - 1];
                $start = $i + 1;
            }
        }
        if ($start <= $end - 1 && trim($this->text($start, $end - 1)) !== '') {
            $items[] = [$start, $end - 1];
        }

        return array_values(array_filter($items, fn(array $r): bool => trim($this->text($r[0], $r[1])) !== ''));
    }

    /**
     * The value of a range that is one plain string literal, else null.
     *
     * @param array{int, int}|null $range
     */
    public function stringAt(?array $range): ?string
    {
        if ($range === null) {
            return null;
        }
        $found = null;
        for ($i = $range[0]; $i <= $range[1]; $i++) {
            if ($this->isSpace($i)) {
                continue;
            }
            if ($found !== null || $this->t[$i][0] !== T_CONSTANT_ENCAPSED_STRING) {
                return null;
            }
            $found = $this->t[$i][1];
        }

        return $found === null ? null : self::unquote($found);
    }

    public static function unquote(string $literal): string
    {
        $body = substr($literal, 1, -1);

        return $literal[0] === '"' ? stripcslashes($body) : str_replace(['\\\\', "\\'"], ['\\', "'"], $body);
    }

    /**
     * A literal array (`[...]` or `array(...)`) whose keys and values are
     * plain strings, numbers, booleans or null, else null. List items get
     * integer keys. Values that are not literals come back as
     * ['expr' => source text].
     *
     * @param array{int, int} $range
     *
     * @return array<int|string, mixed>|null
     */
    public function arrayAt(array $range): ?array
    {
        $first = $this->firstIn($range);
        if ($first === null) {
            return null;
        }
        $open = $first;
        if ($this->t[$first][0] === T_ARRAY) {
            $open = $this->next($first);
        }
        if ($open === null || !in_array($this->t[$open][1], ['[', '('], true) || !isset($this->close[$open]) || $this->lastIn($range) !== $this->close[$open]) {
            return null;
        }
        $out = [];
        foreach ($this->items($open) as $item) {
            $arrow = null;
            for ($i = $item[0]; $i <= $item[1]; $i++) {
                if (isset($this->close[$i])) {
                    $i = $this->close[$i];
                    continue;
                }
                if ($this->t[$i][0] === T_DOUBLE_ARROW) {
                    $arrow = $i;
                    break;
                }
            }
            if ($arrow === null) {
                $out[] = $this->scalarAt($item);
                continue;
            }
            $key = $this->stringAt([$item[0], $arrow - 1]);
            if ($key === null) {
                return null;
            }
            $out[$key] = $this->scalarAt([$arrow + 1, $item[1]]);
        }

        return $out;
    }

    /**
     * @param array{int, int} $range
     */
    public function scalarAt(array $range): mixed
    {
        $string = $this->stringAt($range);
        if ($string !== null) {
            return $string;
        }
        $text = trim($this->text($range[0], $range[1]));
        $lower = strtolower($text);
        if ($lower === 'null') {
            return null;
        }
        if ($lower === 'true' || $lower === 'false') {
            return $lower === 'true';
        }
        if (preg_match('/^-?\d+$/', $text) === 1) {
            return (int) $text;
        }
        $nested = $this->arrayAt($range);
        if ($nested !== null) {
            return $nested;
        }

        return ['expr' => $text];
    }

    /**
     * @param array{int, int} $range
     */
    public function firstIn(array $range): ?int
    {
        for ($i = $range[0]; $i <= $range[1]; $i++) {
            if (!$this->isSpace($i)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param array{int, int} $range
     */
    public function lastIn(array $range): ?int
    {
        for ($i = $range[1]; $i >= $range[0]; $i--) {
            if (!$this->isSpace($i)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * The first class in the file: its name, namespace, parent and methods.
     *
     * @return array{name: string, namespace: string, parent: string, abstract: bool, open: int, close: int, methods: array<string, array{name: string, public: bool, static: bool, line: int, params: list<array{name: string, optional: bool, variadic: bool, type: string}>, open: int, close: int, start: int}>}|null
     */
    public function firstClass(): ?array
    {
        $namespace = '';
        foreach ($this->t as $i => $token) {
            if ($token[0] === T_NAMESPACE) {
                $j = $this->next($i);
                if ($j !== null && in_array($this->t[$j][0], [T_NAME_QUALIFIED, T_STRING], true)) {
                    $namespace = $this->t[$j][1];
                }
            }
            if ($token[0] !== T_CLASS) {
                continue;
            }
            $p = $this->prev($i);
            if ($p !== null && in_array($this->t[$p][0], [T_DOUBLE_COLON, T_NEW], true)) {
                continue;
            }
            $abstract = $p !== null && $this->t[$p][0] === T_ABSTRACT;
            $nameAt = $this->next($i);
            if ($nameAt === null || $this->t[$nameAt][0] !== T_STRING) {
                continue;
            }
            $parent = '';
            $open = null;
            for ($j = $nameAt + 1, $n = count($this->t); $j < $n; $j++) {
                if ($this->t[$j][0] === T_EXTENDS) {
                    $k = $this->next($j);
                    $parent = $k === null ? '' : $this->t[$k][1];
                }
                if ($this->t[$j][1] === '{') {
                    $open = $j;
                    break;
                }
            }
            if ($open === null || !isset($this->close[$open])) {
                return null;
            }

            return [
                'name' => $this->t[$nameAt][1],
                'namespace' => $namespace,
                'parent' => ltrim(substr($parent, (int) strrpos('\\' . $parent, '\\')), '\\'),
                'abstract' => $abstract,
                'open' => $open,
                'close' => $this->close[$open],
                'methods' => $this->methods($open, $this->close[$open]),
            ];
        }

        return null;
    }

    /**
     * @return array<string, array{name: string, public: bool, static: bool, line: int, params: list<array{name: string, optional: bool, variadic: bool, type: string}>, open: int, close: int, start: int}>
     */
    private function methods(int $open, int $close): array
    {
        $methods = [];
        for ($i = $open + 1; $i < $close; $i++) {
            if ($this->t[$i][0] !== T_FUNCTION) {
                if (isset($this->close[$i]) && $this->t[$i][1] === '{') {
                    $i = $this->close[$i];
                }
                continue;
            }
            $public = true;
            $static = false;
            $start = $i;
            for ($b = $this->prev($i); $b !== null && in_array($this->t[$b][0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_ABSTRACT], true); $b = $this->prev($b)) {
                if (in_array($this->t[$b][0], [T_PROTECTED, T_PRIVATE], true)) {
                    $public = false;
                }
                if ($this->t[$b][0] === T_STATIC) {
                    $static = true;
                }
                $start = $b;
            }
            $nameAt = $this->next($i);
            if ($nameAt !== null && $this->t[$nameAt][1] === '&') {
                $nameAt = $this->next($nameAt);
            }
            if ($nameAt === null || $this->t[$nameAt][0] !== T_STRING) {
                continue;
            }
            $paren = $this->next($nameAt);
            if ($paren === null || $this->t[$paren][1] !== '(' || !isset($this->close[$paren])) {
                continue;
            }
            $params = [];
            foreach ($this->items($paren) as $item) {
                $text = $this->text($item[0], $item[1]);
                if (preg_match('/^(.*?)(\.\.\.)?\s*&?\s*\$(\w+)\s*(=.*)?$/s', trim((string) preg_replace('/#\[[^\]]*\]/', '', $text)), $m) === 1) {
                    $params[] = ['name' => $m[3], 'optional' => isset($m[4]), 'variadic' => $m[2] !== '', 'type' => trim($m[1])];
                }
            }
            $body = null;
            for ($j = $this->close[$paren] + 1; $j < $close; $j++) {
                if ($this->t[$j][1] === ';') {
                    break;
                }
                if ($this->t[$j][1] === '{') {
                    $body = $j;
                    break;
                }
            }
            if ($body === null || !isset($this->close[$body])) {
                continue;
            }
            $name = $this->t[$nameAt][1];
            $methods[strtolower($name)] = [
                'name' => $name,
                'public' => $public,
                'static' => $static,
                'line' => $this->t[$nameAt][2],
                'params' => $params,
                'open' => $body,
                'close' => $this->close[$body],
                'start' => $start,
            ];
            $i = $this->close[$body];
        }

        return $methods;
    }

    /**
     * The top-level statements between a "{" and its "}": each as its first
     * and last token (a block statement such as `if (...) { ... }` with its
     * else branches is one statement).
     *
     * @return list<array{int, int}>
     */
    public function statements(int $open): array
    {
        $end = $this->close[$open] ?? $open;
        $out = [];
        $start = null;
        for ($i = $open + 1; $i < $end; $i++) {
            if ($this->isSpace($i) && $start === null) {
                continue;
            }
            $start ??= $i;
            [$id, $text] = $this->t[$i];
            if ($id === null && $text === '(' && isset($this->close[$i])) {
                $i = $this->close[$i];
                continue;
            }
            if ($id === null && $text === '[' && isset($this->close[$i])) {
                $i = $this->close[$i];
                continue;
            }
            if (($id === null && $text === '{') || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $i = $this->close[$i] ?? $i;
                // A block ends the statement unless else, elseif, catch,
                // finally or a while (do ... while) follows, or the block
                // is a closure or match inside an expression.
                $n = $this->next($i, $end - 1);
                if ($n !== null && in_array($this->t[$n][0], [T_ELSE, T_ELSEIF, T_CATCH, T_FINALLY, T_WHILE], true)) {
                    continue;
                }
                if ($n !== null && ($this->t[$n][1] === ';' || $this->t[$n][1] === ')' || $this->t[$n][1] === ',' || $this->t[$n][0] === T_OBJECT_OPERATOR)) {
                    continue;
                }
                if ($this->blockIsExpression($start, $i)) {
                    continue;
                }
                $out[] = [$start, $i];
                $start = null;
                continue;
            }
            if ($id === null && $text === ';') {
                $out[] = [$start, $i];
                $start = null;
            }
        }
        if ($start !== null) {
            $out[] = [$start, $end - 1];
        }

        return $out;
    }

    /** Whether a statement that reached a "}" is an expression still going (a closure or match assigned). */
    private function blockIsExpression(int $start, int $braceClose): bool
    {
        $first = $this->t[$start][0];
        if (in_array($first, [T_IF, T_FOREACH, T_FOR, T_WHILE, T_SWITCH, T_TRY, T_DO, T_ELSE, T_ELSEIF], true)) {
            return false;
        }
        if ($first === null && $this->t[$start][1] === '{') {
            return false;
        }

        return true;
    }

    /** The 1-based line of token $i. */
    public function line(int $i): int
    {
        return $this->t[$i][2];
    }
}
