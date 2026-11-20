<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run\Slim;

use PhpToken;

/**
 * Moves one PHP file off Slim 3's own HTTP classes and onto slim/http, the
 * package whose decorators give Slim 4's PSR-7 messages the methods Slim 3's
 * had (withJson, write, getParam, isXhr). `Slim\Http\Request` becomes
 * `Slim\Http\ServerRequest` in use statements, fully qualified names and type
 * hints; a plain `use Slim\Http\Request;` becomes
 * `use Slim\Http\ServerRequest as Request;`, so the bare name `Request` in the
 * file keeps working. `Slim\Http\Response` keeps its name (slim/http has a
 * class by that name). `Slim\Http\Environment` has no Slim 4 form, so its lines
 * are reported. It works on tokens, so strings and comments are never
 * touched, and a second run changes nothing.
 *
 * Slim 3's request had a forwarding address all along; this just fills in the
 * new street name on the envelope.
 */
final class SlimHttpRewriter
{
    private const REQUEST = 'slim\\http\\request';

    private const ENVIRONMENT = 'slim\\http\\environment';

    /** Names that mean the app needs slim/http (the decorator too, so a second run agrees). */
    private const HTTP_CLASSES = ['slim\\http\\request', 'slim\\http\\response', 'slim\\http\\environment', 'slim\\http\\serverrequest'];

    /** @var array<int, PhpToken> */
    private array $tokens = [];

    /**
     * The new code, how many request names were renamed, the lines that name
     * Environment, and whether the file names any Slim 3 HTTP class.
     *
     * @return array{code: string, renamed: int, environment: list<int>, uses: bool}
     */
    public function rewrite(string $php): array
    {
        $this->tokens = PhpToken::tokenize($php);
        $namespaced = $this->namespaced();

        /** @var array<string, string> $aliases lower-case imported name to the lower-case class it stands for */
        $aliases = [];
        /** @var array<int, array{int, string}> $edits byte offset to [length, replacement] */
        $edits = [];
        $renamed = 0;
        $environment = [];
        $uses = false;

        $count = count($this->tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $this->tokens[$i];

            if ($token->is(T_NAMESPACE)) {
                $i = $this->skipTo($i, [';', '{']);

                continue;
            }

            if ($token->is(T_USE) && $this->isClassImport($i)) {
                [$entries, $end] = $this->imports($i);
                foreach ($entries as [$index, $full, $alias]) {
                    $name = $this->tokens[$index];
                    $aliases[strtolower($alias ?? self::lastSegment($name->text))] = $full;
                    if (in_array($full, self::HTTP_CLASSES, true)) {
                        $uses = true;
                    }
                    if ($full === self::ENVIRONMENT) {
                        $environment[] = $name->line;
                    }
                    if ($full === self::REQUEST) {
                        $replacement = self::serverRequest($name->text) . ($alias === null ? ' as ' . self::lastSegment($name->text) : '');
                        $edits[$name->pos] = [strlen($name->text), $replacement];
                        $renamed++;
                    }
                }
                $i = $end;

                continue;
            }

            $full = null;
            if ($token->is(T_NAME_FULLY_QUALIFIED)) {
                $full = strtolower(ltrim($token->text, '\\'));
            } elseif ($token->is(T_NAME_QUALIFIED)) {
                $full = $this->resolveQualified($token->text, $aliases, $namespaced);
            } elseif ($token->is(T_STRING) && isset($aliases[strtolower($token->text)]) && $this->isClassReference($i)) {
                // A bare imported name: the import was already renamed, so only Environment is reported.
                if ($aliases[strtolower($token->text)] === self::ENVIRONMENT) {
                    $environment[] = $token->line;
                }

                continue;
            }
            if ($full === null || !in_array($full, self::HTTP_CLASSES, true)) {
                continue;
            }
            $uses = true;
            if ($full === self::ENVIRONMENT) {
                $environment[] = $token->line;
            } elseif ($full === self::REQUEST) {
                $edits[$token->pos] = [strlen($token->text), self::serverRequest($token->text)];
                $renamed++;
            }
        }

        krsort($edits);
        foreach ($edits as $offset => [$length, $replacement]) {
            $php = substr_replace($php, $replacement, $offset, $length);
        }
        $environment = array_values(array_unique($environment));
        sort($environment);

        return ['code' => $php, 'renamed' => $renamed, 'environment' => $environment, 'uses' => $uses];
    }

