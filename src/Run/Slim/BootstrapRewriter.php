<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run\Slim;

use PhpToken;

/**
 * Rewrites a Slim 3 bootstrap file for Slim 4 through the generated LegacyApp
 * glue (see LegacyStubs): `new \Slim\App(ARGS)` becomes
 * `LegacyApp::create(ARGS)` and `LegacyApp::finish($app)` goes in before
 * `$app->run()`. Everything it cannot move by itself (Slim 3 handler keys,
 * settings with no Slim 4 form) becomes a MANUAL line. It works on tokens, so
 * strings and comments are never touched, and running it twice changes nothing
 * the second time.
 *
 * Slim 3 told the app "I'll handle it". Slim 4 hands it a factory and says
 * "handle it yourself". This class is the couples counsellor.
 */
final class BootstrapRewriter
{
    /** The Slim 3 settings LegacyApp::finish() maps or Slim 4 does not need. */
    private const KNOWN_SETTINGS = [
        'outputBuffering',
        'determineRouteBeforeAppMiddleware',
        'displayErrorDetails',
        'addContentLengthHeader',
        'routerCacheFile',
        'httpVersion',
        'responseChunkSize',
    ];

    /** Slim 3 container keys for handlers, which Slim 4 does not read. */
    private const HANDLER_KEYS = ['errorHandler', 'phpErrorHandler', 'notFoundHandler', 'notAllowedHandler'];

    /** The app expressions whose run() is expected to need LegacyApp::finish(). */
    private const APP_EXPRESSIONS = ['$app', '$this->app', '$this->slim'];

    private const HANDLER_NOTE = "Slim 4 has no such container keys; register the handler with the error middleware's"
        . ' setDefaultErrorHandler()/setErrorHandler() by hand, and note route exceptions now reach Slim\'s'
        . " ErrorMiddleware before the app's own PHP error or exception handler.";

    private const UNSET_NOTE = "left as is; Slim 4's default handler applies, and route exceptions now reach Slim's"
        . " ErrorMiddleware before the app's own PHP error or exception handler.";

    /**
     * @return array{code: string, changes: list<string>, manual: list<string>}
     */
    public function rewrite(string $php, string $namespace): array
    {
        $legacyApp = '\\' . trim($namespace, '\\') . '\\LegacyApp';
        $changes = [];
        $manual = [];

        $code = $this->replaceNewApp($php, $legacyApp, $changes);
        $code = $this->addFinish($code, $legacyApp, $changes, $manual);

        $tokens = PhpToken::tokenize($php);
        $this->handlerKeys($tokens, $manual);
        $this->settingsKeys($tokens, $manual);
        $this->settingsGet($tokens, $manual);

        return ['code' => $code, 'changes' => $changes, 'manual' => array_values(array_unique($manual))];
    }

    /**
     * Rule 1: `new \Slim\App(ARGS)` (or the short name a `use Slim\App;` import
     * gives) becomes `\NS\LegacyApp::create(ARGS)`, ARGS byte for byte.
     *
     * @param list<string> $changes
     */
    private function replaceNewApp(string $php, string $legacyApp, array &$changes): string
    {
        $tokens = PhpToken::tokenize($php);
        $aliases = $this->slimAppAliases($tokens);
        $edits = [];

        foreach ($tokens as $i => $token) {
            if (!$token->is(T_NEW)) {
                continue;
            }
            $name = $this->nextSignificant($tokens, $i);
            if ($name === null || !$this->namesSlimApp($tokens[$name], $aliases)) {
                continue;
            }
            $open = $this->nextSignificant($tokens, $name);
            if ($open === null || $tokens[$open]->text !== '(') {
                continue;
            }
            $start = $token->pos;
            $end = $tokens[$name]->pos + strlen($tokens[$name]->text);
            $edits[] = [$start, $end - $start, $legacyApp . '::create'];
            $changes[] = 'Replaced new \\Slim\\App(...) with LegacyApp::create(...).';
        }

        return $this->apply($php, $edits);
    }

