<?php

declare(strict_types=1);

namespace Waynestate\Booster\Node;

use Waynestate\Booster\ToolError;

/**
 * Reads a simple webpack.mix.js without executing it. The simple shape is one
 * or more mix.js('<in>', '<out>') calls, each optionally chained with .vue(),
 * .sass('<in>', '<out>'), .postCss('<in>', '<out>', [...]) and .version().
 * Anything else earns a refusal that names the first unsupported call, so the
 * developer follows the manual Vite migration guide instead of guessing.
 */
final class MixFile
{
    /** The only calls BOOSTER converts. */
    private const ALLOWED = ['js', 'vue', 'sass', 'postCss', 'version'];

    private const GUIDE = 'https://laravel.com/docs/9.x/upgrade#vite';

    public const FILE = 'webpack.mix.js';

    /**
     * @throws ToolError when the file uses a call outside the simple shape
     */
    public static function parse(string $text): MixBuild
    {
        $code = self::stripComments($text);
        $entries = [];
        $vue = false;
        $violation = null;

        // Every mix.<name> use in the file, in order: catches mix.options,
        // mix.webpackConfig and friends wherever they sit.
        if (preg_match_all('/\bmix\s*\.\s*([A-Za-z_$][\w$]*)/', $code, $uses, PREG_OFFSET_CAPTURE) === false) {
            throw new ToolError('Could not scan ' . self::FILE . ' for Mix calls.');
        }
        foreach ($uses[1] as [$name, $offset]) {
            if (!in_array($name, self::ALLOWED, true)) {
                $violation = self::earlier($violation, $name, $offset);
            }
        }

        // Each mix.js(...) starts a chain; walk what follows it.
        $calls = self::jsCalls($code);
        if ($calls === []) {
            throw new ToolError(self::refusal('no mix.js("<input>", "<output>") entry found'));
        }
        foreach ($calls as [$start, $input, $output]) {
            $entries[] = new MixEntry(MixEntry::JS, $input, $output);
            self::walkChain($code, $start, $entries, $vue, $violation);
        }

        if ($violation !== null) {
            throw new ToolError(self::refusal(sprintf('unsupported Mix call "%s"', $violation[0])));
        }

        return new MixBuild($entries, $vue);
    }

    /**
     * @return list<array{0: int, 1: string, 2: string}> [offset, input, output] of each mix.js(...) call
     */
    private static function jsCalls(string $code): array
    {
        $pattern = "/mix\\s*\\.\\s*js\\s*\\(\\s*(['\"])([^'\"]+)\\1\\s*,\\s*(['\"])([^'\"]+)\\3\\s*\\)/";
        if (preg_match_all($pattern, $code, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === false) {
            return [];
        }

        $calls = [];
        foreach ($matches as $match) {
            $calls[] = [$match[0][1], $match[2][0], $match[4][0]];
        }

        return $calls;
    }

    /**
     * Walks the method chain after one mix.js(...) until its semicolon.
     *
     * @param list<MixEntry> $entries
     * @param array{0: string, 1: int}|null $violation
     */
    private static function walkChain(string $code, int $start, array &$entries, bool &$vue, ?array &$violation): void
    {
        // Resume just past the closing parenthesis of the mix.js(...) call.
        $paren = strpos($code, ')', $start);
        if ($paren === false) {
            $violation = self::earlier($violation, 'a mix.js(...) call with no closing parenthesis', $start);

            return;
        }
        $pos = $paren + 1;
        $length = strlen($code);

        while ($pos < $length) {
            if (preg_match('/^\s*/A', substr($code, $pos), $ws) === 1) {
                $pos += strlen($ws[0]);
            }
            if ($pos >= $length || $code[$pos] === ';') {
                return;
            }
            if ($code[$pos] !== '.') {
                $violation = self::earlier($violation, 'an unexpected token where a chained Mix call was expected', $pos);

                return;
            }
            if (preg_match('/^\.([A-Za-z_$][\w$]*)/A', substr($code, $pos), $call) !== 1) {
                $violation = self::earlier($violation, 'an unrecognised chained call', $pos);

                return;
            }
            $name = $call[1];
            $after = $pos + strlen($call[0]);
            if (!in_array($name, self::ALLOWED, true)) {
                $violation = self::earlier($violation, $name, $pos);

                return;
            }

            if ($name === 'vue' || $name === 'version') {
                if (preg_match('/^\(\s*\)/A', substr($code, $after), $empty) !== 1) {
                    $violation = self::earlier($violation, $name . '() with arguments', $pos);

                    return;
                }
                $pos = $after + strlen($empty[0]);
                if ($name === 'vue') {
                    $vue = true;
                }

                continue;
            }

            // js, sass and postCss take ('<in>', '<out>'); postCss may add a plugin list.
            if (preg_match('/^\(\s*(\x27|\x22)([^\x27\x22]+)\1\s*,\s*(\x27|\x22)([^\x27\x22]+)\3\s*(,\s*\[[^\]]*\])?\s*\)/', substr($code, $after), $args) !== 1) {
                $violation = self::earlier($violation, $name . '() with arguments BOOSTER cannot read', $pos);

                return;
            }
            $type = $name === 'js' ? MixEntry::JS : ($name === 'sass' ? MixEntry::SASS : MixEntry::POSTCSS);
            $entries[] = new MixEntry($type, $args[2], $args[4]);
            $pos = $after + strlen($args[0]);
        }
    }

    /**
     * @param array{0: string, 1: int}|null $violation
     *
     * @return array{0: string, 1: int}
     */
    private static function earlier(?array $violation, string $name, int $offset): array
    {
        if ($violation === null || $offset < $violation[1]) {
            return [$name, $offset];
        }

        return $violation;
    }

    private static function refusal(string $reason): string
    {
        return sprintf(
            'Refusing to convert %s: %s. BOOSTER converts the simple shape only (mix.js, .vue(), .sass(), .postCss(), .version()); follow the manual Vite migration guide at %s.',
            self::FILE,
            $reason,
            self::GUIDE,
        );
    }

    /** Comments are dropped before scanning; nothing else about the file is interpreted. */
    private static function stripComments(string $code): string
    {
        $code = preg_replace('#/\*.*?\*/#s', ' ', $code) ?? $code;
        $code = preg_replace('#//[^\n]*#', '', $code) ?? $code;

        return $code;
    }
}
