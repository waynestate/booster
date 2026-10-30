<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

use Composer\InstalledVersions;
use Waynestate\Booster\Packaging\PharFiles;
use Waynestate\Booster\Policy\PolicyLocator;
use Waynestate\Booster\ToolError;

/**
 * Runs waynestate/proctor from Booster's own vendor directory against the
 * upgraded app, as the final gate after every hop succeeded. PROCTOR never
 * comes from the app's composer.json, so an app on any PHP can be measured by
 * a PROCTOR that runs on PHP 8.3 or newer.
 *
 * From the phar, PROCTOR's binary is addressed through PharFiles, because
 * PROCTOR is a child process and cannot be pointed at a phar:// path.
 */
final class ProctorRunner
{
    public function __construct(
        private readonly ProcessRunner $processes,
        private readonly ?string $proctorBinary = null,
        private readonly ?string $phpBinary = null,
    ) {}

    /**
     * Measures the app at $appPath against the policy the plan used.
     *
     * @param string $appPath      where the app lives
     * @param string $phpTarget    the PHP version the app now runs on (major.minor)
     * @param string $policyOrigin the plan's policy origin: a path, or the waynestate/creed package label
     * @param string $today        the date the plan was made (YYYY-MM-DD), so the gate checks the same policy state the planner used
     */
    public function run(string $appPath, string $phpTarget, string $policyOrigin, string $today): ProctorResult
    {
        $appPath = rtrim($appPath, '/\\');
        $command = [
            $this->phpBinary ?? PHP_BINARY,
            $this->binary(),
            'check',
            '--path=' . $appPath,
            '--format=json',
            '--policy=' . self::policyFile($policyOrigin),
            '--today=' . $today,
            '--php-runtime=' . self::fullVersion($phpTarget),
        ];
        $result = $this->processes->run($command, null, [], 600);

        return $this->parse($result, $phpTarget);
    }

    private function binary(): string
    {
        if ($this->proctorBinary !== null) {
            return $this->proctorBinary;
        }

        if (PharFiles::running()) {
            // The whole runtime vendor tree is unpacked with the phar, so
            // PROCTOR's bin finds Booster's autoloader three levels up.
            $binary = PharFiles::path('vendor') . '/waynestate/proctor/bin/proctor';
            if (!is_file($binary)) {
                throw new ToolError('PROCTOR is not installed in the BOOSTER phar.');
            }

            return $binary;
        }

        $root = InstalledVersions::isInstalled('waynestate/proctor')
            ? InstalledVersions::getInstallPath('waynestate/proctor')
            : null;
        $binary = ($root ?? dirname(__DIR__, 2) . '/vendor/waynestate/proctor') . '/bin/proctor';
        if (!is_file($binary)) {
            throw new ToolError('PROCTOR is not installed. Run `composer install` in BOOSTER first.');
        }

        return $binary;
    }

    /**
     * The policy file for PROCTOR: the user's path as-is, or the
     * waynestate/creed package file as a real filesystem path (a phar:// path
     * would not do).
     */
    private static function policyFile(string $origin): string
    {
        return str_starts_with($origin, PolicyLocator::CREED_PACKAGE)
            ? PharFiles::path(PolicyLocator::CREED_POLICY_PATH)
            : $origin;
    }

    /** PROCTOR wants X.Y.Z; Booster plans in major.minor. */
    private static function fullVersion(string $php): string
    {
        if (preg_match('/^\d+\.\d+$/', $php) === 1) {
            return $php . '.0';
        }
        if (preg_match('/^\d+\.\d+\.\d+$/', $php) === 1) {
            return $php;
        }

        throw new ToolError(sprintf('Invalid PHP target "%s". Expected major.minor, such as 8.3.', $php));
    }

    private function parse(ProcessResult $result, string $php): ProctorResult
    {
        if ($result->exitCode === 2) {
            // PROCTOR tripped over its own tooling (bad path, unreadable
            // composer.json, audit timeout, invalid policy). That is not a
            // policy verdict, so report it as tripped with PROCTOR's words.
            return new ProctorResult(
                ProctorResult::TRIPPED,
                $result->exitCode,
                $php,
                [],
                trim($result->stderr . "\n" . $result->stdout),
            );
        }

        $data = json_decode($result->stdout, true);
        if (!is_array($data)) {
            throw new ToolError(sprintf(
                "PROCTOR produced no JSON report (exit %d).\n%s",
                $result->exitCode,
                trim($result->stderr . "\n" . $result->stdout),
            ));
        }

        $failures = [];
        foreach (is_array($data['findings'] ?? null) ? $data['findings'] : [] as $finding) {
            if (!is_array($finding) || ($finding['status'] ?? null) !== 'fail') {
                continue;
            }
            $failures[] = [
                'check' => is_string($finding['check_id'] ?? null) ? $finding['check_id'] : 'unknown',
                'rule' => is_string($finding['rule_id'] ?? null) ? $finding['rule_id'] : 'unknown',
                'message' => is_string($finding['message'] ?? null) ? $finding['message'] : 'unknown failure',
                'path' => is_string($finding['path'] ?? null) ? $finding['path'] : null,
                'line' => is_int($finding['line'] ?? null) ? $finding['line'] : 1,
            ];
        }

        $failed = ($data['result'] ?? ($result->exitCode !== 0 ? 'fail' : 'pass')) === 'fail';

        return new ProctorResult(
            $failed ? ProctorResult::FAILED : ProctorResult::PASSED,
            $result->exitCode,
            $php,
            $failures,
        );
    }
}
