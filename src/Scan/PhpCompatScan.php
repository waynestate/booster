<?php

declare(strict_types=1);

namespace Waynestate\Booster\Scan;

use Composer\InstalledVersions;
use Waynestate\Booster\Packaging\PharFiles;
use Waynestate\Booster\Run\ProcessResult;
use Waynestate\Booster\Run\ProcessRunner;
use Waynestate\Booster\ToolError;

/**
 * Runs PHP_CodeSniffer with the PHPCompatibility standard against an app,
 * asking "what breaks on PHP X.Y?". Advisory only: every finding is a hint
 * for the human doing the upgrade, never a gate. It gives the plan a boost of
 * foreknowledge, the way a supercharger gives an engine more power up front.
 *
 * phpcs runs from BOOSTER's own vendor tree (like Rector), so the app can sit
 * on any old PHP; the scan needs nothing from the app itself.
 */
final class PhpCompatScan
{
    /**
     * Directories that hold third-party code, not the app's. phpcs matches
     * this PCRE (unanchored) against each file's full path, so the boundary
     * groups keep names like /vendor-app or my-storage out of the match.
     */
    private const IGNORE_PATTERN = '(?:^|/)(?:vendor|node_modules|storage)(?:/|\z)';

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
        try {
            $binary = $this->binary();
        } catch (ToolError $e) {
            return ScanResult::error($testVersion, $e->getMessage());
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

        $command = [
            $this->phpBinary ?? PHP_BINARY,
            $binary,
            '--standard=PHPCompatibility',
            '--runtime-set', 'testVersion', $testVersion,
            '--report=json',
            '--extensions=php,inc',
            '--ignore=' . self::IGNORE_PATTERN,
            $absolute,
        ];

        $result = $this->processes->run($command, null, [], 900);

        // phpcs exits 0 (clean), 1 (findings, none fixable) or 2 (findings,
        // some fixable). Anything else (3 usage error, 124 timeout, 127
        // missing binary) means there is no report to read.
        if ($result->exitCode > 2) {
            return $this->noReport($result, $testVersion);
        }

        return $this->parse($result, $testVersion, $prefix);
    }

    private function binary(): string
    {
        if ($this->phpcsBinary !== null) {
            return $this->phpcsBinary;
        }

        if (PharFiles::running()) {
            $binary = PharFiles::path('vendor') . '/squizlabs/php_codesniffer/bin/phpcs';
            if (!is_file($binary)) {
                throw new ToolError('PHP_CodeSniffer is not installed in the BOOSTER phar.');
            }

            return $binary;
        }

        $root = InstalledVersions::isInstalled('squizlabs/php_codesniffer')
            ? InstalledVersions::getInstallPath('squizlabs/php_codesniffer')
            : null;
        $binary = ($root ?? dirname(__DIR__, 2) . '/vendor/squizlabs/php_codesniffer') . '/bin/phpcs';
        if (!is_file($binary)) {
            throw new ToolError('PHP_CodeSniffer is not installed. Run `composer install` in BOOSTER first.');
        }

        return $binary;
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
