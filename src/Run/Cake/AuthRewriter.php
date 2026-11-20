<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run\Cake;

use PhpToken;

/**
 * Moves a CakePHP 4 app's AuthComponent calls to the Authentication plugin,
 * like for like, for the CakePHP 5 hop (CakePHP 5 removes AuthComponent and
 * cakephp/upgrade has no rule for it). Every method is a pure function of the
 * PHP source it is given: no files, no engine, no Composer dependency.
 *
 * The source is read with PHP's own tokenizer, so string literals, comments,
 * heredocs and interpolated strings are never touched, and arguments are
 * found by balanced brackets, so `user($a[f($b)])` keeps its whole argument.
 * The output never mentions `$this->Auth` again, which is what makes a second
 * run a no-op. (Auth goes out, Authentication comes in: the component equivalent
 * of changing your name and keeping the same desk.)
 */
final class AuthRewriter
{
    private const RECEIVER = '$this->Authentication';

    private const IDENTITY = self::RECEIVER . '->getIdentity()';

    /** The call rules in the order their `changes` lines are reported. */
    private const CALL_RULES = [
        'user' => '$this->Auth->user()',
        'setuser' => '$this->Auth->setUser()',
        'logout' => '$this->Auth->logout()',
        'allow' => '$this->Auth->allow()',
    ];

    /** Dropped config keys that need no more than "port it by hand". */
    private const DROPPED_KEY_NOTES = [
        'authError' => "MANUAL: Auth config 'authError' dropped: the Authentication plugin has no counterpart; flash a message on the login action if the app wants one.",
        'loginAction' => "MANUAL: Auth config 'loginAction' dropped: it becomes the authentication service's unauthenticatedRedirect; scaffold getAuthenticationService() in src/Application.php.",
    ];

    /**
     * Rewrites `$this->Auth->...` calls (method names match case-insensitively).
     *
     * @return array{code: string, changes: list<string>, manual: list<string>}
     */
    public function rewriteCalls(string $php): array
    {
        $counts = array_fill_keys(array_keys(self::CALL_RULES), 0);
        $manual = [];
        $code = $this->rewriteCallTokens(PhpToken::tokenize($php), $counts, $manual);

        $changes = [];
        foreach (self::CALL_RULES as $rule => $label) {
            if ($counts[$rule] > 0) {
                $changes[] = sprintf(
                    'Rewrote %d %s %s to the Authentication plugin.',
                    $counts[$rule],
                    $label,
                    $counts[$rule] === 1 ? 'call' : 'calls',
                );
            }
        }

        return ['code' => $code, 'changes' => $changes, 'manual' => array_values($manual)];
    }

    /**
     * `$this->loadComponent('Auth', [...])` becomes
     * `$this->loadComponent('Authentication.Authentication')`; each dropped
     * config key is named in `manual`.
     *
     * @return array{code: string, changes: list<string>, manual: list<string>}
     */
    public function rewriteLoadComponent(string $php): array
    {
        $tokens = PhpToken::tokenize($php);
        $code = '';
        $changes = [];
        $manual = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $call = $this->authLoadComponentAt($tokens, $i);
            if ($call === null) {
                $code .= $tokens[$i]->text;

                continue;
            }

            $code .= "\$this->loadComponent('Authentication.Authentication')";
            $changes[] = "Replaced \$this->loadComponent('Auth') with \$this->loadComponent('Authentication.Authentication').";
            if ($call['config'] === null && $call['hasConfig']) {
                $manual[] = 'MANUAL: the Auth config passed to loadComponent() was dropped; port it by hand.';
            }
            foreach ($call['config'] ?? [] as $entry) {
                $key = $entry['key'];
                if ($key === null) {
                    continue;
                }
                $manual[] = self::DROPPED_KEY_NOTES[$key]
                    ?? sprintf("MANUAL: Auth config '%s' dropped: the Authentication plugin has no like-for-like; port it by hand.", $key);
            }
            $i = $call['close'];
        }

