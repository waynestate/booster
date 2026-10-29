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
 * The scanner is optional. BOOSTER looks for a PHP_CodeSniffer that carries
 * the PHPCompatibility standard, trying the app's own vendor/bin/phpcs, then
 * BOOSTER's vendor/bin/phpcs, then phpcs on the PATH. When none of them has
 * the standard, the scan reports a skip instead of failing the plan.
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
     * Shown when no PHP_CodeSniffer carrying the PHPCompatibility standard can
     * be found. Plain and exact, so it can be copied straight into a terminal.
     */
    private const MISSING_STANDARD = 'PHP compatibility scan skipped: PHPCompatibility is not installed. Enable it with: composer require --dev phpcompatibility/php-compatibility:"^10.0@alpha"';

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
        $launch = $this->selectLauncher($appPath);
        if ($launch === null) {
            return ScanResult::error($testVersion, self::MISSING_STANDARD);
        }

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
     * PHPCompatibility standard, or null when none of the candidates does.
     *
     * @return list<string>|null
     */
    private function selectLauncher(string $appPath): ?array
    {
        if ($this->phpcsBinary !== null) {
            // Test seam: trust the injected binary and skip discovery, so tests
            // can exercise the scan without a real PHP_CodeSniffer installed.
            return $this->launcher($this->phpcsBinary);
        }

        foreach ($this->candidates($appPath) as $candidate) {
            if ($this->hasStandard($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The candidate PHP_CodeSniffer launchers, in priority order: the scanned
     * app's own vendor copy, BOOSTER's vendor copy, then phpcs on the PATH.
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
     * Whether a launcher offers the PHPCompatibility standard, asked with
     * `phpcs -i`. A launcher that cannot run, or whose standard list does not
     * name PHPCompatibility, counts as a miss.
     *
     * @param list<string> $launch
     */
    private function hasStandard(array $launch): bool
    {
        try {
            $result = $this->processes->run(array_merge($launch, ['-i']), null, [], 60);
        } catch (Throwable) {
            return false;
        }

        if (!$result->successful()) {
            return false;
        }

        // `phpcs -i` lists the standards comma separated, and PHPCompatibility
        // is a prefix of the Paragonary and PHPToken variants, so compare whole
        // tokens rather than substring-matching.
        $standards = preg_split('/[\s,]+/', $result->stdout) ?: [];

        return in_array('PHPCompatibility', $standards, true);
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
