<?php

declare(strict_types=1);

namespace Waynestate\Booster\Scan;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Waynestate\Booster\Packaging\PharFiles;
use Waynestate\Booster\ToolError;

/**
 * A token-level inventory of the curated silent behaviour changes that apply
 * when an app crosses a PHP or Laravel hop: things that still run, but do
 * something different. It lexes with token_get_all and never parses, so even
 * files Rector cannot parse (exactly the legacy apps that need this) get
 * inventoried. Advisory only: it lists what to check, it never fails a hop.
 */
final class BehaviourScan
{
    /** How many hit locations one entry lists; the total says how many more. */
    public const MAX_HITS = 5;

    /** The most always rows a data file may carry: they print on every matching hop. */
    public const MAX_ALWAYS = 3;

    /** Directories that never hold app code. */
    private const SKIPPED_DIRECTORIES = ['vendor', 'node_modules', 'storage', '.git'];

    /** @var list<array{id: string, hop: string, kind: string, match: ?string, file: ?string, key: ?string, package: ?string, package_major: ?int, label: string, keep_old: ?string, check: string, source: string}> */
    private readonly array $entries;

    public function __construct(?string $dataPath = null)
    {
        $this->entries = self::load($dataPath ?? PharFiles::path('data/behaviour-changes.json'));
    }

    /**
     * The app's composer require map (require plus require-dev), so package
     * rows can tell apart "the app holds this package" from a transitive one.
     * Empty when composer.json is missing or unreadable.
     *
     * @return array<string, string>
     */
    public static function composerRequire(string $appPath): array
    {
        $file = str_replace('\\', '/', rtrim($appPath, '/\\')) . '/composer.json';
        if (!is_file($file)) {
            return [];
        }
        $raw = json_decode((string) file_get_contents($file), true);
        if (!is_array($raw)) {
            return [];
        }
        $require = [];
        foreach (['require', 'require-dev'] as $section) {
            if (!is_array($raw[$section] ?? null)) {
                continue;
            }
            foreach ($raw[$section] as $name => $constraint) {
                if (is_string($name) && is_string($constraint)) {
                    $require[$name] = $constraint;
                }
            }
        }

        return $require;
    }

