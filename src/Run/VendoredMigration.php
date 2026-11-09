<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

use Waynestate\Booster\Packaging\PharFiles;
use Waynestate\Booster\Scan\VendoredLibraries;
use Waynestate\Booster\Scan\VendoredLibrary;
use Waynestate\Booster\ToolError;

/**
 * The vendored-to-composer hop's engine. An app that hand-copied Doctrine,
 * Smarty or Slim 1 into vendor/ (a real one did all three in 2012) cannot run
 * on a new PHP, and Booster never edits a library's files. So this moves the
 * app instead: the hand copies leave, composer.json asks for the maintained
 * release of the same library, the lines that loaded the copies load
 * Composer's autoloader, and a Slim 1 app keeps its routes through a bridge
 * onto Slim 4. The app's own code is not otherwise touched; the replay gate
 * proves the answers did not change.
 *
 * Every rewrite is one of a few exact line shapes. Anything else that touches
 * a moved library is a stop sign: the hop refuses, names the file and line,
 * and changes nothing. Guessing is how a framework move breaks production on
 * a Friday afternoon, and Booster would rather sulk than guess.
 */
final class VendoredMigration
{
    /** Where the Slim 1 bridge lands, at the app root, loaded by Composer's classmap. */
    public const BRIDGE_FILE = 'slim1-bridge.php';

    /** The header Smarty 2 and 3 write at the top of every compiled template. */
    private const COMPILED_TEMPLATE = '/\/\*\s*Smarty version\b.{0,200}?compiled from\b/s';

    /** A path segment that names a cache or a compile folder (templates_c, tmp/template_compile, cache/). */
    private const CACHE_FOLDER = '#/(?:[^/]*_c|[^/]*compile[^/]*|[^/]*cache[^/]*)/#i';

    /**
     * One recipe per library, keyed by the hand copy's lowercased folder
     * name. major: the only major line the recipe accepts. floor: the lowest
     * PHP the Composer releases run on, so the planner knows where the hop
     * fits. Versions checked against Packagist on 2026-11-06: smarty/smarty
     * 4.5.8 and doctrine/orm 2.20.14 run on PHP 7.1 to 8.x, slim/slim 4.15.3
     * on 7.4 to 8.5. Smarty 4 and ORM 2.20 keep the API a 2012 app calls, so
     * its code does not change; Slim 4 does not, hence the bridge.
     */
    private const RECIPES = [
        'doctrine' => [
            'major' => '2',
            'floor' => '7.1',
            'release' => 'doctrine/orm 2.20',
            'packages' => [
                'doctrine/orm' => '^2.20',
                // ORM 2.20's Setup reads docblock annotations with the simple
                // reader, which annotations 2.0 removed.
                'doctrine/annotations' => '^1.14',
                // ORM 2.20's Setup needs a PSR-6 cache and doctrine/cache 2 ships none.
                'symfony/cache' => '^4.4 || ^5.4 || ^6.4 || ^7.0',
            ],
        ],
        'smarty' => [
            'major' => '3',
            'floor' => '7.1',
            'release' => 'smarty/smarty 4.5',
            'packages' => ['smarty/smarty' => '^4.5'],
        ],
        'slim' => [
            'major' => '1',
            'floor' => '7.4',
            'release' => 'slim/slim 4 through the Slim 1 bridge',
            'packages' => ['slim/slim' => '^4.15', 'slim/psr7' => '^1.6'],
        ],
    ];

    /** The Slim 1 app methods the bridge does not carry. Using one is a stop sign. */
    private const SLIM_UNSUPPORTED = [
        'hook', 'applyHook', 'getHooks', 'clearHooks', 'add', 'flash', 'flashNow', 'flashKeep',
        'setCookie', 'getCookie', 'deleteCookie', 'setEncryptedCookie', 'getEncryptedCookie',
        'etag', 'lastModified', 'expires', 'pass', 'environment', 'router', 'getLog', 'root',
        'setName', 'getName',
    ];

