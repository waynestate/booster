<?php

declare(strict_types=1);

namespace Waynestate\Booster\Strangler;

use Waynestate\Booster\ToolError;

/**
 * The moved-routes table, `bootstrap/booster-moved.php`: the one file the
 * strangler's dispatcher (public/index.php) reads to decide that a request
 * belongs to the Laravel half. Everything not in it goes to legacy.
 *
 * The file returns `list<array{unit: string, methods: list<string>, regex:
 * string}>`, one row per route (a form unit writes one row per part, all with
 * the form's id), first match wins. The dispatcher matches `regex` against
 * the request path after the base path is stripped, decoded with `%2F` kept
 * encoded, and treats HEAD as GET.
 *
 * Booster never executes the file to read it back: load() parses the same
 * small grammar save() writes (arrays, string keys, single-quoted strings),
 * so a hostile or hand-mangled table is a ToolError, never code that runs.
 * Think of it as a guest list the bouncer reads, not one he lets talk back.
 */
final class RouteTable
{
    public const FILE = 'bootstrap/booster-moved.php';

    /**
     * @param list<array{unit: string, methods: list<string>, regex: string}> $rows
     */
    private function __construct(
        private readonly string $appRoot,
        private array $rows,
    ) {}

    /** The table in $appRoot, or an empty one when the file does not exist yet. */
    public static function load(string $appRoot): self
    {
        $path = rtrim($appRoot, '/\\') . '/' . self::FILE;
        if (!is_file($path)) {
            return new self($appRoot, []);
        }
        $source = (string) file_get_contents($path);

        return new self($appRoot, self::parse($source, self::FILE));
    }

    /**
     * Adds a unit (replacing any rows it already had): one row for a route,
     * one row per part for a form, each part with its own methods and URI.
     */
    public function add(Unit $u): void
    {
        $this->remove($u->id);
        $routes = $u->kind === 'form' && $u->parts !== [] ? $u->parts : [$u];
        foreach ($routes as $route) {
            $methods = array_values(array_unique(array_map('strtoupper', $route->methods)));
            if ($methods === []) {
                throw new ToolError(sprintf('Unit "%s" declares no HTTP method, so the route table cannot hold it.', $u->id));
            }
            $this->rows[] = ['unit' => $u->id, 'methods' => $methods, 'regex' => self::compile($route->uri)];
        }
    }

    public function remove(string $unitId): void
    {
        $this->rows = array_values(array_filter($this->rows, static fn(array $row): bool => $row['unit'] !== $unitId));
    }

    public function has(string $unitId): bool
    {
        foreach ($this->rows as $row) {
            if ($row['unit'] === $unitId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{unit: string, methods: list<string>, regex: string}>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * The unit ids in the table, in table order, each once.
     *
     * @return list<string>
     */
    public function units(): array
    {
        return array_values(array_unique(array_column($this->rows, 'unit')));
    }

    /** Writes the table (atomically: a temp file renamed over the old one). */
    public function save(): void
    {
        $path = rtrim($this->appRoot, '/\\') . '/' . self::FILE;
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new ToolError(sprintf('Could not create %s for the route table.', $dir));
        }
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, self::render($this->rows)) === false || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new ToolError(sprintf('Could not write the route table %s.', $path));
        }
    }

    /**
     * The file save() writes for $rows. Exposed so tests and the mount can
     * compare against it.
     *
     * @param list<array{unit: string, methods: list<string>, regex: string}> $rows
     */
    public static function render(array $rows): string
    {
        $lines = [
            '<?php',
            '',
            '// The strangler\'s moved-routes table, written by Booster. public/index.php',
            '// reads it on every request: a request whose path matches a row\'s regex',
            '// with one of its methods (HEAD counts as GET) goes to the Laravel half,',
            '// everything else to legacy/. Booster changes it with `booster run --only',
            '// <hop> --moves N`, `--route` and `--hand --route`; an edit by hand must',
            '// keep this shape (arrays and single-quoted strings only), or Booster',
            '// refuses to read it. The finish step deletes it.',
            '',
            'return [',
        ];
        foreach ($rows as $row) {
            $methods = implode(', ', array_map(self::quote(...), $row['methods']));
            $lines[] = sprintf("    ['unit' => %s, 'methods' => [%s], 'regex' => %s],", self::quote($row['unit']), $methods, self::quote($row['regex']));
        }
        $lines[] = '];';

        return implode("\n", $lines) . "\n";
    }