    /** True when the file declares a named namespace, so a qualified name is relative to it. */
    private function namespaced(): bool
    {
        foreach ($this->tokens as $i => $token) {
            if ($token->is(T_NAMESPACE)) {
                $next = $this->next($i);
                if ($next !== null && $this->tokens[$next]->is([T_STRING, T_NAME_QUALIFIED])) {
                    return true;
                }
            }
        }

        return false;
    }

    /** A `use` that imports classes: not a closure's `use (`, not `use function` or `use const`. */
    private function isClassImport(int $i): bool
    {
        $next = $this->next($i);

        return $next !== null && !$this->tokens[$next]->is([T_FUNCTION, T_CONST]) && $this->tokens[$next]->text !== '(';
    }

    /**
     * The names one use statement imports, group use included. Stops at the
     * statement's `;`, or before a trait use's `{ ... }` block.
     *
     * @return array{list<array{int, string, ?string}>, int} [token index, lower-case full name, alias or null] per name, and the last token read
     */
    private function imports(int $use): array
    {
        $entries = [];
        $prefix = '';
        $groupOpen = false;
        $count = count($this->tokens);
        for ($j = $use + 1; $j < $count; $j++) {
            $token = $this->tokens[$j];
            if ($token->text === ';') {
                return [$entries, $j];
            }
            if ($token->text === '{') {
                if ($prefix === '') {
                    return [$entries, $j - 1];
                }
                $groupOpen = true;

                continue;
            }
            if ($token->text === '}') {
                $groupOpen = false;
                $prefix = '';

                continue;
            }
            if (!$token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                continue;
            }
            if (!$groupOpen && isset($this->tokens[$j + 1]) && $this->tokens[$j + 1]->is(T_NS_SEPARATOR)) {
                $prefix = strtolower(ltrim($token->text, '\\'));
                $j++;

                continue;
            }
            $full = ($groupOpen ? $prefix . '\\' : '') . strtolower(ltrim($token->text, '\\'));
            $alias = null;
            $after = $this->next($j);
            if ($after !== null && $this->tokens[$after]->is(T_AS)) {
                $aliasIndex = $this->next($after);
                if ($aliasIndex !== null) {
                    $alias = $this->tokens[$aliasIndex]->text;
                    $entries[] = [$j, $full, $alias];
                    $j = $aliasIndex;

                    continue;
                }
            }
            $entries[] = [$j, $full, $alias];
        }

        return [$entries, $count - 1];
    }

    /**
     * A qualified name (no leading backslash): through an import of its first
     * segment, else global when the file has no namespace.
     *
     * @param array<string, string> $aliases
     */
    private function resolveQualified(string $name, array $aliases, bool $namespaced): ?string
    {
        $lower = strtolower($name);
        $slash = strpos($lower, '\\');
        $first = $slash === false ? $lower : substr($lower, 0, $slash);
        if (isset($aliases[$first])) {
            return $aliases[$first] . ($slash === false ? '' : substr($lower, $slash));
        }

        return $namespaced ? null : $lower;
    }

    /** A bare name used as a class (a type, `new X`, `X::`), not a method, property, function or constant name. */
    private function isClassReference(int $i): bool
    {
        $previous = $this->previous($i);
        if ($previous !== null && $this->tokens[$previous]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST])) {
            return false;
        }
        $next = $this->next($i);
        if ($next === null) {
            return true;
        }
        $text = $this->tokens[$next]->text;
        if ($text === ':') {
            return false;
        }

        return $text !== '(' || ($previous !== null && $this->tokens[$previous]->is(T_NEW));
    }

    /**
     * The index of the first token after $i whose text is one of $stops, else the last index.
     *
     * @param list<string> $stops
     */
    private function skipTo(int $i, array $stops): int
    {
        $count = count($this->tokens);
        for ($j = $i + 1; $j < $count; $j++) {
            if (in_array($this->tokens[$j]->text, $stops, true)) {
                return $j;
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

    /** The same name with its last segment ServerRequest (the leading backslash and prefix as written). */
    private static function serverRequest(string $name): string
    {
        return (string) preg_replace('/[^\\\\]+$/', 'ServerRequest', $name);
    }

    private static function lastSegment(string $name): string
    {
        $slash = strrpos($name, '\\');

        return $slash === false ? $name : substr($name, $slash + 1);
    }
}
