<?php

declare(strict_types=1);

namespace Waynestate\Booster\Scan;

use Throwable;
use Waynestate\Booster\Packaging\PharFiles;
use Waynestate\Booster\Run\ProcessResult;
use Waynestate\Booster\Run\ProcessRunner;

/**
 * Runs PHP_CodeSniffer with the PHPCompatibility standard against an app,
 * asking "what breaks on PHP X.Y?". Advisory only: every finding is a hint
 * for the human doing the upgrade, never a gate. It gives the plan a boost of
 * foreknowledge, the way a supercharger gives an engine more power up front.
 *
 * The scanner is optional. Booster looks for a PHP_CodeSniffer that carries
 * the PHPCompatibility standard, trying the app's own vendor/bin/phpcs, then
 * Booster's vendor/bin/phpcs, then phpcs on the PATH. When none of them has
 * the standard, the scan reports a skip instead of failing the plan, and the
 * skip says how to fix it: approve the installer plugin when a phpcs ran
 * without the standard, install the packages when no phpcs ran at all.
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
     * Shown when a PHP_CodeSniffer runs but does not offer the
     * PHPCompatibility standard, which is what happens when Composer blocks
     * the installer plugin that registers it.
     */
    private const STANDARD_NOT_REGISTERED = 'PHPCompatibility is not registered. If composer blocked a plugin, run: composer config allow-plugins.dealerdirect/phpcodesniffer-composer-installer true, then composer require --dev phpcompatibility/php-compatibility:"^10.0@alpha"';

    /**
     * Shown when no PHP_CodeSniffer carrying the PHPCompatibility standard can
     * be found. Plain and exact, so it can be copied straight into a terminal.
     */
    private const MISSING_STANDARD = 'PHP compatibility scan skipped: PHPCompatibility is not installed. Enable it with: composer config allow-plugins.dealerdirect/phpcodesniffer-composer-installer true, then composer require --dev phpcompatibility/php-compatibility:"^10.0@alpha"';

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

        return $this->parse($result, $testVersion, $prefix);
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
            $status = $this->standardStatus($candidate);
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
     * What `phpcs -i` says about a launcher: that it offers the
     * PHPCompatibility standard, that it ran but names other standards only,
     * or that it could not run at all. The last is how a missing phpcs shows
     * up, and it is what lets the skip message say "not installed".
     *
     * @param list<string> $launch
     */
    private function standardStatus(array $launch): string
    {
        try {
            $result = $this->processes->run(array_merge($launch, ['-i']), null, [], 60);
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

    private function parse(ProcessResult $result, string $testVersion, string $prefix): ScanResult
    {
        $data = json_decode($result->stdout, true);
        if (!is_array($data) || !isset($data['files']) || !is_array($data['files'])) {
            return $this->noReport($result, $testVersion);
        }

        $findings = [];
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
                $findings[] = new Finding(
                    $file,
                    $message['line'],
                    $message['message'],
                    ($message['type'] ?? 'ERROR') === 'WARNING' ? 'warning' : 'error',
                    is_string($message['source'] ?? null) ? $message['source'] : '',
                );
            }
        }

        return ScanResult::ok($testVersion, $findings);
    }

    private function noReport(ProcessResult $result, string $testVersion): ScanResult
    {
        $detail = trim($result->stderr !== '' ? $result->stderr : $result->stdout);
        $suffix = $detail !== '' ? "\n" . $detail : '';

        return ScanResult::error($testVersion, sprintf('phpcs exited %d without a JSON report.', $result->exitCode) . $suffix);
    }
}
