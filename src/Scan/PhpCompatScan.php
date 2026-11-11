<?php

declare(strict_types=1);

namespace Waynestate\Booster\Scan;

use Throwable;
use Waynestate\Booster\Packaging\PharFiles;
use Waynestate\Booster\Project\ProjectDetector;
use Waynestate\Booster\Run\ProcessResult;
use Waynestate\Booster\Run\ProcessRunner;

/**
 * Runs PHP_CodeSniffer with the PHPCompatibility standard against an app,
 * asking "what breaks on PHP X.Y?". Advisory only: every finding is a hint
 * for the human doing the upgrade, never a gate. It gives the plan a boost of
 * foreknowledge, the way a supercharger gives an engine more power up front.
 *
 * The scanner ships with Booster. Booster looks for a PHP_CodeSniffer that
 * carries the PHPCompatibility standard, trying the app's own vendor/bin/phpcs,
 * then Booster's vendor/bin/phpcs, then phpcs on the PATH. When the launcher
 * is Booster's own copy, the standard is pointed at with --runtime-set
 * installed_paths, because the CodeSniffer.conf that Composer's installer
 * plugin writes cannot be trusted inside a PHAR unpacked into a temp
 * directory. When none of the launchers has the standard, the scan reports a
 * skip instead of failing the plan, and the skip says how to fix it:
 * reinstall Booster's dependencies, or download the PHAR again.
 */
final class PhpCompatScan
{
    /**
     * Directories that hold third-party code, not the app's. phpcs matches
     * this PCRE (unanchored) against each file's full path, so the boundary
     * groups keep names like /vendor-app or my-storage out of the match.
     */
    private const IGNORE_PATTERN = '(?:^|/)(?:vendor|node_modules|storage)(?:/|\z)';

    /**
     * "Function oci_connect() is removed since PHP 8.4": the sniff's way of
     * saying PHP 8.4 moved the oci8 extension out of its own download and
     * into PECL. Only 8.4: an oci function removed earlier is gone from the
     * PECL extension too, so that finding always stays.
     */
    private const OCI_MOVED_TO_PECL = '/^Function oci\w*\(\) is removed since PHP 8\.4\b/i';

    /** The Squire kit files that name the Citadel image an app deploys on. */
    private const CITADEL_KIT_FILES = ['.env.squire.example', '.env.squire', 'docker-compose.squire.yml'];

    /**
     * Shown when a PHP_CodeSniffer runs but does not offer the
     * PHPCompatibility standard. The tool ships with Booster, so the fix is to
     * reinstall Booster's own dependencies, never to change the app.
     */
    private const STANDARD_NOT_REGISTERED = "PHPCompatibility is not registered. Booster ships the tool with its dependencies; reinstall Booster's dependencies with: composer install in Booster's folder, or download the PHAR again";

    /**
     * Shown when no PHP_CodeSniffer carrying the PHPCompatibility standard can
     * be found. Plain and exact, so it can be copied straight into a terminal.
     */
    private const MISSING_STANDARD = "PHP compatibility scan skipped: no PHP_CodeSniffer carrying PHPCompatibility could run. Booster ships the tool; reinstall Booster's dependencies with: composer install in Booster's folder, or download the PHAR again";

    /** `phpcs -i` ran and named PHPCompatibility among the standards. */
    private const STANDARD_OFFERED = 'offered';

    /** `phpcs -i` ran but named other standards only. */
    private const STANDARD_MISSING = 'missing';

    /** The launcher could not run at all. */
    private const NOT_RUNNING = 'not-running';

    public function __construct(
        private readonly ProcessRunner $processes,
        private readonly ?string $phpcsBinary = null,
        private readonly ?string $phpBinary = null,
    ) {}