    /**
     * The regex for a Laravel-style URI, matched against the request path
     * (leading slash, base path stripped, `%2F` kept encoded):
     *
     * - `{name}` is one segment (`[^/]+`), as in Laravel and FastRoute;
     * - `{name?}` is an optional last segment, slash included;
     * - `{name:regex}` (FastRoute's inline form) keeps the legacy route's
     *   constraint, so the table is exactly as narrow as the route was;
     * - a trailing `[/]` (FastRoute) makes the trailing slash optional.
     *
     * Everything else is literal. The path is matched whole and is case
     * sensitive, like both legacy routers.
     *
     * $wheres maps a placeholder name to its regex, as the Laravel route's
     * ->where() gives it, so the dispatcher matches exactly as narrowly as
     * Laravel. An inline `{name:regex}` wins over a where for the same name.
     *
     * @param array<array-key, mixed> $wheres name => regex; checked here, since callers read them from code
     */
    public static function compile(string $laravelUri, array $wheres = []): string
    {
        foreach ($wheres as $name => $where) {
            if (!is_string($name) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1 || !is_string($where) || $where === '' || @preg_match('#' . str_replace('#', '\#', $where) . '#', '') === false) {
                throw new ToolError(sprintf('The route "%s" has a where() constraint the route table cannot compile: %s => %s.', $laravelUri, (string) $name, is_string($where) ? $where : gettype($where)));
            }
        }
        $uri = trim($laravelUri);
        $optionalSlash = false;
        if (str_ends_with($uri, '[/]')) {
            $optionalSlash = true;
            $uri = substr($uri, 0, -3);
        }
        $uri = '/' . ltrim($uri, '/');
        if ($uri !== '/') {
            $uri = rtrim($uri, '/');
        }

        $regex = '';
        $offset = 0;
        $length = strlen($uri);
        while ($offset < $length) {
            $open = strpos($uri, '{', $offset);
            if ($open === false) {
                $regex .= preg_quote(substr($uri, $offset), '#');
                break;
            }
            $close = self::closingBrace($uri, $open, $laravelUri);
            $literal = substr($uri, $offset, $open - $offset);
            $inner = trim(substr($uri, $open + 1, $close - $open - 1));
            $offset = $close + 1;
            $pattern = '[^/]+';
            $optional = false;
            $colon = strpos($inner, ':');
            if ($colon !== false) {
                $pattern = trim(substr($inner, $colon + 1));
                $inner = trim(substr($inner, 0, $colon));
                if ($pattern === '' || @preg_match('#' . str_replace('#', '\#', $pattern) . '#', '') === false) {
                    throw new ToolError(sprintf('The route "%s" has a placeholder constraint the route table cannot compile: {%s:%s}.', $laravelUri, $inner, $pattern));
                }
                $pattern = str_replace('#', '\#', $pattern);
            } elseif (str_ends_with($inner, '?')) {
                $optional = true;
                $inner = rtrim(substr($inner, 0, -1));
            }
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $inner) !== 1) {
                throw new ToolError(sprintf('The route "%s" has a placeholder the route table cannot compile: {%s}.', $laravelUri, $inner));
            }
            if ($colon === false && isset($wheres[$inner])) {
                $pattern = str_replace('#', '\#', (string) $wheres[$inner]);
            }
            if ($optional) {
                if ($offset !== $length || !str_ends_with($literal, '/')) {
                    throw new ToolError(sprintf('The route "%s" has an optional placeholder that is not the last segment; the route table cannot express it.', $laravelUri));
                }
                $literal = substr($literal, 0, -1);
                // "{page?}" alone also answers "/", as Laravel's does.
                $regex .= $regex === '' && $literal === '' ? '/(?:' . $pattern . ')?' : preg_quote($literal, '#') . '(?:/' . $pattern . ')?';
                continue;
            }
            $regex .= preg_quote($literal, '#') . '(?:' . $pattern . ')';
        }
        if ($optionalSlash && $uri !== '/') {
            $regex .= '/?';
        }

        return '#^' . $regex . '$#D';
    }

    private static function closingBrace(string $uri, int $open, string $original): int
    {
        $depth = 0;
        $length = strlen($uri);
        for ($i = $open; $i < $length; $i++) {
            if ($uri[$i] === '\\') {
                $i++;
                continue;
            }
            if ($uri[$i] === '{') {
                $depth++;
            } elseif ($uri[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        throw new ToolError(sprintf('The route "%s" has a { with no closing }.', $original));
    }

    private static function quote(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    /**
     * Reads the table without running it: only `<?php`, comments, `return`,
     * arrays, `=>`, commas and single-quoted strings are allowed.
     *
     * @return list<array{unit: string, methods: list<string>, regex: string}>
     */
    public static function parse(string $source, string $where = self::FILE): array
    {
        $tokens = [];
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if ($token[0] === T_RETURN || $token[0] === T_DOUBLE_ARROW) {
                    $tokens[] = $token[1];
                    continue;
                }
                if ($token[0] === T_CONSTANT_ENCAPSED_STRING && str_starts_with($token[1], "'")) {
                    $tokens[] = ['s', (string) preg_replace('/\\\\([\\\\\'])/', '$1', substr($token[1], 1, -1))];
                    continue;
                }
                throw new ToolError(sprintf('%s holds "%s", which a route table never does (only arrays and single-quoted strings). Restore it from git or remove the line.', $where, trim($token[1])));
            }
            $tokens[] = $token;
        }
        $at = 0;
        if (($tokens[$at++] ?? null) !== 'return') {
            throw new ToolError(sprintf('%s must return the table (return [...];).', $where));
        }
        $value = self::parseValue($tokens, $at, $where);
        if (($tokens[$at++] ?? null) !== ';' || isset($tokens[$at])) {
            throw new ToolError(sprintf('%s must end right after the table (];).', $where));
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new ToolError(sprintf('%s must return a list of rows.', $where));
        }
        $rows = [];
        foreach ($value as $i => $row) {
            if (!is_array($row) || !is_string($row['unit'] ?? null) || $row['unit'] === '' || !is_array($row['methods'] ?? null) || !is_string($row['regex'] ?? null) || count($row) !== 3) {
                throw new ToolError(sprintf('%s row %d must be [\'unit\' => ..., \'methods\' => [...], \'regex\' => ...].', $where, $i + 1));
            }
            $methods = [];
            foreach ($row['methods'] as $method) {
                if (!is_string($method) || preg_match('/^[A-Z]+$/', $method) !== 1) {
                    throw new ToolError(sprintf('%s row %d: methods must be upper-case HTTP methods.', $where, $i + 1));
                }
                $methods[] = $method;
            }
            if (@preg_match($row['regex'], '') === false) {
                throw new ToolError(sprintf('%s row %d (%s): the regex does not compile.', $where, $i + 1, $row['unit']));
            }
            $rows[] = ['unit' => $row['unit'], 'methods' => $methods, 'regex' => $row['regex']];
        }

        return $rows;
    }

    /**
     * @param list<string|array{0: string, 1: string}> $tokens
     * @return string|array<int|string, mixed>
     */
    private static function parseValue(array $tokens, int &$at, string $where): string|array
    {
        $token = $tokens[$at++] ?? null;
        if (is_array($token)) {
            return $token[1];
        }
        if ($token !== '[') {
            throw new ToolError(sprintf('%s is not a route table this Booster can read.', $where));
        }
        $out = [];
        while (true) {
            if (($tokens[$at] ?? null) === ']') {
                $at++;

                return $out;
            }
            $value = self::parseValue($tokens, $at, $where);
            if (($tokens[$at] ?? null) === '=>') {
                $at++;
                if (!is_string($value)) {
                    throw new ToolError(sprintf('%s has an array used as a key.', $where));
                }
                $out[$value] = self::parseValue($tokens, $at, $where);
            } else {
                $out[] = $value;
            }
            $next = $tokens[$at] ?? null;
            if ($next === ',') {
                $at++;
                continue;
            }
            if ($next !== ']') {
                throw new ToolError(sprintf('%s is not a route table this Booster can read.', $where));
            }
        }
    }
}
