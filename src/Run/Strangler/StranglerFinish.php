<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run\Strangler;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Waynestate\Booster\Replay\ReplayFile;
use Waynestate\Booster\Strangler\Ledger;
use Waynestate\Booster\ToolError;

/**
 * The finish step's file work (design section 4, "Finish"), once no unit is
 * left in legacy: legacy/ goes, and with it the route table, the install
 * hook and the dispatcher, which gives back Laravel's stock front
 * controller. The legacy docroot's static files (robots.txt, images, css)
 * move into public/ first, since the dispatcher served them until now. The sign-in move (/login to Laravel) is SIGNIN's part and the
 * ledger goes only after the gate passes; both are the caller's.
 */
final class StranglerFinish
{
    /** The route table the dispatcher reads (MOUNT's file). */
    public const ROUTE_TABLE = 'bootstrap/booster-moved.php';

    /** The app-owned hook the mount adds so one composer install installs both roots. */
    public const INSTALL_HOOK = 'bin/install-legacy.php';

    /** Laravel 13's stock public/index.php, the file Sourdough's laravel-blade shape ships. */
    public const STOCK_FRONT_CONTROLLER = <<<'PHP'
        <?php

        use Illuminate\Foundation\Application;
        use Illuminate\Http\Request;

        define('LARAVEL_START', microtime(true));

