<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run\Slim;

use Composer\InstalledVersions;
use PhpToken;

/**
 * Moves one PHP file off Twig 1 and 2's underscore class names
 * (`Twig_Environment`, `Twig_SimpleFilter`, `\Twig_Extension`), which Twig 3
 * removed, onto the namespaced classes, and Twig-View 2's
 * `new Slim\Views\Twig($path, $settings)` onto Twig-View 3's
 * `Slim\Views\Twig::create($path, $settings)` (its constructor now takes a
 * loader). The Slim 3 to 4 hop moves Twig-View to 3 and Twig to 3, so without
 * this every page of a Twig app dies on a missing class (testeval,
 * 2026-11-20).
 *
 * The name map is Rector's own (rector-symfony's Twig set, the list Twig's
 * underscore names were retired with), read from the Rector Booster ships, so
 * it stays as maintained as Rector is. An import keeps its name through an
 * alias (`use Twig_SimpleFilter;` becomes
 * `use Twig\TwigFilter as Twig_SimpleFilter;`), so the bare names in the file
 * keep working; a fully qualified name, or a bare one in a file with no
 * namespace, becomes the new fully qualified name. It works on tokens, so
 * strings and comments are never touched, and a second run changes nothing.
 *
 * Twig took the underscores out of its names years ago; this just updates
 * the address book.
 */
final class TwigNameRewriter
{
    /** Where rector/rector keeps the rector-symfony Twig set. */
    public const RECTOR_SET = '/vendor/rector/rector-symfony/config/sets/twig/composer-based.php';

    private const TWIG_VIEW = 'slim\\views\\twig';

    /** @var array<string, string>|null lower-case underscore name to the namespaced class, once read */
    private static ?array $map = null;

    /** @var array<int, PhpToken> */
    private array $tokens = [];

    /**
     * @param array<string, string>|null $nameMap lower-case underscore name to namespaced class; Rector's when null
     */
    public function __construct(private readonly ?array $nameMap = null) {}

    /**
     * Rector's underscore-to-namespace map for Twig, lower-case keys; empty
     * when the set cannot be found or read.
     *
     * @return array<string, string>
     */
    public static function rectorMap(?string $file = null): array
    {
        if ($file === null && self::$map !== null) {
            return self::$map;
        }
        $path = $file ?? self::rectorRoot() . self::RECTOR_SET;
        $text = is_file($path) ? (string) file_get_contents($path) : '';
        $map = [];
        if (preg_match_all("/'(Twig_[A-Za-z0-9_]+)'\\s*=>\\s*'(Twig\\\\[A-Za-z0-9_\\\\]+)'/", $text, $pairs, PREG_SET_ORDER) > 0) {
            foreach ($pairs as [, $old, $new]) {
                // The first namespaced target wins; later entries for the same name are Rector's older detours.
                $map[strtolower($old)] ??= str_replace('\\\\', '\\', $new);
            }
        }
        if ($file === null) {
            self::$map = $map;
        }

        return $map;
    }