    /** Slim 1 classes the bridge does not define. Naming one is a stop sign. */
    private const SLIM_CLASSES = '/\b(Slim::getInstance|Slim_Middleware\w*|Slim_Environment|Slim_Log\w*|Slim_Http_Util|Slim_Http_Headers|Slim_Router|Slim_Exception_Pass)\b/';

    /** A one-line require or include statement, keyword first. */
    private const REQUIRE_LINE = '/^\s*(?:require_once|require|include_once|include)\b[^;]*;\s*(?:(?:\/\/|#).*)?$/';

    /** A one-line Setup::registerAutoloadDirectory(...) call, the 2012 Doctrine bootstrap. */
    private const REGISTER_AUTOLOAD_LINE = '/^\s*\\\\?(?:Doctrine\\\\ORM\\\\Tools\\\\)?Setup::registerAutoloadDirectory\s*\(.*\)\s*;\s*(?:(?:\/\/|#).*)?$/';

    /** A one-line define('SMARTY_DIR', ...), which would point Smarty 4 at the removed copy. */
    private const SMARTY_DIR_LINE = '/^\s*define\s*\(\s*([\'"])SMARTY_DIR\1\s*,.*\)\s*;\s*(?:(?:\/\/|#).*)?$/';

    public function __construct(private readonly ?string $bridgeSource = null) {}

    /** The lowest PHP the recipe for this hand copy needs, or null when Booster has no recipe. */
    public static function floorFor(VendoredLibrary $library): ?string
    {
        return self::RECIPES[self::key($library)]['floor'] ?? null;
    }

    /** Read-only: what the hop would move, leave alone or refuse. */
    public function inspect(string $appPath): VendoredInspection
    {
        return $this->work(rtrim($appPath, '/\\'))['inspection'];
    }

    /**
     * Moves the hand-copied libraries to Composer. $php is the PHP the hop
     * lands on; a new composer.json asks for it the way the PHP hops do.
     * A dry run or a refusal writes nothing.
     */
    public function migrate(string $appPath, bool $dryRun, string $php): VendoredMigrationResult
    {
        $appPath = rtrim($appPath, '/\\');
        $work = $this->work($appPath);
        $inspection = $work['inspection'];
        if ($inspection->moves === []) {
            return new VendoredMigrationResult([], [], [], ['Found no hand-copied library under vendor/ that Booster knows how to move.']);
        }
        if ($inspection->refusals !== []) {
            return new VendoredMigrationResult([], [], [], $inspection->refusals);
        }

        $would = $dryRun ? 'Dry run: would ' : '';
        $changes = [];
        $files = [];

        $slim = false;
        foreach ($inspection->moves as $library) {
            $recipe = self::RECIPES[self::key($library)];
            $slim = $slim || self::key($library) === 'slim';
            $count = self::countFiles($appPath . '/' . $library->path);
            $changes[] = sprintf(
                '%s%s the hand-copied %s%s in %s/ (%d files) with %s from Composer.',
                $would,
                $dryRun ? 'replace' : 'Replaced',
                $library->name,
                $library->version !== null ? ' ' . $library->version : '',
                $library->path,
                $count,
                $recipe['release'],
            );
            $files[] = ['file' => $library->path . '/', 'diff' => sprintf('removed: hand-copied %s (%d files)', $library->name, $count)];
        }

        [$composerJson, $composerChanges, $packages] = $this->composerJson($appPath, $inspection, $php, $slim);
        foreach ($composerChanges as $index => $line) {
            // The heading carries the dry-run words; the indented lines under
            // it are its list. Prefixing every line gave "would wrote
            // composer.json" and "would   require php ^7.4".
            $changes[] = match (true) {
                !$dryRun || $index > 0 => $line,
                str_starts_with($line, 'Wrote ') => $would . 'write ' . substr($line, strlen('Wrote ')),
                str_starts_with($line, 'Edited ') => $would . 'edit ' . substr($line, strlen('Edited ')),
                default => $would . lcfirst($line),
            };
        }
        $files[] = ['file' => 'composer.json', 'diff' => implode("\n", $composerChanges)];

        foreach ($work['edits'] as $file => $edit) {
            $changes[] = sprintf('%s%s %s: %s.', $would, $dryRun ? 'edit' : 'Edited', $file, implode('; ', $edit['what']));
            $files[] = ['file' => $file, 'diff' => implode("\n", $edit['diff'])];
        }
        if ($slim) {
            $changes[] = sprintf('%s%s %s, the Slim 1 bridge onto Slim 4, so the routes keep running unchanged.', $would, $dryRun ? 'write' : 'Wrote', self::BRIDGE_FILE);
            $files[] = ['file' => self::BRIDGE_FILE, 'diff' => 'new: the Slim 1 bridge'];
        }

        if (!$dryRun) {
            foreach ($inspection->moves as $library) {
                self::removeTree($appPath . '/' . $library->path);
            }
            $vendor = $appPath . '/vendor';
            if (is_dir($vendor) && (scandir($vendor) ?: []) === ['.', '..']) {
                rmdir($vendor);
            }
            self::write($appPath . '/composer.json', $composerJson);
            foreach ($work['edits'] as $file => $edit) {
                self::write($appPath . '/' . $file, $edit['content']);
            }
            if ($slim) {
                self::write($appPath . '/' . self::BRIDGE_FILE, $this->bridge());
            }
        }

        return new VendoredMigrationResult($changes, $files, $this->manual($inspection, $slim), [], $packages);
    }

