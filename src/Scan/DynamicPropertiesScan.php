<?php

declare(strict_types=1);

namespace Waynestate\Booster\Scan;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Finds the dynamic properties PHP 8.2 deprecates: `$this->name = ...` in a
 * class that never declares `name`, has no `#[AllowDynamicProperties]` and
 * no `__set()`. Rector has no safe fix (declare the property? which type?
 * or allow it?), so this only names them, file and line, for a person.
 *
 * It reads tokens, not text, and follows each class up its parents and
 * traits. When a parent or a trait lives outside the app's own code (in a
 * vendor folder), it cannot know what that one declares, so the class goes
 * on the "not checked" list instead of being cleared. A booster that says
 * "nominal" without a sensor reading is how rockets end up in the ocean.
 */
final class DynamicPropertiesScan
{
    /** The PHP version that deprecates creating a property nobody declared. */
    public const DEPRECATED_IN = '8.2';

    private const SKIPPED_DIRECTORIES = ['vendor', 'node_modules', 'storage', '.git'];

    /** The longest file it reads; bigger ones are generated, not written. */
    private const MAX_BYTES = 1048576;

    /**
     * True when a PHP hop from $from to $to crosses PHP 8.2, the step that
     * should name the dynamic properties.
     */
    public static function appliesTo(?string $from, ?string $to): bool
    {
        return $from !== null && $to !== null
            && version_compare($from, self::DEPRECATED_IN, '<')
            && version_compare($to, self::DEPRECATED_IN, '>=');
    }

    public function scan(string $appPath): DynamicPropertiesResult
    {
        /** @var array<string, array{file: string, kind: string, name: string, parent: ?string, traits: list<string>, props: array<string, true>, magicSet: bool, allow: bool, writes: list<array{0: string, 1: int}>}> $types */
        $types = [];
        foreach (self::files($appPath) as $relative => $absolute) {
            $source = @file_get_contents($absolute);
            if (!is_string($source) || strlen($source) > self::MAX_BYTES) {
                continue;
            }
            foreach (self::parse($source) as $fqn => $type) {
                // The first declaration wins: a second class with the same
                // name sits in another entry point, and either answer is a guess.
                $types[strtolower($fqn)] ??= ['file' => $relative, ...$type];
            }
        }

        $findings = [];
        $unchecked = [];
        $classes = 0;
        foreach ($types as $type) {
            if ($type['kind'] !== 'class') {
                continue;
            }
            $classes++;
            $resolved = self::resolve($type, $types, []);
            if ($resolved['allow'] || $type['writes'] === []) {
                continue;
            }
            if ($resolved['unknown'] !== null) {
                $unchecked[] = sprintf('%s (%s, which is not in the app\'s own code)', $type['name'], $resolved['unknown']);
                continue;
            }
            foreach ($type['writes'] as [$property, $line]) {
                if (!isset($resolved['props'][$property])) {
                    $findings[] = new DynamicPropertyFinding($type['file'], $line, $type['name'], $property);
                    // One line per property is enough to point at it.
                    $resolved['props'][$property] = true;
                }
            }
        }
        usort($findings, static fn(DynamicPropertyFinding $a, DynamicPropertyFinding $b): int => [$a->file, $a->line] <=> [$b->file, $b->line]);
        sort($unchecked);

        return new DynamicPropertiesResult($findings, $unchecked, $classes);
    }

