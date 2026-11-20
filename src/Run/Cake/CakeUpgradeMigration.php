<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run\Cake;

use Waynestate\Booster\Hop\Hop;
use Waynestate\Booster\Packaging\RunScratch;
use Waynestate\Booster\Refresh\HopDataRefresher;
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
 *
 * The same set marks the CakePHP 5 steps no rule covers, done in the copy
 * after the rules: an app that used AuthComponent moves to the
 * Authentication plugin like for like (AuthRewriter for the controllers and
 * components, ApplicationScaffold for src/Application.php, and
 * cakephp/authentication in composer.json), and an app on CakeDC's Oracle
 * driver, which has no CakePHP 5 release, moves to the CakePHP 5 one.
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

    /** The Authentication plugin the CakePHP 5 hop moves AuthComponent apps onto (never cakephp/authorization). */
    private const AUTHENTICATION = 'cakephp/authentication';

    private const AUTHENTICATION_PIN = '^3.0';

    /** CakeDC's Oracle driver has no CakePHP 5 release; the fleet's CakePHP 5 apps use this one (D-123). */
    private const OLD_ORACLE = 'cakedc/cakephp-oracle-driver';

    private const NEW_ORACLE = 'ioigoume/cakephp5-oracle-driver';

    private const NEW_ORACLE_PIN = '^1.0';

    /** The Oracle driver namespaces, as written in code and in a double-backslash string. */
    private const ORACLE_NAMESPACES = [
        'CakeDC\OracleDriver\\' => 'Ioigoume\OracleDriver\\',
        'CakeDC\\\\OracleDriver\\\\' => 'Ioigoume\\\\OracleDriver\\\\',
    ];

    public function __construct(
        private readonly CakeUpgradeRunner $runner,
        private readonly ComposerEditor $composer,
        private readonly AuthRewriter $auth = new AuthRewriter(),
        private readonly ApplicationScaffold $scaffold = new ApplicationScaffold(),
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
                if (self::printedError($result)) {
                    return self::refuse(self::failure(sprintf('The cakephp/upgrade rule set %s printed an error on %s/', $set, $subdir), $result));
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

        $authUsed = false;
        $oracle = false;
        $stepManual = [];
        if ($five) {
            [$authUsed, $authChanges, $stepManual] = $this->moveAuth($copy);
            $changes = [...$changes, ...$authChanges];
            $oracle = self::requires($copy, self::OLD_ORACLE);
            if ($oracle) {
                self::renameOracleNamespace($copy);
                $stepManual[] = 'MANUAL: check that the Oracle driver for CakePHP 5 covers the Oracle calls the app makes.';
            }
        }

        [$files, $written, $deleted] = self::compare($appPath, $copy);
        if ($files === []) {
            $changes[] = 'The upgrade rules changed no files.';
        }
        $manual = array_values(array_unique([...$stepManual, ...$this->manual($appPath, $copy, $five)]));

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
        $composerChanges = [
            ...$this->composer->apply($hop, $target),
            ...self::pinAuthenticationAndOracle($target, $authUsed, $oracle),
        ];
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
     * The CakePHP 5 hop's AuthComponent move, in the copy: every .php under
     * src/ and plugins/ goes through AuthRewriter (the loadComponent call,
     * the components' $components, then the calls), and src/Application.php
     * gets the Authentication plugin wiring with the old loginAction as the
     * guests' redirect. An app that never used AuthComponent (no
     * loadComponent('Auth'), no call or $components entry to move) is left
     * alone, its Application class included.
     *
     * @return array{0: bool, 1: list<string>, 2: list<string>} whether AuthComponent was used, the changes, the MANUAL lines
     */
    private function moveAuth(string $copy): array
    {
        // Read before the rewrite drops the loadComponent config it sits in.
        $appController = $copy . '/src/Controller/AppController.php';
        $loginAction = is_file($appController) ? $this->auth->loginActionOf(self::read($appController)) : null;

        $used = false;
        $moved = 0;
        $edits = [];
        $loads = [];
        $propertyChanges = [];
        $manual = [];
        $files = array_filter(
            self::filesWithExtension($copy, 'php'),
            static fn(string $file): bool => str_starts_with($file, 'src/') || str_starts_with($file, 'plugins/'),
        );
        foreach ($files as $file) {
            $source = self::read($copy . '/' . $file);
            $load = $this->auth->rewriteLoadComponent($source);
            $properties = $this->auth->rewriteComponentProperties($load['code']);
            $calls = $this->auth->rewriteCalls($properties['code']);
            $movedHere = self::authCalls($source) - self::authCalls($calls['code']);
            $entryRenamed = substr_count($properties['code'], "'Authentication.Authentication'") > substr_count($load['code'], "'Authentication.Authentication'");
            $used = $used || $load['changes'] !== [] || $movedHere > 0 || $entryRenamed;
            $moved += max(0, $movedHere);
            if ($load['changes'] !== []) {
                $loads[] = $file;
            }
            $propertyChanges = [...$propertyChanges, ...$properties['changes']];
            $manual = [...$manual, ...$load['manual'], ...$properties['manual'], ...$calls['manual']];
            if ($calls['code'] !== $source) {
                $edits[$file] = $calls['code'];
            }
        }
        if (!$used) {
            return [false, [], []];
        }

        foreach ($edits as $file => $code) {
            self::write($copy . '/' . $file, $code);
        }
        $changes = [];
        if ($moved > 0) {
            $changes[] = sprintf('Moved %d AuthComponent %s to the Authentication plugin.', $moved, $moved === 1 ? 'call' : 'calls');
        }
        foreach ($loads as $file) {
            $changes[] = sprintf("Replaced loadComponent('Auth') with loadComponent('Authentication.Authentication') in %s.", $file);
        }
        $changes = [...$changes, ...$propertyChanges];

        $application = $copy . '/src/Application.php';
        if (is_file($application)) {
            $before = self::read($application);
            $scaffold = $this->scaffold->scaffold($before, $loginAction);
            $manual = [...$manual, ...$scaffold['manual']];
            if ($scaffold['code'] !== $before) {
                self::write($application, $scaffold['code']);
                $changes[] = 'Wired src/Application.php for the Authentication plugin.';
            }
        } else {
            $manual[] = 'MANUAL: there is no src/Application.php; wire the Authentication plugin (the provider interface, the middleware and getAuthenticationService()) by hand.';
        }

        return [true, $changes, array_values(array_unique($manual))];
    }

    /** The `$this->Auth->name(` calls in $php. */
    private static function authCalls(string $php): int
    {
        return (int) preg_match_all('/\$this\s*->\s*Auth\s*->\s*\w+\s*\(/', $php);
    }

    /** Whether composer.json in $dir lists $package in require or require-dev. */
    private static function requires(string $dir, string $package): bool
    {
        $json = json_decode(self::read($dir . '/composer.json'), true);

        return is_array($json) && (isset($json['require'][$package]) || isset($json['require-dev'][$package]));
    }

    /**
     * The Oracle driver's namespace moves from CakeDC\OracleDriver\ to
     * Ioigoume\OracleDriver\ in every .php under src/ and config/: the
     * plugin class in Application.php, the connection and driver classes in
     * the datasource config. A plain text replace; the class names under
     * the namespace are the same in both drivers.
     */
    private static function renameOracleNamespace(string $copy): void
    {
        foreach (self::filesWithExtension($copy, 'php') as $file) {
            if (!str_starts_with($file, 'src/') && !str_starts_with($file, 'config/')) {
                continue;
            }
            $source = self::read($copy . '/' . $file);
            $renamed = strtr($source, self::ORACLE_NAMESPACES);
            if ($renamed !== $source) {
                self::write($copy . '/' . $file, $renamed);
            }
        }
    }

    /**
     * The CakePHP 5 hop's own Composer edits, after the hop's pins:
     * cakephp/authentication when the app used AuthComponent, and the
     * Oracle driver swap. ComposerEditor adds no missing package for a
     * framework hop, so these two are written here.
     *
     * @return list<string>
     */
    private static function pinAuthenticationAndOracle(string $target, bool $authentication, bool $oracle): array
    {
        if (!$authentication && !$oracle) {
            return [];
        }
        $file = $target . '/composer.json';
        $data = json_decode(self::read($file), true);
        if (!is_array($data)) {
            throw new ToolError(sprintf('"%s" is not valid JSON.', $file));
        }

        $changes = [];
        if ($authentication) {
            $section = !isset($data['require'][self::AUTHENTICATION]) && isset($data['require-dev'][self::AUTHENTICATION]) ? 'require-dev' : 'require';
            $current = $data[$section][self::AUTHENTICATION] ?? null;
            $floor = is_string($current) ? HopDataRefresher::lowestMajor($current) : null;
            if ($floor === null || version_compare($floor, '3', '<')) {
                $data[$section][self::AUTHENTICATION] = self::AUTHENTICATION_PIN;
                $changes[] = sprintf('Required %s %s for the Authentication plugin.', self::AUTHENTICATION, self::AUTHENTICATION_PIN);
            }
        }
        if ($oracle) {
            foreach (['require', 'require-dev'] as $section) {
                if (!isset($data[$section][self::OLD_ORACLE])) {
                    continue;
                }
                unset($data[$section][self::OLD_ORACLE]);
                $data[$section][self::NEW_ORACLE] ??= self::NEW_ORACLE_PIN;
            }
            $changes[] = 'Swapped the Oracle driver for the CakePHP 5 one.';
        }
        if ($changes === []) {
            return [];
        }
        if (($data['config']['sort-packages'] ?? false) === true) {
            foreach (['require', 'require-dev'] as $section) {
                if (is_array($data[$section] ?? null)) {
                    $data[$section] = self::sortedRequire($data[$section]);
                }
            }
        }

        self::write($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");

        return $changes;
    }

    /**
     * A require block in Composer's sort-packages order: php, then the
     * php-, ext- and lib- platform entries, then the rest by name.
     *
     * @param array<mixed> $require
     *
     * @return array<mixed>
     */
    private static function sortedRequire(array $require): array
    {
        $group = static fn(string $name): int => match (true) {
            $name === 'php' => 0,
            str_starts_with($name, 'php-') => 1,
            str_starts_with($name, 'ext-') => 2,
            str_starts_with($name, 'lib-') => 3,
            default => 4,
        };
        uksort($require, static fn(int|string $a, int|string $b): int => [$group((string) $a), (string) $a] <=> [$group((string) $b), (string) $b]);

        return $require;
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

    /**
     * cakephp/upgrade runs Rector as a child process and says "Rector applied
     * successfully" with exit 0 even when Rector could not load the rule set
     * (a missing config file, a Rector class that no longer exists). Rector's
     * error block, a PHP fatal or the tool's own "Could not ..." line means
     * the set never ran, so it is a failure, not a quiet success.
     */
    private static function printedError(ProcessResult $result): bool
    {
        return preg_match(
            '/^\s*\[ERROR\]|^(PHP )?Fatal error:|^Could not (run|create) rector/mi',
            $result->stdout . "\n" . $result->stderr,
        ) === 1;
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
