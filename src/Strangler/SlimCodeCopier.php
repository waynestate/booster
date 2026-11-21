<?php

declare(strict_types=1);

namespace Waynestate\Booster\Strangler;

/**
 * Copies legacy Slim code into the Laravel half, renamed so the two `App\`
 * namespaces never meet (design sections 1, 4 and 10.1 to 10.4).
 *
 * Every class under one of the legacy app's PSR-4 prefixes keeps its whole
 * name under `App\Http\Controllers\Legacy\`: legacy
 * `App\Application\Actions\User\ListUsersAction` becomes
 * `App\Http\Controllers\Legacy\App\Application\Actions\User\ListUsersAction`
 * in `app/Http/Controllers/Legacy/App/Application/Actions/User/`. One rule for
 * every class means a handler and the classes it reaches can be copied in any
 * order, by any move, and still name each other. A copy follows every app
 * class the code names (parents, imports, `new`, type hints, `::class`),
 * transitively, so the finish step can delete `legacy/` and the moved code
 * still runs. The copy changes names only: the code is the legacy code, dead
 * calls included (section 10.4).
 *
 * Vendor classes stay what they are; the copier reports the Composer
 * packages they come from, read from legacy/composer.lock, so the root can
 * require them.
 */
final class SlimCodeCopier
{
    public const LEGACY_NAMESPACE = 'App\\Http\\Controllers\\Legacy\\';

    public const DIR = 'app/Http/Controllers/Legacy/';

    /** @var array<string, string> lower-cased FQCN to legacy-relative file */
    private array $classFiles;

    /** @var list<string> legacy PSR-4 prefixes, each ending in a backslash */
    private array $prefixes;

    /** @var array<string, string> constant name to the legacy file:line that defines it */
    private array $constants = [];

    /** @var array<string, array{string, int, int}> constant name to the file and tokens of its define() value */
    private array $constantValues = [];

    /**
     * Bootstrap mode (design 10.2): the moved units run a copy of the legacy
     * front controller, so the constants it defines exist in Laravel and a
     * require or include is repointed at the copied script it names.
     */
    public bool $bootstrap = false;

    /**
     * Fragment mode: every class name is written fully qualified, and
     * `__CLASS__`, `self` and `static` become the copied class, so a
     * statement can be pasted into a file with no namespace (the generated
     * routes file).
     */
    public bool $qualify = false;

    /** @var array<string, string> legacy-relative routes script to the app-relative file that replaces it */
    public array $routeScripts = [];

    /** @var array<string, bool> routes scripts the copied front controller loads */
    public array $routeHits = [];

    /** @var array<string, bool> legacy-relative scripts copied */
    public array $scripts = [];

    /** Where copied legacy scripts (the front controller and what it requires) live. */
    public const BOOT = self::DIR . 'boot/';

    /** @var array<string, string> lower-cased namespace prefix to Composer package */
    private array $packagePrefixes = [];

    /** @var array<string, string> package to locked version */
    private array $lockedVersions = [];

    /** @var list<string> app-relative files written */
    public array $written = [];

    /** @var array<string, string> app-relative file to the code staged for it */
    private array $pending = [];

    /** @var list<string> */
    public array $manual = [];

    /** @var list<array{file: string, line: int, reason: string}> */
    public array $refusals = [];

    /** @var array<string, bool> vendor packages the copied code names */
    public array $packages = [];

    /** @var array<string, bool> */
    private array $done = [];

