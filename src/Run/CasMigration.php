<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Waynestate\Booster\ToolError;

/**
 * Moves an app off cit-web-infra/wayne-cas where Rector cannot reach: the
 * published CAS config and the user model's trait usage. It never runs app
 * code; the old config is read with conservative patterns, and anything it
 * cannot move safely becomes a manual note that names the file.
 */
final class CasMigration
{
    public const OLD_CONFIG = 'config/wayne-cas.php';
    public const NEW_CONFIG = 'config/sesame.php';

    public const INTERFACE = 'WayneCasable';
    public const READ_ONLY_TRAIT = 'ReadOnlyAttributes';

    private const OLD_NAMESPACE = 'WSU\WayneCas';
    private const NEW_NAMESPACE = 'Waynestate\Sesame\WayneCas';

    public function migrate(string $appPath, bool $dryRun): CasMigrationResult
    {
        $appPath = str_replace('\\', '/', rtrim($appPath, '/\\'));
        $changes = [];
        $files = [];
        $manual = [];

        $root = $this->oldConfig($appPath, $manual);
        if ($root !== null) {
            $newFile = $appPath . '/' . self::NEW_CONFIG;
            if (!is_file($newFile)) {
                if (!$dryRun) {
                    $this->writeSesameConfig($newFile, $root);
                }
                $changes[] = ($dryRun ? 'Dry run: would write ' : '') . self::NEW_CONFIG . sprintf(" with cas.base_url '%s' from %s.", $root, self::OLD_CONFIG);
                $files[] = ['file' => self::NEW_CONFIG, 'diff' => 'written from ' . self::OLD_CONFIG];
            } else {
                $manual[] = self::NEW_CONFIG . ' already exists, so BOOSTER left it alone. Set its cas.base_url to \'' . $root . '\' (the old wayne-cas root) if it differs.';
            }
        }

        foreach ($this->modelFiles($appPath) as $relative => $absolute) {
            $rewritten = $this->rewriteModel((string) file_get_contents($absolute), $relative, $manual);
            if ($rewritten === null) {
                continue;
            }
            if (!$dryRun) {
                file_put_contents($absolute, $rewritten);
            }
            $changes[] = ($dryRun ? 'Dry run: would rewrite ' : '') . $relative . ': rewritten to implements ' . self::INTERFACE . ' plus use ' . self::READ_ONLY_TRAIT . '.';
            $files[] = ['file' => $relative, 'diff' => 'implements ' . self::INTERFACE . ' plus use ' . self::READ_ONLY_TRAIT];
        }

        return new CasMigrationResult($changes, $files, $manual);
    }

    /**
     * Reads the published wayne-cas config without running it. Returns the
     * plain-string root, or null when Booster cannot read one.
     *
     * @param list<string> $manual
     */
    private function oldConfig(string $appPath, array &$manual): ?string
    {
        $file = $appPath . '/' . self::OLD_CONFIG;
        if (!is_file($file)) {
            $manual[] = self::OLD_CONFIG . ' is missing, so BOOSTER has no CAS root to move. Write ' . self::NEW_CONFIG . ' with sesame.cas.base_url set to your CAS server.';

            return null;
        }

        $content = (string) file_get_contents($file);
        $root = self::plainString($content, 'root');
        if ($root === null) {
            $manual[] = self::OLD_CONFIG . ': BOOSTER could not read a plain string for \'root\', so it left ' . self::NEW_CONFIG . ' alone. Set sesame.cas.base_url by hand.';
        }
        if (self::boolValue($content, 'use2FA') === true) {
            $manual[] = 'MANUAL: the app had use2FA enabled in ' . self::OLD_CONFIG . ', but SESAME does not carry Duo. Re-enable two-factor in the app itself; DUO_HOSTNAME, DUO_IKEY, DUO_SKEY and DUO_AKEY do nothing under SESAME.';
        }
        $manual[] = self::OLD_CONFIG . ': left in place; remove it once SESAME is verified.';

        return $root;
    }