    /**
     * Rule 2: `\NS\LegacyApp::finish(X);` on its own line before every
     * statement `X->run(` where X was assigned the LegacyApp::create() result.
     *
     * @param list<string> $changes
     * @param list<string> $manual
     */
    private function addFinish(string $code, string $legacyApp, array &$changes, array &$manual): string
    {
        $tokens = PhpToken::tokenize($code);
        $assigned = $this->createAssignments($tokens, $legacyApp);
        $edits = [];

        foreach ($tokens as $i => $token) {
            if (!$token->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                continue;
            }
            $method = $this->nextSignificant($tokens, $i);
            if ($method === null || !$tokens[$method]->is(T_STRING) || strtolower($tokens[$method]->text) !== 'run') {
                continue;
            }
            $open = $this->nextSignificant($tokens, $method);
            if ($open === null || $tokens[$open]->text !== '(') {
                continue;
            }
            $receiver = $this->receiverBefore($tokens, $i);
            if ($receiver === null) {
                continue;
            }
            [$receiverStart, $expression] = $receiver;
            if (!in_array($expression, $assigned, true)) {
                if (in_array($expression, self::APP_EXPRESSIONS, true)) {
                    $manual[] = 'MANUAL: add ' . $legacyApp . '::finish(' . $expression . ') just before ' . $expression . '->run().';
                }
                continue;
            }

            $finish = $legacyApp . '::finish(' . $expression . ');';
            $offset = $tokens[$receiverStart]->pos;
            if (str_ends_with(rtrim(substr($code, 0, $offset)), $finish)) {
                continue;
            }
            if (!$this->startsStatement($tokens, $receiverStart)) {
                $manual[] = 'MANUAL: add ' . $legacyApp . '::finish(' . $expression . ') just before ' . $expression . '->run().';
                continue;
            }

            $edits[] = [$offset, 0, $finish . "\n" . $this->indentAt($code, $offset)];
            $changes[] = 'Added LegacyApp::finish() before run().';
        }

        return $this->apply($code, $edits);
    }

    /**
     * Rule 3: Slim 3 handler keys, set or unset.
     *
     * @param array<int, PhpToken> $tokens
     * @param list<string> $manual
     */
    private function handlerKeys(array $tokens, array &$manual): void
    {
        $inUnset = [];
        foreach ($tokens as $i => $token) {
            if (!$token->is(T_UNSET)) {
                continue;
            }
            $open = $this->nextSignificant($tokens, $i);
            if ($open === null || $tokens[$open]->text !== '(') {
                continue;
            }
            $close = $this->matching($tokens, $open);
            for ($j = $open + 1; $j < $close; $j++) {
                $key = $this->stringValue($tokens[$j]);
                if ($key !== null && in_array($key, self::HANDLER_KEYS, true)) {
                    $inUnset[$j] = true;
                    $manual[] = sprintf("MANUAL: line %d: unset(...['%s']) %s", $tokens[$j]->line, $key, self::UNSET_NOTE);
                }
            }
        }

        foreach ($tokens as $i => $token) {
            $key = $this->stringValue($token);
            if ($key === null || !in_array($key, self::HANDLER_KEYS, true) || isset($inUnset[$i])) {
                continue;
            }
            $after = $this->nextSignificant($tokens, $i);
            if ($after === null) {
                continue;
            }
            $isKeyed = $tokens[$after]->is(T_DOUBLE_ARROW);
            if (!$isKeyed && $tokens[$after]->text === ']') {
                $before = $this->previousSignificant($tokens, $i);
                $assign = $this->nextSignificant($tokens, $after);
                $isKeyed = $before !== null && $tokens[$before]->text === '['
                    && $assign !== null && ($tokens[$assign]->text === '=' || $tokens[$assign]->is(T_COALESCE_EQUAL));
            }
            if ($isKeyed) {
                $manual[] = sprintf("MANUAL: line %d: ['%s'] %s", $token->line, $key, self::HANDLER_NOTE);
            }
        }
    }

    /**
     * Rule 3: top-level settings keys with no Slim 4 form, wherever the
     * settings array is written: `'settings' => [...]`, `$settings = [...]`,
     * `->settings = [...]`, `['settings'] = [...]`, `['settings']['k'] =` and
     * `$settings['k'] =`.
     *
     * @param array<int, PhpToken> $tokens
     * @param list<string> $manual
     */
    private function settingsKeys(array $tokens, array &$manual): void
    {
        foreach ($tokens as $i => $token) {
            $isSettings = $this->stringValue($token) === 'settings'
                || ($token->is(T_VARIABLE) && $token->text === '$settings')
                || ($token->is(T_STRING) && $token->text === 'settings' && $this->previousIs($tokens, $i, [T_OBJECT_OPERATOR]));
            if (!$isSettings) {
                continue;
            }

            $after = $i;
            if ($this->stringValue($token) !== null) {
                $next = $this->nextSignificant($tokens, $i);
                $before = $this->previousSignificant($tokens, $i);
                if ($next !== null && $tokens[$next]->is(T_DOUBLE_ARROW)) {
                    $this->arrayKeys($tokens, $this->nextSignificant($tokens, $next), $manual);
                    continue;
                }
                if ($next === null || $tokens[$next]->text !== ']' || $before === null || $tokens[$before]->text !== '[') {
                    continue;
                }
                $after = $next;
            }

            $next = $this->nextSignificant($tokens, $after);
            if ($next === null) {
                continue;
            }
            if ($tokens[$next]->text === '=') {
                $this->arrayKeys($tokens, $this->nextSignificant($tokens, $next), $manual);
                continue;
            }
            if ($tokens[$next]->text === '[') {
                $key = $this->nextSignificant($tokens, $next);
                $close = $key === null ? null : $this->nextSignificant($tokens, $key);
                $assign = $close === null ? null : $this->nextSignificant($tokens, $close);
                if ($key !== null && $close !== null && $assign !== null && $tokens[$close]->text === ']' && $tokens[$assign]->text === '=') {
                    $this->settingsKey($tokens[$key], $manual);
                }
            }
        }
    }