    /**
     * @param array<string, SlimSource> $sources legacy-relative path to source
     */
    public function __construct(private readonly string $appRoot, private readonly array $sources)
    {
        $this->classFiles = self::classFiles($sources);
        $composer = self::json($appRoot . '/legacy/composer.json');
        $this->prefixes = [];
        foreach (array_keys((array) ($composer['autoload']['psr-4'] ?? [])) as $prefix) {
            if (is_string($prefix) && $prefix !== '') {
                $this->prefixes[] = rtrim($prefix, '\\') . '\\';
            }
        }
        foreach ($sources as $path => $src) {
            foreach ($src->t as $i => [$id, $text, $line]) {
                if ($id !== T_STRING || strtolower($text) !== 'define') {
                    continue;
                }
                $open = $src->next($i);
                if ($open === null || $src->t[$open][1] !== '(' || !isset($src->parens[$open])) {
                    continue;
                }
                $args = $src->args($open, $src->parens[$open]);
                $name = isset($args[0]) ? $src->stringAt($args[0][0], $args[0][1]) : null;
                if ($name !== null && preg_match('/^\w+$/', $name) === 1) {
                    $this->constants[$name] ??= 'legacy/' . $path . ':' . $line;
                    if (isset($args[1])) {
                        $this->constantValues[$name] ??= [$path, $args[1][0], $args[1][1]];
                    }
                }
            }
        }
        $lock = self::json($appRoot . '/legacy/composer.lock');
        foreach (array_merge((array) ($lock['packages'] ?? []), (array) ($lock['packages-dev'] ?? [])) as $package) {
            if (!is_array($package) || !is_string($package['name'] ?? null)) {
                continue;
            }
            $this->lockedVersions[$package['name']] = (string) ($package['version'] ?? '');
            foreach (['psr-4', 'psr-0'] as $kind) {
                foreach (array_keys((array) ($package['autoload'][$kind] ?? [])) as $prefix) {
                    if (is_string($prefix) && $prefix !== '') {
                        $this->packagePrefixes[strtolower(rtrim($prefix, '\\') . '\\')] = $package['name'];
                    }
                }
            }
        }
    }

    /**
     * Each declared class's legacy-relative file; when two files declare one
     * class, the one named after it (the one the autoloader loads).
     *
     * @param array<string, SlimSource> $sources
     *
     * @return array<string, string>
     */
    public static function classFiles(array $sources): array
    {
        $files = [];
        foreach ($sources as $path => $src) {
            foreach ($src->classes as [, , $fqcn]) {
                $short = substr($fqcn, (int) strrpos('\\' . $fqcn, '\\'));
                if (!isset($files[strtolower($fqcn)]) || strcasecmp(basename($path, '.php'), $short) === 0) {
                    $files[strtolower($fqcn)] = $path;
                }
            }
        }

        return $files;
    }

    /** The Laravel-half name of a legacy app class, or null when it is not one. */
    public function map(string $fqcn): ?string
    {
        $fqcn = ltrim($fqcn, '\\');
        foreach ($this->prefixes as $prefix) {
            if (stripos($fqcn . '\\', $prefix) === 0) {
                return self::LEGACY_NAMESPACE . $fqcn;
            }
        }

        return null;
    }

    public function isAppClass(string $fqcn): bool
    {
        return isset($this->classFiles[strtolower(ltrim($fqcn, '\\'))]) && $this->map($fqcn) !== null;
    }

    /** The app-relative file a mapped class lives in. */
    public static function targetFile(string $mappedFqcn): string
    {
        return 'app/' . str_replace('\\', '/', substr($mappedFqcn, 4)) . '.php';
    }

    /**
     * Copy one app class and everything it names. Returns false when the
     * class is not app code.
     */
    public function copyClass(string $fqcn): bool
    {
        $fqcn = ltrim($fqcn, '\\');
        $key = strtolower($fqcn);
        if (isset($this->done[$key])) {
            return true;
        }
        $path = $this->classFiles[$key] ?? null;
        $mapped = $this->map($fqcn);
        if ($path === null || $mapped === null) {
            return false;
        }
        $this->done[$key] = true;
        // Every class in the file moves with it, so they keep sharing it.
        foreach ($this->sources[$path]->classes as [, , $other]) {
            $this->done[strtolower($other)] = true;
        }
        $src = $this->sources[$path];
        [$code, $refs] = $this->rewrite($src, $path, 0, count($src->t) - 1);
        $target = self::targetFile($mapped);
        $this->write($target, $code);
        foreach ($refs as $ref) {
            $this->copyClass($ref);
        }

        return true;
    }

