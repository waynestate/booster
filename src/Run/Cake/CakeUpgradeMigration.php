<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run\Cake;

use Waynestate\Booster\Hop\Hop;
use Waynestate\Booster\Packaging\RunScratch;
use Waynestate\Booster\Run\CakeUpgradeRunner;
use Waynestate\Booster\Run\ComposerEditor;
use Waynestate\Booster\Run\FrameworkEngine;
use Waynestate\Booster\Run\ProcessResult;
use Waynestate\Booster\Run\VendoredMigrationResult;
use Waynestate\Booster\ToolError;

/**
 * The CakeUpgrade engine (EngineId::CakeUpgrade): the CakePHP hops. It runs
 * CakePHP's own upgrade rules (cakephp/upgrade, through CakeUpgradeRunner's
 * container) over a copy of the app, so a rule set that fails halfway leaves
 * the app exactly as it was. Then it copies the changed files back, applies
 * the hop's Composer pins, and lists what no rule does (AuthComponent,
 * RequestHandler, the mutable date classes and the like) as MANUAL lines.
 *
 * Everything it does comes from the hop it is handed: the rule sets, in
 * order, from Hop::$rectorSets, and the pins from composerSet and
 * composerSetIfPresent. The template and locale renames run when the sets
 * include cakephp50, the CakePHP 5 set; the hop's name is never read.
 * (Measure twice, cut once: the copy is the measuring.)
 */
final class CakeUpgradeMigration implements FrameworkEngine
{
    /** The set that marks a hop onto CakePHP 5. */
    private const FIVE_SET = 'cakephp50';

    /** The app folders the rule sets run on, in this order, when the copy has them. */
    private const SUBDIRS = ['src', 'tests', 'config', 'plugins'];

    /** Never copied, at any depth: installed code and history. */
    private const SKIP_ANYWHERE = ['vendor', 'node_modules', '.git'];

    /** Never copied at the app root: CakePHP's runtime folders. */
    private const SKIP_AT_ROOT = ['tmp', 'logs'];

    /** How much of a failed tool run the refusal quotes. */
    private const TAIL_LINES = 15;

    public function __construct(
        private readonly CakeUpgradeRunner $runner,
        private readonly ComposerEditor $composer,
    ) {}

    public function migrate(string $appPath, bool $dryRun, string $php, ?Hop $hop = null): VendoredMigrationResult
    {
        $appPath = rtrim($appPath, '/\\');
        if ($hop === null) {
            return self::refuse('The CakePHP upgrade engine runs only for a CakePHP hop, and none was given.');
        }
        $refusals = $this->refusals($appPath, $hop);
        if ($refusals !== []) {
            return new VendoredMigrationResult([], [], [], $refusals);
        }

        $copy = RunScratch::dir('cake-upgrade/' . bin2hex(random_bytes(6)));
        try {
            return $this->work($appPath, $copy, $dryRun, $hop);
        } finally {
            self::removeTree($copy);
        }
    }

    /**
     * @return list<string>
     */
    private function refusals(string $appPath, Hop $hop): array
    {
        $refusals = [];
        if (!is_file($appPath . '/composer.json')) {
            $refusals[] = 'There is no composer.json, so this is not a Composer-managed CakePHP app that cakephp/upgrade can work on.';
        } elseif (!self::requiresCake($appPath)) {
            $refusals[] = 'Neither composer.lock nor composer.json requires cakephp/cakephp, so this is not a CakePHP app.';
        }
        if (!is_dir($appPath . '/src')) {
            $refusals[] = 'There is no src/ directory, so the app does not have the CakePHP layout the upgrade rules expect.';
        }
        if ($hop->rectorSets === []) {
            $refusals[] = sprintf('The %s hop names no cakephp/upgrade rule sets, so there is nothing to run.', $hop->id);
        }

        return $refusals;
    }