    /**
     * Reads the app once: the libraries, the stop signs and, for each PHP
     * file, the rewritten content. Nothing is written here.
     *
     * @return array{inspection: VendoredInspection, edits: array<string, array{content: string, what: list<string>, diff: list<string>}>}
     */
    private function work(string $appPath): array
    {
        $moves = [];
        $leftAlone = [];
        $refusals = [];
        foreach (VendoredLibraries::find($appPath) as $library) {
            $key = self::key($library);
            $recipe = self::RECIPES[$key] ?? null;
            if ($recipe === null) {
                $leftAlone[] = $library;
                continue;
            }
            if (dirname($library->path) !== 'vendor') {
                // composer.json goes at the app root, so its vendor/ is the
                // only one the paths in the app's requires can be rewritten to.
                $leftAlone[] = $library;
                continue;
            }
            if ($library->version === null || explode('.', $library->version)[0] !== $recipe['major']) {
                $refusals[] = sprintf(
                    '%s/ holds %s %s; the recipe moves %s %s.x only.',
                    $library->path,
                    $library->name,
                    $library->version ?? 'of an unknown version',
                    $library->name,
                    $recipe['major'],
                );
                continue;
            }
            $moves[] = $library;
        }
        if ($moves === []) {
            return ['inspection' => new VendoredInspection([], $leftAlone, $refusals, null), 'edits' => []];
        }

        $moved = [];
        $floor = '5.5';
        foreach ($moves as $library) {
            $moved[self::key($library)] = basename($library->path);
            if (version_compare(self::RECIPES[self::key($library)]['floor'], $floor, '>')) {
                $floor = self::RECIPES[self::key($library)]['floor'];
            }
        }

        $composer = $appPath . '/composer.json';
        if (is_file($composer) && !is_array(json_decode((string) file_get_contents($composer), true))) {
            $refusals[] = 'composer.json is not valid JSON, so Booster cannot add the packages to it.';
        }
        if (isset($moved['slim']) && file_exists($appPath . '/' . self::BRIDGE_FILE)) {
            $refusals[] = sprintf('%s already exists; Booster will not overwrite it with the Slim 1 bridge.', self::BRIDGE_FILE);
        }

        // Smarty's compiled templates are a cache of the old copy: they name
        // SMARTY_DIR and the copy's plugin paths on every page, and Smarty
        // rebuilds them. A real app's tmp/template_compile/ raised a stop
        // sign that never said it was looking at a cache, so they stay out
        // of the rewrite and get a MANUAL line instead.
        $sources = [];
        $compiled = [];
        foreach (self::phpFiles($appPath) as $file) {
            $source = (string) file_get_contents($appPath . '/' . $file);
            if (preg_match(self::COMPILED_TEMPLATE, substr($source, 0, 2048)) === 1) {
                $compiled[dirname($file)] = ($compiled[dirname($file)] ?? 0) + 1;
                continue;
            }
            $sources[$file] = $source;
        }
        ksort($compiled);
        $slimVars = isset($moved['slim']) ? self::slimVariables($sources) : [];

        $edits = [];
        foreach ($sources as $file => $source) {
            [$edit, $stops] = $this->rewrite($file, $source, $moved, $slimVars);
            $refusals = [...$refusals, ...$stops];
            if ($edit !== null) {
                $edits[$file] = $edit;
            }
        }

        return ['inspection' => new VendoredInspection($moves, $leftAlone, $refusals, $floor, $compiled), 'edits' => $edits];
    }