    /**
     * The new code, how many names moved, and the lines that name an
     * underscore Twig class the map does not know (kept as they are).
     *
     * @return array{code: string, renamed: int, unknown: list<int>}
     */
    public function rewrite(string $php): array
    {
        $map = $this->nameMap ?? self::rectorMap();
        $this->tokens = PhpToken::tokenize($php);
        $namespaced = $this->namespaced();
        /** @var array<string, string> $aliases lower-case imported name to the lower-case class it stands for */
        $aliases = [];
        /** @var array<int, array{int, string}> $edits */
        $edits = [];
        $renamed = 0;
        $unknown = [];

        $count = count($this->tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $this->tokens[$i];
            if ($token->is(T_USE) && $this->isClassImport($i)) {
                for ($j = $i + 1; $j < $count && $this->tokens[$j]->text !== ';' && $this->tokens[$j]->text !== '{'; $j++) {
                    $name = $this->tokens[$j];
                    if (!$name->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                        continue;
                    }
                    $full = strtolower(ltrim($name->text, '\\'));
                    $after = $this->next($j);
                    $hasAlias = $after !== null && $this->tokens[$after]->is(T_AS);
                    $alias = $hasAlias ? $this->tokens[(int) $this->next((int) $after)]->text : self::lastSegment($name->text);
                    $aliases[strtolower($alias)] = $full;
                    if (str_starts_with($full, 'twig_')) {
                        if (isset($map[$full])) {
                            $edits[$name->pos] = [strlen($name->text), $map[$full] . ($hasAlias ? '' : ' as ' . ltrim($name->text, '\\'))];
                            $renamed++;
                        } else {
                            $unknown[] = $name->line;
                        }
                    }
                    if ($hasAlias) {
                        $j = (int) $this->next((int) $after);
                    }
                }
                $i = $j;

                continue;
            }

            $full = null;
            $global = false;
            if ($token->is(T_NAME_FULLY_QUALIFIED)) {
                $full = strtolower(ltrim($token->text, '\\'));
            } elseif ($token->is(T_NAME_QUALIFIED)) {
                $first = strtolower(explode('\\', $token->text)[0]);
                $full = isset($aliases[$first]) ? $aliases[$first] . strtolower(substr($token->text, strlen($first))) : ($namespaced ? null : strtolower($token->text));
            } elseif ($token->is(T_STRING) && $this->isClassReference($i)) {
                $lower = strtolower($token->text);
                if (isset($aliases[$lower])) {
                    $full = $aliases[$lower];
                } elseif (!$namespaced && str_starts_with($lower, 'twig_')) {
                    $full = $lower;
                    $global = true;
                }
            }
            if ($full === null) {
                continue;
            }

            if ($full === self::TWIG_VIEW && $this->isNewCall($i)) {
                // new Twig($path, $settings) is Twig::create($path, $settings) on Twig-View 3.
                $new = (int) $this->previous($i);
                $edits[$this->tokens[$new]->pos] = [$token->pos + strlen($token->text) - $this->tokens[$new]->pos, $token->text . '::create'];
                $renamed++;

                continue;
            }
            if (!str_starts_with($full, 'twig_') || ($token->is(T_STRING) && !$global)) {
                // A bare imported name: its import was moved (or reported) already.
                continue;
            }
            if (!isset($map[$full])) {
                $unknown[] = $token->line;

                continue;
            }
            $edits[$token->pos] = [strlen($token->text), '\\' . $map[$full]];
            $renamed++;
        }

        krsort($edits);
        foreach ($edits as $offset => [$length, $replacement]) {
            $php = substr_replace($php, $replacement, $offset, $length);
        }
        $unknown = array_values(array_unique($unknown));
        sort($unknown);

        return ['code' => $php, 'renamed' => $renamed, 'unknown' => $unknown];
    }

    private static function rectorRoot(): string
    {
        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('rector/rector')) {
            $root = InstalledVersions::getInstallPath('rector/rector');
            if ($root !== null) {
                return $root;
            }
        }

        return dirname(__DIR__, 3) . '/vendor/rector/rector';
    }

    /** `new X(`: the token before $i is `new` and the one after is `(`. */
    private function isNewCall(int $i): bool
    {
        $previous = $this->previous($i);
        $next = $this->next($i);

        return $previous !== null && $this->tokens[$previous]->is(T_NEW) && $next !== null && $this->tokens[$next]->text === '(';
    }

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

    /** A `use` that imports classes: not a closure's `use (`, not `use function` or `use const`, not a trait use. */
    private function isClassImport(int $i): bool
    {
        $next = $this->next($i);
        if ($next === null || $this->tokens[$next]->is([T_FUNCTION, T_CONST]) || $this->tokens[$next]->text === '(') {
            return false;
        }
        // A trait use sits inside a class body: an open brace with no matching close before it.
        $depth = 0;
        for ($j = 0; $j < $i; $j++) {
            $text = $this->tokens[$j]->text;
            if ($text === '{' || $this->tokens[$j]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($text === '}') {
                $depth--;
            }
        }

        return $depth === 0 || $this->inNamespaceBlockOnly($i);
    }

    /** True when every brace open before $i belongs to a `namespace X { ... }` block. */
    private function inNamespaceBlockOnly(int $i): bool
    {
        $stack = [];
        for ($j = 0; $j < $i; $j++) {
            $token = $this->tokens[$j];
            if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $stack[] = $this->opensNamespace($j);
            } elseif ($token->text === '}') {
                array_pop($stack);
            }
        }

        return !in_array(false, $stack, true);
    }

    private function opensNamespace(int $brace): bool
    {
        for ($j = $brace - 1; $j >= 0; $j--) {
            $token = $this->tokens[$j];
            if ($token->isIgnorable() || $token->is([T_STRING, T_NAME_QUALIFIED])) {
                continue;
            }

            return $token->is(T_NAMESPACE);
        }

        return false;
    }

    /** A bare name used as a class (a type, `new X`, `X::`, `extends X`), not a method, property, function or constant name. */
    private function isClassReference(int $i): bool
    {
        $previous = $this->previous($i);
        if ($previous !== null && $this->tokens[$previous]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST, T_CLASS, T_INTERFACE, T_TRAIT])) {
            return false;
        }
        $next = $this->next($i);
        if ($next === null) {
            return true;
        }
        $text = $this->tokens[$next]->text;
        if ($text === ':' || $text === '=') {
            return false;
        }

        return $text !== '(' || ($previous !== null && $this->tokens[$previous]->is(T_NEW));
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

    private static function lastSegment(string $name): string
    {
        $slash = strrpos($name, '\\');

        return $slash === false ? $name : substr($name, $slash + 1);
    }
}