    /**
     * Rule 3: `->settings->get(` (Slim 3's settings Collection).
     *
     * @param array<int, PhpToken> $tokens
     * @param list<string> $manual
     */
    private function settingsGet(array $tokens, array &$manual): void
    {
        foreach ($tokens as $i => $token) {
            if (!$token->is(T_STRING) || $token->text !== 'settings' || !$this->previousIs($tokens, $i, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                continue;
            }
            $arrow = $this->nextSignificant($tokens, $i);
            $get = $arrow === null ? null : $this->nextSignificant($tokens, $arrow);
            $open = $get === null ? null : $this->nextSignificant($tokens, $get);
            if ($arrow === null || $get === null || $open === null) {
                continue;
            }
            if ($tokens[$arrow]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR]) && strtolower($tokens[$get]->text) === 'get' && $tokens[$open]->text === '(') {
                $manual[] = sprintf("MANUAL: line %d: settings is a plain array on Slim 4; replace ->get('k') with ['k'].", $token->line);
            }
        }
    }

    /**
     * The top-level string keys of the array literal starting at $start.
     *
     * @param array<int, PhpToken> $tokens
     * @param list<string> $manual
     */
    private function arrayKeys(array $tokens, ?int $start, array &$manual): void
    {
        if ($start === null) {
            return;
        }
        $open = $start;
        if ($tokens[$start]->is(T_ARRAY)) {
            $open = $this->nextSignificant($tokens, $start);
            if ($open === null || $tokens[$open]->text !== '(') {
                return;
            }
        } elseif ($tokens[$start]->text !== '[') {
            return;
        }

        $close = $this->matching($tokens, $open);
        $depth = 0;
        for ($j = $open + 1; $j < $close; $j++) {
            $text = $tokens[$j]->text;
            if (in_array($text, ['(', '[', '{'], true) || $tokens[$j]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
                continue;
            }
            if (in_array($text, [')', ']', '}'], true)) {
                $depth--;
                continue;
            }
            if ($depth !== 0 || $this->stringValue($tokens[$j]) === null) {
                continue;
            }
            $next = $this->nextSignificant($tokens, $j);
            if ($next !== null && $tokens[$next]->is(T_DOUBLE_ARROW)) {
                $this->settingsKey($tokens[$j], $manual);
            }
        }
    }

    /**
     * @param list<string> $manual
     */
    private function settingsKey(PhpToken $token, array &$manual): void
    {
        $key = $this->stringValue($token);
        if ($key !== null && !in_array($key, self::KNOWN_SETTINGS, true)) {
            $manual[] = sprintf("MANUAL: settings key '%s' has no Slim 4 equivalent (line %d).", $key, $token->line);
        }
    }

    /**
     * Lower-cased short names that mean Slim\App through a `use` import.
     *
     * @param array<int, PhpToken> $tokens
     * @return list<string>
     */
    private function slimAppAliases(array $tokens): array
    {
        $aliases = [];
        foreach ($tokens as $i => $token) {
            if (!$token->is(T_USE)) {
                continue;
            }
            $name = $this->nextSignificant($tokens, $i);
            if ($name === null || !$tokens[$name]->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                continue;
            }
            if (strtolower(ltrim($tokens[$name]->text, '\\')) !== 'slim\\app') {
                continue;
            }
            $next = $this->nextSignificant($tokens, $name);
            if ($next !== null && $tokens[$next]->text === ';') {
                $aliases[] = 'app';
            } elseif ($next !== null && $tokens[$next]->is(T_AS)) {
                $alias = $this->nextSignificant($tokens, $next);
                if ($alias !== null && $tokens[$alias]->is(T_STRING)) {
                    $aliases[] = strtolower($tokens[$alias]->text);
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

    /**
     * The expressions (`$app`, `$this->app`) assigned `\NS\LegacyApp::create(`.
     *
     * @param array<int, PhpToken> $tokens
     * @return list<string>
     */
    private function createAssignments(array $tokens, string $legacyApp): array
    {
        $assigned = [];
        foreach ($tokens as $i => $token) {
            if ($token->text !== '=') {
                continue;
            }
            $name = $this->nextSignificant($tokens, $i);
            $colon = $name === null ? null : $this->nextSignificant($tokens, $name);
            $create = $colon === null ? null : $this->nextSignificant($tokens, $colon);
            if ($name === null || $colon === null || $create === null) {
                continue;
            }
            if (strcasecmp($tokens[$name]->text, $legacyApp) !== 0 || !$tokens[$colon]->is(T_DOUBLE_COLON) || strtolower($tokens[$create]->text) !== 'create') {
                continue;
            }
            $target = $this->receiverBefore($tokens, $i);
            if ($target !== null) {
                $assigned[] = $target[1];
            }
        }

        return $assigned;
    }

    /**
     * The `$var` or `$this->prop` that ends just before token $i, as
     * [first token index, text without whitespace].
     *
     * @param array<int, PhpToken> $tokens
     * @return array{int, string}|null
     */
    private function receiverBefore(array $tokens, int $i): ?array
    {
        $last = $this->previousSignificant($tokens, $i);
        if ($last === null) {
            return null;
        }
        if ($tokens[$last]->is(T_VARIABLE)) {
            $arrow = $this->previousSignificant($tokens, $last);
            if ($arrow !== null && $tokens[$arrow]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON])) {
                return null;
            }

            return [$last, $tokens[$last]->text];
        }
        if (!$tokens[$last]->is(T_STRING)) {
            return null;
        }
        $arrow = $this->previousSignificant($tokens, $last);
        $self = $arrow === null ? null : $this->previousSignificant($tokens, $arrow);
        if ($arrow === null || $self === null || !$tokens[$arrow]->is(T_OBJECT_OPERATOR) || $tokens[$self]->text !== '$this') {
            return null;
        }
        $before = $this->previousSignificant($tokens, $self);
        if ($before !== null && $tokens[$before]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON])) {
            return null;
        }

        return [$self, '$this->' . $tokens[$last]->text];
    }

    /**
     * @param array<int, PhpToken> $tokens
     */
    private function startsStatement(array $tokens, int $i): bool
    {
        $before = $this->previousSignificant($tokens, $i);

        return $before === null
            || in_array($tokens[$before]->text, [';', '{', '}'], true)
            || $tokens[$before]->is([T_OPEN_TAG, T_CLOSE_TAG]);
    }

    /** The whitespace that starts the line holding byte $offset. */
    private function indentAt(string $code, int $offset): string
    {
        $lineStart = strrpos(substr($code, 0, $offset), "\n");
        $prefix = substr($code, $lineStart === false ? 0 : $lineStart + 1, $offset - ($lineStart === false ? 0 : $lineStart + 1));

        return trim($prefix, " \t") === '' ? $prefix : '';
    }

    /**
     * @param array<int, PhpToken> $tokens
     * @param list<int> $kinds
     */
    private function previousIs(array $tokens, int $i, array $kinds): bool
    {
        $before = $this->previousSignificant($tokens, $i);

        return $before !== null && $tokens[$before]->is($kinds);
    }

    /**
     * Index of the bracket that closes the one at $open (or the last token).
     *
     * @param array<int, PhpToken> $tokens
     */
    private function matching(array $tokens, int $open): int
    {
        $depth = 0;
        $count = count($tokens);
        for ($j = $open; $j < $count; $j++) {
            $text = $tokens[$j]->text;
            if (in_array($text, ['(', '[', '{'], true) || $tokens[$j]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;
                if ($depth === 0) {
                    return $j;
                }
            }
        }

        return $count - 1;
    }

    /**
     * @param array<int, PhpToken> $tokens
     */
    private function nextSignificant(array $tokens, int $i): ?int
    {
        $count = count($tokens);
        for ($j = $i + 1; $j < $count; $j++) {
            if (!$tokens[$j]->isIgnorable()) {
                return $j;
            }
        }

        return null;
    }

    /**
     * @param array<int, PhpToken> $tokens
     */
    private function previousSignificant(array $tokens, int $i): ?int
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (!$tokens[$j]->isIgnorable()) {
                return $j;
            }
        }

        return null;
    }

    /** The value of a plain single- or double-quoted string token, else null. */
    private function stringValue(PhpToken $token): ?string
    {
        if (!$token->is(T_CONSTANT_ENCAPSED_STRING) || strlen($token->text) < 2) {
            return null;
        }

        return substr($token->text, 1, -1);
    }

    /**
     * @param list<array{int, int, string}> $edits byte offset, length, replacement
     */
    private function apply(string $code, array $edits): string
    {
        usort($edits, static fn(array $a, array $b): int => $b[0] <=> $a[0]);
        foreach ($edits as [$offset, $length, $replacement]) {
            $code = substr_replace($code, $replacement, $offset, $length);
        }

        return $code;
    }
}
