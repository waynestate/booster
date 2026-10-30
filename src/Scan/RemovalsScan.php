<?php

declare(strict_types=1);

namespace Waynestate\Booster\Scan;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Waynestate\Booster\Packaging\PharFiles;
use Waynestate\Booster\ToolError;

/**
 * A token-level inventory of things the language itself removed between two
 * PHP versions: gone functions and extensions, plus the new-by-reference
 * syntax. It lexes with token_get_all and never parses, so even files Rector
 * cannot parse (exactly the legacy apps that need this) get inventoried.
 * Advisory only: it lists, it never fails a hop.
 */
final class RemovalsScan
{
    /** Stored findings per hop; the total count survives so output can say how many more. */
    public const MAX_FINDINGS = 200;

    /** Directories that never hold app code. */
    private const SKIPPED_DIRECTORIES = ['vendor', 'node_modules', 'storage', '.git'];

    /** @var list<array{id: string, removed_in: string, kind: string, match: string, label: string, replacement: string}> */
    private readonly array $entries;

    public function __construct(?string $dataPath = null)
    {
        $this->entries = self::load($dataPath ?? PharFiles::path('data/removals.json'));
    }

    /**
     * The entries removed after PHP $from and at or before PHP $to, in data-file order.
     *
     * @return list<array{id: string, removed_in: string, kind: string, match: string, label: string, replacement: string}>
     */
    public function entriesForHop(string $from, string $to): array
    {
        $out = [];
        foreach ($this->entries as $entry) {
            if (version_compare($entry['removed_in'], $from, '>') && version_compare($entry['removed_in'], $to, '<=')) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * Scans $appPath for uses of everything removed between PHP $from and PHP $to.
     * Never throws: an unreadable tree yields an empty result, because an
     * advisory must not take down a plan or a run.
     */
    public function scan(string $appPath, string $from, string $to): RemovalsResult
    {
        $entries = $this->entriesForHop($from, $to);
        $findings = [];
        $total = 0;
        $scanned = 0;
        $skipped = 0;

        foreach ($this->files($appPath) as $relative => $absolute) {
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
            foreach ($this->scanSource($source, $entries) as [$line, $entry]) {
                $total++;
                if (count($findings) < self::MAX_FINDINGS) {
                    $findings[] = new RemovalFinding($relative, $line, $entry['id'], $entry['label'], $entry['replacement']);
                }
            }
        }

        return new RemovalsResult($from, $to, $findings, $total, $scanned, $skipped);
    }

    /**
     * Relative path (forward slashes) to absolute path, sorted for stable output.
     *
     * @return array<string, string>
     */
    private function files(string $appPath): array
    {
        $base = str_replace('\\', '/', rtrim($appPath, '/\\'));
        if (!is_dir($base)) {
            return [];
        }

        $files = [];
        // SELF_FIRST so a directory named like a code file (broken.php) is still
        // listed and then counted as skipped, instead of vanishing as a non-leaf.
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            $name = strtolower($file->getFilename());
            if (!str_ends_with($name, '.php') && !str_ends_with($name, '.inc') && !str_ends_with($name, '.phtml')) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
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
     * Every removal found in one source string, in source order, as [line, entry].
     *
     * @param list<array{id: string, removed_in: string, kind: string, match: string, label: string, replacement: string}> $entries
     *
     * @return list<array{0: int, 1: array{id: string, removed_in: string, kind: string, match: string, label: string, replacement: string}}>
     */
    private function scanSource(string $source, array $entries): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $hits = [];
        $line = 1;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token)) {
                $line = $token[2];
                if ($token[0] !== T_STRING) {
                    continue;
                }
                // A function call: the name, then ( skipping whitespace and comments,
                // and not preceded by ->, ?->, ::, function or new.
                $next = $this->nextSignificant($tokens, $i + 1);
                if ($next === null || $next[0] !== '(') {
                    continue;
                }
                $prev = $this->prevSignificant($tokens, $i - 1);
                if ($prev !== null && in_array($prev, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
                    continue;
                }
                $name = strtolower((string) $token[1]);
                foreach ($entries as $entry) {
                    if ($entry['kind'] === 'function' && $name === $entry['match']) {
                        $hits[] = [$line, $entry];
                    } elseif (in_array($entry['kind'], ['function-prefix', 'extension'], true) && str_starts_with($name, $entry['match'])) {
                        $hits[] = [$line, $entry];
                    }
                }
                continue;
            }

            // The new-by-reference construct: = then & then new, skipping whitespace and comments.
            if ($token === '=') {
                $first = $this->nextSignificant($tokens, $i + 1);
                if ($first === null || !self::isAmpersand($first[0])) {
                    continue;
                }
                $second = $this->nextSignificant($tokens, $first[1] + 1);
                if ($second === null || $second[0] !== T_NEW) {
                    continue;
                }
                foreach ($entries as $entry) {
                    if ($entry['kind'] === 'construct') {
                        $hits[] = [$line, $entry];
                    }
                }
            }
        }

        return $hits;
    }

    /**
     * Whether a significant-token value is an ampersand. PHP 8 splits & into
     * two array tokens (T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG and
     * T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG); the bare string covers the
     * pre-8 shape.
     */
    private static function isAmpersand(string|int $value): bool
    {
        return $value === '&'
            || $value === T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG
            || $value === T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG;
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
     * Loads and validates the removals data file.
     *
     * @return list<array{id: string, removed_in: string, kind: string, match: string, label: string, replacement: string}>
     */
    private static function load(string $path): array
    {
        if (!is_file($path)) {
            throw new ToolError(sprintf('Removals data file "%s" is missing.', $path));
        }
        $raw = json_decode((string) file_get_contents($path), true);
        if (!is_array($raw) || !is_int($raw['schema_version'] ?? null) || !is_array($raw['removals'] ?? null)) {
            throw new ToolError(sprintf('Removals data file "%s" must be a JSON object with a schema_version and a removals list.', $path));
        }

        $entries = [];
        foreach ($raw['removals'] as $index => $entry) {
            if (!is_array($entry)) {
                throw new ToolError(sprintf('Removals entry %d in "%s" must be an object.', $index, $path));
            }
            foreach (['id', 'removed_in', 'kind', 'match', 'label', 'replacement'] as $key) {
                if (!isset($entry[$key]) || !is_string($entry[$key]) || $entry[$key] === '') {
                    throw new ToolError(sprintf('Removals entry %d in "%s" needs a non-empty string "%s".', $index, $path, $key));
                }
            }
            if (!in_array($entry['kind'], ['function', 'function-prefix', 'extension', 'construct'], true)) {
                throw new ToolError(sprintf('Removals entry %d in "%s" has unknown kind "%s".', $index, $path, $entry['kind']));
            }
            if (preg_match('/^\d+\.\d+$/', $entry['removed_in']) !== 1) {
                throw new ToolError(sprintf('Removals entry %d in "%s" has removed_in "%s"; expected major.minor.', $index, $path, $entry['removed_in']));
            }
            $entries[] = [
                'id' => $entry['id'],
                'removed_in' => $entry['removed_in'],
                'kind' => $entry['kind'],
                'match' => $entry['match'],
                'label' => $entry['label'],
                'replacement' => $entry['replacement'],
            ];
        }

        return $entries;
    }
}