    /**
     * Every property a class has, through its parents and traits, and
     * whether anything up the chain allows dynamic ones. `unknown` names the
     * first parent or trait it could not read.
     *
     * @param array<string, mixed>                $type
     * @param array<string, array<string, mixed>> $types
     * @param array<string, true>                 $seen
     *
     * @return array{props: array<string, true>, allow: bool, unknown: ?string}
     */
    private static function resolve(array $type, array $types, array $seen): array
    {
        /** @var array<string, true> $props */
        $props = $type['props'];
        $allow = (bool) $type['allow'] || (bool) $type['magicSet'];
        $unknown = null;
        /** @var list<string> $traits */
        $traits = $type['traits'];
        $parent = is_string($type['parent']) ? [$type['parent']] : [];
        foreach ([...$traits, ...$parent] as $name) {
            $key = strtolower($name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if (isset($types[$key])) {
                $up = self::resolve($types[$key], $types, $seen);
            } else {
                $up = self::internal($name);
            }
            $props += $up['props'];
            $allow = $allow || $up['allow'];
            $unknown ??= $up['unknown'];
        }

        return ['props' => $props, 'allow' => $allow, 'unknown' => $allow ? null : $unknown];
    }

    /**
     * A parent PHP itself ships (Exception, ArrayObject, stdClass), read by
     * reflection; anything else is unknown.
     *
     * @return array{props: array<string, true>, allow: bool, unknown: ?string}
     */
    private static function internal(string $name): array
    {
        if (!class_exists($name, false) && !trait_exists($name, false)) {
            return ['props' => [], 'allow' => false, 'unknown' => $name];
        }
        $class = new \ReflectionClass($name);
        if (!$class->isInternal()) {
            return ['props' => [], 'allow' => false, 'unknown' => $name];
        }
        $props = [];
        foreach ($class->getProperties() as $property) {
            $props[$property->getName()] = true;
        }
        $allow = $class->hasMethod('__set');
        for ($c = $class; $c !== false && !$allow; $c = $c->getParentClass()) {
            $allow = $c->getAttributes(\AllowDynamicProperties::class) !== [];
        }

        return ['props' => $props, 'allow' => $allow, 'unknown' => null];
    }

    /**
     * The classes and traits one file declares, keyed by full name.
     *
     * @return array<string, array{kind: string, name: string, parent: ?string, traits: list<string>, props: array<string, true>, magicSet: bool, allow: bool, writes: list<array{0: string, 1: int}>}>
     */
    private static function parse(string $source): array
    {
        try {
            $tokens = \PhpToken::tokenize($source);
        } catch (\Throwable) {
            return [];
        }
        $tokens = array_values(array_filter($tokens, static fn(\PhpToken $t): bool => !$t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])));
        $count = count($tokens);

        $namespace = '';
        /** @var array<string, string> $imports */
        $imports = [];
        /** @var array<string, array{kind: string, name: string, parent: ?string, traits: list<string>, props: array<string, true>, magicSet: bool, allow: bool, writes: list<array{0: string, 1: int}>}> $types */
        $types = [];
        /** @var list<array{key: ?string, depth: int}> $stack the open class-like bodies, innermost last */
        $stack = [];
        $depth = 0;
        $paren = 0;
        $function = null;
        $functionDepth = null;

        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            $frame = $stack === [] ? null : $stack[count($stack) - 1];
            $atBody = $frame !== null && $depth === $frame['depth'];

            if ($t->text === '{' || $t->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
                continue;
            }
            if ($t->text === '}') {
                $depth--;
                if ($frame !== null && $depth < $frame['depth']) {
                    array_pop($stack);
                }
                if ($functionDepth !== null && $depth <= $functionDepth) {
                    $function = null;
                    $functionDepth = null;
                }
                continue;
            }
            if ($t->text === '(') {
                $paren++;
                continue;
            }
            if ($t->text === ')') {
                $paren--;
                continue;
            }
            if ($stack === [] && $t->is(T_NAMESPACE) && isset($tokens[$i + 1]) && $tokens[$i + 1]->is([T_STRING, T_NAME_QUALIFIED])) {
                $namespace = $tokens[$i + 1]->text;
                $imports = [];
                continue;
            }
            if ($stack === [] && $function === null && $t->is(T_USE)) {
                $i = self::imports($tokens, $i, $imports);
                continue;
            }

