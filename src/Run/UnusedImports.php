<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

/**
 * Removes class imports that no longer name anything in the file. Runs after
 * a Laravel hop's Rector pass, which renames and drops classes and leaves
 * dead `use` lines behind: Rector rewrites code, it does not tidy imports.
 *
 * Only whole-line, column-zero class imports are touched, aliased or not.
 * Indented trait uses inside class bodies stay alone, as do `use function`,
 * `use const` and grouped `use X\{...}` imports. A name found anywhere else
 * in the file (code, docblock, comment, string) keeps its import.
 */
final class UnusedImports
{
    /**
     * @return array{0: string, 1: int} the cleaned content and how many imports were removed
     */
    public static function remove(string $content): array
    {
        $lines = explode("\n", $content);
        $imports = [];
        foreach ($lines as $i => $line) {
            // Group 1 is the class's last segment, group 2 its alias when there is one.
            if (preg_match('/^use\s+(?:[A-Za-z_][A-Za-z0-9_]*\\\\)+([A-Za-z_][A-Za-z0-9_]*)\s*(?:as\s+([A-Za-z_][A-Za-z0-9_]*))?\s*;/', $line, $m) === 1) {
                $alias = $m[2] ?? '';
                $imports[$i] = $alias !== '' ? $alias : $m[1];
            }
        }
        if ($imports === []) {
            return [$content, 0];
        }

        // The search corpus is the file without its import lines, so one
        // import's name appearing in another import line never counts as a use.
        $corpus = '';
        foreach ($lines as $i => $line) {
            if (!isset($imports[$i])) {
                $corpus .= $line . "\n";
            }
        }

        $kept = [];
        $removed = 0;
        foreach ($lines as $i => $line) {
            if (isset($imports[$i]) && preg_match('/\b' . preg_quote($imports[$i], '/') . '\b/', $corpus) !== 1) {
                $removed++;
                continue;
            }
            $kept[] = $line;
        }

        return [implode("\n", $kept), $removed];
    }

    /**
     * Cleans one file in place. Returns how many imports were removed, or 0
     * when the file is missing, unreadable or had none to remove.
     */
    public static function cleanFile(string $path): int
    {
        if (!is_file($path)) {
            return 0;
        }
        $content = file_get_contents($path);
        if ($content === false) {
            return 0;
        }
        [$cleaned, $removed] = self::remove($content);
        if ($removed === 0 || file_put_contents($path, $cleaned) === false) {
            return 0;
        }

        return $removed;
    }
}
