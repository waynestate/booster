<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run\Cake;

use PhpToken;

/**
 * Wires a CakePHP app's `src/Application.php` for the Authentication plugin,
 * like for like, so the controllers' `$this->Authentication` calls (see
 * AuthRewriter) have a service behind them in CakePHP 5:
 *
 * - the class implements AuthenticationServiceProviderInterface,
 * - bootstrap() loads the plugin,
 * - middleware() adds AuthenticationMiddleware last of CSRF, body parsing and
 *   routing (AuthComponent checked inside the controller, so inside every
 *   middleware: the CSRF cookie still reaches a guest's redirect),
 * - getAuthenticationService() builds a service whose guests go to the app's
 *   old AuthComponent loginAction, with only the Session authenticator: the
 *   apps sign people in by hand (setUser, now setIdentity), and a service with
 *   no authenticator would never keep that identity past the request. The
 *   guest redirect is AuthComponent's (CAKE-12): a full URL, no way back when
 *   the guest asked for the home page, and its authError flashed first.
 *
 * Pure: it edits the source string it is given and reports what it did. A
 * class that already implements the interface is left alone and only
 * reported. (The scaffold goes up once; nobody likes a building with two
 * front doors.)
 */
final class ApplicationScaffold
{
    private const INTERFACE = 'AuthenticationServiceProviderInterface';

    private const PLACEHOLDER = "'/login'";

    /** Imports every scaffold needs, as written in the `use` lines. */
    private const IMPORTS = [
        'Authentication\AuthenticationService',
        'Authentication\AuthenticationServiceInterface',
        'Authentication\AuthenticationServiceProviderInterface',
        'Authentication\Middleware\AuthenticationMiddleware',
        'Cake\Http\ServerRequest',
        'Cake\Routing\Router',
        'Psr\Http\Message\ServerRequestInterface',
    ];

    /** AuthComponent's message for a guest, when the app set no authError. */
    private const DEFAULT_AUTH_ERROR = "__d('cake', 'You are not authorized to access that location.')";

    /** AuthComponent's default `flash` config. */
    private const FLASH_OPTIONS = "['element' => 'error', 'key' => 'flash', 'params' => ['class' => 'error']]";

