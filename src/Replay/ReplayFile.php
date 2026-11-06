<?php

declare(strict_types=1);

namespace Waynestate\Booster\Replay;

use Waynestate\Booster\ToolError;

/**
 * The request file (booster-replay.json): how to serve the app on fake data,
 * which requests to send, in which sequences, and what to hide before the
 * answers are compared. Parsing is strict: an unknown key is a typo until
 * proven otherwise, so it stops the run instead of being ignored. Keys that
 * start with "$" are comments.
 */
final readonly class ReplayFile
{
    public const DEFAULT_NAME = 'booster-replay.json';

    public const COMPARE_JSON_VALUE = 'value';

    public const COMPARE_EXACT = 'exact';

    public const COMPARE_WHITESPACE_COLLAPSE = 'collapse';

    /** The methods a request may use. */
    private const METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    /**
     * @param list<ReplayRequest>         $requests    every request, numbered in file order across sequences
     * @param list<string>                $sequences   the sequence names, in order (each starts with an empty cookie jar)
     * @param list<ReplayNormalizer>      $normalizers
     * @param array<string, string>       $env         environment variables for setup and the server (fake values only)
     * @param array<string, string>       $ini         php -d settings for the server
     * @param list<array{name: string, image: string, env: array<string, string>}> $services containers the app reaches on a private network (a fixture database)
     * @param list<string>                $todos       what a person still has to fill in, one line each
     */
    public function __construct(
        public string $path,
        public array $requests,
        public array $sequences,
        public array $normalizers = [],
        public string $docroot = '.',
        public ?string $frontController = null,
        public ?string $setup = null,
        public array $env = [],
        public array $ini = [],
        public array $services = [],
        public string $compareJson = self::COMPARE_JSON_VALUE,
        public string $compareWhitespace = self::COMPARE_WHITESPACE_COLLAPSE,
        public array $todos = [],
        public string $fingerprint = '',
    ) {}

    /**
     * The file for an app: an explicit path (relative to the app, or
     * absolute), or booster-replay.json in the app.
     */
    public static function locate(string $appPath, ?string $file): string
    {
        $file ??= self::DEFAULT_NAME;
        $isAbsolute = str_starts_with($file, '/') || str_starts_with($file, '\\') || preg_match('#^[A-Za-z]:[\\\\/]#', $file) === 1;

        return $isAbsolute ? $file : rtrim($appPath, '/\\') . '/' . $file;
    }

    public static function load(string $path): self
    {
        if (!is_file($path)) {
            throw new ToolError(sprintf('No request file at %s. Draft one with: booster replay init', $path));
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            throw new ToolError(sprintf('%s is not valid JSON: %s.', $path, json_last_error_msg()));
        }

        return self::fromArray($data, $path);
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data, string $path = self::DEFAULT_NAME): self
    {
        $file = basename($path);
        self::onlyKeys($data, ['version', 'serve', 'defaults', 'normalize', 'compare', 'sequences', 'requests', 'todo'], $file);
        $version = $data['version'] ?? 1;
        if ($version !== 1) {
            throw new ToolError(sprintf('%s: "version" must be 1; this Booster reads version 1 request files.', $file));
        }
        $todos = [];
        if (isset($data['todo'])) {
            $todos[] = 'the file: ' . self::text($data['todo'], $file . ' "todo"');
        }

        $serve = self::object($data['serve'] ?? [], $file . ' "serve"');
        self::onlyKeys($serve, ['docroot', 'front_controller', 'setup', 'env', 'ini', 'services', 'todo'], $file . ' "serve"');
        if (isset($serve['todo'])) {
            $todos[] = 'serve: ' . self::text($serve['todo'], $file . ' "serve.todo"');
        }
        $docroot = trim(self::text($serve['docroot'] ?? '.', $file . ' "serve.docroot"'), '/');
        if ($docroot === '') {
            $docroot = '.';
        }
        if (str_contains($docroot, '..') || str_starts_with($docroot, '\\') || preg_match('#^[A-Za-z]:#', $docroot) === 1) {
            throw new ToolError(sprintf('%s: "serve.docroot" must be a folder inside the app, such as public.', $file));
        }
        $front = isset($serve['front_controller']) ? ltrim(self::text($serve['front_controller'], $file . ' "serve.front_controller"'), '/') : null;
        if ($front !== null && (str_contains($front, '..') || preg_match('/\.php$/', $front) !== 1)) {
            throw new ToolError(sprintf('%s: "serve.front_controller" must be a .php file inside the docroot, such as index.php.', $file));
        }
        $setup = isset($serve['setup']) && $serve['setup'] !== '' ? self::text($serve['setup'], $file . ' "serve.setup"') : null;
        $env = self::stringMap($serve['env'] ?? [], $file . ' "serve.env"');
        foreach (array_keys($env) as $key) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
                throw new ToolError(sprintf('%s: "serve.env" key "%s" is not a valid environment variable name.', $file, $key));
            }
        }
        $ini = self::stringMap($serve['ini'] ?? [], $file . ' "serve.ini"');
        $services = [];
        foreach (self::list($serve['services'] ?? [], $file . ' "serve.services"') as $i => $service) {
            $where = sprintf('%s "serve.services[%d]"', $file, $i);
            $service = self::object($service, $where);
            self::onlyKeys($service, ['name', 'image', 'env'], $where);
            $name = self::text($service['name'] ?? null, $where . ' "name"');
            if (preg_match('/^[a-z][a-z0-9-]*$/', $name) !== 1) {
                throw new ToolError(sprintf('%s: "name" must be a lowercase host name such as db.', $where));
            }
            $services[] = [
                'name' => $name,
                'image' => self::text($service['image'] ?? null, $where . ' "image"'),
                'env' => self::stringMap($service['env'] ?? [], $where . ' "env"'),
            ];
        }

        $defaults = self::object($data['defaults'] ?? [], $file . ' "defaults"');
        self::onlyKeys($defaults, ['headers'], $file . ' "defaults"');
        $defaultHeaders = self::stringMap($defaults['headers'] ?? [], $file . ' "defaults.headers"');

        $normalizers = [];
        foreach (self::list($data['normalize'] ?? [], $file . ' "normalize"') as $i => $entry) {
            $where = sprintf('%s "normalize[%d]"', $file, $i);
            if (is_string($entry)) {
                $normalizers[] = ReplayNormalizer::preset($entry);
                continue;
            }
            $entry = self::object($entry, $where);
            self::onlyKeys($entry, ['name', 'pattern', 'replace', 'in'], $where);
            $normalizers[] = ReplayNormalizer::custom($entry, $where);
        }

        $compare = self::object($data['compare'] ?? [], $file . ' "compare"');
        self::onlyKeys($compare, ['json', 'whitespace'], $file . ' "compare"');
        $compareJson = self::text($compare['json'] ?? self::COMPARE_JSON_VALUE, $file . ' "compare.json"');
        if (!in_array($compareJson, [self::COMPARE_JSON_VALUE, self::COMPARE_EXACT], true)) {
            throw new ToolError(sprintf('%s: "compare.json" must be "value" (key order and 1.0 versus 1 do not matter) or "exact".', $file));
        }
        $compareWhitespace = self::text($compare['whitespace'] ?? self::COMPARE_WHITESPACE_COLLAPSE, $file . ' "compare.whitespace"');
        if (!in_array($compareWhitespace, [self::COMPARE_WHITESPACE_COLLAPSE, self::COMPARE_EXACT], true)) {
            throw new ToolError(sprintf('%s: "compare.whitespace" must be "collapse" (runs of spaces and blank lines do not matter) or "exact".', $file));
        }

        $rawSequences = [];
        if (isset($data['requests'])) {
            $rawSequences[] = ['name' => 'requests', 'requests' => $data['requests']];
        }
        foreach (self::list($data['sequences'] ?? [], $file . ' "sequences"') as $i => $sequence) {
            $where = sprintf('%s "sequences[%d]"', $file, $i);
            $sequence = self::object($sequence, $where);
            self::onlyKeys($sequence, ['name', 'requests'], $where);
            $rawSequences[] = ['name' => self::text($sequence['name'] ?? null, $where . ' "name"'), 'requests' => $sequence['requests'] ?? null];
        }
        if ($rawSequences === []) {
            throw new ToolError(sprintf('%s has no requests. Add a "requests" list or "sequences".', $file));
        }

        $requests = [];
        $names = [];
        $number = 0;
        foreach ($rawSequences as $sequence) {
            if (in_array($sequence['name'], $names, true)) {
                throw new ToolError(sprintf('%s: two sequences are named "%s".', $file, $sequence['name']));
            }
            $names[] = $sequence['name'];
            $list = self::list($sequence['requests'], sprintf('%s sequence "%s" "requests"', $file, $sequence['name']));
            if ($list === []) {
                throw new ToolError(sprintf('%s: sequence "%s" has no requests.', $file, $sequence['name']));
            }
            $captured = [];
            foreach ($list as $raw) {
                $number++;
                $request = self::request($raw, $number, $sequence['name'], $defaultHeaders, $file);
                foreach (self::placeholders($request) as $placeholder) {
                    if (!in_array($placeholder, $captured, true)) {
                        throw new ToolError(sprintf('%s: request %s uses {{%s}}, which no earlier request in sequence "%s" captures.', $file, $request->label(), $placeholder, $sequence['name']));
                    }
                }
                $captured = [...$captured, ...array_keys($request->capture)];
                if ($request->todo !== null) {
                    $todos[] = $request->label() . ': ' . $request->todo;
                }
                $requests[] = $request;
            }
        }

        // What the recording depends on: the requests and how the app is
        // served. Normalizers and compare modes are left out on purpose, so a
        // person can hide a volatile value without recording again.
        $fingerprint = hash('sha256', (string) json_encode([
            'serve' => [$docroot, $front, $setup, $env, $ini, $services],
            'requests' => array_map(static fn(ReplayRequest $r): array => $r->toClientArray(), $requests),
        ]));

        return new self(
            path: $path,
            requests: $requests,
            sequences: $names,
            normalizers: $normalizers,
            docroot: $docroot,
            frontController: $front,
            setup: $setup,
            env: $env,
            ini: $ini,
            services: $services,
            compareJson: $compareJson,
            compareWhitespace: $compareWhitespace,
            todos: $todos,
            fingerprint: $fingerprint,
        );
    }

    /**
     * The plan the in-container client reads.
     *
     * @return array<string, mixed>
     */
    public function clientPlan(int $timeoutSeconds = 30): array
    {
        $sequences = [];
        foreach ($this->requests as $request) {
            $sequences[$request->sequence][] = $request->toClientArray();
        }
        $out = [];
        foreach ($this->sequences as $name) {
            $out[] = ['name' => $name, 'requests' => $sequences[$name] ?? []];
        }

        return ['timeout' => $timeoutSeconds, 'sequences' => $out];
    }

    /**
     * @param array<string, string> $defaultHeaders
     */
    private static function request(mixed $raw, int $number, string $sequence, array $defaultHeaders, string $file): ReplayRequest
    {
        $where = sprintf('%s request %d (sequence "%s")', $file, $number, $sequence);
        $raw = self::object($raw, $where);
        self::onlyKeys($raw, ['name', 'method', 'path', 'query', 'headers', 'cookies', 'form', 'json', 'body', 'capture', 'expect', 'todo'], $where);
        $method = strtoupper(self::text($raw['method'] ?? 'GET', $where . ' "method"'));
        if (!in_array($method, self::METHODS, true)) {
            throw new ToolError(sprintf('%s: method "%s" is not one of %s.', $where, $method, implode(', ', self::METHODS)));
        }
        $path = self::text($raw['path'] ?? null, $where . ' "path"');
        if (!str_starts_with($path, '/')) {
            throw new ToolError(sprintf('%s: "path" must start with / (it is a path on the app, never a full address).', $where));
        }
        $query = [];
        if (isset($raw['query'])) {
            if (is_string($raw['query'])) {
                parse_str(ltrim($raw['query'], '?'), $parsed);
                foreach ($parsed as $key => $value) {
                    $query[(string) $key] = is_scalar($value) ? (string) $value : (string) json_encode($value);
                }
            } else {
                $query = self::stringMap($raw['query'], $where . ' "query"');
            }
        }
        $bodies = (isset($raw['form']) ? 1 : 0) + (array_key_exists('json', $raw) ? 1 : 0) + (isset($raw['body']) ? 1 : 0);
        if ($bodies > 1) {
            throw new ToolError(sprintf('%s: use one of "form", "json" or "body", not several.', $where));
        }
        $form = null;
        if (isset($raw['form'])) {
            $form = self::object($raw['form'], $where . ' "form"');
            foreach ($form as $key => $value) {
                if (!is_scalar($value) && !is_array($value)) {
                    throw new ToolError(sprintf('%s: form field "%s" must be a string, number or list.', $where, $key));
                }
            }
        }
        $capture = self::stringMap($raw['capture'] ?? [], $where . ' "capture"');
        foreach ($capture as $name => $pattern) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
                throw new ToolError(sprintf('%s: capture name "%s" must be a word (letters, digits, underscore).', $where, $name));
            }
            if (@preg_match('~' . str_replace('~', '\~', $pattern) . '~', '') === false) {
                throw new ToolError(sprintf('%s: capture "%s" is not a valid regular expression.', $where, $name));
            }
        }
        $expect = $raw['expect'] ?? null;
        if ($expect !== null && (!is_int($expect) || $expect < 100 || $expect > 599)) {
            throw new ToolError(sprintf('%s: "expect" must be an HTTP status such as 200.', $where));
        }
        $headers = $defaultHeaders;
        foreach (self::stringMap($raw['headers'] ?? [], $where . ' "headers"') as $name => $value) {
            foreach (array_keys($headers) as $existing) {
                if (strcasecmp($existing, $name) === 0) {
                    unset($headers[$existing]);
                }
            }
            $headers[$name] = $value;
        }
        return new ReplayRequest(
            number: $number,
            sequence: $sequence,
            name: isset($raw['name']) ? self::text($raw['name'], $where . ' "name"') : sprintf('%s %s', $method, $path),
            method: $method,
            path: $path,
            query: $query,
            headers: $headers,
            cookies: self::stringMap($raw['cookies'] ?? [], $where . ' "cookies"'),
            form: $form,
            json: $raw['json'] ?? null,
            hasJson: array_key_exists('json', $raw),
            body: isset($raw['body']) ? self::text($raw['body'], $where . ' "body"') : null,
            capture: $capture,
            expect: $expect,
            todo: isset($raw['todo']) ? self::text($raw['todo'], $where . ' "todo"') : null,
        );
    }

    /**
     * The {{name}} placeholders a request uses.
     *
     * @return list<string>
     */
    private static function placeholders(ReplayRequest $request): array
    {
        $text = (string) json_encode([$request->target(), $request->path, $request->query, $request->headers, $request->cookies, $request->form, $request->json, $request->body]);
        preg_match_all('/\{\{([A-Za-z_][A-Za-z0-9_]*)\}\}/', $text, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * @param array<mixed> $data
     * @param list<string> $allowed
     */
    private static function onlyKeys(array $data, array $allowed, string $where): void
    {
        foreach (array_keys($data) as $key) {
            if (is_string($key) && str_starts_with($key, '$')) {
                continue;
            }
            if (!in_array($key, $allowed, true)) {
                throw new ToolError(sprintf('%s: unknown key "%s". Allowed: %s (keys starting with $ are comments).', $where, $key, implode(', ', $allowed)));
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function object(mixed $value, string $where): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new ToolError(sprintf('%s must be an object.', $where));
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value, string $where): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new ToolError(sprintf('%s must be a list.', $where));
        }

        return $value;
    }

    private static function text(mixed $value, string $where): string
    {
        if (!is_string($value) || $value === '') {
            throw new ToolError(sprintf('%s must be a non-empty string.', $where));
        }

        return $value;
    }

    /**
     * @return array<string, string>
     */
    private static function stringMap(mixed $value, string $where): array
    {
        $out = [];
        foreach (self::object($value, $where) as $key => $item) {
            if (is_bool($item) || $item === null || !is_scalar($item)) {
                throw new ToolError(sprintf('%s: "%s" must be a string or number.', $where, $key));
            }
            $out[$key] = (string) $item;
        }

        return $out;
    }
}