    /**
     * Scans $appPath for the curated changes that apply to hop $hopId: every
     * entry whose hop matches, plus the package rows (the caller keeps those
     * only on the plan's last step, where the lock lands). Never throws: an
     * unreadable tree yields an empty result, because an advisory must not
     * take down a plan or a run.
     *
     * @param array<string, string>|null $composerRequire
     * @param array<string, string>|null $lockedVersions the pre-run package-to-version map; null reads the app's composer.lock
     */
    public function scan(string $appPath, string $hopId, ?array $composerRequire, ?array $lockedVersions = null): BehaviourResult
    {
        $entries = [];
        foreach ($this->entries as $entry) {
            if ($entry['hop'] === $hopId || $entry['kind'] === 'package') {
                $entries[] = $entry;
            }
        }

        /** @var array<string, list<string>> $hits */
        $hits = [];
        /** @var array<string, int> $totals */
        $totals = [];

        $scanned = 0;
        $skipped = 0;
        $phpEntries = array_values(array_filter($entries, static fn(array $e): bool => in_array($e['kind'], ['function', 'method', 'class', 'property', 'token'], true)));
        if ($phpEntries !== []) {
            foreach ($this->files($appPath, ['.php', '.inc', '.phtml']) as $relative => $absolute) {
                // Blade templates get their own pass below; do not lex them here.
                if (str_ends_with(strtolower($relative), '.blade.php')) {
                    continue;
                }
                if (!is_file($absolute)) {
                    $skipped++;

                    continue;
                }
                $source = file_get_contents($absolute);
                if ($source === false) {
                    $skipped++;

                    continue;
                }
                $scanned++;
                foreach ($this->scanSource($source, $phpEntries) as $id => $lines) {
                    $this->merge($hits, $totals, $id, array_map(static fn(int $line): string => $relative . ':' . $line, $lines));
                }
            }
        }
        $bladeEntries = array_values(array_filter($entries, static fn(array $e): bool => $e['kind'] === 'blade'));
        if ($bladeEntries !== []) {
            foreach ($this->files($appPath, ['.blade.php'], 'resources/views') as $relative => $absolute) {
                if (!is_file($absolute)) {
                    continue;
                }
                $source = file_get_contents($absolute);
                if ($source === false) {
                    continue;
                }
                foreach ($this->scanBlade($source, $bladeEntries) as $id => $lines) {
                    $this->merge($hits, $totals, $id, array_map(static fn(int $line): string => $relative . ':' . $line, $lines));
                }
            }
        }
        // Read the lock once per scan; a run passes the pre-run map so earlier
        // steps that already bumped a package do not hide its row.
        $locked = $lockedVersions ?? self::lockedVersions($appPath);
        foreach ($entries as $entry) {
            switch ($entry['kind']) {
                case 'config':
                    $hit = $this->configHit($appPath, $entry);
                    if ($hit !== null) {
                        $hits[$entry['id']] = [$hit];
                        $totals[$entry['id']] = 1;
                    }
                    break;
                case 'package':
                    if ($this->packageHit($entry, $composerRequire, $locked)) {
                        $hits[$entry['id']] = ['composer.json'];
                        $totals[$entry['id']] = 1;
                    }
                    break;
                case 'always':
                    // Applies no matter what the app contains: the change hits
                    // every app that crosses the hop.
                    $totals[$entry['id']] = 1;
                    break;
            }
        }

        $found = [];
        foreach ($entries as $entry) {
            if (($totals[$entry['id']] ?? 0) === 0) {
                continue;
            }
            $found[] = new BehaviourEntry(
                $entry['id'],
                $entry['kind'],
                $entry['label'],
                $entry['keep_old'],
                $entry['check'],
                $entry['source'],
                $hits[$entry['id']] ?? [],
                $totals[$entry['id']],
            );
        }

        return new BehaviourResult($hopId, $found, $scanned, $skipped);
    }

    /**
     * Adds the hit locations of one entry to the running totals, keeping at
     * most MAX_HITS locations but counting every one in the total.
     *
     * @param array<string, list<string>> $hits
     * @param array<string, int> $totals
     * @param list<string> $locations
     */
    private function merge(array &$hits, array &$totals, string $id, array $locations): void
    {
        $totals[$id] = ($totals[$id] ?? 0) + count($locations);
        foreach ($locations as $location) {
            if (count($hits[$id] ?? []) < self::MAX_HITS) {
                $hits[$id][] = $location;
            }
        }
    }