    /**
     * A quoted string value for a key, straight from the source text.
     */
    private static function plainString(string $content, string $key): ?string
    {
        if (preg_match('/^[ \t]*\x27' . $key . '\x27[ \t]*=>[ \t]*\x27([^\x27]*)\x27/m', $content, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/^[ \t]*\x27' . $key . '\x27[ \t]*=>[ \t]*"([^"]*)"/m', $content, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * A boolean value for a key, straight from the source text.
     */
    private static function boolValue(string $content, string $key): ?bool
    {
        if (preg_match('/^[ \t]*\x27' . $key . '\x27[ \t]*=>[ \t]*true\b/m', $content) === 1) {
            return true;
        }
        if (preg_match('/^[ \t]*\x27' . $key . '\x27[ \t]*=>[ \t]*false\b/m', $content) === 1) {
            return false;
        }

        return null;
    }

    /**
     * The config/sesame.php Booster writes: the driver the app is moving to,
     * and the old CAS root as the base URL.
     */
    private function writeSesameConfig(string $file, string $root): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new ToolError(sprintf('Could not create "%s".', $dir));
        }
        $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $root);
        $php = <<<PHP
        <?php

        /*
         * Generated by Booster from config/wayne-cas.php. The old file stays
         * in place and can be removed once SESAME is verified.
         */
        return [
            'driver' => 'cas',
            'cas' => [
                'base_url' => '{$escaped}',
            ],
        ];
        PHP . "\n";
        if (file_put_contents($file, $php) === false) {
            throw new ToolError(sprintf('Could not write "%s".', $file));
        }
    }

    /**
     * PHP files outside vendor/, node_modules/, public/ and storage/.
     *
     * @return array<string, string> relative path to absolute path
     */
    private function modelFiles(string $path): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                continue;
            }
            $relative = $this->relative($path, $file->getPathname());
            foreach (['.git/', 'vendor/', 'node_modules/', 'public/', 'storage/', 'bootstrap/cache/'] as $skip) {
                if (str_starts_with($relative, $skip)) {
                    continue 2;
                }
            }
            $files[$relative] = $file->getPathname();
        }
        ksort($files);

        return $files;
    }

    /**
     * Rewrites a model that uses WayneCasable as a trait into one that
     * implements it and uses ReadOnlyAttributes. Returns the new content, or
     * null when there is nothing to do or the shape is not safe, in which
     * case a manual note is added.
     *
     * @param list<string> $manual
     */
    private function rewriteModel(string $content, string $relative, array &$manual): ?string
    {
        $region = $this->classRegion($content);
        if ($region === null) {
            if (self::referencesTrait($content)) {
                $manual[] = $relative . ': BOOSTER could not find the class that uses ' . self::INTERFACE . ', so it left the file alone. Move the trait to implements ' . self::INTERFACE . ' plus use ' . self::READ_ONLY_TRAIT . ' by hand.';
            }

            return null;
        }
        [$classStart, $bracePos] = $region;
        $decl = substr($content, $classStart, $bracePos - $classStart);
        if (str_contains($decl, '{') || preg_match_all('/\b(class|interface|trait|enum)\b/', $decl) !== 1) {
            $manual[] = $relative . ': BOOSTER could not read the class declaration around the ' . self::INTERFACE . ' trait use, so it left the file alone. Move the trait to implements ' . self::INTERFACE . ' plus use ' . self::READ_ONLY_TRAIT . ' by hand.';

            return null;
        }

        $head = substr($content, 0, $classStart);
        $body = substr($content, $bracePos);
        $traitLines = self::traitUseLines($body);
        $targets = array_values(array_filter($traitLines, static fn(array $line): bool => in_array(self::INTERFACE, $line[2], true)));
        if ($targets === []) {
            return null;
        }
        foreach ($traitLines as $line) {
            foreach ($line[2] as $item) {
                if (str_starts_with($item, '\\') && str_ends_with($item, '\\' . self::INTERFACE)) {
                    $manual[] = $relative . ': BOOSTER found a fully qualified ' . self::INTERFACE . ' trait use, which it will not rewrite. Move it to implements ' . self::INTERFACE . ' plus use ' . self::READ_ONLY_TRAIT . ' by hand.';

                    return null;
                }
            }
        }

        $oldImport = 'use ' . self::OLD_NAMESPACE . '\\' . self::INTERFACE . ';';
        $newImport = 'use ' . self::NEW_NAMESPACE . '\\' . self::INTERFACE . ';';
        $oldRoImport = 'use ' . self::OLD_NAMESPACE . '\\' . self::READ_ONLY_TRAIT . ';';
        $newRoImport = 'use ' . self::NEW_NAMESPACE . '\\' . self::READ_ONLY_TRAIT . ';';
        $hasOldImport = str_contains($head, $oldImport);
        $hasNewImport = str_contains($head, $newImport);
        if (!$hasOldImport && !$hasNewImport) {
            $manual[] = $relative . ': BOOSTER found no import for ' . self::INTERFACE . ', so it left the file alone. Add the import and move the trait to implements ' . self::INTERFACE . ' by hand.';

            return null;
        }
        $hasOldRo = str_contains($head, $oldRoImport);
        $hasNewRo = str_contains($head, $newRoImport);
        $roAlreadyUsed = self::anyTraitLineHas($traitLines, self::READ_ONLY_TRAIT);

        // Imports first, so the trait lines below see the final namespaces.
        if ($hasOldImport && !$hasNewImport) {
            $head = str_replace($oldImport, $newImport, $head);
        }
        if ($hasOldRo && !$hasNewRo) {
            $head = str_replace($oldRoImport, $newRoImport, $head);
        }
        if (!$hasNewRo && !$hasOldRo) {
            $pos = strpos($head, $newImport);
            $eol = $pos === false ? false : strpos($head, "\n", $pos + strlen($newImport));
            $at = $eol === false ? strlen($head) : $eol + 1;
            $head = substr($head, 0, $at) . $newRoImport . "\n" . substr($head, $at);
        }

        // The class declaration gains the interface when it lacks one. The
        // whitespace between the declaration and the opening brace is kept.
        $newDecl = $decl;
        if (preg_match('/\bimplements\b(.+)$/s', $decl, $m, PREG_OFFSET_CAPTURE) === 1) {
            $listText = $m[1][0];
            $separator = strlen($listText) - strlen(ltrim($listText, " \t\n"));
            $listText = substr($listText, $separator);
            $items = array_values(array_filter(array_map('trim', explode(',', $listText)), static fn(string $item): bool => $item !== ''));
            $already = false;
            foreach ($items as $item) {
                if ($item === self::INTERFACE || str_ends_with($item, '\\' . self::INTERFACE)) {
                    $already = true;
                    break;
                }
            }
            if (!$already) {
                $items[] = self::INTERFACE;
                $tail = self::trailingWhitespace($listText);
                $newDecl = substr($decl, 0, $m[1][1] + $separator) . implode(', ', $items) . $tail;
            }
        } else {
            $tail = self::trailingWhitespace($decl);
            $newDecl = rtrim($decl) . ' implements ' . self::INTERFACE . $tail;
        }

        // Trait lines lose the interface and gain ReadOnlyAttributes once.
        usort($targets, static fn(array $a, array $b): int => $b[0] <=> $a[0]);
        foreach ($targets as [$offset, $indent, $items]) {
            $kept = array_values(array_filter($items, static fn(string $item): bool => $item !== self::INTERFACE));
            if (!$roAlreadyUsed) {
                $kept[] = self::READ_ONLY_TRAIT;
            }
            $lineStart = strrpos(substr($body, 0, $offset), "\n");
            $lineStart = $lineStart === false ? 0 : $lineStart + 1;
            $end = strpos($body, "\n", $offset);
            if ($kept === []) {
                // Drop the whole line, newline included, so no blank line lingers.
                $cutEnd = $end === false ? strlen($body) : $end + 1;
                $body = substr($body, 0, $lineStart) . substr($body, $cutEnd);
            } else {
                $end = $end === false ? strlen($body) : $end;
                $body = substr($body, 0, $lineStart) . $indent . 'use ' . implode(', ', $kept) . ';' . substr($body, $end);
            }
        }

        return $head . $newDecl . $body;
    }

    /**
     * The class whose body uses WayneCasable as a trait: the declaration
     * offset and the offset of its opening brace, or null.
     *
     * @return array{0: int, 1: int}|null
     */
    private function classRegion(string $content): ?array
    {
        $starts = [];
        if (preg_match_all('/^[ \t]*(?:final[ \t]+|abstract[ \t]+)?class[ \t]+[A-Za-z0-9_]+/m', $content, $m, PREG_OFFSET_CAPTURE) !== false) {
            foreach ($m[0] as $match) {
                $starts[] = $match[1];
            }
        }
        foreach ($starts as $i => $start) {
            $brace = strpos($content, '{', $start);
            if ($brace === false || $brace - $start > 500) {
                continue;
            }
            $next = $i + 1 < count($starts) ? $starts[$i + 1] : strlen($content);
            if (self::referencesTrait(substr($content, $brace, $next - $brace))) {
                return [$start, $brace];
            }
        }

        return null;
    }

    /**
     * Whether the text carries a trait-use line that mentions WayneCasable.
     */
    private static function referencesTrait(string $content): bool
    {
        return preg_match('/^[ \t]*use[ \t]+[^;]*' . self::INTERFACE . '[^;]*;/m', $content) === 1;
    }

    /**
     * Every `use Trait[, Trait];` statement in the text.
     *
     * @return list<array{0: int, 1: string, 2: list<string>}> offset, indent, trait names
     */
    private static function traitUseLines(string $content): array
    {
        $lines = [];
        if (preg_match_all('/^[ \t]*use[ \t]+([^;]+);[ \t]*$/m', $content, $m, PREG_OFFSET_CAPTURE) !== false) {
            foreach ($m[0] as $i => $match) {
                // The match starts at the line's first column, so the indent is
                // its own leading whitespace.
                $text = $m[0][$i][0];
                $indent = substr($text, 0, strlen($text) - strlen(ltrim($text, " \t")));
                $lines[] = [
                    $match[1],
                    $indent,
                    array_map('trim', explode(',', $m[1][$i][0])),
                ];
            }
        }

        return $lines;
    }

    /**
     * The run of whitespace at the end of the text.
     */
    private static function trailingWhitespace(string $text): string
    {
        $trimmed = rtrim($text);

        return substr($text, strlen($trimmed));
    }

    /**
     * @param list<array{0: int, 1: string, 2: list<string>}> $lines
     */
    private static function anyTraitLineHas(array $lines, string $trait): bool
    {
        foreach ($lines as $line) {
            if (in_array($trait, $line[2], true)) {
                return true;
            }
        }

        return false;
    }

    private function relative(string $base, string $file): string
    {
        $base = str_replace('\\', '/', rtrim($base, '/\\'));
        $file = str_replace('\\', '/', $file);

        return substr($file, strlen($base) + 1);
    }
}