    /**
     * @param string $applicationPhp the app's src/Application.php
     * @param ?string $loginActionSource the source text of the old AuthComponent loginAction (AuthRewriter::loginActionOf), or null
     * @param ?string $authErrorSource the source text of the old authError (AuthRewriter::configValueOf), or null when the app set none
     * @param bool $customFlash whether the old Auth config set its own `flash` options
     * @return array{code: string, changes: list<string>, manual: list<string>}
     */
    public function scaffold(string $applicationPhp, ?string $loginActionSource, ?string $authErrorSource = null, bool $customFlash = false): array
    {
        $tokens = PhpToken::tokenize($applicationPhp);
        $class = $this->applicationClass($tokens);
        if ($class === null) {
            return [
                'code' => $applicationPhp,
                'changes' => [],
                'manual' => ['MANUAL: no Application class found in src/Application.php; wire the Authentication plugin by hand.'],
            ];
        }
        if ($class['implementsInterface']) {
            return [
                'code' => $applicationPhp,
                'changes' => [],
                'manual' => ['MANUAL: src/Application.php already implements AuthenticationServiceProviderInterface; check that its getAuthenticationService() loads the Authentication.Session authenticator.'],
            ];
        }

        $nl = str_contains($applicationPhp, "\r\n") ? "\r\n" : "\n";
        /** @var array<int, string> $inserts byte offset => text inserted there */
        $inserts = [];
        $changes = [];
        $manual = [];
        $methods = $this->methods($tokens, $class['open'], $class['close']);

        // The implements clause.
        $after = $class['lastInterface'] ?? $class['parent'] ?? $class['name'];
        self::insertAfter($inserts, $tokens[$after], $class['lastInterface'] !== null ? ', ' . self::INTERFACE : ' implements ' . self::INTERFACE);
        $changes[] = 'Added AuthenticationServiceProviderInterface to the Application class.';

        // The service method, built first because it decides whether Router and ServerRequest are imported.
        $imports = self::IMPORTS;
        $method = null;
        if (isset($methods['getauthenticationservice'])) {
            $manual[] = 'MANUAL: Application already has a getAuthenticationService(); check that it loads the Authentication.Session authenticator.';
            $imports = array_values(array_diff($imports, ['Cake\Http\ServerRequest', 'Cake\Routing\Router']));
        } else {
            $redirect = $this->redirectExpression($loginActionSource, $nl);
            if ($loginActionSource === null || trim($loginActionSource) === '') {
                $manual[] = "MANUAL: no loginAction was found, so unauthenticatedRedirect in getAuthenticationService() is the placeholder '/login'; set it to the app's sign-in URL.";
            }
            $message = $this->flashMessage($authErrorSource, $manual);
            if ($message !== null && $customFlash) {
                $manual[] = "MANUAL: the Auth config set its own 'flash' options; the guest message in getAuthenticationService() uses AuthComponent's default ones, so check it.";
            }
            $method = $this->serviceMethod($redirect, $message, $nl);
            if ($message === null) {
                $imports = array_values(array_diff($imports, ['Cake\Http\ServerRequest']));
            }
        }

        $added = $this->addImports($tokens, $class['class'], $imports, $inserts, $nl);
        if ($added !== []) {
            $changes[] = 'Added use imports: ' . implode(', ', $added) . '.';
        }

        if (!isset($methods['bootstrap'])) {
            $manual[] = "MANUAL: Application has no bootstrap(); load the plugin there with \$this->addPlugin('Authentication').";
        } elseif ($this->addPlugin($tokens, $applicationPhp, $methods['bootstrap'], $inserts, $nl)) {
            $changes[] = "Added \$this->addPlugin('Authentication') to bootstrap().";
        }

        if (!isset($methods['middleware'])) {
            $manual[] = 'MANUAL: Application has no middleware(); add AuthenticationMiddleware after the routing middleware by hand.';
        } else {
            $anchor = $this->addMiddleware($tokens, $applicationPhp, $methods['middleware'], $inserts, $nl);
            if ($anchor === '') {
                $manual[] = 'MANUAL: no RoutingMiddleware found in middleware(); add ->add(new AuthenticationMiddleware($this)) after the routing middleware by hand.';
            } elseif ($anchor !== null) {
                $changes[] = sprintf('Added AuthenticationMiddleware after %s in middleware().', $anchor);
            }
        }

        if ($method !== null) {
            $inserts[$tokens[$class['close']]->pos] = ($inserts[$tokens[$class['close']]->pos] ?? '') . $nl . $method . $nl;
            $changes[] = "Added getAuthenticationService(): the Session authenticator, and AuthComponent's guest redirect to the old loginAction (a full URL, queryParam 'redirect' except for the home page, the authError flashed).";
        }

        krsort($inserts);
        $code = $applicationPhp;
        foreach ($inserts as $offset => $text) {
            $code = substr($code, 0, $offset) . $text . substr($code, $offset);
        }

        return ['code' => $code, 'changes' => $changes, 'manual' => $manual];
    }

