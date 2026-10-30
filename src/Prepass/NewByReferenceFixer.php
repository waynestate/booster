<?php

declare(strict_types=1);

namespace Waynestate\Booster\Prepass;

use FilesystemIterator;
use ParseError;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Waynestate\Booster\ToolError;

/**
 * Rewrites PHP 4 era "new by reference" ($a = &new Foo();) to a plain
 * assignment before Rector runs. PHP 7 and newer refuse to compile the old
 * spelling, so Rector never gets a parse tree for such a file and the hop
 * stops with nothing changed. In PHP 5 objects were already handles, so
 * dropping the reference changes nothing at runtime.
 *
 * The fix is token based: a regex over raw text would reach into strings and
 * comments where "= &new" is just text.
 */
final class NewByReferenceFixer
{
    /** Directory prefixes the walker never enters. */
    private const SKIP = ['.git/', 'vendor/', 'node_modules/', 'storage/'];

    /** File extensions the walker reads. */
    private const EXTENSIONS = ['.php', '.inc', '.phtml'];

    /**
     * @return array{0: string, 1: int} the new source and how many times it was rewritten
     */
    public function fix(string $source): array
    {
        try {
            $tokens = token_get_all($source);
        } catch (ParseError) {
            // TOKEN_PARSE is not passed, so this is belt and braces: a file
            // the lexer cannot handle is left alone.
            return [$source, 0];
        }

        $count = count($tokens);
        $out = '';
        $rewrites = 0;
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $newAt = is_string($token) && $token === '=' ? $this->newAfterEquals($tokens, $i, $count) : -1;
            if ($newAt !== -1) {
                $out .= '= new';
                $rewrites++;
                $i = $newAt; // the loop increment moves past T_NEW
            } else {
                $out .= is_string($token) ? $token : $token[1];
            }
        }

        return [$out, $rewrites];
    }

    /**
     * Walks $path and rewrites every file it owns. When $write is false the
     * files are only reported, not touched.
     */
    public function fixTree(string $path, bool $write): PrepassResult
    {
        $entries = [];
        foreach ($this->files($path) as $relative => $absolute) {
            $source = @file_get_contents($absolute);
            if ($source === false) {
                continue;
            }
            [$fixed, $rewrites] = $this->fix($source);
            if ($rewrites === 0) {
                continue;
            }
            if ($write && file_put_contents($absolute, $fixed) === false) {
                throw new ToolError(sprintf('The pre-pass could not write %s.', $relative));
            }
            $entries[] = [
                'file' => $relative,
                'rewrites' => $rewrites,
                'original' => $write ? $source : null,
                'absolute' => $absolute,
            ];
        }

        return new PrepassResult($entries);
    }

    /**
     * When $tokens[$i] is "=", the index of the T_NEW reached by skipping
     * whitespace and comments past one "&", or -1 when the shape is not
     * "new by reference".
     *
     * @param list<string|array{0: int, 1: string, 2: int}> $tokens
     */
    private function newAfterEquals(array $tokens, int $i, int $count): int
    {
        $amp = $this->pastSkippable($tokens, $i + 1, $count);
        if ($amp === null || !$this->isAmpersand($tokens[$amp])) {
            return -1;
        }

        $next = $this->pastSkippable($tokens, $amp + 1, $count);

        return $next !== null && is_array($tokens[$next]) && $tokens[$next][0] === T_NEW ? $next : -1;
    }

    /**
     * The first index at or after $start whose token is neither whitespace nor
     * a comment, or null past the end.
     *
     * @param list<string|array{0: int, 1: string, 2: int}> $tokens
     */
    private function pastSkippable(array $tokens, int $start, int $count): ?int
    {
        while ($start < $count && $this->isSkippable($tokens[$start])) {
            $start++;
        }

        return $start < $count ? $start : null;
    }

    /**
     * @param string|array{0: int, 1: string, 2: int} $token
     */
    private function isSkippable(string|array $token): bool
    {
        return is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }

    /**
     * PHP 8 lexes "&" as T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG when it does
     * not introduce a variable and as a plain "&" when it does, so both
     * spellings are checked.
     *
     * @param string|array{0: int, 1: string, 2: int} $token
     */
    private function isAmpersand(string|array $token): bool
    {
        if (is_string($token)) {
            return $token === '&';
        }

        return $token[0] === T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG;
    }

    /**
     * Files under $path the pre-pass owns: the listed extensions outside the
     * skipped directories.
     *
     * @return array<string, string> relative path (forward slashes) to absolute path, sorted
     */
    private function files(string $path): array
    {
        $base = str_replace('\\', '/', rtrim($path, '/\\'));
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
            foreach (self::SKIP as $skip) {
                if (str_starts_with($relative, $skip)) {
                    continue 2;
                }
            }
            $name = $file->getFilename();
            // IDE helper files are editor output, not app source.
            $lower = strtolower($name);
            if ($lower === '.phpstorm.meta.php' || (str_starts_with($lower, '_ide_helper') && str_ends_with($lower, '.php'))) {
                continue;
            }
            $dot = strrpos($name, '.');
            if ($dot === false) {
                continue;
            }
            if (!in_array(strtolower(substr($name, $dot)), self::EXTENSIONS, true)) {
                continue;
            }
            $files[$relative] = $file->getPathname();
        }
        ksort($files);

        return $files;
    }
}