        // Determine if the application is in maintenance mode...
        if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
            require $maintenance;
        }

        // Register the Composer autoloader...
        require __DIR__.'/../vendor/autoload.php';

        // Bootstrap Laravel and handle the request...
        /** @var Application $app */
        $app = require_once __DIR__.'/../bootstrap/app.php';

        $app->handleRequest(Request::capture());

        PHP;

    /**
     * Does the file work and returns the app-relative paths it changed or
     * deleted, plus the lines for the report.
     *
     * @return array{files: list<string>, lines: list<string>, manual: list<string>}
     */
    public function run(string $appRoot): array
    {
        $root = rtrim($appRoot, '/\\');
        $files = [];
        $lines = [];
        $manual = [];
        if (is_dir($root . '/legacy')) {
            [$copied, $clashes, $docroot] = self::keepStaticFiles($root);
            if ($copied !== []) {
                $files = [...$files, ...array_map(static fn(string $f): string => 'public/' . $f, $copied)];
                $lines[] = sprintf('Copied %d static file%s from %s into public/ (%s), where the dispatcher served them from.', count($copied), count($copied) === 1 ? '' : 's', $docroot, implode(', ', array_slice($copied, 0, 8)) . (count($copied) > 8 ? ', ...' : ''));
            }
            foreach ($clashes as $clash) {
                $manual[] = sprintf('MANUAL: public/%s and %s/%s differ; the dispatcher served the legacy one, public/ keeps its own. Compare them before you deploy.', $clash, $docroot, $clash);
            }
            $count = self::deleteTree($root . '/legacy');
            $files[] = 'legacy/';
            $lines[] = sprintf('Deleted legacy/ (%d file%s): no unit answers from it any more.', $count, $count === 1 ? '' : 's');
        }
        foreach ([self::ROUTE_TABLE, self::INSTALL_HOOK] as $relative) {
            if (is_file($root . '/' . $relative)) {
                if (!unlink($root . '/' . $relative)) {
                    throw new ToolError(sprintf('Could not delete %s.', $relative));
                }
                $files[] = $relative;
                $lines[] = sprintf('Deleted %s.', $relative);
            }
        }
        $index = $root . '/public/index.php';
        if (!is_file($index) || file_get_contents($index) !== self::STOCK_FRONT_CONTROLLER) {
            if (!is_dir($root . '/public')) {
                mkdir($root . '/public', 0775, true);
            }
            file_put_contents($index, self::STOCK_FRONT_CONTROLLER);
            $files[] = 'public/index.php';
            $lines[] = 'public/index.php is Laravel\'s stock front controller again (the dispatcher is gone), so Sourdough feed manages it again.';
        }
        if ($this->dropInstallHook($root . '/composer.json')) {
            $files[] = 'composer.json';
            $lines[] = 'composer.json: dropped the post-install-cmd entry that installed legacy/.';
        }
        $serve = $this->dropLegacySetup($root . '/' . ReplayFile::DEFAULT_NAME);
        if ($serve !== null) {
            $files[] = ReplayFile::DEFAULT_NAME;
            $lines[] = $serve;
        }
        $manual[] = 'MANUAL: Citadel config files that moved into legacy/ at the mount (legacy/config/app_local.php and the like) go with it: point the dashboard\'s config file paths at the Laravel app\'s files.';
        $manual[] = 'MANUAL: run PROCTOR and `sourdough feed` on the plain Laravel app, and drop any legacy-only lines from .env.example.';

        return ['files' => $files, 'lines' => $lines, 'manual' => $manual];
    }

    /**
     * Copies every file the legacy docroot holds that is not a script (and
     * not its .htaccess, whose rules the mount already moved) into public/,
     * where public/ has no such file. Reads the docroot from the dispatcher's
     * marked line. Returns the copied paths, the paths public/ already has
     * with other content, and the docroot.
     *
     * @return array{list<string>, list<string>, string}
     */
    private static function keepStaticFiles(string $root): array
    {
        $index = $root . '/public/index.php';
        $code = is_file($index) ? (string) file_get_contents($index) : '';
        if (preg_match('/\$legacyDocroot = \'([^\']+)\'; \/\/ booster:legacy-docroot/', $code, $m) !== 1) {
            return [[], [], ''];
        }
        $docroot = trim($m[1], '/');
        $dir = $root . '/' . $docroot;
        if ((!str_starts_with($docroot, 'legacy/') && $docroot !== 'legacy') || !is_dir($dir)) {
            return [[], [], $docroot];
        }
        $copied = [];
        $clashes = [];
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($items as $item) {
            if (!$item instanceof SplFileInfo || !$item->isFile()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($dir) + 1));
            if (preg_match('/\.(php[0-9s]?|phtml|phar)$/i', $relative) === 1 || basename($relative) === '.htaccess') {
                continue;
            }
            $target = $root . '/public/' . $relative;
            if (is_file($target)) {
                if (file_get_contents($target) !== file_get_contents($item->getPathname())) {
                    $clashes[] = $relative;
                }

                continue;
            }
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0775, true);
            }
            copy($item->getPathname(), $target);
            $copied[] = $relative;
        }
        sort($copied);
        sort($clashes);

        return [$copied, $clashes, $docroot];
    }

    /** Deletes a folder and everything in it; returns the number of files deleted. */
    public static function deleteTree(string $dir): int
    {
        $count = 0;
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
                $count++;
            }
        }
        rmdir($dir);

        return $count;
    }

    /** Drops every post-install-cmd entry that names legacy/ or the install hook; true when composer.json changed. */
    private function dropInstallHook(string $file): bool
    {
        if (!is_file($file)) {
            return false;
        }
        $raw = (string) file_get_contents($file);
        $data = json_decode($raw, true);
        if (!is_array($data) || !is_array($data['scripts'] ?? null) || !array_key_exists('post-install-cmd', $data['scripts'])) {
            return false;
        }
        $entries = (array) $data['scripts']['post-install-cmd'];
        $kept = array_values(array_filter($entries, static fn(mixed $entry): bool => !is_string($entry) || (!str_contains($entry, 'install-legacy') && !str_contains($entry, 'legacy/') && !str_contains($entry, '-d legacy'))));
        if (count($kept) === count($entries)) {
            return false;
        }
        if ($kept === []) {
            unset($data['scripts']['post-install-cmd']);
        } else {
            $data['scripts']['post-install-cmd'] = $kept;
        }
        $indent = preg_match('/^( +)"/m', $raw, $m) === 1 ? strlen($m[1]) : 4;
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if ($indent !== 4) {
            $json = (string) preg_replace_callback('/^( +)/m', static fn(array $mm): string => str_repeat(' ', intdiv(strlen($mm[1]), 4) * $indent), $json);
        }
        file_put_contents($file, $json . "\n");

        return true;
    }

    /**
     * The mount set the replay's serve.setup to run in legacy/ as well; with
     * legacy/ gone, the commands that name it are dropped. Returns the line
     * for the report, or null when nothing named legacy.
     */
    private function dropLegacySetup(string $file): ?string
    {
        if (!is_file($file)) {
            return null;
        }
        $raw = (string) file_get_contents($file);
        $data = json_decode($raw, true);
        $setup = is_array($data) && is_array($data['serve'] ?? null) ? ($data['serve']['setup'] ?? null) : null;
        if (!is_string($setup) || !str_contains($setup, 'legacy')) {
            return null;
        }
        // The mount appends "&& (cd legacy && ...)": that whole group goes first,
        // so no command is split out of it and no bracket is left behind.
        $setup = (string) preg_replace('/\s*(?:&&|;)?\s*\(\s*cd\s+\.?\/?legacy\/?\s*(?:&&|;)[^()]*\)/', '', $setup);
        $parts = preg_split('/\s*(?:&&|;)\s*/', $setup) ?: [];
        $kept = array_values(array_filter($parts, static fn(string $part): bool => $part !== '' && !str_contains($part, 'legacy')));
        if ($kept === []) {
            unset($data['serve']['setup']);
        } else {
            $data['serve']['setup'] = implode(' && ', $kept);
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (preg_match('/^( +)"/m', $raw, $m) === 1 && strlen($m[1]) === 2) {
            $json = (string) preg_replace_callback('/^( +)/m', static fn(array $mm): string => str_repeat(' ', intdiv(strlen($mm[1]), 2)), $json);
        }
        file_put_contents($file, $json . "\n");

        return sprintf('%s: serve.setup no longer runs anything in legacy/ (%s).', ReplayFile::DEFAULT_NAME, $kept === [] ? 'no setup left' : implode(' && ', $kept));
    }

    /** The ledger goes once the finish gate is green: the app is a plain Laravel app, and the planner plans it as one. */
    public static function dropLedger(string $appRoot): void
    {
        $file = Ledger::path($appRoot);
        if (is_file($file)) {
            unlink($file);
        }
    }
}