        return ['code' => $code, 'changes' => $changes, 'manual' => array_values(array_unique($manual))];
    }

    /**
     * In a class extending `Component`, an untyped `$components` property
     * (`public`, `var` or `protected`) becomes `protected array $components`
     * (CakePHP 5 types the parent's), and its `'Auth'` entry becomes
     * `'Authentication.Authentication'`. Other classes are left alone.
     *
     * @return array{code: string, changes: list<string>, manual: list<string>}
     */
    public function rewriteComponentProperties(string $php): array
    {
        $tokens = PhpToken::tokenize($php);
        $count = count($tokens);
        /** @var array<int, string> $replace token index => new text */
        $replace = [];
        $changes = [];
        $manual = [];

        $depth = 0;
        /** @var list<array{name: string, depth: int}> $classes */
        $classes = [];
        $pending = null;
        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if ($t->id === T_CLASS && !$this->isClassConstant($tokens, $i)) {
                $pending = $this->componentClassAt($tokens, $i);
            }
            if (self::opens($t)) {
                $depth++;
                if ($pending !== null && self::isChar($t, '{')) {
                    if ($pending !== '') {
                        $classes[] = ['name' => $pending, 'depth' => $depth];
                    }
                    $pending = null;
                }

                continue;
            }
            if (self::closes($t)) {
                if ($classes !== [] && $classes[count($classes) - 1]['depth'] === $depth) {
                    array_pop($classes);
                }
                $depth--;

                continue;
            }
            if ($classes === [] || $classes[count($classes) - 1]['depth'] !== $depth) {
                continue;
            }
            if (!in_array($t->id, [T_PUBLIC, T_VAR, T_PROTECTED], true)) {
                continue;
            }
            $var = self::nextSignificant($tokens, $i);
            if ($var === null || $tokens[$var]->id !== T_VARIABLE || $tokens[$var]->text !== '$components') {
                continue;
            }

            $class = $classes[count($classes) - 1]['name'];
            $replace[$i] = 'protected array';
            $changes[] = sprintf('Changed $components in %s to protected array.', $class);

            $equals = self::nextSignificant($tokens, $var);
            $open = $equals === null ? null : self::nextSignificant($tokens, $equals);
            $open = $open === null ? null : self::arrayOpen($tokens, $open);
            if ($equals === null || !self::isChar($tokens[$equals], '=') || $open === null) {
                $i = $var;

                continue;
            }
            $close = self::matchClose($tokens, $open);
            foreach (self::arrayEntries($tokens, $open, $close) as $entry) {
                if ($entry['key'] === null && $entry['valueName'] === 'Auth') {
                    $replace[$entry['valueStart']] = "'Authentication.Authentication'";
                    $changes[] = sprintf("Renamed 'Auth' to 'Authentication.Authentication' in %s's \$components.", $class);
                } elseif ($entry['key'] === 'Auth') {
                    $manual[] = sprintf("MANUAL: %s's \$components configures 'Auth'; load 'Authentication.Authentication' with its own config by hand.", $class);
                }
            }
            $i = $var;
        }

        $code = '';
        foreach ($tokens as $index => $t) {
            $code .= $replace[$index] ?? $t->text;
        }

        return ['code' => $code, 'changes' => $changes, 'manual' => $manual];
    }

    /**
     * The source text of the `loginAction` value in a
     * `$this->loadComponent('Auth', [...])` call, or null when there is none.
     */
    public function loginActionOf(string $php): ?string
    {
        $tokens = PhpToken::tokenize($php);
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $call = $this->authLoadComponentAt($tokens, $i);
            if ($call === null) {
                continue;
            }
            foreach ($call['config'] ?? [] as $entry) {
                if ($entry['key'] === 'loginAction') {
                    return $entry['value'];
                }
            }
            $i = $call['close'];
        }

        return null;
    }

    /**
     * @param array<int, PhpToken> $tokens
     * @param array<string, int> $counts
     * @param array<string, string> $manual keyed so each note is listed once
     */
    private function rewriteCallTokens(array $tokens, array &$counts, array &$manual): string
    {
        $out = '';
        $count = count($tokens);
        $quote = null;
        $heredoc = false;
        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if ($t->id === T_START_HEREDOC) {
                $heredoc = true;
            } elseif ($t->id === T_END_HEREDOC) {
                $heredoc = false;
            } elseif (!$heredoc && (self::isChar($t, '"') || self::isChar($t, '`'))) {
                $quote = $quote === null ? $t->id : ($quote === $t->id ? null : $quote);
            }
            $member = ($heredoc || $quote !== null) ? null : $this->authMemberAt($tokens, $i);
            if ($member === null) {
                $out .= $t->text;

                continue;
            }

            [$nameIndex, $open] = $member;
            $name = $tokens[$nameIndex]->text;
            $rule = strtolower($name);
            if ($open === null || !isset(self::CALL_RULES[$rule])) {
                $manual['member:' . strtolower($name)] = sprintf(
                    'MANUAL: $this->Auth->%s() has no Authentication plugin like-for-like; port it by hand.',
                    $name,
                );
                $out .= self::text($tokens, $i, $nameIndex);
                $i = $nameIndex;

                continue;
            }

            $close = self::matchClose($tokens, $open);
            $argTokens = array_slice($tokens, $open + 1, $close - $open - 1);
            $args = $this->rewriteCallTokens($argTokens, $counts, $manual);
            $out .= match ($rule) {
                'user' => $this->userRead($argTokens, $args),
                'setuser' => self::RECEIVER . '->setIdentity(' . $args . ')',
                'logout' => self::RECEIVER . '->logout(' . $args . ')',
                default => self::RECEIVER . '->allowUnauthenticated(' . $args . ')',
            };
            $counts[$rule]++;
            if ($rule === 'logout') {
                $manual['logout'] = 'MANUAL: $this->Authentication->logout() returns the redirect URL or null (AuthComponent returned its logoutRedirect); check what the action does with it.';
            }
            if ($rule === 'allow' && !$this->startsWithArray($argTokens)) {
                $manual['allow'] = "MANUAL: allowUnauthenticated() takes an array of action names, and AuthComponent's allow() also took one name or none (every action); check each converted call.";
            }
            $i = $close;
        }

        return $out;
    }

    /**
     * @param list<PhpToken> $argTokens the argument tokens as written
     * @param string $args the arguments with any nested Auth calls rewritten
     */
    private function userRead(array $argTokens, string $args): string
    {
        $significant = array_values(array_filter($argTokens, static fn(PhpToken $t): bool => $t->id !== T_WHITESPACE));
        if ($significant === []) {
            return self::IDENTITY . '?->getOriginalData()';
        }
        if (count($significant) === 1 && $significant[0]->id === T_CONSTANT_ENCAPSED_STRING && !str_contains($significant[0]->text, '.')) {
            return self::IDENTITY . '?->get(' . $significant[0]->text . ')';
        }

        return '\Cake\Utility\Hash::get(' . self::IDENTITY . '?->getOriginalData() ?? [], ' . trim($args) . ')';
    }

    /**
     * @param list<PhpToken> $argTokens
     */
    private function startsWithArray(array $argTokens): bool
    {
        foreach ($argTokens as $t) {
            if ($t->id === T_WHITESPACE) {
                continue;
            }

            return self::isChar($t, '[') || $t->id === T_ARRAY;
        }

        return false;
    }

    /**
     * When `$this->Auth->NAME` starts at $i: the index of NAME and of the
     * call's `(` (null when NAME is not called).
     *
     * @param array<int, PhpToken> $tokens
     * @return array{0: int, 1: ?int}|null
     */
    private function authMemberAt(array $tokens, int $i): ?array
    {
        if ($tokens[$i]->id !== T_VARIABLE || $tokens[$i]->text !== '$this') {
            return null;
        }
        $arrow = self::nextSignificant($tokens, $i);
        $auth = $arrow === null ? null : self::nextSignificant($tokens, $arrow);
        $arrow2 = $auth === null ? null : self::nextSignificant($tokens, $auth);
        $name = $arrow2 === null ? null : self::nextSignificant($tokens, $arrow2);
        if (
            $arrow === null || $auth === null || $arrow2 === null || $name === null
            || $tokens[$arrow]->id !== T_OBJECT_OPERATOR
            || $tokens[$auth]->id !== T_STRING || $tokens[$auth]->text !== 'Auth'
            || $tokens[$arrow2]->id !== T_OBJECT_OPERATOR
            || $tokens[$name]->id !== T_STRING
        ) {
            return null;
        }
        $open = self::nextSignificant($tokens, $name);

        return [$name, $open !== null && self::isChar($tokens[$open], '(') ? $open : null];
    }

    /**
     * When `$this->loadComponent('Auth' ...)` starts at $i: the index of its
     * closing `)`, whether a config argument follows the name, and that
     * config's top-level entries (null when it is not an array literal).
     *
     * @param array<int, PhpToken> $tokens
     * @return array{close: int, hasConfig: bool, config: list<array{key: ?string, value: string, valueName: ?string, valueStart: int}>|null}|null
     */
    private function authLoadComponentAt(array $tokens, int $i): ?array
    {
        if ($tokens[$i]->id !== T_VARIABLE || $tokens[$i]->text !== '$this') {
            return null;
        }
        $arrow = self::nextSignificant($tokens, $i);
        $method = $arrow === null ? null : self::nextSignificant($tokens, $arrow);
        $open = $method === null ? null : self::nextSignificant($tokens, $method);
        $name = $open === null ? null : self::nextSignificant($tokens, $open);
        if (
            $arrow === null || $method === null || $open === null || $name === null
            || $tokens[$arrow]->id !== T_OBJECT_OPERATOR
            || $tokens[$method]->id !== T_STRING || strtolower($tokens[$method]->text) !== 'loadcomponent'
            || !self::isChar($tokens[$open], '(')
            || self::literalValue($tokens[$name]) !== 'Auth'
        ) {
            return null;
        }
        $close = self::matchClose($tokens, $open);
        $comma = self::nextSignificant($tokens, $name);
        $configStart = $comma !== null && $comma < $close && self::isChar($tokens[$comma], ',')
            ? self::nextSignificant($tokens, $comma)
            : null;
        if ($configStart === null || $configStart >= $close) {
            return ['close' => $close, 'hasConfig' => false, 'config' => null];
        }
        $configOpen = self::arrayOpen($tokens, $configStart);
        if ($configOpen === null) {
            return ['close' => $close, 'hasConfig' => true, 'config' => null];
        }

        return [
            'close' => $close,
            'hasConfig' => true,
            'config' => self::arrayEntries($tokens, $configOpen, self::matchClose($tokens, $configOpen)),
        ];
    }

    /**
     * The name of the class declared at the `class` token $i when it extends
     * `Component` (any namespace), '' for any other class.
     *
     * @param array<int, PhpToken> $tokens
     */
    private function componentClassAt(array $tokens, int $i): string
    {
        $name = self::nextSignificant($tokens, $i);
        if ($name === null || $tokens[$name]->id !== T_STRING) {
            return '';
        }
        $extends = self::nextSignificant($tokens, $name);
        $parent = $extends === null ? null : self::nextSignificant($tokens, $extends);
        if ($extends === null || $parent === null || $tokens[$extends]->id !== T_EXTENDS) {
            return '';
        }
        // The last segment of a plain, qualified or fully qualified name.
        $parentName = $tokens[$parent]->text;
        $last = substr($parentName, (int) strrpos('\\' . $parentName, '\\'));

        return strtolower($last) === 'component' ? $tokens[$name]->text : '';
    }

    /**
     * @param array<int, PhpToken> $tokens
     */
    private function isClassConstant(array $tokens, int $i): bool
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if ($tokens[$j]->id !== T_WHITESPACE) {
                return $tokens[$j]->id === T_DOUBLE_COLON || $tokens[$j]->id === T_NEW;
            }
        }

        return false;
    }

    /**
     * The top-level entries of the array literal whose opening bracket is at
     * $open and closing bracket at $close.
     *
     * @param array<int, PhpToken> $tokens
     * @return list<array{key: ?string, value: string, valueName: ?string, valueStart: int}>
     */
    private static function arrayEntries(array $tokens, int $open, int $close): array
    {
        $entries = [];
        $start = $open + 1;
        $depth = 0;
        $arrow = null;
        for ($j = $open + 1; $j <= $close; $j++) {
            $t = $tokens[$j];
            if ($j < $close && self::opens($t)) {
                $depth++;

                continue;
            }
            if ($j < $close && self::closes($t)) {
                $depth--;

                continue;
            }
            if ($depth === 0 && $j < $close && $t->id === T_DOUBLE_ARROW && $arrow === null) {
                $arrow = $j;

                continue;
            }
            if ($j === $close || ($depth === 0 && self::isChar($t, ','))) {
                $entry = self::entry($tokens, $start, $j, $arrow);
                if ($entry !== null) {
                    $entries[] = $entry;
                }
                $start = $j + 1;
                $arrow = null;
            }
        }

        return $entries;
    }

    /**
     * One array entry from token $start up to (not including) $end.
     *
     * @param array<int, PhpToken> $tokens
     * @return array{key: ?string, value: string, valueName: ?string, valueStart: int}|null
     */
    private static function entry(array $tokens, int $start, int $end, ?int $arrow): ?array
    {
        $valueFrom = $arrow === null ? $start : $arrow + 1;
        $value = trim(self::text($tokens, $valueFrom, $end - 1));
        if ($value === '') {
            return null;
        }
        $valueStart = $valueFrom;
        while ($tokens[$valueStart]->id === T_WHITESPACE) {
            $valueStart++;
        }
        $key = $arrow === null ? null : self::singleLiteral($tokens, $start, $arrow);

        return [
            'key' => $key,
            'value' => $value,
            'valueName' => self::singleLiteral($tokens, $valueStart, $end),
            'valueStart' => $valueStart,
        ];
    }

    /**
     * The value of the string literal that is the only non-whitespace token
     * from $from up to (not including) $end, or null.
     *
     * @param array<int, PhpToken> $tokens
     */
    private static function singleLiteral(array $tokens, int $from, int $end): ?string
    {
        $found = null;
        for ($j = $from; $j < $end; $j++) {
            if ($tokens[$j]->id === T_WHITESPACE) {
                continue;
            }
            if ($found !== null) {
                return null;
            }
            $found = $tokens[$j];
        }

        return $found === null ? null : self::literalValue($found);
    }

    /**
     * The index of the bracket that opens the array literal starting at $i
     * (`[` or the `(` after `array`), or null when it is not an array literal.
     *
     * @param array<int, PhpToken> $tokens
     */
    private static function arrayOpen(array $tokens, int $i): ?int
    {
        if (self::isChar($tokens[$i], '[')) {
            return $i;
        }
        if ($tokens[$i]->id === T_ARRAY) {
            $open = self::nextSignificant($tokens, $i);

            return $open !== null && self::isChar($tokens[$open], '(') ? $open : null;
        }

        return null;
    }

    /** The value of a plain string literal token, or null for anything else. */
    private static function literalValue(PhpToken $t): ?string
    {
        if ($t->id !== T_CONSTANT_ENCAPSED_STRING || strlen($t->text) < 2) {
            return null;
        }

        return substr($t->text, 1, -1);
    }

    /**
     * The index of the bracket that closes the one opened at $open, or the
     * last token when the source is unbalanced.
     *
     * @param array<int, PhpToken> $tokens
     */
    private static function matchClose(array $tokens, int $open): int
    {
        $depth = 0;
        $count = count($tokens);
        for ($j = $open; $j < $count; $j++) {
            if (self::opens($tokens[$j])) {
                $depth++;
            } elseif (self::closes($tokens[$j])) {
                $depth--;
                if ($depth === 0) {
                    return $j;
                }
            }
        }

        return $count - 1;
    }

    /**
     * The index of the next token after $i that is not whitespace.
     *
     * @param array<int, PhpToken> $tokens
     */
    private static function nextSignificant(array $tokens, int $i): ?int
    {
        $count = count($tokens);
        for ($j = $i + 1; $j < $count; $j++) {
            if ($tokens[$j]->id !== T_WHITESPACE) {
                return $j;
            }
        }

        return null;
    }

    /**
     * The source text of tokens $from to $to, inclusive.
     *
     * @param array<int, PhpToken> $tokens
     */
    private static function text(array $tokens, int $from, int $to): string
    {
        $text = '';
        for ($j = $from; $j <= $to; $j++) {
            $text .= $tokens[$j]->text;
        }

        return $text;
    }

    private static function opens(PhpToken $t): bool
    {
        return self::isChar($t, '(') || self::isChar($t, '[') || self::isChar($t, '{')
            || $t->id === T_CURLY_OPEN || $t->id === T_DOLLAR_OPEN_CURLY_BRACES || $t->id === T_ATTRIBUTE;
    }

    private static function closes(PhpToken $t): bool
    {
        return self::isChar($t, ')') || self::isChar($t, ']') || self::isChar($t, '}');
    }

    /** One-character tokens carry the character's code as their id. */
    private static function isChar(PhpToken $t, string $char): bool
    {
        return $t->id === ord($char);
    }
}