    /**
     * Every curated PHP-level change found in one source string, as entry id
     * to the line numbers it was found on, in source order.
     *
     * @param list<array{id: string, hop: string, kind: string, match: ?string, file: ?string, key: ?string, package: ?string, package_major: ?int, label: string, keep_old: ?string, check: string, source: string}> $entries
     *
     * @return array<string, list<int>>
     */
    private function scanSource(string $source, array $entries): array
    {
        /** @var array<string, list<int>> $lines */
        $lines = [];
        $tokens = token_get_all($source);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }
            $line = $token[2];
            if ($token[0] === T_STRING) {
                $name = strtolower((string) $token[1]);
                $prev = $this->prevSignificant($tokens, $i - 1);
                $next = $this->nextSignificant($tokens, $i + 1);
                $call = $next !== null && $next[0] === '(';
                foreach ($entries as $entry) {
                    switch ($entry['kind']) {
                        case 'function':
                            // A call: the name, then ( skipping whitespace and comments,
                            // and not preceded by ->, ?->, ::, function or new.
                            if ($call && !in_array($prev, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true) && $name === strtolower((string) $entry['match'])) {
                                $lines[$entry['id']][] = $line;
                            }
                            break;
                        case 'method':
                            // A member call: ->name(, ?->name( or a static ::name(.
                            if ($call && in_array($prev, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true) && $name === strtolower((string) $entry['match'])) {
                                $lines[$entry['id']][] = $line;
                            }
                            break;
                        case 'class':
                            // Any reference to the class name, case-insensitive: new, instanceof,
                            // extends, imports and plain names all count. Member access does not.
                            if (!in_array($prev, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NAMESPACE], true) && $name === strtolower((string) $entry['match'])) {
                                $lines[$entry['id']][] = $line;
                            }
                            break;
                    }
                }
            } elseif ($token[0] === T_VARIABLE) {
                // A property declaration: the variable directly after a visibility,
                // static, var, array or a type name. Assignments and parameters
                // have some other token before them, so they do not count.
                $prev = $this->prevSignificant($tokens, $i - 1);
                foreach ($entries as $entry) {
                    if ($entry['kind'] === 'property' && in_array($prev, [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_VAR, T_ARRAY, T_STRING], true) && (string) $token[1] === (string) $entry['match']) {
                        $lines[$entry['id']][] = $line;
                    }
                }
            } elseif ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
                // A quoted literal whose unquoted content equals the match.
                $content = substr((string) $token[1], 1, -1);
                foreach ($entries as $entry) {
                    if ($entry['kind'] === 'token' && $content === (string) $entry['match']) {
                        $lines[$entry['id']][] = $line;
                    }
                }
            }
        }

        return $lines;
    }

    /**
     * Every blade literal found in one template, as entry id to the line
     * numbers it was found on, in source order.
     *
     * @param list<array{id: string, hop: string, kind: string, match: ?string, file: ?string, key: ?string, package: ?string, package_major: ?int, label: string, keep_old: ?string, check: string, source: string}> $entries
     *
     * @return array<string, list<int>>
     */
    private function scanBlade(string $source, array $entries): array
    {
        /** @var array<string, list<int>> $lines */
        $lines = [];
        foreach ($entries as $entry) {
            $match = (string) $entry['match'];
            $start = 0;
            while (($pos = strpos($source, $match, $start)) !== false) {
                $lines[$entry['id']][] = substr_count(substr($source, 0, $pos), "\n") + 1;
                $start = $pos + strlen($match);
            }
        }

        return $lines;
    }

    /**
     * The config file a config row fires on, or null when the key is present.
     * A missing file fires too: the key is certainly absent there.
     *
     * @param array{id: string, hop: string, kind: string, match: ?string, file: ?string, key: ?string, package: ?string, package_major: ?int, label: string, keep_old: ?string, check: string, source: string} $entry
     */
    private function configHit(string $appPath, array $entry): ?string
    {
        $file = (string) $entry['file'];
        $absolute = str_replace('\\', '/', rtrim($appPath, '/\\')) . '/' . ltrim($file, '/');
        if (!is_file($absolute)) {
            return $file;
        }
        $content = (string) file_get_contents($absolute);
        $key = (string) $entry['key'];
        $dot = strrchr($key, '.');
        $leaf = $dot === false ? $key : substr($dot, 1);
        $pattern = '/[\'"]' . preg_quote($leaf, '/') . '[\'"]\s*=>/';

        return preg_match($pattern, $content) === 1 ? null : $file;
    }

    /**
     * Whether a package row fires: the app requires the package and its locked
     * major is below the row's major, so the upgrade is about to cross it.
     *
     * @param array{id: string, hop: string, kind: string, match: ?string, file: ?string, key: ?string, package: ?string, package_major: ?int, label: string, keep_old: ?string, check: string, source: string} $entry
     * @param array<string, string>|null $composerRequire
     * @param array<string, string> $locked
     */
    private function packageHit(array $entry, ?array $composerRequire, array $locked): bool
    {
        $package = (string) $entry['package'];
        $major = (int) $entry['package_major'];
        if ($composerRequire === null || !isset($composerRequire[$package])) {
            return false;
        }
        $version = $locked[$package] ?? null;
        if ($version === null) {
            return false;
        }
        $lockedMajor = self::majorOf($version);

        return $lockedMajor !== null && $lockedMajor < $major;
    }

    /**
     * The package-to-version map of the app's lock file, across packages and
     * packages-dev. Empty when the lock is missing or unreadable. A run calls
     * this once before the first step and passes the result back into scan(),
     * so package rows compare against the lock the app started with.
     *
     * @return array<string, string>
     */
    public static function lockedVersions(string $appPath): array
    {
        $lockFile = str_replace('\\', '/', rtrim($appPath, '/\\')) . '/composer.lock';
        if (!is_file($lockFile)) {
            return [];
        }
        $lock = json_decode((string) file_get_contents($lockFile), true);
        if (!is_array($lock)) {
            return [];
        }
        $locked = [];
        foreach (['packages', 'packages-dev'] as $section) {
            if (!is_array($lock[$section] ?? null)) {
                continue;
            }
            foreach ($lock[$section] as $package) {
                if (is_array($package) && isset($package['name'], $package['version']) && is_string($package['name']) && is_string($package['version'])) {
                    $locked[$package['name']] = $package['version'];
                }
            }
        }

        return $locked;
    }

    /** The major of a locked version ('2.72.5' gives 2), or null for branch aliases. */
    private static function majorOf(string $version): ?int
    {
        if (preg_match('/^v?(\d+)/', $version, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }

    /**
     * Relative path (forward slashes, against the app root) to absolute path
     * for every file under $subdir (or the app root) whose name ends in one
     * of $extensions, sorted for stable output. The walk mirrors
     * RemovalsScan's: SELF_FIRST so a directory named like a code file is
     * listed and counted as skipped, instead of vanishing as a non-leaf.
     *
     * @param list<string> $extensions
     *
     * @return array<string, string>
     */
    private function files(string $appPath, array $extensions, ?string $subdir = null): array
    {
        $appBase = str_replace('\\', '/', rtrim($appPath, '/\\'));
        $base = $subdir === null ? $appBase : $appBase . '/' . ltrim($subdir, '/');
        if (!is_dir($base)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            $name = strtolower($file->getFilename());
            $kept = false;
            foreach ($extensions as $extension) {
                if (str_ends_with($name, $extension)) {
                    $kept = true;

                    break;
                }
            }
            if (!$kept) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($appBase) + 1));
            foreach (explode('/', $relative) as $segment) {
                if (in_array($segment, self::SKIPPED_DIRECTORIES, true)) {
                    continue 2;
                }
            }
            $files[$relative] = $file->getPathname();
        }
        ksort($files);

        return $files;
    }

    /**
     * The next token that is not whitespace or a comment, as [value, index].
     *
     * @param list<string|array{0: int, 1: string, 2: int}> $tokens
     *
     * @return array{0: string|int, 1: int}|null null at the end of the source
     */
    private function nextSignificant(array $tokens, int $from): ?array
    {
        for ($i = max(0, $from); $i < count($tokens); $i++) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return [is_array($token) ? $token[0] : $token, $i];
        }

        return null;
    }

    /**
     * The value of the previous token that is not whitespace or a comment.
     *
     * @param list<string|array{0: int, 1: string, 2: int}> $tokens
     *
     * @return string|int|null null at the start of the source
     */
    private function prevSignificant(array $tokens, int $from): string|int|null
    {
        for ($i = min($from, count($tokens) - 1); $i >= 0; $i--) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($token) ? $token[0] : $token;
        }

        return null;
    }

    /**
     * Loads and validates the behaviour-changes data file.
     *
     * @return list<array{id: string, hop: string, kind: string, match: ?string, file: ?string, key: ?string, package: ?string, package_major: ?int, label: string, keep_old: ?string, check: string, source: string}>
     */
    private static function load(string $path): array
    {
        if (!is_file($path)) {
            throw new ToolError(sprintf('Behaviour changes data file "%s" is missing.', $path));
        }
        $raw = json_decode((string) file_get_contents($path), true);
        if (!is_array($raw) || ($raw['schema_version'] ?? null) !== 1 || !is_array($raw['changes'] ?? null)) {
            throw new ToolError(sprintf('Behaviour changes data file "%s" must be a JSON object with schema_version 1 and a changes list.', $path));
        }

        $entries = [];
        $seen = [];
        $always = 0;
        foreach ($raw['changes'] as $index => $entry) {
            if (!is_array($entry)) {
                throw new ToolError(sprintf('Behaviour change %d in "%s" must be an object.', $index, $path));
            }
            foreach (['id', 'hop', 'label', 'check', 'source'] as $key) {
                if (!isset($entry[$key]) || !is_string($entry[$key]) || $entry[$key] === '') {
                    throw new ToolError(sprintf('Behaviour change %d in "%s" needs a non-empty string "%s".', $index, $path, $key));
                }
            }
            if (isset($seen[$entry['id']])) {
                throw new ToolError(sprintf('Behaviour change %d in "%s" repeats id "%s".', $index, $path, $entry['id']));
            }
            $seen[$entry['id']] = true;
            if (preg_match('/^php-\d{2}-to-\d{2}$|^laravel-[0-9]+(\.[0-9]+)?-to-[0-9]+$|^package:[a-z0-9._\/-]+@\d+$/', (string) $entry['hop']) !== 1) {
                throw new ToolError(sprintf('Behaviour change %d in "%s" has hop "%s"; expected php-74-to-80, laravel-10-to-11 or package:name@major.', $index, $path, $entry['hop']));
            }
            if (!is_array($entry['detect'] ?? null)) {
                throw new ToolError(sprintf('Behaviour change %d in "%s" needs a detect object.', $index, $path));
            }
            $detect = $entry['detect'];
            if (!isset($detect['kind']) || !is_string($detect['kind']) || !in_array($detect['kind'], ['function', 'method', 'class', 'property', 'token', 'config', 'blade', 'package', 'always'], true)) {
                throw new ToolError(sprintf('Behaviour change %d in "%s" has an unknown or missing detect kind.', $index, $path));
            }
            $kind = $detect['kind'];
            $match = null;
            $file = null;
            $key = null;
            $package = null;
            $packageMajor = null;
            switch ($kind) {
                case 'config':
                    if (!isset($detect['file']) || !is_string($detect['file']) || $detect['file'] === '' || !isset($detect['key']) || !is_string($detect['key']) || $detect['key'] === '') {
                        throw new ToolError(sprintf('Behaviour change %d in "%s" needs detect.file and detect.key for a config row.', $index, $path));
                    }
                    $file = $detect['file'];
                    $key = $detect['key'];
                    break;
                case 'package':
                    // The hop was validated above, so this cannot fail; the
                    // match is kept here so the parser lives in one place.
                    preg_match('/^package:([a-z0-9._\/-]+)@(\d+)$/', (string) $entry['hop'], $m);
                    $package = $m[1] ?? null;
                    $packageMajor = isset($m[2]) ? (int) $m[2] : null;
                    break;
                case 'always':
                    // Applies to the whole hop; nothing to detect in the app.
                    break;
                default:
                    if (!isset($detect['match']) || !is_string($detect['match']) || $detect['match'] === '') {
                        throw new ToolError(sprintf('Behaviour change %d in "%s" needs detect.match for a %s row.', $index, $path, $kind));
                    }
                    $match = $detect['match'];
            }
            if (!array_key_exists('keep_old', $entry) || (!is_string($entry['keep_old']) && $entry['keep_old'] !== null)) {
                throw new ToolError(sprintf('Behaviour change %d in "%s" needs a keep_old string or null.', $index, $path));
            }
            if (!str_starts_with((string) $entry['source'], 'https://')) {
                throw new ToolError(sprintf('Behaviour change %d in "%s" has source "%s"; expected an https URL.', $index, $path, $entry['source']));
            }
            if ($kind === 'always') {
                $always++;
                if ($always > self::MAX_ALWAYS) {
                    throw new ToolError(sprintf('Behaviour changes data file "%s" has more than %d always rows.', $path, self::MAX_ALWAYS));
                }
            }
            $entries[] = [
                'id' => $entry['id'],
                'hop' => $entry['hop'],
                'kind' => $kind,
                'match' => $match,
                'file' => $file,
                'key' => $key,
                'package' => $package,
                'package_major' => $packageMajor,
                'label' => $entry['label'],
                'keep_old' => $entry['keep_old'],
                'check' => $entry['check'],
                'source' => $entry['source'],
            ];
        }

        return $entries;
    }
}