    /**
     * Scans $appPath for code that will break on PHP $testVersion (major.minor).
     * Never throws: a broken scan becomes ScanResult::error, because an
     * advisory must not take down the plan.
     */
    public function scan(string $appPath, string $testVersion): ScanResult
    {
        $selected = $this->selectLauncher($appPath);
        if ($selected['launch'] === null) {
            // A phpcs that ran but lacks the standard points at a blocked
            // installer plugin; no phpcs running at all points at installing.
            $reason = $selected['binaryFound'] ? self::STANDARD_NOT_REGISTERED : self::MISSING_STANDARD;

            return ScanResult::error($testVersion, $reason);
        }

        $launch = $selected['launch'];
        $installedPaths = $this->boosterInstalledPaths($launch);

        // Resolve to an absolute path so the reported file paths (always
        // absolute from phpcs) can be made relative to the app again. If the
        // path does not exist, keep it as given: phpcs will say so.
        $absolute = realpath($appPath);
        if ($absolute === false) {
            $absolute = $appPath;
        }
        $base = is_dir($absolute) ? $absolute : dirname($absolute);
        $prefix = rtrim(str_replace('\\', '/', $base), '/') . '/';

        $command = array_merge($launch, [
            '--standard=PHPCompatibility',
            '--runtime-set', 'testVersion', $testVersion,
        ], $this->installedPathsArgs($installedPaths), [
            '--report=json',
            '--extensions=php,inc',
            '--ignore=' . self::IGNORE_PATTERN,
            $absolute,
        ]);

        try {
            $result = $this->processes->run($command, null, [], 900);
        } catch (Throwable $e) {
            return ScanResult::error($testVersion, 'The PHPCompatibility scan crashed: ' . $e->getMessage());
        }

        // phpcs exits 0 (clean), 1 (findings, none fixable) or 2 (findings,
        // some fixable). Exit codes above 2 (3 usage error, 124 timeout) have
        // no report to read; a missing binary leaves no JSON either, because
        // PHP itself says "Could not open input file".
        if ($result->exitCode > 2) {
            return $this->noReport($result, $testVersion);
        }

        // Pest binds every test closure to a test case, so `$this` inside
        // `it()` and `test()` is the test case, and the sniff that asks "is
        // $this bound here?" fired on every one of them: a Laravel app on
        // Pest got 51 such warnings and nothing else from the scan. Laravel
        // binds the closures in routes/console.php to their command the same
        // way, so its stock `inspire` command drew the warning on every app.
        $boundThis = [];
        if (ProjectDetector::requiresAt($appPath, 'pestphp/pest')) {
            $boundThis[] = static fn(string $file): bool => str_starts_with($file, 'tests/');
        }
        if (ProjectDetector::requiresAt($appPath, 'laravel/framework')) {
            $boundThis[] = static fn(string $file): bool => $file === 'routes/console.php';
        }

        return $this->parse($result, $testVersion, $prefix, $this->declaresOci8($appPath), $boundThis, $this->citadelKit($appPath));
    }

    /**
     * Pick the argument prefix that starts a PHP_CodeSniffer carrying the
     * PHPCompatibility standard. Returns the launch prefix, or null when none
     * of the candidates does, plus whether some phpcs ran at all, so the skip
     * message can tell "installed but not registered" from "not installed".
     *
     * @return array{launch: list<string>|null, binaryFound: bool}
     */
    private function selectLauncher(string $appPath): array
    {
        if ($this->phpcsBinary !== null) {
            // Test seam: trust the injected binary and skip discovery, so tests
            // can exercise the scan without a real PHP_CodeSniffer installed.
            return ['launch' => $this->launcher($this->phpcsBinary), 'binaryFound' => true];
        }

        $binaryFound = false;
        foreach ($this->candidates($appPath) as $candidate) {
            $status = $this->standardStatus($candidate, $this->boosterInstalledPaths($candidate));
            if ($status === self::STANDARD_OFFERED) {
                return ['launch' => $candidate, 'binaryFound' => true];
            }
            $binaryFound = $binaryFound || $status === self::STANDARD_MISSING;
        }

        return ['launch' => null, 'binaryFound' => $binaryFound];
    }

    /**
     * The candidate PHP_CodeSniffer launchers, in priority order: the scanned
     * app's own vendor copy, Booster's vendor copy, then phpcs on the PATH.
     *
     * @return list<list<string>>
     */
    private function candidates(string $appPath): array
    {
        $candidates = [];

        $appPhpcs = $appPath . '/vendor/bin/phpcs';
        if (is_file($appPhpcs)) {
            $candidates[] = $this->launcher($appPhpcs);
        }

        $boosterPhpcs = PharFiles::path('vendor') . '/bin/phpcs';
        if (is_file($boosterPhpcs)) {
            $candidates[] = $this->launcher($boosterPhpcs);
        }

        $candidates[] = ['phpcs'];

        return $candidates;
    }