    private function work(string $appPath, string $copy, bool $dryRun, Hop $hop): VendoredMigrationResult
    {
        self::copyTree($appPath, $copy);
        $five = in_array(self::FIVE_SET, $hop->rectorSets, true);
        $changes = [];

        try {
            $this->runner->ensureImage();
        } catch (ToolError $e) {
            return self::refuse(strtok($e->getMessage(), "\n") ?: $e->getMessage());
        }

        $subdirs = array_values(array_filter(self::SUBDIRS, static fn(string $dir): bool => is_dir($copy . '/' . $dir)));
        foreach ($hop->rectorSets as $set) {
            foreach ($subdirs as $subdir) {
                $result = $this->runner->run($this->runner->rectorCommand($copy, $set, $subdir));
                if (!$result->successful()) {
                    return self::refuse(self::failure(sprintf('The cakephp/upgrade rule set %s failed on %s/', $set, $subdir), $result));
                }
            }
            $changes[] = sprintf('Ran %s on %s.', $set, implode(', ', $subdirs));
        }

        if ($five) {
            $before = count(self::filesWithExtension($copy, 'ctp'));
            foreach (['templates', 'locales'] as $what) {
                $result = $this->runner->run($this->runner->fileRenameCommand($copy, $what));
                if (!$result->successful()) {
                    return self::refuse(self::failure(sprintf('The cakephp/upgrade %s rename failed', $what), $result));
                }
            }
            $renamed = $before - count(self::filesWithExtension($copy, 'ctp'));
            if ($renamed > 0) {
                $changes[] = sprintf('Renamed %d .ctp %s to .php.', $renamed, $renamed === 1 ? 'template' : 'templates');
            }
        }

        [$files, $written, $deleted] = self::compare($appPath, $copy);
        if ($files === []) {
            $changes[] = 'The upgrade rules changed no files.';
        }
        $manual = $this->manual($appPath, $copy, $five);

        if (!$dryRun) {
            foreach ($written as $file) {
                self::write($appPath . '/' . $file, self::read($copy . '/' . $file));
            }
            foreach ($deleted as $file) {
                @unlink($appPath . '/' . $file);
                // A folder the rules emptied (src/Template after the move to
                // templates/) goes too; a folder with anything left stays.
                for ($dir = dirname($file); $dir !== '.' && $dir !== ''; $dir = dirname($dir)) {
                    if ((@scandir($appPath . '/' . $dir) ?: ['x']) !== ['.', '..'] || !@rmdir($appPath . '/' . $dir)) {
                        break;
                    }
                }
            }
        }

        // The pins go on the app itself in a real run (the copy is gone a
        // moment later) and on the copy in a dry run, so the preview shows
        // the same lines and the same composer.json diff.
        $target = $dryRun ? $copy : $appPath;
        $composerBefore = self::read($target . '/composer.json');
        $composerChanges = $this->composer->apply($hop, $target);
        $composerAfter = self::read($target . '/composer.json');
        $changes = [...$changes, ...$composerChanges];
        if ($composerAfter !== $composerBefore) {
            $files[] = ['file' => 'composer.json', 'diff' => UnifiedDiff::of('composer.json', $composerBefore, $composerAfter)];
        }

        return new VendoredMigrationResult(
            $dryRun ? array_map(self::wouldLine(...), $changes) : $changes,
            $files,
            $manual,
            [],
            self::raisedPackages($composerBefore, $composerAfter),
        );
    }

    /**
     * What no rule set does, found as text in the upgraded copy (and, for
     * the date classes, in the app as it was, since cakephp50 renames
     * FrozenDate to the CakePHP 5 Date class). Report lines only: nothing
     * here edits a file.
     *
     * @return list<string>
     */
    private function manual(string $appPath, string $copy, bool $five): array
    {
        $copySources = self::phpSources($copy);
        $manual = [];
        if ($five) {
            $checks = [
                ['/\$this->loadComponent\(\s*[\'"]Auth[\'"]|\bAuthComponent\b/', 'AuthComponent is removed in CakePHP 5; port it to the Authentication plugin'],
                ['/->Auth->/', 'the $this->Auth calls go with AuthComponent; move them to $this->Authentication'],
                ['/\bisAuthorized\s*\(/', 'isAuthorized() was AuthComponent\'s authorize hook; move the checks to Authorization plugin policies or plain controller code'],
                ['/loadComponent\(\s*[\'"]RequestHandler[\'"]|\bRequestHandlerComponent\b|->RequestHandler->/', 'RequestHandlerComponent is removed in CakePHP 5; drop it and declare viewClasses() (for example JsonView) where a controller serializes'],
                ['/->withType\(/', 'no upgrade rule checks ->withType(); check each call against the CakePHP 5 response types'],
                ['/\bCake\\\\Mailer\\\\Email\b|\bnew\s+Email\s*\(/', 'Cake\Mailer\Email is removed in CakePHP 5; use Cake\Mailer\Mailer'],
            ];
            foreach ($checks as [$pattern, $message]) {
                $line = self::manualLine($message, self::matches($copySources, $pattern));
                if ($line !== null) {
                    $manual[] = $line;
                }
            }
            $dates = self::manualLine(
                'Cake\I18n\Time and the mutable Cake\I18n\Date are gone in CakePHP 5; use DateTime or the immutable Date and check code that changed a date in place',
                self::matches(self::phpSources($appPath), '/\bCake\\\\I18n\\\\(?:Time|Date)\b/'),
            );
            if ($dates !== null) {
                $manual[] = $dates;
            }
            $ctp = self::filesWithExtension($copy, 'ctp');
            if ($ctp !== []) {
                $manual[] = sprintf('MANUAL: %d .ctp %s left; rename %s to .php: %s.', count($ctp), count($ctp) === 1 ? 'template is' : 'templates are', count($ctp) === 1 ? 'it' : 'them', implode(', ', $ctp));
            }
        }
        $helpers = self::manualLine('View::loadHelper() is addHelper() since CakePHP 4.5 and gone in 5; rename the calls the rules could not type', self::matches($copySources, '/->loadHelper\(/'));
        if ($helpers !== null) {
            $manual[] = $helpers;
        }
        if (!$five) {
            $manual[] = 'MANUAL: run the tests with E_ALL on CakePHP 4.6 and fix every deprecation before the 5 step.';
        }

        return $manual;
    }