    /**
     * Rewrite the tokens $from..$to of a legacy file: app class names to
     * their Laravel-half names, `__DIR__` and `__FILE__` to where the file
     * was. Returns the code and the app classes it names.
     *
     * @return array{string, list<string>}
     */
    public function rewrite(SlimSource $src, string $path, int $from, int $to): array
    {
        $out = '';
        $refs = [];
        $t = $src->t;
        $useDepth = 0;
        $inUse = false;
        for ($i = $from; $i <= $to; $i++) {
            [$id, $text, $line] = $t[$i];
            if (($id === null && $text === '{') || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $useDepth++;
            } elseif ($id === null && $text === '}') {
                $useDepth--;
            }
            if ($id === T_USE && $useDepth === 0) {
                $n = $src->next($i);
                $inUse = $n !== null && $t[$n][1] !== '(';
            } elseif ($inUse && $id === null && $text === ';') {
                $inUse = false;
            }
            if ($id === T_NAMESPACE) {
                $n = $src->next($i);
                if ($n !== null && in_array($t[$n][0], [T_STRING, T_NAME_QUALIFIED], true)) {
                    $mapped = $this->map($t[$n][1] . '\\');
                    $out .= $src->text($i, $n - 1) . ($mapped === null ? $t[$n][1] : rtrim($mapped, '\\'));
                    $i = $n;

                    continue;
                }
            }
            if (in_array($id, [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true) && $this->bootstrap) {
                $end = $this->expressionEnd($src, $i + 1, $to);
                $out .= $this->repointInclude($src, $path, $i, $end);
                $i = $end;

                continue;
            }
            if ($this->qualify && in_array($id, [T_CLASS_C, T_STATIC, T_STRING], true)) {
                $n = $src->next($i);
                $self = $id === T_CLASS_C || ($n !== null && $t[$n][0] === T_DOUBLE_COLON && ($id === T_STATIC || strtolower($text) === 'self'));
                $class = $self ? $src->classAt($i) : null;
                if ($class !== null) {
                    $mapped = $this->isAppClass($class) ? (string) $this->map($class) : $class;
                    if ($this->isAppClass($class)) {
                        $refs[] = $class;
                    }
                    $out .= $id === T_CLASS_C ? var_export($mapped, true) : '\\' . $mapped;

                    continue;
                }
            }
            if ($id === T_DIR || $id === T_FILE) {
                $where = 'legacy/' . ($id === T_DIR ? (dirname($path) === '.' ? '' : dirname($path)) : $path);
                $out .= "\\base_path('" . rtrim($where, '/') . "')";
                $this->manual[] = 'legacy/' . $path . ':' . $line . ' ' . ($id === T_DIR ? '__DIR__' : '__FILE__') . ' in the copied code now points at ' . rtrim($where, '/') . '; repoint it before the finish step deletes legacy/';

                continue;
            }
            if (in_array($id, [T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_STRING], true)) {
                $resolved = $inUse ? ltrim($text, '\\') : null;
                if ($resolved === null && $id === T_NAME_FULLY_QUALIFIED) {
                    $resolved = ltrim($text, '\\');
                } elseif ($resolved === null) {
                    $resolved = $this->classPosition($src, $i) ? $src->resolveClass($text, $i) : null;
                }
                if ($resolved !== null && $this->isAppClass($resolved)) {
                    $refs[] = $resolved;
                } elseif ($resolved !== null && !$inUse) {
                    $this->notePackage($resolved);
                } elseif ($inUse) {
                    $this->notePackage(ltrim($text, '\\'));
                }
                // Only names written in full change: a relative name follows
                // its namespace, which is renamed with it.
                $absolute = $inUse || $id === T_NAME_FULLY_QUALIFIED || ($id === T_NAME_QUALIFIED && $src->namespace === '' && $this->classPosition($src, $i));
                if ($absolute && $resolved !== null && ($mapped = $this->map($resolved)) !== null && $this->isAppClass($resolved)) {
                    $out .= ($id === T_NAME_FULLY_QUALIFIED || !$inUse ? '\\' : '') . $mapped;

                    continue;
                }
                if ($this->qualify && !$inUse && $resolved !== null && !in_array(strtolower($text), ['self', 'static', 'parent'], true)) {
                    $out .= '\\' . ($this->isAppClass($resolved) ? (string) $this->map($resolved) : $resolved);

                    continue;
                }
                if ($id === T_STRING && !$inUse && !$this->bootstrap && isset($this->constants[$text]) && $this->constantPosition($src, $i)) {
                    $this->refusals[] = ['file' => 'legacy/' . $path, 'line' => $line, 'reason' => 'uses the constant ' . $text . ', which legacy defines at ' . $this->constants[$text] . ' and the Laravel half does not'];
                }
            }
            if ($id === T_CONSTANT_ENCAPSED_STRING) {
                $value = SlimSource::unquote($text);
                $class = preg_match('/^\\\\?[A-Za-z_][\\\\\w]*$/', $value) === 1 ? ltrim($value, '\\') : null;
                if ($class !== null && $this->isAppClass($class)) {
                    $refs[] = $class;
                    $mapped = (string) $this->map($class);
                    $out .= $text[0] === '"' ? '"' . str_replace('\\', '\\\\', $mapped) . '"' : "'" . $mapped . "'";

                    continue;
                }
            }
            $out .= $text;
        }

        return [$this->userArrays($out, 'legacy/' . $path), array_values(array_unique($refs))];
    }

    /**
     * SESAME's Laravel half hands moved code a user object wrapping the
     * legacy `['username' => ...]` array, so `is_array()` on the user becomes
     * `is_array()` on its array; other array reads of it get a MANUAL line.
     */
    private function userArrays(string $code, string $where): string
    {
        if (!str_contains($code, 'sesame_user(')) {
            return $code;
        }
        $wrap = static fn(string $expr): string => 'is_array(\is_object($__booster_user = ' . $expr . ') && method_exists($__booster_user, \'toArray\') ? $__booster_user->toArray() : $__booster_user)';
        $code = (string) preg_replace_callback('/\bis_array\(\s*(\\\\?sesame_user\(\s*\))\s*\)/', static fn(array $m): string => $wrap($m[1]), $code);
        preg_match_all('/(\$\w+)\s*=\s*\\\\?sesame_user\(\s*\)/', $code, $vars);
        foreach (array_unique($vars[1]) as $var) {
            $code = (string) preg_replace_callback('/\bis_array\(\s*(' . preg_quote($var, '/') . ')\s*\)/', static fn(array $m): string => $wrap($m[1]), $code);
            if (preg_match('/' . preg_quote($var, '/') . '\s*\[/', $code) === 1) {
                $this->manual[] = $where . ' reads ' . $var . '[...] from sesame_user(), which the Laravel half answers with a user object; read it through ' . $var . '->toArray()';
            }
        }

        return $code;
    }

    /**
     * Copy a legacy script (the front controller, or a file it requires) to
     * boot/ with its requires repointed (bootstrap mode). Returns the
     * app-relative file.
     */
    public function copyScript(string $path): string
    {
        $target = self::BOOT . $path;
        if (isset($this->scripts[$path])) {
            return $target;
        }
        $this->scripts[$path] = true;
        $src = $this->sources[$path];
        [$code, $refs] = $this->rewrite($src, $path, 0, count($src->t) - 1);
        $this->pending[$target] = $code;
        foreach ($refs as $ref) {
            $this->copyClass($ref);
        }

        return $target;
    }

    /**
     * The token that ends the expression starting at $from: the ";" or ","
     * after it, or the ")" or "]" that closes a bracket opened before it.
     */
    private function expressionEnd(SlimSource $src, int $from, int $to): int
    {
        $depth = 0;
        for ($k = $from; $k <= $to; $k++) {
            $text = $src->t[$k][0] === null ? $src->t[$k][1] : '';
            if (in_array($text, ['(', '['], true)) {
                $depth++;
            } elseif (in_array($text, [')', ']'], true)) {
                if ($depth === 0) {
                    return $k - 1;
                }
                $depth--;
            } elseif ($depth === 0 && in_array($text, [';', ',', '?', ':'], true)) {
                return $k - 1;
            } elseif ($depth === 0 && in_array($src->t[$k][0], [T_CLOSE_TAG, T_AS, T_LOGICAL_AND, T_LOGICAL_OR, T_LOGICAL_XOR], true)) {
                // `foreach (require X as $f)` and `require X or die()` end the expression too.
                $end = $k - 1;
                while ($end > $from && $src->t[$end][0] === T_WHITESPACE) {
                    $end--;
                }

                return $end;
            }
        }

        return $to;
    }

    /**
     * A require or include, repointed: the legacy autoloader becomes `true`
     * (Laravel's autoloader has the packages, section 10.2), a routes script
     * the generated one with only the moved routes, an app script its copy
     * in boot/, and anything else the legacy file itself.
     */
    private function repointInclude(SlimSource $src, string $path, int $at, int $end): string
    {
        $keyword = $src->t[$at][1];
        $line = $src->t[$at][2];
        $value = $this->evaluate($src, $path, $at + 1, $end);
        $target = $value === null ? null : $this->locate($value, $path);
        if ($target === null) {
            $this->refusals[] = ['file' => 'legacy/' . $path, 'line' => $line, 'reason' => 'Booster cannot work out which file `' . trim($src->text($at, $end)) . '` loads, so it cannot repoint it in the copied front controller'];

            return $src->text($at, $end);
        }
        if (preg_match('#(^|/)vendor/autoload\.php$#', $target) === 1) {
            return 'true';
        }
        if (isset($this->routeScripts[$target])) {
            $this->routeHits[$target] = true;

            return $keyword . ' \\base_path(' . var_export($this->routeScripts[$target], true) . ')';
        }
        if (isset($this->sources[$target]) && !str_starts_with($target, 'vendor/')) {
            if ($this->sources[$target]->classes !== []) {
                foreach ($this->sources[$target]->classes as [, , $fqcn]) {
                    $this->copyClass($fqcn);
                }
                $mapped = $this->map($this->sources[$target]->classes[0][2]);
                if ($mapped !== null) {
                    return $keyword . ' \\base_path(' . var_export(self::targetFile($mapped), true) . ')';
                }
            }

            return $keyword . ' \\base_path(' . var_export($this->copyScript($target), true) . ')';
        }
        $this->manual[] = 'legacy/' . $path . ':' . $line . ' the copied code still loads legacy/' . $target . '; move it into the Laravel half before the finish step deletes legacy/';

        return $keyword . ' \\base_path(' . var_export('legacy/' . $target, true) . ')';
    }

    /**
     * The legacy-relative file a statically known include path names: a
     * path from __DIR__ or a constant is already legacy-relative ("/L/..."),
     * "./x" and "../x" are relative to legacy/public (the cwd the legacy
     * front controller runs in), and a bare "x" is looked up there and then
     * beside the including file, as PHP's include_path does.
     */
    private function locate(string $value, string $path): ?string
    {
        if (str_starts_with($value, '/L/') || $value === '/L') {
            return self::normalizePath(substr($value, 3));
        }
        if (str_starts_with($value, '/') || preg_match('/^[A-Za-z]:/', $value) === 1) {
            return null;
        }
        $candidates = str_starts_with($value, './') || str_starts_with($value, '../')
            ? ['public/' . $value]
            : ['public/' . $value, (dirname($path) === '.' ? '' : dirname($path) . '/') . $value];
        foreach ($candidates as $candidate) {
            $candidate = self::normalizePath($candidate);
            if (isset($this->sources[$candidate]) || is_file($this->appRoot . '/legacy/' . $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The value of a path expression, worked out without running it:
     * string literals, ".", parentheses, __DIR__ and __FILE__ (as "/L/..."
     * paths), DIRECTORY_SEPARATOR, realpath(), dirname() and the constants
     * legacy define()s from such values. Null when it is anything else.
     *
     * @param array<string, bool> $seen
     */
    private function evaluate(SlimSource $src, string $path, int $from, int $to, array $seen = []): ?string
    {
        $out = '';
        $expectValue = true;
        for ($k = $from; $k <= $to; $k++) {
            [$id, $text] = $src->t[$k];
            if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (!$expectValue) {
                if ($text !== '.') {
                    return null;
                }
                $expectValue = true;

                continue;
            }
            $expectValue = false;
            if ($id === T_CONSTANT_ENCAPSED_STRING) {
                $out .= SlimSource::unquote($text);
            } elseif ($id === T_DIR) {
                $out .= rtrim('/L/' . (dirname($path) === '.' ? '' : dirname($path)), '/');
            } elseif ($id === T_FILE) {
                $out .= '/L/' . $path;
            } elseif ($text === '(' && isset($src->parens[$k])) {
                $inner = $this->evaluate($src, $path, $k + 1, $src->parens[$k] - 1, $seen);
                if ($inner === null) {
                    return null;
                }
                $out .= $inner;
                $k = $src->parens[$k];
            } elseif (in_array($id, [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                $name = ltrim($text, '\\');
                $open = $src->next($k);
                if ($open !== null && $src->t[$open][1] === '(' && isset($src->parens[$open])) {
                    $args = $src->args($open, $src->parens[$open]);
                    $arg = isset($args[0]) ? $this->evaluate($src, $path, $args[0][0], $args[0][1], $seen) : null;
                    if ($arg === null) {
                        return null;
                    }
                    $lower = strtolower($name);
                    if ($lower === 'realpath') {
                        $out .= rtrim(self::normalizePath($arg, true), '/');
                    } elseif ($lower === 'dirname') {
                        $levels = isset($args[1]) ? (int) $this->evaluate($src, $path, $args[1][0], $args[1][1], $seen) : 1;
                        $dir = rtrim(self::normalizePath($arg, true), '/');
                        for ($n = 0; $n < max(1, $levels); $n++) {
                            $dir = str_contains($dir, '/') ? substr($dir, 0, (int) strrpos($dir, '/')) : '.';
                        }
                        $out .= $dir;
                    } else {
                        return null;
                    }
                    $k = $src->parens[$open];
                } elseif ($name === 'DIRECTORY_SEPARATOR') {
                    $out .= '/';
                } elseif (isset($this->constantValues[$name]) && !isset($seen[$name])) {
                    [$file, $a, $b] = $this->constantValues[$name];
                    $value = $this->evaluate($this->sources[$file], $file, $a, $b, $seen + [$name => true]);
                    if ($value === null) {
                        return null;
                    }
                    $out .= $value;
                } else {
                    return null;
                }
            } elseif ($id === T_LNUMBER) {
                $out .= $text;
            } else {
                return null;
            }
        }

        return $expectValue && $out === '' ? null : $out;
    }

    /** "a/./b/../c" as "a/c"; with $keepRoot a "/L/" path keeps its root. */
    private static function normalizePath(string $path, bool $keepRoot = false): string
    {
        $root = $keepRoot && str_starts_with($path, '/L') ? '/L/' : '';
        $rest = $root === '' ? $path : substr($path, 2);
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $rest)) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '.' && $part !== '') {
                $parts[] = $part;
            }
        }
        $trail = str_ends_with($rest, '/') && $parts !== [] ? '/' : '';

        return $root . implode('/', $parts) . $trail;
    }

    /**
     * The use lines a fragment of $src needs in its new file: every import
     * the fragment names, renamed where it is app code.
     */
    public function importLines(SlimSource $src, string $fragment): string
    {
        $lines = [];
        foreach ($src->imports as [$alias, $fqcn]) {
            if (preg_match('/(?<![\\\\\w$])' . preg_quote($alias, '/') . '(?![\w])/i', $fragment) !== 1) {
                continue;
            }
            $short = substr($fqcn, (int) strrpos('\\' . $fqcn, '\\'));
            $mapped = $this->isAppClass($fqcn) ? (string) $this->map($fqcn) : $fqcn;
            if ($this->isAppClass($fqcn)) {
                $this->copyClass($fqcn);
            } else {
                $this->notePackage($fqcn);
            }
            $lines[] = 'use ' . $mapped . ($alias === $short ? '' : ' as ' . $alias) . ';';
        }

        return implode("\n", $lines);
    }

    /** @return array<string, string> package to the constraint the root should require */
    public function requirements(): array
    {
        $composer = self::json($this->appRoot . '/legacy/composer.json');
        $out = [];
        foreach (array_keys($this->packages) as $package) {
            $constraint = $composer['require'][$package] ?? null;
            if (!is_string($constraint)) {
                $version = ltrim($this->lockedVersions[$package] ?? '', 'v');
                $constraint = preg_match('/^(\d+)\.(\d+)/', $version, $m) === 1 ? '^' . $m[1] . '.' . $m[2] : '*';
            }
            $out[$package] = $constraint;
        }
        ksort($out);

        return $out;
    }

    /**
     * Stage a file; nothing is written until flush(), so a refusal found
     * half way through a copy leaves the tree as it was. An existing file is
     * never replaced: a person may have edited an earlier copy (a --hand port).
     */
    public function write(string $relative, string $code): void
    {
        if (!is_file($this->appRoot . '/' . $relative)) {
            $this->pending[$relative] = $code;
        }
    }

    /** Replace a staged file's code (the mover swaps the emitter's parent class). */
    public function restage(string $relative, string $code): void
    {
        if (isset($this->pending[$relative])) {
            $this->pending[$relative] = $code;
        }
    }

    /** The staged code of a file, for a caller that adjusts it before the flush. */
    public function staged(string $relative): ?string
    {
        return $this->pending[$relative] ?? null;
    }

    /**
     * Write every staged file; returns the app-relative paths written.
     *
     * @return list<string>
     */
    public function flush(): array
    {
        foreach ($this->pending as $relative => $code) {
            $full = $this->appRoot . '/' . $relative;
            if (!is_dir(dirname($full))) {
                mkdir(dirname($full), 0o777, true);
            }
            file_put_contents($full, $code);
            $this->written[] = $relative;
        }
        $this->pending = [];

        return $this->written;
    }

    private function notePackage(string $fqcn): void
    {
        $lower = strtolower(ltrim($fqcn, '\\')) . '\\';
        $best = null;
        $bestLength = 0;
        foreach ($this->packagePrefixes as $prefix => $package) {
            if (str_starts_with($lower, $prefix) && strlen($prefix) > $bestLength) {
                [$best, $bestLength] = [$package, strlen($prefix)];
            }
        }
        if ($best !== null) {
            $this->packages[$best] = true;
        }
    }

    /** Whether the name at $i is used as a class (not a function, constant, method or property). */
    private function classPosition(SlimSource $src, int $i): bool
    {
        $prev = $src->prev($i);
        $next = $src->next($i);
        $p = $prev === null ? null : $src->t[$prev];
        $n = $next === null ? null : $src->t[$next];
        if ($p !== null && in_array($p[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_FUNCTION, T_CONST, T_GOTO], true)) {
            return false;
        }
        if ($p !== null && $p[0] === T_DOUBLE_COLON) {
            return false;
        }
        if ($n !== null && $n[1] === '(' && ($p === null || $p[0] !== T_NEW)) {
            return false;
        }
        if ($p !== null && in_array($p[0], [T_NEW, T_EXTENDS, T_IMPLEMENTS, T_INSTANCEOF, T_CATCH, T_USE], true)) {
            return true;
        }
        if ($n !== null && in_array($n[0], [T_DOUBLE_COLON, T_VARIABLE, T_ELLIPSIS], true)) {
            return true;
        }
        if ($p !== null && in_array($p[1], [',', '|', '(', '?', ':', '&'], true) && $n !== null && in_array($n[0], [T_VARIABLE, T_ELLIPSIS], true)) {
            return true;
        }
        if ($p !== null && $p[1] === ':' && $n !== null && in_array($n[1], ['{', ';', '=>'], true)) {
            return true;
        }
        if ($p !== null && in_array($p[1], [',', '|'], true)) {
            // implements A, B / catch (A | B $e)
            return $n !== null && in_array($n[1], [',', '{', '|'], true);
        }

        return false;
    }

    private function constantPosition(SlimSource $src, int $i): bool
    {
        $prev = $src->prev($i);
        $next = $src->next($i);
        if ($prev !== null && in_array($src->t[$prev][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST, T_NEW, T_CLASS], true)) {
            return false;
        }

        return $next === null || !in_array($src->t[$next][1], ['(', '::'], true) && $src->t[$next][0] !== T_DOUBLE_COLON;
    }

    /** @return array<mixed> */
    public static function json(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : [];
    }
}
