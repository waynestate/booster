<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run\Cake;

/**
 * The CakePHP 5 changes to the app skeleton's own files that no
 * cakephp/upgrade rule makes, each one the CakePHP 5 skeleton's own line:
 *
 * - config/bootstrap.php loads CakePHP's global functions (env(), h(),
 *   __() and the rest are opt-in since 5.0), right after the core bootstrap.
 *   Without it config/app.php dies on its first env() call.
 * - phpunit.xml(.dist) loads the fixture extension the PHPUnit 10 way
 *   (<bootstrap class="Cake\TestSuite\Fixture\Extension\PHPUnitExtension"/>)
 *   and names the covered code in <source> instead of PHPUnit 9's
 *   <filter><whitelist>, which PHPUnit 10 and later no longer read.
 *
 * A file that does not have the skeleton's shape is left alone with a
 * MANUAL line. Pure string in, string out; the engine decides which files.
 * (A skeleton crew: it only touches the bones every CakePHP app shares.)
 */
final class SkeletonRewriter
{
    /** The CakePHP 5 skeleton's line, with its comment. */
    private const FUNCTIONS_REQUIRE = "\n/*\n * Load global functions for collections, translations, debugging etc.\n */\nrequire CAKE . 'functions.php';\n";

    public const FUNCTIONS_MANUAL = "MANUAL: CakePHP 5 loads its global functions (env(), h(), __()) only on request; add require CAKE . 'functions.php'; to config/bootstrap.php right after the core bootstrap require.";

    public const EXTENSION_MANUAL = 'MANUAL: phpunit.xml loads a PHPUnit 9 extension; PHPUnit 10 and later load extensions as <bootstrap class="..."/> inside <extensions>; move it by hand.';