    /**
     * Argument prefix that runs a phpcs launcher file through the PHP
     * interpreter, so it behaves the same on Windows, macOS and Linux.
     *
     * @return list<string>
     */
    private function launcher(string $phpcs): array
    {
        return [($this->phpBinary ?? PHP_BINARY), $phpcs];
    }

    /**
     * The directory to pass as --runtime-set installed_paths when the launcher
     * is Booster's own vendor/bin/phpcs: the folder that holds the bundled
     * PHPCompatibility standard. The registration in CodeSniffer.conf that
     * Composer's installer plugin writes cannot be trusted inside a PHAR
     * unpacked into a temp directory, so the standard is pointed at directly.
     * Null for every other launcher, and when the standard folder is absent.
     *
     * @param list<string> $launch
     */
    private function boosterInstalledPaths(array $launch): ?string
    {
        $binary = str_replace('\\', '/', (string) ($launch[1] ?? ''));
        $own = str_replace('\\', '/', PharFiles::path('vendor') . '/bin/phpcs');
        if ($binary !== $own) {
            return null;
        }

        $standard = PharFiles::path('vendor') . '/phpcompatibility/php-compatibility/PHPCompatibility';
        if (!is_dir($standard)) {
            return null;
        }

        return PharFiles::path('vendor') . '/phpcompatibility/php-compatibility';
    }

    /**
     * The --runtime-set arguments that point phpcs at the bundled standard,
     * or none when the launcher is not Booster's own copy.
     *
     * @return list<string>
     */
    private function installedPathsArgs(?string $installedPaths): array
    {
        return $installedPaths === null ? [] : ['--runtime-set', 'installed_paths', $installedPaths];
    }

    /**
     * What `phpcs -i` says about a launcher: that it offers the
     * PHPCompatibility standard, that it ran but names other standards only,
     * or that it could not run at all. The last is how a missing phpcs shows
     * up, and it is what lets the skip message say "not installed". When the
     * launcher is Booster's own copy, the probe carries the same
     * --runtime-set installed_paths the scan will, so the bundled standard
     * shows up in the list even without a CodeSniffer.conf registration.
     *
     * @param list<string> $launch
     */
    private function standardStatus(array $launch, ?string $installedPaths): string
    {
        try {
            $result = $this->processes->run(array_merge($launch, $this->installedPathsArgs($installedPaths), ['-i']), null, [], 60);
        } catch (Throwable) {
            return self::NOT_RUNNING;
        }

        if (!$result->successful()) {
            return self::NOT_RUNNING;
        }

        // `phpcs -i` lists the standards comma separated, and PHPCompatibility
        // is a prefix of the Paragonary and PHPToken variants, so compare whole
        // tokens rather than substring-matching.
        $standards = preg_split('/[\s,]+/', $result->stdout) ?: [];

        return in_array('PHPCompatibility', $standards, true) ? self::STANDARD_OFFERED : self::STANDARD_MISSING;
    }

    /**
     * Whether the app's composer.json declares the oci8 extension, in
     * require, require-dev or config.platform. PHP 8.4 moved oci8 out of the
     * core PHP download and into PECL, so an app that installs the PECL
     * extension on purpose keeps Oracle support, and its oci_* removed
     * findings are not "will break" news. An absent or invalid composer.json
     * means the app does not declare it, and the findings stay.
     */
    private function declaresOci8(string $appPath): bool
    {
        $file = $appPath . '/composer.json';
        if (!is_file($file)) {
            return false;
        }

        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            return false;
        }

        $sections = [
            $data['require'] ?? null,
            $data['require-dev'] ?? null,
            $data['config']['platform'] ?? null,
        ];

