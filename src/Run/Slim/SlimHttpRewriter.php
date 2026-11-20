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
 * class by that name). Given the glue namespace, what slim/http lacks moves to
 * the generated glue: `Slim\Http\Environment` to LegacyEnvironment,
 * `Request::createFromEnvironment(` to `LegacyHttp::createRequest(` and
 * `new Response(...)` to `LegacyHttp::createResponse(...)`. Slim 3's other
 * HTTP classes (Headers, Body, Stream, UploadedFile, Cookies) have no Slim 4
 * form, so their lines are reported, as Environment's are without the glue
 * namespace. Slim 3's own container class, `Slim\Container` (apps type their
 * controllers with it), moves to the generated LegacyContainer the same way,
 * and container-interop's `Interop\Container\ContainerInterface`, which Slim 4
 * no longer installs, becomes PSR-11's `Psr\Container\ContainerInterface`.
 * It works on tokens, so strings and comments are never touched, and a
 * second run changes nothing.
 *
 * Slim 3's request had a forwarding address all along; this just fills in the
 * new street name on the envelope.
 */
final class SlimHttpRewriter
{
    private const REQUEST = 'slim\\http\\request';

    private const SERVER_REQUEST = 'slim\\http\\serverrequest';

    private const RESPONSE = 'slim\\http\\response';

    private const ENVIRONMENT = 'slim\\http\\environment';

    private const CONTAINER = 'slim\\container';

    private const INTEROP = 'interop\\container\\containerinterface';

    /** Slim 3 classes the generated glue stands in for, by their glue class. */
    private const GLUE = [self::ENVIRONMENT => 'LegacyEnvironment', self::CONTAINER => 'LegacyContainer'];

    /** Names that mean the app needs slim/http (the decorator too, so a second run agrees). */
    private const HTTP_CLASSES = ['slim\\http\\request', 'slim\\http\\response', 'slim\\http\\environment', 'slim\\http\\serverrequest'];

    /** Slim 3 HTTP classes slim/http and the glue have no form of. */
    private const SLIM3_ONLY = ['slim\\http\\headers', 'slim\\http\\body', 'slim\\http\\requestbody', 'slim\\http\\stream', 'slim\\http\\uploadedfile', 'slim\\http\\cookies'];

    /** @var array<int, PhpToken> */
    private array $tokens = [];