    /**
     * @return array{code: string, changes: list<string>, manual: list<string>}
     */
    public function bootstrap(string $code): array
    {
        // Already loads them (the CakePHP 5 skeleton, or an app that did it by hand).
        if (preg_match('/\bCAKE\s*\.\s*[\'"]functions\.php[\'"]/', $code) === 1) {
            return ['code' => $code, 'changes' => [], 'manual' => []];
        }
        $core = '/^([ \t]*require(?:_once)?\s*\(?\s*CORE_PATH\s*\.\s*[\'"]config[\'"]\s*\.\s*DS\s*\.\s*[\'"]bootstrap\.php[\'"]\s*\)?\s*;[^\n]*\n)/m';
        if (preg_match($core, $code, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return ['code' => $code, 'changes' => [], 'manual' => [self::FUNCTIONS_MANUAL]];
        }
        $end = $match[1][1] + strlen($match[1][0]);
        $eol = str_contains($code, "\r\n") ? "\r\n" : "\n";
        $insert = str_replace("\n", $eol, self::FUNCTIONS_REQUIRE);

        return [
            'code' => substr($code, 0, $end) . $insert . substr($code, $end),
            'changes' => ["Loaded CakePHP's global functions in config/bootstrap.php (require CAKE . 'functions.php'), as CakePHP 5 asks."],
            'manual' => [],
        ];
    }

    /**
     * @return array{code: string, changes: list<string>, manual: list<string>}
     */
    public function phpunitXml(string $xml, string $file): array
    {
        $changes = [];
        $manual = [];

        $extension = '/<extension\s+class\s*=\s*"\\\\?Cake\\\\TestSuite\\\\Fixture\\\\PHPUnitExtension"\s*\/>/';
        $count = 0;
        $xml = (string) preg_replace($extension, '<bootstrap class="Cake\TestSuite\Fixture\Extension\PHPUnitExtension"/>', $xml, -1, $count);
        if ($count > 0) {
            $changes[] = sprintf('Loaded the CakePHP fixture extension the PHPUnit 10 way in %s.', $file);
        }
        if (preg_match('/<extensions>(?:(?!<\/extensions>).)*<extension\s/s', $xml) === 1) {
            $manual[] = self::EXTENSION_MANUAL . ' (' . $file . ')';
        }

        // <filter><whitelist ...>DIRS [<exclude>...</exclude>]</whitelist></filter>, the PHPUnit 9 shape.
        $filter = '/([ \t]*)<filter>\s*<whitelist\b[^>]*>(.*?)<\/whitelist>\s*<\/filter>/s';
        if (preg_match($filter, $xml, $m) === 1) {
            $indent = $m[1];
            $body = $m[2];
            $exclude = '';
            if (preg_match('/<exclude>(.*?)<\/exclude>/s', $body, $e) === 1) {
                $exclude = trim($e[1]);
                $body = str_replace($e[0], '', $body);
            }
            $include = trim($body);
            if (str_contains($include, '<') && preg_match('/<(?!directory\b|file\b|\/directory|\/file|!--)/', $include) !== 1) {
                $eol = str_contains($xml, "\r\n") ? "\r\n" : "\n";
                $inner = $indent . '    ';
                $source = $indent . '<source>' . $eol
                    . $inner . '<include>' . $eol . self::reindent($include, $inner . '    ', $eol) . $eol . $inner . '</include>' . $eol
                    . ($exclude !== '' ? $inner . '<exclude>' . $eol . self::reindent($exclude, $inner . '    ', $eol) . $eol . $inner . '</exclude>' . $eol : '')
                    . $indent . '</source>';
                $xml = str_replace($m[0], $source, $xml);
                $changes[] = sprintf('Moved the covered code in %s from PHPUnit 9\'s <filter><whitelist> to <source>.', $file);
            } else {
                $manual[] = sprintf('MANUAL: %s names the covered code in <filter><whitelist>, which PHPUnit 10 and later ignore; run vendor/bin/phpunit --migrate-configuration.', $file);
            }
        }

        return ['code' => $xml, 'changes' => $changes, 'manual' => $manual];
    }

    /**
     * RequestHandlerComponent is gone in CakePHP 5, so a controller that
     * still loads it answers every request with a MissingComponentException
     * (a 500). The loadComponent('RequestHandler', ...) statement goes when
     * nothing else in the app reads $this->RequestHandler; with
     * $withViewClasses (the app routes .json or .xml extensions) the
     * controller gets the viewClasses() that keeps those extensions on
     * JsonView and XmlView, the part of the component CakePHP 5 moved into
     * the controller.
     *
     * @return array{code: string, removed: bool, viewClasses: bool}
     */
    public function requestHandler(string $code, bool $withViewClasses): array
    {
        $load = '/^[ \t]*\$this\s*->\s*loadComponent\(\s*[\'"]RequestHandler[\'"]\s*(?:,\s*(\[(?:[^\[\]]++|(?1))*\]))?\s*,?\s*\)\s*;[^\S\r\n]*(?:\/\/[^\r\n]*)?\R/m';
        $count = 0;
        $code = (string) preg_replace($load, '', $code, -1, $count);
        if ($count === 0) {
            return ['code' => $code, 'removed' => false, 'viewClasses' => false];
        }
        $added = false;
        if ($withViewClasses && preg_match('/function\s+viewClasses\s*\(/i', $code) !== 1) {
            $close = strrpos($code, '}');
            if ($close !== false) {
                $eol = str_contains($code, "\r\n") ? "\r\n" : "\n";
                $method = implode($eol, [
                    '',
                    '    /**',
                    '     * The view classes CakePHP 4\'s RequestHandler component picked by',
                    '     * extension (moved here by Booster for CakePHP 5).',
                    '     *',
                    '     * @return array<class-string<\Cake\View\View>>',
                    '     */',
                    '    public function viewClasses(): array',
                    '    {',
                    '        return [\Cake\View\JsonView::class, \Cake\View\XmlView::class];',
                    '    }',
                    '',
                ]);
                $before = rtrim(substr($code, 0, $close));
                $code = $before . $eol . $method . substr($code, $close);
                $added = true;
            }
        }

        return ['code' => $code, 'removed' => true, 'viewClasses' => $added];
    }

    /**
     * CakePHP 5 names the core cache _cake_translations_ (cakephp50 renames
     * it in config/app.php) and logs a deprecation for a log engine with
     * 'scopes' => false (null is the new "no scopes"). With $cacheRenamed
     * the other '_cake_core_' strings follow the rename (bootstrap.php's
     * debug duration, the home page's cache check).
     *
     * @return array{code: string, changes: int}
     */
    public function config(string $code, bool $cacheRenamed): array
    {
        $changes = 0;
        $code = (string) preg_replace('/([\'"]scopes[\'"]\s*=>\s*)false\b/', '$1null', $code, -1, $scopes);
        $changes += $scopes;
        if ($cacheRenamed) {
            $code = str_replace(["'_cake_core_'", '"_cake_core_"', '._cake_core_.'], ["'_cake_translations_'", '"_cake_translations_"', '._cake_translations_.'], $code, $renamed);
            $changes += $renamed;
        }

        return ['code' => $code, 'changes' => $changes];
    }

    /**
     * A template: CakePHP 5's Connection has no connect() (the skeleton's
     * home page checks the database with
     * ConnectionManager::get('default')->connect(), a 500 on 5), so the call
     * goes through the driver, as the CakePHP 5 skeleton does; and the
     * _cake_core_ cache follows the rename when $cacheRenamed.
     *
     * @return array{code: string, changes: int}
     */
    public function template(string $code, bool $cacheRenamed): array
    {
        $code = (string) preg_replace('/(ConnectionManager::get\(\s*[^()]*\))\s*->\s*connect\(\s*\)/', '$1->getDriver()->connect()', $code, -1, $connect);
        $changes = $connect;
        // The skeleton's own shape: $connection = ConnectionManager::get('default'); $connection->connect().
        if (preg_match_all('/\$(\w+)\s*=\s*ConnectionManager::get\(/', $code, $vars) > 0) {
            foreach (array_unique($vars[1]) as $var) {
                $code = (string) preg_replace('/\$' . $var . '\s*->\s*connect\(\s*\)/', '$' . $var . '->getDriver()->connect()', $code, -1, $count);
                $changes += $count;
            }
        }
        if ($cacheRenamed) {
            $code = str_replace(["'_cake_core_'", '"_cake_core_"'], ["'_cake_translations_'", '"_cake_translations_"'], $code, $renamed);
            $changes += $renamed;
        }

        return ['code' => $code, 'changes' => $changes];
    }

    /** Each line of $block, trimmed and put at $indent. */
    private static function reindent(string $block, string $indent, string $eol): string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $block) ?: []), static fn(string $line): bool => $line !== ''));

        return implode($eol, array_map(static fn(string $line): string => $indent . $line, $lines));
    }
}
