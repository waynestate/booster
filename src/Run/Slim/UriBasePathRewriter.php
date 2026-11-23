<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run\Slim;

use PhpToken;

/**
 * Slim 3's Uri knew the folder the app is served from (`getBasePath()`,
 * `getBaseUrl()`); Slim 4's PSR-7 Uri does not, so a Slim 3 call answers a
 * 500 on Slim 4. Found on a Slim 3 fleet app (2026-11-20): a signed-in `/login/`
 * redirected to `$request->getUri()->getBasePath()`. Slim 4 keeps the base
 * path on the app, and the hop's routing glue (LegacyRouting) copies it onto
 * every request as RouteContext::BASE_PATH before the app's middleware and
 * routes run. So `$request->getUri()->getBasePath()` on a plain variable
 * becomes `$request->getAttribute(\Slim\Routing\RouteContext::BASE_PATH, '')`.
 * A call it cannot place (another receiver, a Uri held in a variable,
 * `getBaseUrl()`) becomes a MANUAL line with its line number. Tokens only,
 * so strings and comments are never touched, and a second run changes
 * nothing. The app moved house; this only fixes the return address.
 */
final class UriBasePathRewriter
{
    /** What a rewritten call reads. */
    public const ATTRIBUTE = "getAttribute(\\Slim\\Routing\\RouteContext::BASE_PATH, '')";

    /** @var array<int, PhpToken> */
    private array $tokens = [];

    /**
     * @return array{code: string, rewritten: int, manual: list<string>}
     */
    public function rewrite(string $php): array
    {
        $this->tokens = PhpToken::tokenize($php);
        $edits = [];
        $manual = [];
        foreach ($this->tokens as $i => $token) {
            if (!$token->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                continue;
            }
            $method = $this->next($i);
            if ($method === null || !$this->tokens[$method]->is(T_STRING)) {
                continue;
            }
            $name = strtolower($this->tokens[$method]->text);
            if ($name !== 'getbasepath' && $name !== 'getbaseurl') {
                continue;
            }
            $open = $this->next($method);
            $close = $open === null ? null : $this->next($open);
            if ($open === null || $close === null || $this->tokens[$open]->text !== '(' || $this->tokens[$close]->text !== ')') {
                continue;
            }
            $receiver = $this->uriReceiver($i);
            if ($receiver === null) {
                continue;
            }
            $line = $token->line;
            if ($name === 'getbaseurl') {
                $manual[] = sprintf("MANUAL: line %d: Slim 4's Uri has no getBaseUrl(); build it from the request's scheme and host plus \$request->%s.", $line, self::ATTRIBUTE);
                continue;
            }
            if ($receiver['variable'] === null) {
                $manual[] = sprintf("MANUAL: line %d: Slim 4's Uri has no getBasePath(); read \$request->%s on the request the route or middleware gets, or \$app->getBasePath().", $line, self::ATTRIBUTE);
                continue;
            }
            $edits[] = [$this->tokens[$receiver['from']]->pos, $this->tokens[$close]->pos + 1 - $this->tokens[$receiver['from']]->pos, $receiver['variable'] . $receiver['arrow'] . self::ATTRIBUTE];
        }

        $code = $php;
        usort($edits, static fn(array $a, array $b): int => $b[0] <=> $a[0]);
        foreach ($edits as [$offset, $length, $replacement]) {
            $code = substr_replace($code, $replacement, $offset, $length);
        }

        return ['code' => $code, 'rewritten' => count($edits), 'manual' => array_values(array_unique($manual))];
    }

    /**
     * Whether the call whose `->` sits at $arrow reads a Slim 3 Uri: chained
     * on `->getUri()`, or on a variable or property named like a Uri. The
     * variable is set (with the token the rewrite starts from) only for
     * `$request->getUri()->...` on a plain variable other than $this; any
     * other Uri receiver gets a MANUAL line. Null when it is not a Uri at all
     * (`$app->getBasePath()` is Slim 4's own and stays).
     *
     * @return array{variable: ?string, from: int, arrow: string}|null
     */
    private function uriReceiver(int $arrow): ?array
    {
        $before = $this->previous($arrow);
        if ($before === null) {
            return null;
        }
        $token = $this->tokens[$before];
        if ($token->text === ')') {
            $open = $this->matchingBackward($before);
            $method = $open === null ? null : $this->previous($open);
            if ($method === null || strtolower($this->tokens[$method]->text) !== 'geturi' || !$this->isMemberAccess($method)) {
                return null;
            }
            $innerArrow = (int) $this->previous($method);
            $owner = $this->previous($innerArrow);
            $plain = $owner !== null
                && $this->next((int) $open) === $before
                && $this->tokens[$owner]->is(T_VARIABLE)
                && $this->tokens[$owner]->text !== '$this'
                && !$this->isMemberAccess($owner);

            return $plain ? ['variable' => $this->tokens[(int) $owner]->text, 'from' => (int) $owner, 'arrow' => $this->tokens[$innerArrow]->text] : ['variable' => null, 'from' => $before, 'arrow' => '->'];
        }
        if (($token->is(T_VARIABLE) || ($token->is(T_STRING) && $this->isMemberAccess($before))) && stripos($token->text, 'uri') !== false) {
            return ['variable' => null, 'from' => $before, 'arrow' => '->'];
        }

        return null;
    }

    /** Whether the token at $i follows `->`, `?->` or `::`. */
    private function isMemberAccess(int $i): bool
    {
        $before = $this->previous($i);

        return $before !== null && $this->tokens[$before]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON]);
    }

    /** Index of the bracket opening the one that closes at $close. */
    private function matchingBackward(int $close): ?int
    {
        $depth = 0;
        for ($j = $close; $j >= 0; $j--) {
            $text = $this->tokens[$j]->text;
            if (in_array($text, [')', ']', '}'], true)) {
                $depth++;
            } elseif (in_array($text, ['(', '[', '{'], true) || $this->tokens[$j]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
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
}