    /**
     * Rewrites one file: the first require of a moved copy loads Composer's
     * autoloader, later ones go, and the Doctrine and Smarty bootstrap lines
     * that pointed at the copies go. Returns the edit (null when nothing
     * changed) and the stop signs found in the file.
     *
     * @param array<string, string> $moved    recipe key to the copy's folder name under vendor/
     * @param list<string>          $slimVars variables that hold the Slim app
     *
     * @return array{0: array{content: string, what: list<string>, diff: list<string>}|null, 1: list<string>}
     */
    private function rewrite(string $file, string $source, array $moved, array $slimVars): array
    {
        $stops = [];
        $lines = preg_split('/(?<=\n)/', $source) ?: [];
        $autoloaded = str_contains($source, 'vendor/autoload.php');
        $out = [];
        $what = [];
        $diff = [];
        foreach ($lines as $index => $raw) {
            $number = $index + 1;
            $eol = str_ends_with($raw, "\r\n") ? "\r\n" : (str_ends_with($raw, "\n") ? "\n" : '');
            $line = substr($raw, 0, strlen($raw) - strlen($eol));

            foreach (self::stopSigns($line, $source, $moved, $slimVars) as $reason) {
                $stops[] = sprintf('%s:%d %s', $file, $number, $reason);
            }

            $copy = self::copyReference($line, $moved);
            if (isset($moved['doctrine']) && preg_match(self::REGISTER_AUTOLOAD_LINE, $line) === 1) {
                $what[] = sprintf('dropped the Setup::registerAutoloadDirectory() call (line %d), Composer loads Doctrine now', $number);
                $diff[] = '- ' . trim($line);
                continue;
            }
            if (isset($moved['doctrine']) && str_contains($line, 'registerAutoloadDirectory')) {
                $stops[] = sprintf('%s:%d calls registerAutoloadDirectory in a shape Booster does not rewrite: %s', $file, $number, trim($line));
            }
            if (isset($moved['smarty']) && preg_match(self::SMARTY_DIR_LINE, $line) === 1) {
                $what[] = sprintf('dropped define(\'SMARTY_DIR\') (line %d), Smarty 4 sets its own', $number);
                $diff[] = '- ' . trim($line);
                continue;
            }
            if (isset($moved['smarty']) && $autoloaded && preg_match(self::REQUIRE_LINE, $line) === 1 && preg_match('/\bSMARTY_DIR\s*\.\s*[\'"]Smarty\.class\.php[\'"]/', $line) === 1) {
                // require SMARTY_DIR . 'Smarty.class.php', after a line above
                // already loads Composer's autoloader.
                $what[] = sprintf('dropped the require of Smarty through SMARTY_DIR (line %d), Composer\'s autoloader loads it', $number);
                $diff[] = '- ' . trim($line);
                continue;
            }
            if (isset($moved['smarty']) && str_contains($line, 'SMARTY_DIR')) {
                $stops[] = sprintf('%s:%d uses SMARTY_DIR, which pointed at the hand copy: %s', $file, $number, trim($line))
                    . (preg_match(self::CACHE_FOLDER, '/' . $file) === 1 ? ' (the file sits in a cache folder: if it is a compiled template, delete the cache, Smarty rebuilds it, and plan again)' : '');
            }
            if ($copy !== null) {
                if (preg_match(self::REQUIRE_LINE, $line) !== 1) {
                    $stops[] = sprintf('%s:%d reaches into the hand-copied vendor/%s in a way Booster does not rewrite: %s', $file, $number, $copy, trim($line));
                    $out[] = $raw;
                    continue;
                }
                if ($autoloaded) {
                    $what[] = sprintf('dropped the require of vendor/%s (line %d), Composer\'s autoloader loads it', $copy, $number);
                    $diff[] = '- ' . trim($line);
                    continue;
                }
                $new = (string) preg_replace('/^(\s*)(?:require_once|require|include_once|include)\b/', '$1require_once', $line, 1);
                $new = (string) preg_replace('/([\'"])([^\'"]*?)vendor\/' . preg_quote($copy, '/') . '\/[^\'"]*\1/', '$1$2vendor/autoload.php$1', $new, 1);
                $autoloaded = true;
                $what[] = sprintf('line %d loads vendor/autoload.php instead of the hand-copied vendor/%s', $number, $copy);
                $diff[] = '- ' . trim($line);
                $diff[] = '+ ' . trim($new);
                $out[] = $new . $eol;
                continue;
            }
            $out[] = $raw;
        }

        if ($what === []) {
            return [null, $stops];
        }

        return [['content' => implode('', $out), 'what' => $what, 'diff' => $diff], $stops];
    }