    /**
     * @param array<string, int> $counts file => matches
     */
    private static function manualLine(string $message, array $counts): ?string
    {
        if ($counts === []) {
            return null;
        }
        $where = [];
        foreach ($counts as $file => $count) {
            $where[] = sprintf('%s (%d)', $file, $count);
        }

        return sprintf('MANUAL: %s: %s.', $message, implode(', ', $where));
    }

    /**
     * @param array<string, string> $sources file => contents
     *
     * @return array<string, int> file => matches, files with none left out
     */
    private static function matches(array $sources, string $pattern): array
    {
        $counts = [];
        foreach ($sources as $file => $source) {
            $n = preg_match_all($pattern, $source);
            if ($n !== false && $n > 0) {
                $counts[$file] = $n;
            }
        }

        return $counts;
    }

    /**
     * @return array<string, string> relative path => contents, every .php file outside the skipped folders
     */
    private static function phpSources(string $root): array
    {
        $sources = [];
        foreach (self::filesWithExtension($root, 'php') as $file) {
            $sources[$file] = self::read($root . '/' . $file);
        }

        return $sources;
    }

    /**
     * @return list<string>
     */
    private static function filesWithExtension(string $root, string $extension): array
    {
        return array_values(array_filter(
            self::files($root),
            static fn(string $file): bool => strtolower(pathinfo($file, PATHINFO_EXTENSION)) === $extension,
        ));
    }

    /**
     * The diffs between the app and its upgraded copy, and which files to
     * write back or delete.
     *
     * @return array{0: list<array{file: string, diff: string}>, 1: list<string>, 2: list<string>}
     */
    private static function compare(string $appPath, string $copy): array
    {
        $before = self::files($appPath);
        $after = self::files($copy);
        $files = [];
        $written = [];
        foreach ($after as $file) {
            $new = self::read($copy . '/' . $file);
            $old = is_file($appPath . '/' . $file) ? self::read($appPath . '/' . $file) : null;
            if ($old === $new) {
                continue;
            }
            $files[] = ['file' => $file, 'diff' => UnifiedDiff::of($file, $old, $new)];
            $written[] = $file;
        }
        $deleted = array_values(array_diff($before, $after));
        foreach ($deleted as $file) {
            $files[] = ['file' => $file, 'diff' => UnifiedDiff::of($file, self::read($appPath . '/' . $file), null)];
        }

        return [$files, $written, $deleted];
    }

    /**
     * The require and require-dev packages the Composer edit added or
     * changed, so the gate's composer update moves exactly those. Platform
     * entries (php, ext-*) are not packages Composer can update.
     *
     * @return list<string>
     */
    private static function raisedPackages(string $before, string $after): array
    {
        $old = json_decode($before, true);
        $new = json_decode($after, true);
        if (!is_array($new)) {
            return [];
        }
        $old = is_array($old) ? $old : [];
        $packages = [];
        foreach (['require', 'require-dev'] as $section) {
            $was = is_array($old[$section] ?? null) ? $old[$section] : [];
            $now = is_array($new[$section] ?? null) ? $new[$section] : [];
            foreach ($now as $package => $constraint) {
                $package = (string) $package;
                if (!str_contains($package, '/') || ($was[$package] ?? null) === $constraint) {
                    continue;
                }
                $packages[] = $package;
            }
        }

        return array_values(array_unique($packages));
    }