        foreach ($sections as $section) {
            if (is_array($section) && array_key_exists('ext-oci8', $section)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The Squire kit file that names Citadel's PHP image, or null when the app
     * carries none. Squire's kit pins the Citadel image the app deploys on
     * (SQUIRE_BASE_IMAGE=citadel/php-apache:8.4r5), and every Citadel PHP
     * image from 8.0 on installs oci8 from PECL, so for such an app PHP 8.4
     * moving oci8 out of the core download changes nothing.
     */
    private function citadelKit(string $appPath): ?string
    {
        foreach (self::CITADEL_KIT_FILES as $name) {
            $file = $appPath . '/' . $name;
            if (is_file($file) && str_contains((string) file_get_contents($file), 'citadel/php-apache')) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @param list<callable(string): bool> $boundThis files (relative to the app) where a framework binds every closure, so "$this outside a class" is not a finding there
     * @param ?string $citadelKit the kit file that says the app deploys on Citadel's image, or null
     */
    private function parse(ProcessResult $result, string $testVersion, string $prefix, bool $oci8Declared = false, array $boundThis = [], ?string $citadelKit = null): ScanResult
    {
        $data = json_decode($result->stdout, true);
        if (!is_array($data) || !isset($data['files']) || !is_array($data['files'])) {
            return $this->noReport($result, $testVersion);
        }

        $findings = [];
        $ociLeftOut = 0;
        $ociKept = 0;
        foreach ($data['files'] as $file => $info) {
            if (!is_string($file) || !is_array($info) || !isset($info['messages']) || !is_array($info['messages'])) {
                continue;
            }
            $file = str_replace('\\', '/', $file);
            if (str_starts_with($file, $prefix)) {
                $file = substr($file, strlen($prefix));
            }
            foreach ($info['messages'] as $message) {
                if (!is_array($message) || !is_string($message['message'] ?? null) || !is_int($message['line'] ?? null)) {
                    continue;
                }
                // Internal.NoCodeFound fires on plain HTML includes that carry
                // no PHP code; it is noise, not a finding.
                if (($message['source'] ?? '') === 'Internal.NoCodeFound') {
                    continue;
                }
                // PHP 8.4 moved oci8 out of the core download and into PECL.
                // When the app installs the PECL extension itself, or deploys
                // on Citadel's image, which does, "oci_*() is removed since
                // PHP 8.4" is not a break, so the finding is dropped (and
                // counted, so the report can say why). Every other finding stays.
                if (preg_match(self::OCI_MOVED_TO_PECL, $message['message']) === 1) {
                    if ($oci8Declared) {
                        continue;
                    }
                    if ($citadelKit !== null) {
                        $ociLeftOut++;
                        continue;
                    }
                    $ociKept++;
                }
                $source = is_string($message['source'] ?? null) ? $message['source'] : '';
                // The RemovedExtensions.oracle sniff matches class names such as
                // OracleSessionInit, while the removed extension's functions are
                // all named ora_* and it never matches those, so every oracle
                // finding is a false positive. Drop them and keep the rest.
                if (str_starts_with(strtolower($source), 'phpcompatibility.extensions.removedextensions.oracle')) {
                    continue;
                }
                // Where the framework binds the closures (Pest's tests/,
                // Laravel's routes/console.php), "$this outside a class" is
                // how the code is written, not a bug. Elsewhere it stays.
                if ($source === 'PHPCompatibility.FunctionDeclarations.NewClosure.ThisFoundOutsideClass') {
                    foreach ($boundThis as $bound) {
                        if ($bound($file)) {
                            continue 2;
                        }
                    }
                }
                $findings[] = new Finding(
                    $file,
                    $message['line'],
                    $message['message'],
                    ($message['type'] ?? 'ERROR') === 'WARNING' ? 'warning' : 'error',
                    $source,
                );
            }
        }

        $hints = [];
        if ($ociLeftOut > 0) {
            $hints[] = sprintf(
                'Left out %d "oci_*() is removed since PHP 8.4" %s: the app deploys on Citadel (citadel/php-apache in %s), and Citadel\'s PHP images install oci8 from PECL.',
                $ociLeftOut,
                $ociLeftOut === 1 ? 'finding' : 'findings',
                $citadelKit,
            );
        } elseif ($ociKept > 0) {
            $hints[] = 'The oci_*() lines mean PHP 8.4 moved oci8 out of its own download and into PECL, not that Oracle support is gone. Citadel\'s PHP images install it, so on Citadel they are not breaks; on another image, install the PECL extension. Declare ext-oci8 in composer.json (require or config.platform) and the scan leaves them out.';
        }

        return ScanResult::ok($testVersion, $findings, $hints);
    }

    private function noReport(ProcessResult $result, string $testVersion): ScanResult
    {
        $detail = trim($result->stderr !== '' ? $result->stderr : $result->stdout);
        $suffix = $detail !== '' ? "\n" . $detail : '';

        return ScanResult::error($testVersion, sprintf('phpcs exited %d without a JSON report.', $result->exitCode) . $suffix);
    }
}