    /**
     * The moved copy's folder name when $line names a path inside it
     * (vendor/Smarty/...), or null. Case-sensitive, so Composer's own
     * lowercase vendor/smarty/smarty paths are never mistaken for the copy.
     *
     * @param array<string, string> $moved
     */
    private static function copyReference(string $line, array $moved): ?string
    {
        foreach ($moved as $folder) {
            if (preg_match('/vendor\/' . preg_quote($folder, '/') . '(?:\/|[\'"])/', $line) === 1) {
                return $folder;
            }
        }

        return null;
    }

    /**
     * Stop signs on one line: Doctrine's own class loader, and the Slim 1
     * API the bridge does not carry.
     *
     * @param array<string, string> $moved
     * @param list<string>          $slimVars
     *
     * @return list<string>
     */
    private static function stopSigns(string $line, string $source, array $moved, array $slimVars): array
    {
        $stops = [];
        $code = trim($line);
        if (str_starts_with($code, '//') || str_starts_with($code, '#') || str_starts_with($code, '*') || str_starts_with($code, '/*')) {
            return [];
        }
        if (isset($moved['doctrine']) && preg_match('/\bClassLoader\b/', $line) === 1 && str_contains($source, 'Doctrine\\Common\\ClassLoader')) {
            $stops[] = 'uses Doctrine\'s ClassLoader, which Doctrine 2.20 no longer ships; move the app\'s own classes to Composer\'s autoload first: ' . $code;
        }
        if (!isset($moved['slim'])) {
            return $stops;
        }
        if (preg_match(self::SLIM_CLASSES, $line, $m) === 1) {
            $stops[] = sprintf('uses %s, which the Slim 1 bridge does not carry: %s', $m[1], $code);
        }
        foreach ($slimVars as $var) {
            $v = preg_quote($var, '/');
            if (preg_match('/\$' . $v . '->(' . implode('|', self::SLIM_UNSUPPORTED) . ')\s*\(/', $line, $m) === 1) {
                $stops[] = sprintf('calls $%s->%s(), which the Slim 1 bridge does not carry: %s', $var, $m[1], $code);
            }
            if (preg_match('/\$' . $v . '->response\(\)->(setCookie|deleteCookie|getCookie|setEncryptedCookie|getEncryptedCookie)\s*\(/', $line, $m) === 1) {
                $stops[] = sprintf('calls %s() on the Slim response, which the Slim 1 bridge does not carry: %s', $m[1], $code);
            }
            if (preg_match('/\$' . $v . '->(?:get|post|put|delete|options|map)\s*\(\s*([\'"])(.*?)\1/', $line, $m) === 1 && preg_match('/[()\[\]{}?*+|^$\\\\]/', $m[2]) === 1) {
                $stops[] = sprintf('has the route pattern "%s", whose regular-expression characters Slim 4\'s router reads differently: %s', $m[2], $code);
            }
        }

        return $stops;
    }