    private static function requiresCake(string $appPath): bool
    {
        $json = json_decode(self::read($appPath . '/composer.json'), true);
        if (is_array($json) && (isset($json['require']['cakephp/cakephp']) || isset($json['require-dev']['cakephp/cakephp']))) {
            return true;
        }
        if (!is_file($appPath . '/composer.lock')) {
            return false;
        }
        $lock = json_decode(self::read($appPath . '/composer.lock'), true);
        foreach (['packages', 'packages-dev'] as $section) {
            foreach (is_array($lock) && is_array($lock[$section] ?? null) ? $lock[$section] : [] as $package) {
                if (is_array($package) && ($package['name'] ?? null) === 'cakephp/cakephp') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * "Ran cakephp50 on src." becomes "Dry run: would run cakephp50 on src.",
     * and the same for the other verbs the engine and ComposerEditor write.
     */
    private static function wouldLine(string $line): string
    {
        $verbs = [
            'Ran' => 'run', 'Renamed' => 'rename', 'Set' => 'set', 'Removed' => 'remove', 'Added' => 'add',
            'Moved' => 'move', 'Wired' => 'wire', 'Swapped' => 'swap', 'Replaced' => 'replace', 'Rewrote' => 'rewrite',
            'Changed' => 'change', 'Required' => 'require',
        ];
        $space = strpos($line, ' ');
        $first = $space === false ? $line : substr($line, 0, $space);
        if (isset($verbs[$first])) {
            return 'Dry run: would ' . $verbs[$first] . substr($line, strlen($first));
        }
        if ($line === 'The upgrade rules changed no files.') {
            return 'Dry run: the upgrade rules would change no files.';
        }

        return 'Dry run: would ' . lcfirst($line);
    }

    private static function failure(string $what, ProcessResult $result): string
    {
        $lines = preg_split('/\R/', trim($result->stderr . "\n" . $result->stdout)) ?: [];
        $tail = implode("\n", array_slice($lines, -self::TAIL_LINES));

        return sprintf("%s (exit %d), so nothing in the app changed. The last lines of its output:\n%s", $what, $result->exitCode, $tail);
    }

    private static function refuse(string $reason): VendoredMigrationResult
    {
        return new VendoredMigrationResult([], [], [], [$reason]);
    }

    /**
     * Every file under $root, relative, with forward slashes, sorted;
     * symlinks and the skipped folders are left out.
     *
     * @return list<string>
     */
    private static function files(string $root, string $prefix = ''): array
    {
        $files = [];
        $entries = scandir($root . ($prefix === '' ? '' : '/' . $prefix)) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || self::skipped($entry, $prefix === '')) {
                continue;
            }
            $relative = $prefix === '' ? $entry : $prefix . '/' . $entry;
            $full = $root . '/' . $relative;
            if (is_link($full)) {
                continue;
            }
            if (is_dir($full)) {
                $files = [...$files, ...self::files($root, $relative)];
            } elseif (is_file($full)) {
                $files[] = $relative;
            }
        }

        return $files;
    }

    private static function skipped(string $entry, bool $atRoot): bool
    {
        return in_array($entry, self::SKIP_ANYWHERE, true) || ($atRoot && in_array($entry, self::SKIP_AT_ROOT, true));
    }

    private static function copyTree(string $from, string $to): void
    {
        foreach (self::files($from) as $file) {
            self::write($to . '/' . $file, self::read($from . '/' . $file));
        }
    }

    private static function read(string $file): string
    {
        $contents = @file_get_contents($file);
        if ($contents === false) {
            throw new ToolError(sprintf('Could not read "%s".', $file));
        }

        return $contents;
    }

    private static function write(string $file, string $contents): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new ToolError(sprintf('Could not create "%s".', $dir));
        }
        if (file_put_contents($file, $contents) === false) {
            throw new ToolError(sprintf('Could not write "%s".', $file));
        }
    }

    /**
     * Removes the copy. The tool's container may leave files owned by
     * another user; whatever cannot go is left to the scratch root's own
     * cleanup at exit and the OS temp cleaner.
     */
    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                self::removeTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
