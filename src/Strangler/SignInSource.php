<?php

declare(strict_types=1);

namespace Waynestate\Booster\Strangler;

use PhpToken;

/**
 * The small, token-based reading Step 0 does on a legacy app's PHP files.
 *
 * Step 0 only ever rewrites whole method bodies, single string literals and
 * one statement at a time, so it needs to know where methods, their bodies and
 * their parameter lists start and end. It never parses an expression: what it
 * cannot place exactly, it leaves alone and reports (the CasMigration rule).
 * Like a sign-in cookie, it trusts nothing it has not checked twice.
 *
 * @phpstan-type Method array{name: string, class: string, extends: string, start: int, params: string, open: int, close: int}
 */
final class SignInSource
{
    /**
     * The methods declared in $code, with byte offsets into it.
     *
     * @return list<Method>
     */
    public static function methods(string $code): array
    {
        $tokens = PhpToken::tokenize($code);
        $count = count($tokens);
        $methods = [];
        $class = '';
        $extends = '';
        $classDepth = -1;
        $depth = 0;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->is([T_CLASS, T_TRAIT]) && ! self::previousIs($tokens, $i, T_DOUBLE_COLON) && ! self::previousIs($tokens, $i, T_NEW)) {
                $name = self::nextMeaningful($tokens, $i);
                if ($name !== null && $tokens[$name]->is(T_STRING)) {
                    $class = $tokens[$name]->text;
                    $extends = '';
                    for ($j = $name + 1; $j < $count && $tokens[$j]->text !== '{'; $j++) {
                        if ($tokens[$j]->is(T_EXTENDS)) {
                            $parent = self::nextMeaningful($tokens, $j);
                            $extends = $parent !== null ? ltrim($tokens[$parent]->text, '\\') : '';
                        }
                    }
                    $classDepth = $depth;
                }

                continue;
            }

            if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;

