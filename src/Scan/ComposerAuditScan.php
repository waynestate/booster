<?php

declare(strict_types=1);

namespace Waynestate\Booster\Scan;

use Throwable;
use Waynestate\Booster\Run\ProcessResult;
use Waynestate\Booster\Run\ProcessRunner;

/**
 * Runs the app's composer audit after a hop changed its composer files,
 * asking "did that change move the app onto an advisory?". Advisory only:
 * every finding is a hint for the human doing the upgrade, never a gate and
 * never a reason to stop. Where the PHPCompatibility scan is the OBD-II code
 * reader, this is the tire-pressure light: it comes on after you have changed
 * something, and it tells you what to look at next.
 *
 * The audit reads the app's own lock file and installed packages, so it runs
 * inside the app's directory. It changes nothing and never throws: a broken
 * audit becomes ComposerAuditResult::error, because an advisory must not take
 * down the run.
 */
final class ComposerAuditScan
{
    /** The command the error message tells the human to run by hand. */
    public const MANUAL_COMMAND = 'composer audit --format=json --no-interaction';

    /** The highest exit a finished audit gives: advisories (1) and abandoned packages (2) together. */
    private const FOUND_EVERYTHING = 3;

    public function __construct(
        private readonly ProcessRunner $processes,
        private readonly ?string $composerBinary = null,
    ) {}

    /**
     * Audits the app at $appPath. Never throws: a broken audit becomes
     * ComposerAuditResult::error, because an advisory must not take down the
     * run.
     */
    public function scan(string $appPath): ComposerAuditResult
    {
        try {
            $result = $this->processes->run([
                $this->composerBinary ?? 'composer',
                'audit',
                '--format=json',
                '--no-interaction',
            ], $appPath, [], 600);
        } catch (Throwable $e) {
            return ComposerAuditResult::error('The composer audit crashed: ' . $e->getMessage() . "\nRun it by hand: " . self::MANUAL_COMMAND);
        }

        return $this->parse($result);
    }

    /**
     * Turn the JSON report into findings. Composer's exit code is a bit mask
     * of what it found (1 advisories, 2 abandoned packages, 3 both), and it
     * also exits 1 when there is nothing installed to audit, so the exit code
     * cannot tell a finding from a failure: a parseable report is the truth,
     * and anything else is a skip with the reason from the process output.
     * A real app hit exit 2 on every hop after its libraries moved to
     * Composer (abandoned doctrine/annotations, cache and common), and the
     * report used to say "could not run" with the JSON right there in it.
     */
    private function parse(ProcessResult $result): ComposerAuditResult
    {
        $data = json_decode($result->stdout, true);
        if ($result->exitCode > self::FOUND_EVERYTHING || !is_array($data) || (!array_key_exists('advisories', $data) && !array_key_exists('abandoned', $data))) {
            return $this->noReport($result);
        }

        return ComposerAuditResult::ok($this->advisories($data['advisories'] ?? []), $this->abandoned($data['abandoned'] ?? []));
    }

    /**
     * @param mixed $raw
     *
     * @return list<array{package: string, id: string, cve: string, affected_versions: string, title: string}>
     */
    private function advisories(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $package => $entries) {
            if (!is_string($package) || !is_array($entries)) {
                continue;
            }
            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $out[] = [
                    'package' => is_string($entry['packageName'] ?? null) ? $entry['packageName'] : $package,
                    'id' => is_string($entry['advisoryId'] ?? null) ? $entry['advisoryId'] : '',
                    'cve' => is_string($entry['cve'] ?? null) ? $entry['cve'] : '',
                    'affected_versions' => is_string($entry['affectedVersions'] ?? null) ? $entry['affectedVersions'] : '',
                    'title' => is_string($entry['title'] ?? null) ? $entry['title'] : '',
                ];
            }
        }

        return $out;
    }

    /**
     * @param mixed $raw
     *
     * @return array<string, string> package name to replacement, '' when Composer names none
     */
    private function abandoned(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $package => $replacement) {
            if (is_string($package)) {
                $out[$package] = is_string($replacement) ? $replacement : '';
            }
        }

        return $out;
    }

    private function noReport(ProcessResult $result): ComposerAuditResult
    {
        $detail = trim($result->stderr !== '' ? $result->stderr : $result->stdout);
        $suffix = $detail !== '' ? "\n" . $detail : '';

        return ComposerAuditResult::error(sprintf('composer audit exited %d without a JSON report.', $result->exitCode) . $suffix . "\nRun it by hand: " . self::MANUAL_COMMAND);
    }
}