    /**
     * The variables the app keeps its Slim app in ($app = new Slim(...)),
     * "app" when no file says.
     *
     * @param array<string, string> $sources
     *
     * @return list<string>
     */
    private static function slimVariables(array $sources): array
    {
        $vars = [];
        foreach ($sources as $source) {
            if (preg_match_all('/\$(\w+)\s*=\s*new\s+\\\\?Slim\s*\(/', $source, $m) > 0) {
                $vars = [...$vars, ...$m[1]];
            }
        }

        return $vars === [] ? ['app'] : array_values(array_unique($vars));
    }

    /**
     * The new or edited composer.json, the lines describing the change and
     * the packages it added.
     *
     * @return array{0: string, 1: list<string>, 2: list<string>}
     */
    private function composerJson(string $appPath, VendoredInspection $inspection, string $php, bool $slim): array
    {
        $file = $appPath . '/composer.json';
        $existed = is_file($file);
        $data = $existed ? json_decode((string) file_get_contents($file), true) : [];
        if (!is_array($data)) {
            throw new ToolError('composer.json is not valid JSON.');
        }
        $require = is_array($data['require'] ?? null) ? $data['require'] : [];
        $lines = [$existed ? 'Edited composer.json:' : 'Wrote composer.json:'];
        $added = [];
        if (!isset($require['php'])) {
            $require['php'] = '^' . $php;
            $lines[] = sprintf('  require php ^%s', $php);
        }
        foreach ($inspection->moves as $library) {
            foreach (self::RECIPES[self::key($library)]['packages'] as $package => $constraint) {
                if (isset($require[$package])) {
                    $lines[] = sprintf('  kept %s %s (already required)', $package, (string) $require[$package]);
                    continue;
                }
                $require[$package] = $constraint;
                $added[] = $package;
                $lines[] = sprintf('  require %s %s', $package, $constraint);
            }
        }
        $data['require'] = self::sortRequire($require);
        if ($slim) {
            $autoload = is_array($data['autoload'] ?? null) ? $data['autoload'] : [];
            $classmap = is_array($autoload['classmap'] ?? null) ? $autoload['classmap'] : [];
            if (!in_array(self::BRIDGE_FILE, $classmap, true)) {
                $classmap[] = self::BRIDGE_FILE;
                $lines[] = sprintf('  autoload.classmap %s', self::BRIDGE_FILE);
            }
            $autoload['classmap'] = $classmap;
            $data['autoload'] = $autoload;
        }

        return [json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n", $lines, $added];
    }

    /**
     * php first, then ext-* platform packages, then the rest by name, the
     * order Composer's own sort-packages keeps.
     *
     * @param array<mixed> $require
     *
     * @return array<mixed>
     */
    private static function sortRequire(array $require): array
    {
        uksort($require, static function (int|string $a, int|string $b): int {
            $rank = static fn(string $name): int => $name === 'php' ? 0 : (str_starts_with($name, 'ext-') ? 1 : 2);

            return [$rank((string) $a), (string) $a] <=> [$rank((string) $b), (string) $b];
        });

        return $require;
    }

    /**
     * What a person does next, as MANUAL lines.
     *
     * @return list<string>
     */
    private function manual(VendoredInspection $inspection, bool $slim): array
    {
        $manual = [];
        foreach ($inspection->leftAlone as $library) {
            $manual[] = sprintf(
                'MANUAL: %s/ (%s%s) stays where it is: %s. It still loads the way it always did.',
                $library->path,
                $library->name,
                $library->version !== null ? ' ' . $library->version : '',
                dirname($library->path) !== 'vendor' ? 'Booster moves only the app root\'s vendor/' : 'Booster has no recipe for it yet',
            );
        }
        $keys = array_map(static fn(VendoredLibrary $l): string => self::key($l), $inspection->moves);
        if (in_array('doctrine', $keys, true)) {
            $manual[] = 'MANUAL: Doctrine ORM 2.20 is stricter than a 2012 copy in two places a real app\'s replay found: DISTINCT(alias.field) in DQL now reads as an expression (name the field instead), and an entity whose id holds a date needs a custom column type. Fix what the replay shows.';
            $manual[] = 'MANUAL: ORM 2.20 is the last line that reads docblock annotations, and doctrine/annotations 1.x is abandoned. Moving the entities to attributes on ORM 3 (PHP 8.1 or newer) is the next step, by hand for now.';
        }
        foreach ($inspection->compiledTemplates as $folder => $count) {
            $manual[] = sprintf(
                'MANUAL: %s/ holds %d of Smarty\'s compiled templates, a cache the hand copy wrote; Booster left %s alone. Delete the compiled files (Smarty rebuilds them on the next request) and keep the folder out of git.',
                $folder,
                $count,
                $count === 1 ? 'it' : 'them',
            );
        }
        if (in_array('smarty', $keys, true)) {
            $manual[] = 'MANUAL: Smarty 4 keeps the Smarty 3 API. Since 4.3 it logs a deprecation when a template uses a PHP function as a modifier without registering it ({$x|json_encode}); register those with registerPlugin() when the log names them.';
        }
        if ($slim) {
            $manual[] = sprintf('MANUAL: The routes run on Slim 4 through %s, which is the app\'s code now: move routes to plain Slim 4 one at a time when you like, and delete the bridge when nothing calls new Slim(). In development mode a PHP notice no longer becomes an error page, on purpose.', self::BRIDGE_FILE);
        }
        $manual[] = 'MANUAL: Commit composer.json and composer.lock. Citadel never runs composer install, so commit vendor/ on the deploy branch or add a deployments/ script that runs composer install --no-dev.';
        $manual[] = 'MANUAL: Prove the move with booster run --replay: the test gate checks the code still parses, and only the replay shows the pages still answer the same.';

        return $manual;
    }

    private function bridge(): string
    {
        $path = $this->bridgeSource ?? PharFiles::path('resources/slim1/slim1-bridge.php');
        $source = @file_get_contents($path);
        if ($source === false) {
            throw new ToolError(sprintf('Could not read the Slim 1 bridge at %s.', $path));
        }

        return $source;
    }

    private static function key(VendoredLibrary $library): string
    {
        return strtolower(basename($library->path));
    }

    /**
     * Every PHP source file of the app, relative and sorted, outside any
     * vendor/, node_modules/ or .git/ folder.
     *
     * @return list<string>
     */
    private static function phpFiles(string $appPath): array
    {
        $found = [];
        $stack = [$appPath];
        while (($dir = array_pop($stack)) !== null) {
            $handle = @opendir($dir);
            if ($handle === false) {
                continue;
            }
            while (($entry = readdir($handle)) !== false) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $full = $dir . '/' . $entry;
                if (is_link($full)) {
                    continue;
                }
                if (is_dir($full)) {
                    if (!in_array($entry, ['vendor', 'node_modules', '.git'], true)) {
                        $stack[] = $full;
                    }
                    continue;
                }
                if (preg_match('/\.(php|inc|phtml)$/i', $entry) === 1) {
                    $found[] = substr($full, strlen($appPath) + 1);
                }
            }
            closedir($handle);
        }
        sort($found);

        return $found;
    }

    private static function countFiles(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if ($item instanceof \SplFileInfo && $item->isFile()) {
                $count++;
            }
        }

        return $count;
    }

    private static function removeTree(string $dir): void
    {
        if (is_link($dir) || is_file($dir)) {
            unlink($dir);

            return;
        }
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($dir . '/' . $entry);
            }
        }
        rmdir($dir);
    }

    private static function write(string $file, string $content): void
    {
        if (file_put_contents($file, $content) === false) {
            throw new ToolError(sprintf('Could not write "%s".', $file));
        }
    }
}