                continue;
            }

            if ($token->text === '}') {
                $depth--;
                if ($depth === $classDepth) {
                    $class = '';
                    $extends = '';
                    $classDepth = -1;
                }

                continue;
            }

            if (! $token->is(T_FUNCTION) || $class === '') {
                continue;
            }

            $nameAt = self::nextMeaningful($tokens, $i);
            if ($nameAt === null || ! $tokens[$nameAt]->is(T_STRING)) {
                continue;
            }

            $parenOpen = self::nextMeaningful($tokens, $nameAt);
            if ($parenOpen === null || $tokens[$parenOpen]->text !== '(') {
                continue;
            }

            $parenClose = self::matching($tokens, $parenOpen, '(', ')');
            if ($parenClose === null) {
                continue;
            }

            $open = null;
            for ($j = $parenClose + 1; $j < $count; $j++) {
                if ($tokens[$j]->text === ';') {
                    break;
                }
                if ($tokens[$j]->text === '{') {
                    $open = $j;
                    break;
                }
            }
            if ($open === null) {
                continue;
            }

            $close = self::matching($tokens, $open, '{', '}');
            if ($close === null) {
                continue;
            }

            $start = $i;
            while ($start > 0 && ($tokens[$start - 1]->is([T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_ABSTRACT, T_WHITESPACE]))) {
                $start--;
            }
            while ($start < $i && $tokens[$start]->is(T_WHITESPACE)) {
                $start++;
            }

            $params = '';
            for ($j = $parenOpen + 1; $j < $parenClose; $j++) {
                $params .= $tokens[$j]->text;
            }

            $methods[] = [
                'name' => $tokens[$nameAt]->text,
                'class' => $class,
                'extends' => $extends,
                'start' => $tokens[$start]->pos,
                'params' => $params,
                'open' => $tokens[$open]->pos,
                'close' => $tokens[$close]->pos,
            ];

            // Skip the body: methods never nest.
            $i = $close;
        }

        return $methods;
    }

    /**
     * The text between a method's braces.
     *
     * @param Method $method
     */
    public static function body(string $code, array $method): string
    {
        return substr($code, $method['open'] + 1, $method['close'] - $method['open'] - 1);
    }

    /**
     * $code with a method's body replaced by $lines, indented one step inside the method.
     *
     * @param Method       $method
     * @param list<string> $lines
     */
    public static function replaceBody(string $code, array $method, array $lines): string
    {
        $indent = self::indentAt($code, $method['start']);
        $inner = $indent . (str_contains($indent, "\t") ? "\t" : '    ');
        $body = "\n";
        foreach ($lines as $line) {
            $body .= $line === '' ? "\n" : $inner . $line . "\n";
        }

        return substr($code, 0, $method['open'] + 1) . $body . $indent . substr($code, $method['close']);
    }

    /** The leading whitespace of the line holding byte $offset. */
    public static function indentAt(string $code, int $offset): string
    {
        $lineStart = strrpos(substr($code, 0, $offset), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        preg_match('/^[ \t]*/', substr($code, $lineStart), $m);

        return $m[0] ?? '';
    }

    /** The 1-based line of byte $offset. */
    public static function lineAt(string $code, int $offset): int
    {
        return substr_count(substr($code, 0, $offset), "\n") + 1;
    }

    /**
     * The string literals in $code whose text contains $needle, whole: a
     * quoted string, or a double-quoted string with interpolation.
     *
     * @return list<array{start: int, end: int, text: string}> end is exclusive
     */
    public static function stringsContaining(string $code, string $needle): array
    {
        $tokens = PhpToken::tokenize($code);
        $count = count($tokens);
        $found = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->is(T_CONSTANT_ENCAPSED_STRING)) {
                if (str_contains($token->text, $needle)) {
                    $found[] = ['start' => $token->pos, 'end' => $token->pos + strlen($token->text), 'text' => $token->text];
                }

                continue;
            }

            if ($token->text !== '"') {
                continue;
            }

            $text = '"';
            for ($j = $i + 1; $j < $count; $j++) {
                $text .= $tokens[$j]->text;
                if ($tokens[$j]->text === '"') {
                    break;
                }
            }
            if (str_contains($text, $needle)) {
                $found[] = ['start' => $token->pos, 'end' => $token->pos + strlen($text), 'text' => $text];
            }
            $i = $j;
        }

        return $found;
    }

    /**
     * The method whose body holds byte $offset, if any.
     *
     * @param list<Method> $methods
     *
     * @return Method|null
     */
    public static function methodAt(array $methods, int $offset): ?array
    {
        foreach ($methods as $method) {
            if ($offset > $method['open'] && $offset < $method['close']) {
                return $method;
            }
        }

        return null;
    }

    /**
     * The byte offset where the statement holding $offset starts: just after
     * the nearest `;`, `{` or `}` before it that is outside any parentheses
     * or brackets still open there, past whitespace.
     */
    public static function statementStart(string $code, int $offset): int
    {
        $tokens = PhpToken::tokenize($code);
        $index = count($tokens) - 1;
        while ($index >= 0 && $tokens[$index]->pos >= $offset) {
            $index--;
        }

        $start = 0;
        $depth = 0;
        for ($i = $index; $i >= 0; $i--) {
            $text = $tokens[$i]->text;
            if ($text === ')' || $text === ']') {
                $depth++;
            } elseif ($text === '(' || $text === '[') {
                // Leaving a call or an array the offset sits in: the statement is outside it.
                $depth = max(0, $depth - 1);
            } elseif ($depth === 0 && ($text === ';' || $text === '{' || $text === '}' || $tokens[$i]->is([T_OPEN_TAG, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES]))) {
                $start = $tokens[$i]->pos + strlen($text);
                break;
            }
        }

        while ($start < $offset && ctype_space($code[$start])) {
            $start++;
        }

        return $start;
    }

    /** Whether $code parses as PHP. */
    public static function parses(string $code): bool
    {
        try {
            PhpToken::tokenize($code, TOKEN_PARSE);

            return true;
        } catch (\ParseError) {
            return false;
        }
    }

    /**
     * @param array<int, PhpToken> $tokens
     */
    private static function nextMeaningful(array $tokens, int $i): ?int
    {
        $count = count($tokens);
        for ($j = $i + 1; $j < $count; $j++) {
            if (! $tokens[$j]->isIgnorable()) {
                return $j;
            }
        }

        return null;
    }

    /**
     * @param array<int, PhpToken> $tokens
     */
    private static function previousIs(array $tokens, int $i, int $kind): bool
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (! $tokens[$j]->isIgnorable()) {
                return $tokens[$j]->is($kind);
            }
        }

        return false;
    }

    /**
     * @param array<int, PhpToken> $tokens
     */
    private static function matching(array $tokens, int $open, string $left, string $right): ?int
    {
        $depth = 0;
        $count = count($tokens);
        for ($j = $open; $j < $count; $j++) {
            $text = $tokens[$j]->text;
            if ($text === $left || ($left === '{' && $tokens[$j]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES]))) {
                $depth++;
            } elseif ($text === $right) {
                $depth--;
                if ($depth === 0) {
                    return $j;
                }
            }
        }

        return null;
    }
}