    /**
     * The new code, how many request names were renamed, how many names moved
     * to the glue, the lines that still name Environment, the lines that name
     * a Slim 3 HTTP class with no Slim 4 form (or call Slim 3's Request
     * constructor), and whether the file names any Slim 3 HTTP class.
     *
     * @param string $glueNamespace the generated glue's namespace; '' leaves Environment and the factories as they are
     *
     * @return array{code: string, renamed: int, helpers: int, environment: list<int>, container: list<int>, legacy: list<int>, uses: bool}
     */
    public function rewrite(string $php, string $glueNamespace = ''): array
    {
        $this->tokens = PhpToken::tokenize($php);
        $namespaced = $this->namespaced();
        $glue = trim($glueNamespace, '\\');

        /** @var array<string, string> $aliases lower-case imported name to the lower-case class it stands for */
        $aliases = [];
        /** @var array<int, array{int, string}> $edits byte offset to [length, replacement] */
        $edits = [];
        $renamed = 0;
        $helpers = 0;
        $environment = [];
        $container = [];
        $legacy = [];
        /** @var array<string, true> $moved lower-case names whose Environment or Container import now names the glue */
        $moved = [];
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
                foreach ($entries as [$index, $full, $alias, $grouped]) {
                    $name = $this->tokens[$index];
                    $aliases[strtolower($alias ?? self::lastSegment($name->text))] = $full;
                    if (in_array($full, self::HTTP_CLASSES, true)) {
                        $uses = true;
                    }
                    if (in_array($full, self::SLIM3_ONLY, true)) {
                        $legacy[] = $name->line;
                    }
                    if (isset(self::GLUE[$full])) {
                        if ($glue === '' || $grouped) {
                            if ($full === self::ENVIRONMENT) {
                                $environment[] = $name->line;
                            } else {
                                $container[] = $name->line;
                            }
                        } else {
                            $edits[$name->pos] = [strlen($name->text), $glue . '\\' . self::GLUE[$full] . ($alias === null ? ' as ' . self::lastSegment($name->text) : '')];
                            $moved[strtolower($alias ?? self::lastSegment($name->text))] = true;
                            $helpers++;
                        }
                    }
                    if ($full === self::INTEROP && !$grouped) {
                        $edits[$name->pos] = [strlen($name->text), (str_starts_with($name->text, '\\') ? '\\' : '') . 'Psr\\Container\\ContainerInterface'];
                        $helpers++;
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
                $full = $aliases[strtolower($token->text)];
            }
            if ($full === null) {
                continue;
            }
            $bare = $token->is(T_STRING);
            if ($full === self::CONTAINER || $full === self::INTEROP) {
                if ($bare) {
                    if ($full === self::CONTAINER && !isset($moved[strtolower($token->text)])) {
                        $container[] = $token->line;
                    }
                } elseif ($full === self::INTEROP) {
                    $edits[$token->pos] = [strlen($token->text), (str_starts_with($token->text, '\\') ? '\\' : '') . 'Psr\\Container\\ContainerInterface'];
                    $helpers++;
                } elseif ($glue === '') {
                    $container[] = $token->line;
                } else {
                    $edits[$token->pos] = [strlen($token->text), '\\' . $glue . '\\LegacyContainer'];
                    $helpers++;
                }

                continue;
            }
            if (in_array($full, self::SLIM3_ONLY, true)) {
                $legacy[] = $token->line;

                continue;
            }
            if (!in_array($full, self::HTTP_CLASSES, true)) {
                continue;
            }
            $uses = true;

            if ($glue !== '' && ($full === self::REQUEST || $full === self::SERVER_REQUEST) && ($end = $this->factoryCall($i)) !== null) {
                $edits[$token->pos] = [$end - $token->pos, '\\' . $glue . '\\LegacyHttp::createRequest'];
                $helpers++;

                continue;
            }
            $new = $this->previous($i);
            $isNew = $new !== null && $this->tokens[$new]->is(T_NEW);
            if ($glue !== '' && $full === self::RESPONSE && $isNew) {
                $open = $this->next($i);
                $parens = $open !== null && $this->tokens[$open]->text === '(' ? '' : '()';
                $start = $this->tokens[(int) $new]->pos;
                $edits[$start] = [$token->pos + strlen($token->text) - $start, '\\' . $glue . '\\LegacyHttp::createResponse' . $parens];
                $helpers++;

                continue;
            }
            if ($isNew && $full === self::REQUEST) {
                $legacy[] = $token->line;
            }
            if ($bare) {
                // A bare imported name: the import was already moved, so only an unmoved Environment is reported.
                if ($full === self::ENVIRONMENT && !isset($moved[strtolower($token->text)])) {
                    $environment[] = $token->line;
                }

                continue;
            }
            if ($full === self::ENVIRONMENT) {
                if ($glue === '') {
                    $environment[] = $token->line;
                } else {
                    $edits[$token->pos] = [strlen($token->text), '\\' . $glue . '\\LegacyEnvironment'];
                    $helpers++;
                }
            } elseif ($full === self::REQUEST) {
                $edits[$token->pos] = [strlen($token->text), self::serverRequest($token->text)];
                $renamed++;
            }
        }

        krsort($edits);
        foreach ($edits as $offset => [$length, $replacement]) {
            $php = substr_replace($php, $replacement, $offset, $length);
        }

        return [
            'code' => $php,
            'renamed' => $renamed,
            'helpers' => $helpers,
            'environment' => self::lines($environment),
            'container' => self::lines($container),
            'legacy' => self::lines($legacy),
            'uses' => $uses,
        ];
    }

    /**
     * After a class name at $i: `::createFromEnvironment(`. The byte offset just
     * past the method name, else null.
     */
    private function factoryCall(int $i): ?int
    {
        $colon = $this->next($i);
        $method = $colon === null ? null : $this->next($colon);
        $open = $method === null ? null : $this->next($method);
        if ($colon === null || $method === null || $open === null || !$this->tokens[$colon]->is(T_DOUBLE_COLON)) {
            return null;
        }
        if (!$this->tokens[$method]->is(T_STRING) || strtolower($this->tokens[$method]->text) !== 'createfromenvironment' || $this->tokens[$open]->text !== '(') {
            return null;
        }

        return $this->tokens[$method]->pos + strlen($this->tokens[$method]->text);
    }

    /**
     * @param list<int> $lines
     *
     * @return list<int>
     */
    private static function lines(array $lines): array
    {
        $lines = array_values(array_unique($lines));
        sort($lines);

        return $lines;
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
     * @return array{list<array{int, string, ?string, bool}>, int} [token index, lower-case full name, alias or null, inside a group use] per name, and the last token read
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
                    $entries[] = [$j, $full, $alias, $groupOpen];
                    $j = $aliasIndex;

                    continue;
                }
            }
            $entries[] = [$j, $full, $alias, $groupOpen];
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