            if ($t->is([T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM])) {
                $prev = $tokens[$i - 1] ?? null;
                if ($prev !== null && $prev->is(T_DOUBLE_COLON)) {
                    continue; // Foo::class
                }
                $anonymous = $prev !== null && $prev->is(T_NEW);
                $kind = $t->is(T_TRAIT) ? 'trait' : ($t->is(T_CLASS) ? 'class' : 'other');
                $name = $anonymous ? null : ($tokens[$i + 1]->text ?? null);
                $allow = false;
                for ($back = $i - 1; $back >= 0; $back--) {
                    $b = $tokens[$back];
                    if ($b->text === ';' || $b->text === '{' || $b->text === '}' || $b->is(T_OPEN_TAG)) {
                        break;
                    }
                    if (str_contains(strtolower($b->text), 'allowdynamicproperties')) {
                        $allow = true;
                    }
                }
                $parent = null;
                $j = $i + 1;
                while ($j < $count && $tokens[$j]->text !== '{') {
                    if ($tokens[$j]->is(T_EXTENDS) && $kind === 'class' && isset($tokens[$j + 1])) {
                        $parent = self::qualify($tokens[$j + 1]->text, $namespace, $imports);
                    }
                    $j++;
                }
                $key = null;
                if ($name !== null && $kind !== 'other') {
                    $fqn = ($namespace !== '' ? $namespace . '\\' : '') . $name;
                    $key = $fqn;
                    $types[$fqn] = ['kind' => $kind, 'name' => $fqn, 'parent' => $parent, 'traits' => [], 'props' => [], 'magicSet' => false, 'allow' => $allow, 'writes' => []];
                }
                $i = $j;
                $depth++;
                $stack[] = ['key' => $key, 'depth' => $depth];
                continue;
            }
            if ($frame === null) {
                continue;
            }
            $key = $frame['key'];
            if ($key !== null && !isset($types[$key])) {
                $key = null;
            }