    /**
     * The first named class in the file, with the token indexes the scaffold
     * edits around.
     *
     * @param array<int, PhpToken> $tokens
     * @return array{class: int, name: int, parent: ?int, lastInterface: ?int, implementsInterface: bool, open: int, close: int}|null
     */
    private function applicationClass(array $tokens): ?array
    {
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]->id !== T_CLASS) {
                continue;
            }
            $previous = self::previousSignificant($tokens, $i);
            if ($previous !== null && in_array($tokens[$previous]->id, [T_DOUBLE_COLON, T_NEW], true)) {
                continue;
            }
            $name = self::nextSignificant($tokens, $i);
            if ($name === null || $tokens[$name]->id !== T_STRING) {
                continue;
            }

            $parent = null;
            $lastInterface = null;
            $implementsInterface = false;
            $inImplements = false;
            for ($j = $name + 1; $j < $count; $j++) {
                $t = $tokens[$j];
                if (self::isChar($t, '{')) {
                    return [
                        'class' => $i,
                        'name' => $name,
                        'parent' => $parent,
                        'lastInterface' => $lastInterface,
                        'implementsInterface' => $implementsInterface,
                        'open' => $j,
                        'close' => self::matchClose($tokens, $j),
                    ];
                }
                if ($t->id === T_IMPLEMENTS) {
                    $inImplements = true;
                } elseif (in_array($t->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    if (!$inImplements) {
                        $parent = $j;
                    } else {
                        $lastInterface = $j;
                        $implementsInterface = $implementsInterface || strcasecmp(self::lastSegment($t->text), self::INTERFACE) === 0;
                    }
                }
            }

            return null;
        }

        return null;
    }

    /**
     * The class's methods: lowercase name => the indexes of the body's braces.
     *
     * @param array<int, PhpToken> $tokens
     * @return array<string, array{open: int, close: int}>
     */
    private function methods(array $tokens, int $classOpen, int $classClose): array
    {
        $methods = [];
        for ($i = $classOpen + 1; $i < $classClose; $i++) {
            if (self::opens($tokens[$i])) {
                $i = self::matchClose($tokens, $i);

                continue;
            }
            if ($tokens[$i]->id !== T_FUNCTION) {
                continue;
            }
            $name = self::nextSignificant($tokens, $i);
            $params = $name === null ? null : self::nextSignificant($tokens, $name);
            if ($name === null || $params === null || !self::isChar($tokens[$params], '(')) {
                continue;
            }
            $i = self::matchClose($tokens, $params);
            for ($j = $i + 1; $j < $classClose; $j++) {
                if (self::isChar($tokens[$j], ';')) {
                    $i = $j;

                    break;
                }
                if (self::isChar($tokens[$j], '{')) {
                    $close = self::matchClose($tokens, $j);
                    $methods[strtolower($tokens[$name]->text)] = ['open' => $j, 'close' => $close];
                    $i = $close;

                    break;
                }
            }
        }

        return $methods;
    }

    /**
     * Adds each missing import in alphabetical place among the file's class
     * imports. Returns the imports added, sorted.
     *
     * @param array<int, PhpToken> $tokens
     * @param list<string> $imports
     * @param array<int, string> $inserts
     * @return list<string>
     */
    private function addImports(array $tokens, int $classIndex, array $imports, array &$inserts, string $nl): array
    {
        /** @var list<array{name: string, start: int, end: int}> $existing */
        $existing = [];
        $namespaceEnd = null;
        $depth = 0;
        for ($i = 0; $i < $classIndex; $i++) {
            $t = $tokens[$i];
            if (self::opens($t)) {
                $depth++;
            } elseif (self::closes($t)) {
                $depth--;
            }
            if ($t->id === T_NAMESPACE && $namespaceEnd === null) {
                $namespaceEnd = self::nextChar($tokens, $i, ';');
            }
            if ($t->id !== T_USE || $depth !== 0) {
                continue;
            }
            $name = self::nextSignificant($tokens, $i);
            $end = self::nextChar($tokens, $i, ';');
            if ($name === null || $end === null) {
                continue;
            }
            if (in_array($tokens[$name]->id, [T_FUNCTION, T_CONST], true)) {
                $i = $end;

                continue;
            }
            $existing[] = ['name' => ltrim($tokens[$name]->text, '\\'), 'start' => $i, 'end' => $end];
            $i = $end;
        }

        sort($imports, SORT_STRING | SORT_FLAG_CASE);
        $added = [];
        foreach ($imports as $import) {
            foreach ($existing as $use) {
                if (strcasecmp($use['name'], $import) === 0) {
                    continue 2;
                }
            }
            $added[] = $import;
            $line = 'use ' . $import . ';';
            $anchor = null;
            foreach ($existing as $use) {
                if (strcasecmp($use['name'], $import) > 0) {
                    $anchor = $use;

                    break;
                }
            }
            if ($anchor !== null) {
                $offset = $tokens[$anchor['start']]->pos;
                $inserts[$offset] = ($inserts[$offset] ?? '') . $line . $nl;
            } elseif ($existing !== []) {
                self::insertAfter($inserts, $tokens[$existing[count($existing) - 1]['end']], $nl . $line);
            } elseif ($namespaceEnd !== null) {
                self::insertAfter($inserts, $tokens[$namespaceEnd], ($added === [$import] ? $nl : '') . $nl . $line);
            } else {
                $offset = $tokens[$classIndex]->pos;
                $inserts[$offset] = ($inserts[$offset] ?? '') . $line . $nl . ($import === $imports[count($imports) - 1] ? $nl : '');
            }
        }

        return $added;
    }

    /**
     * Adds `$this->addPlugin('Authentication');` to bootstrap() when no
     * statement loads the plugin yet: after the last top-level addPlugin()
     * statement, else after `parent::bootstrap();`, else first in the body.
     *
     * @param array<int, PhpToken> $tokens
     * @param array{open: int, close: int} $body
     * @param array<int, string> $inserts
     */
    private function addPlugin(array $tokens, string $php, array $body, array &$inserts, string $nl): bool
    {
        $lastPlugin = null;
        $parentCall = null;
        $depth = 0;
        for ($i = $body['open'] + 1; $i < $body['close']; $i++) {
            $t = $tokens[$i];
            if (self::opens($t)) {
                $depth++;
            } elseif (self::closes($t)) {
                $depth--;
            }
            if ($t->id === T_STRING && strcasecmp($t->text, 'addPlugin') === 0) {
                $open = self::nextSignificant($tokens, $i);
                $arg = $open === null ? null : self::nextSignificant($tokens, $open);
                if ($arg !== null && self::loadsAuthentication($tokens[$arg])) {
                    return false;
                }
            }
            if ($depth !== 0) {
                continue;
            }
            if ($t->id === T_VARIABLE && $t->text === '$this' && $this->callsMember($tokens, $i, 'addPlugin')) {
                $lastPlugin = $i;
            }
            if ($t->id === T_STRING && strcasecmp($t->text, 'parent') === 0 && $parentCall === null) {
                $parentCall = $i;
            }
        }

        $statement = "\$this->addPlugin('Authentication');";
        $anchor = $lastPlugin ?? $parentCall;
        if ($anchor !== null) {
            $end = self::nextChar($tokens, $anchor, ';');
            if ($end !== null) {
                self::insertAfter($inserts, $tokens[$end], $nl . self::indentAt($php, $tokens[$anchor]->pos) . $statement);

                return true;
            }
        }
        $indent = self::indentAt($php, $tokens[$body['open']]->pos) . '    ';
        self::insertAfter($inserts, $tokens[$body['open']], $nl . $indent . $statement);

        return true;
    }

    /**
     * Adds AuthenticationMiddleware right after the CsrfProtectionMiddleware
     * (else the BodyParserMiddleware, else the RoutingMiddleware) entry of
     * middleware(). Returns the anchor's class
     * name, null when the queue already has the middleware, or '' when there
     * is no anchor.
     *
     * @param array<int, PhpToken> $tokens
     * @param array{open: int, close: int} $body
     * @param array<int, string> $inserts
     */
    private function addMiddleware(array $tokens, string $php, array $body, array &$inserts, string $nl): ?string
    {
        $found = [];
        for ($i = $body['open'] + 1; $i < $body['close']; $i++) {
            $t = $tokens[$i];
            if (in_array($t->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                && strcasecmp(self::lastSegment($t->text), 'AuthenticationMiddleware') === 0) {
                return null;
            }
            if ($t->id !== T_NEW) {
                continue;
            }
            $class = self::nextSignificant($tokens, $i);
            if ($class !== null) {
                $found[strtolower(self::lastSegment($tokens[$class]->text))] ??= $i;
            }
        }
        $anchorName = 'RoutingMiddleware';
        foreach (['CsrfProtectionMiddleware', 'BodyParserMiddleware'] as $candidate) {
            if (isset($found[strtolower($candidate)])) {
                $anchorName = $candidate;

                break;
            }
        }
        $new = $found[strtolower($anchorName)] ?? null;
        $call = $new === null ? null : $this->enclosingCall($tokens, $body['open'], $new);
        if ($call === null) {
            return '';
        }

        [$arrow, $close] = $call;
        $lineStart = self::lineStart($php, $tokens[$arrow]->pos);
        $beforeArrow = substr($php, $lineStart, $tokens[$arrow]->pos - $lineStart);
        $indent = self::indentAt($php, $tokens[$arrow]->pos);
        $after = self::nextSignificant($tokens, $close);
        if (trim($beforeArrow) === '') {
            // A chained queue: one ->add() per line.
            self::insertAfter($inserts, $tokens[$close], $nl . $indent . '->add(new AuthenticationMiddleware($this))');
        } elseif ($after !== null && self::isChar($tokens[$after], ';') && preg_match('/^\s*(\$\w+)$/', $beforeArrow, $m) === 1) {
            // One statement per middleware: `$middlewareQueue->add(...);`.
            self::insertAfter($inserts, $tokens[$after], $nl . $indent . $m[1] . '->add(new AuthenticationMiddleware($this));');
        } else {
            self::insertAfter($inserts, $tokens[$close], '->add(new AuthenticationMiddleware($this))');
        }

        return $anchorName;
    }

    /**
     * The `->` and closing `)` of the innermost method call whose arguments
     * hold the token at $inner.
     *
     * @param array<int, PhpToken> $tokens
     * @return array{0: int, 1: int}|null
     */
    private function enclosingCall(array $tokens, int $from, int $inner): ?array
    {
        for ($j = $inner - 1; $j > $from; $j--) {
            if (!self::isChar($tokens[$j], '(')) {
                continue;
            }
            $close = self::matchClose($tokens, $j);
            if ($close < $inner) {
                continue;
            }
            $name = self::previousSignificant($tokens, $j);
            $arrow = $name === null ? null : self::previousSignificant($tokens, $name);
            if ($name !== null && $arrow !== null && $tokens[$name]->id === T_STRING && $tokens[$arrow]->id === T_OBJECT_OPERATOR) {
                return [$arrow, $close];
            }
        }

        return null;
    }

    /**
     * The value for unauthenticatedRedirect: the loginAction through
     * Router::url(..., true), as AuthComponent's controller redirect made it
     * a full URL; a multi-line value is re-indented to sit in the service's
     * config array.
     */
    private function redirectExpression(?string $loginAction, string $nl): string
    {
        $source = trim((string) $loginAction);
        if ($source === '') {
            $source = self::PLACEHOLDER;
        }
        $lines = preg_split('/\r\n|\n/', $source) ?: [$source];
        if (count($lines) > 1) {
            $rest = array_slice($lines, 1);
            $indents = array_map(
                static fn(string $line): int => strlen($line) - strlen(ltrim($line, " \t")),
                array_filter($rest, static fn(string $line): bool => trim($line) !== ''),
            );
            $min = $indents === [] ? 0 : min($indents);
            foreach ($rest as $k => $line) {
                $rest[$k] = trim($line) === '' ? '' : str_repeat(' ', 12) . substr($line, $min);
            }
            $source = $lines[0] . $nl . implode($nl, $rest);
        }

        return 'Router::url(' . $source . ', true)';
    }

    /**
     * The message AuthComponent flashed to a guest it sent to sign in: its
     * default when the app set no authError, none for false, the app's own
     * when it is a string or a __() / __d() call on strings; anything else
     * (a variable, a method call) cannot move out of the controller, so it
     * is a MANUAL line and no message.
     *
     * @param list<string> $manual
     */
    private function flashMessage(?string $authErrorSource, array &$manual): ?string
    {
        if ($authErrorSource === null) {
            return self::DEFAULT_AUTH_ERROR;
        }
        $source = trim($authErrorSource);
        if (strcasecmp($source, 'false') === 0) {
            return null;
        }
        $literal = "(?:'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\$]|\\\\.)*\")";
        if (preg_match('/^' . $literal . '$/s', $source) === 1
            || preg_match('/^__d?\(\s*' . $literal . '(?:\s*,\s*' . $literal . ')?\s*\)$/s', $source) === 1) {
            return $source;
        }
        $manual[] = "MANUAL: the Auth config's authError is not a plain string, so getAuthenticationService() flashes no message to guests; flash it in getUnauthenticatedRedirectUrl() by hand.";

        return null;
    }

    private function serviceMethod(string $redirect, ?string $message, string $nl): string
    {
        $flash = $message === null ? [] : [
            "                if (\$request instanceof ServerRequest && !\$request->is('ajax')) {",
            '                    $request->getFlash()->set(' . $message . ', ' . self::FLASH_OPTIONS . ');',
            '                }',
            '',
        ];

        return implode($nl, [
            '    /**',
            '     * The authentication service: the session keeps the identity the app',
            '     * sets when it signs someone in, and guests go to the old login action',
            '     * the way the CakePHP 4 Auth component sent them: a full URL, no way back',
            $message === null
                ? '     * when they asked for the home page.'
                : '     * when they asked for the home page, and its message flashed first.',
            '     */',
            '    public function getAuthenticationService(ServerRequestInterface $request): AuthenticationServiceInterface',
            '    {',
            '        $service = new class ([',
            "            'unauthenticatedRedirect' => " . $redirect . ',',
            "            'queryParam' => 'redirect',",
            '        ]) extends AuthenticationService {',
            '            public function getUnauthenticatedRedirectUrl(ServerRequestInterface $request): ?string',
            '            {',
            ...$flash,
            "                \$url = \$this->getConfig('unauthenticatedRedirect');",
            '                $back = $request->getRequestTarget();',
            "                if (\$request->getMethod() !== 'GET' || \$back === '/') {",
            '                    return $url;',
            '                }',
            '',
            "                return \$url . (str_contains(\$url, '?') ? '&' : '?') . urlencode(\$this->getConfig('queryParam')) . '=' . urlencode(\$back);",
            '            }',
            '        };',
            "        \$service->loadAuthenticator('Authentication.Session');",
            '',
            '        return $service;',
            '    }',
        ]);
    }

    /**
     * Whether `$this->NAME(` starts at $i.
     *
     * @param array<int, PhpToken> $tokens
     */
    private function callsMember(array $tokens, int $i, string $name): bool
    {
        $arrow = self::nextSignificant($tokens, $i);
        $member = $arrow === null ? null : self::nextSignificant($tokens, $arrow);

        return $arrow !== null && $member !== null
            && $tokens[$arrow]->id === T_OBJECT_OPERATOR
            && $tokens[$member]->id === T_STRING && strcasecmp($tokens[$member]->text, $name) === 0;
    }

    private static function loadsAuthentication(PhpToken $arg): bool
    {
        if ($arg->id === T_CONSTANT_ENCAPSED_STRING) {
            return strcasecmp(substr($arg->text, 1, -1), 'Authentication') === 0;
        }

        return in_array($arg->id, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
            && strcasecmp(ltrim($arg->text, '\\'), 'Authentication\Plugin') === 0;
    }

    /** @param array<int, string> $inserts */
    private static function insertAfter(array &$inserts, PhpToken $token, string $text): void
    {
        $offset = $token->pos + strlen($token->text);
        $inserts[$offset] = ($inserts[$offset] ?? '') . $text;
    }

    private static function lineStart(string $php, int $pos): int
    {
        $newline = strrpos(substr($php, 0, $pos), "\n");

        return $newline === false ? 0 : $newline + 1;
    }

    /** The leading whitespace of the line holding byte $pos. */
    private static function indentAt(string $php, int $pos): string
    {
        $start = self::lineStart($php, $pos);
        $line = substr($php, $start, $pos - $start);

        return substr($line, 0, strlen($line) - strlen(ltrim($line, " \t")));
    }

    /** The last segment of a class name: `Cake\Http\BaseApplication` -> `BaseApplication`. */
    private static function lastSegment(string $name): string
    {
        return substr($name, (int) strrpos('\\' . $name, '\\'));
    }

    /**
     * The index of the next `$char` token after $i at the same bracket depth.
     *
     * @param array<int, PhpToken> $tokens
     */
    private static function nextChar(array $tokens, int $i, string $char): ?int
    {
        $depth = 0;
        $count = count($tokens);
        for ($j = $i + 1; $j < $count; $j++) {
            if ($depth === 0 && self::isChar($tokens[$j], $char)) {
                return $j;
            }
            if (self::opens($tokens[$j])) {
                $depth++;
            } elseif (self::closes($tokens[$j])) {
                $depth--;
            }
        }

        return null;
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

    /** @param array<int, PhpToken> $tokens */
    private static function nextSignificant(array $tokens, int $i): ?int
    {
        $count = count($tokens);
        for ($j = $i + 1; $j < $count; $j++) {
            if (!in_array($tokens[$j]->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $j;
            }
        }

        return null;
    }

    /** @param array<int, PhpToken> $tokens */
    private static function previousSignificant(array $tokens, int $i): ?int
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (!in_array($tokens[$j]->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $j;
            }
        }

        return null;
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