            if ($atBody && $paren === 0 && $t->is(T_USE) && $key !== null) {
                for ($j = $i + 1; $j < $count && $tokens[$j]->text !== ';' && $tokens[$j]->text !== '{'; $j++) {
                    if ($tokens[$j]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                        $types[$key]['traits'][] = self::qualify($tokens[$j]->text, $namespace, $imports);
                    }
                }
                $i = $j - 1;
                continue;
            }
            if ($atBody && $t->is(T_FUNCTION)) {
                $function = strtolower($tokens[$i + 1]->text ?? '');
                $functionDepth = $depth;
                if ($function === '__set' && $key !== null) {
                    $types[$key]['magicSet'] = true;
                }
                continue;
            }
            if ($t->is(T_VARIABLE) && $key !== null) {
                if ($atBody && $paren === 0 && $t->text !== '$this') {
                    // public $a, $b = 1; var $c; static $d;
                    $types[$key]['props'][substr($t->text, 1)] = true;
                } elseif ($atBody && $paren === 1 && $function === '__construct' && self::promoted($tokens, $i)) {
                    $types[$key]['props'][substr($t->text, 1)] = true;
                } elseif ($t->text === '$this' && $depth > $frame['depth']) {
                    $write = self::write($tokens, $i);
                    if ($write !== null) {
                        $types[$key]['writes'][] = [$write, $t->line];
                    }
                }
            }
        }

        return $types;
    }

    /**
     * Reads a top-level `use A\B;` or `use A\B as C, D;` into $imports and
     * returns the index of its closing semicolon.
     *
     * @param list<\PhpToken>       $tokens
     * @param array<string, string> $imports
     */
    private static function imports(array $tokens, int $i, array &$imports): int
    {
        if (($tokens[$i + 1]->text ?? '') === '(') {
            return $i; // a closure's use (...), not an import
        }
        $name = null;
        $skip = false;
        for ($j = $i + 1; $j < count($tokens); $j++) {
            $t = $tokens[$j];
            if ($t->text === ';') {
                break;
            }
            if ($skip) {
                continue;
            }
            if ($t->is([T_FUNCTION, T_CONST]) || $t->text === '{') {
                // use function, use const, and group uses: not class names
                // this scan needs, so it reads past them to the semicolon.
                $skip = true;
                continue;
            }
            if ($t->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) && $name === null) {
                $name = ltrim($t->text, '\\');
                $alias = substr($name, (int) strrpos('\\' . $name, '\\'));
                $imports[strtolower($alias)] = $name;
            } elseif ($t->is(T_AS) && $name !== null && isset($tokens[$j + 1])) {
                unset($imports[strtolower(substr($name, (int) strrpos('\\' . $name, '\\')))]);
                $imports[strtolower($tokens[$j + 1]->text)] = $name;
                $j++;
            } elseif ($t->text === ',') {
                $name = null;
            }
        }

        return $j;
    }

    /** @param array<string, string> $imports */
    private static function qualify(string $name, string $namespace, array $imports): string
    {
        if (str_starts_with($name, '\\')) {
            return substr($name, 1);
        }
        $first = strtolower(explode('\\', $name)[0]);
        if (isset($imports[$first])) {
            $rest = substr($name, strlen($first));

            return $imports[$first] . $rest;
        }

        return ($namespace !== '' ? $namespace . '\\' : '') . $name;
    }

    /**
     * True when the constructor parameter ending at $i carries a visibility
     * or readonly modifier, which makes it a promoted property.
     *
     * @param list<\PhpToken> $tokens
     */
    private static function promoted(array $tokens, int $i): bool
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            $t = $tokens[$j];
            if ($t->text === '(' || $t->text === ',') {
                return false;
            }
            if ($t->is([T_PUBLIC, T_PROTECTED, T_PRIVATE, T_READONLY]) || in_array($t->getTokenName(), ['T_PUBLIC_SET', 'T_PROTECTED_SET', 'T_PRIVATE_SET'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The property name when `$this` at $i starts an assignment to a plain
     * property: `$this->name = `, `$this->name[] = `, `$this->name ??= `.
     * Reads (`$this->name->x = `), calls and `$this->{$x}` return null.
     *
     * @param list<\PhpToken> $tokens
     */
    private static function write(array $tokens, int $i): ?string
    {
        if (!isset($tokens[$i + 2]) || !$tokens[$i + 1]->is(T_OBJECT_OPERATOR) || !$tokens[$i + 2]->is(T_STRING)) {
            return null;
        }
        $j = $i + 3;
        while (isset($tokens[$j]) && $tokens[$j]->text === '[') {
            $level = 0;
            for (; isset($tokens[$j]); $j++) {
                if ($tokens[$j]->text === '[') {
                    $level++;
                } elseif ($tokens[$j]->text === ']' && --$level === 0) {
                    break;
                }
            }
            $j++;
        }
        $next = $tokens[$j] ?? null;
        if ($next === null) {
            return null;
        }
        $assigns = $next->text === '=' || $next->is([
            T_COALESCE_EQUAL, T_CONCAT_EQUAL, T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL,
            T_MOD_EQUAL, T_POW_EQUAL, T_AND_EQUAL, T_OR_EQUAL, T_XOR_EQUAL, T_SL_EQUAL, T_SR_EQUAL,
        ]);

        return $assigns ? $tokens[$i + 2]->text : null;
    }

    /**
     * Relative path (forward slashes) to absolute path, sorted.
     *
     * @return array<string, string>
     */
    private static function files(string $appPath): array
    {
        $base = str_replace('\\', '/', rtrim($appPath, '/\\'));
        if (!is_dir($base)) {
            return [];
        }
        $files = [];
        // Prune vendor/ and friends on the way down, so a Laravel app's
        // ten thousand vendor files are never even listed.
        $directories = new \RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            static fn(\SplFileInfo $file): bool => !$file->isDir() || !in_array($file->getFilename(), self::SKIPPED_DIRECTORIES, true),
        );
        foreach (new RecursiveIteratorIterator($directories) as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }
            $name = strtolower($file->getFilename());
            if (!str_ends_with($name, '.php') && !str_ends_with($name, '.inc') && !str_ends_with($name, '.phtml')) {
                continue;
            }
            $files[str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1))] = $file->getPathname();
        }
        ksort($files);

        return $files;
    }
}
