<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use RuntimeException;
use Waynestate\Booster\Report\TextWriter;
use Waynestate\Booster\Run\ProcessResult;
use Waynestate\Booster\Run\ProcessRunner;
use Waynestate\Booster\Scan\ComposerAuditScan;

/**
 * The advisory composer audit's parsing of Composer's JSON report, through the
 * process seam so no real composer runs.
 */
final class ComposerAuditScanTest extends TestCase
{
    /** A report shaped like a real one: one package with two advisories, one abandoned. */
    private const FINDINGS_JSON = <<<'JSON'
        {
            "advisories": {
                "guzzle/guzzle": [
                    {
                        "advisoryId": "GHSA-27h6-jg56-68r3",
                        "packageName": "guzzle/guzzle",
                        "affectedVersions": "< 6.5.9",
                        "title": "Guzzle 4/5/6 SSRF and HTTP Request Smuggling",
                        "cve": "CVE-2021-42378",
                        "link": "https://example.invalid/advisory",
                        "severity": "high"
                    },
                    {
                        "advisoryId": "GHSA-x2rg-p6x5-3rm4",
                        "packageName": "guzzle/guzzle",
                        "affectedVersions": "< 7.4.3",
                        "title": "Guzzle 7.x: HTTP request smuggling via proxy",
                        "link": "https://example.invalid/advisory-2",
                        "severity": "moderate"
                    }
                ]
            },
            "abandoned": {
                "waynesoft/old-widget": "wayne-ui/widget",
                "legacy/standalone": ""
            },
            "filter": []
        }
        JSON;

    public function test_it_runs_the_fixed_audit_command_in_the_app_directory(): void
    {
        $fake = new FakeProcessRunner(static fn(array $command): ProcessResult => new ProcessResult(0, '', ''));
        (new ComposerAuditScan($fake))->scan('/tmp/app');

        self::assertSame([['command' => ['composer', 'audit', '--format=json', '--no-interaction'], 'cwd' => '/tmp/app', 'env' => []]], $fake->calls);
    }

    public function test_a_clean_report_has_no_findings(): void
    {
        $fake = new FakeProcessRunner(static fn(array $command): ProcessResult => new ProcessResult(0, '', ''));
        $result = (new ComposerAuditScan($fake))->scan('/tmp/app');

        self::assertTrue($result->ok);
        self::assertFalse($result->hasFindings());
        self::assertSame([], $result->advisories);
        self::assertSame([], $result->abandoned);
    }

    public function test_an_advisory_report_parses_into_findings(): void
    {
        $fake = new FakeProcessRunner(static fn(array $command): ProcessResult => new ProcessResult(0, '', ''));
        $fake->auditResponse = new ProcessResult(1, self::FINDINGS_JSON, '');
        $result = (new ComposerAuditScan($fake))->scan('/tmp/app');

        self::assertTrue($result->ok);
        self::assertTrue($result->hasFindings());
        self::assertSame([
            [
                'package' => 'guzzle/guzzle',
                'id' => 'GHSA-27h6-jg56-68r3',
                'cve' => 'CVE-2021-42378',
                'affected_versions' => '< 6.5.9',
                'title' => 'Guzzle 4/5/6 SSRF and HTTP Request Smuggling',
            ],
            [
                'package' => 'guzzle/guzzle',
                'id' => 'GHSA-x2rg-p6x5-3rm4',
                'cve' => '',
                'affected_versions' => '< 7.4.3',
                'title' => 'Guzzle 7.x: HTTP request smuggling via proxy',
            ],
        ], $result->advisories);
        self::assertSame(['waynesoft/old-widget' => 'wayne-ui/widget', 'legacy/standalone' => ''], $result->abandoned);
    }

    public function test_the_array_form_carries_counts_and_findings(): void
    {
        $fake = new FakeProcessRunner(static fn(array $command): ProcessResult => new ProcessResult(0, '', ''));
        $fake->auditResponse = new ProcessResult(1, self::FINDINGS_JSON, '');
        $result = (new ComposerAuditScan($fake))->scan('/tmp/app');

        $array = $result->toArray();
        self::assertSame('ok', $array['status']);
        self::assertSame(2, $array['advisory_count']);
        self::assertSame(2, $array['abandoned_count']);
        self::assertSame('GHSA-27h6-jg56-68r3', $array['advisories'][0]['id']);
        self::assertSame('wayne-ui/widget', $array['abandoned']['waynesoft/old-widget']);
    }

    public function test_a_missing_composer_is_an_error_with_the_manual_command(): void
    {
        $fake = new FakeProcessRunner(static fn(array $command): ProcessResult => new ProcessResult(0, '', ''));
        $fake->auditResponse = new ProcessResult(127, '', 'sh: composer: command not found');
        $result = (new ComposerAuditScan($fake))->scan('/tmp/app');

        self::assertFalse($result->ok);
        self::assertStringContainsString('exited 127 without a JSON report', (string) $result->error);
        self::assertStringContainsString('sh: composer: command not found', (string) $result->error);
        self::assertStringContainsString('Run it by hand: composer audit --format=json --no-interaction', (string) $result->error);
        self::assertSame(['status' => 'error', 'error' => $result->error], $result->toArray());
    }

    public function test_a_report_without_installed_packages_is_an_error_not_findings(): void
    {
        // Composer's real answer for an app with no vendor/: exit 1 and a text
        // note, no JSON. That is "could not run", not "clean".
        $fake = new FakeProcessRunner(static fn(array $command): ProcessResult => new ProcessResult(0, '', ''));
        $fake->auditResponse = new ProcessResult(1, "No installed packages found. Nothing to audit.\n", '');
        $result = (new ComposerAuditScan($fake))->scan('/tmp/app');

        self::assertFalse($result->ok);
        self::assertStringContainsString('No installed packages found.', (string) $result->error);
    }

    public function test_a_usage_error_exit_is_an_error(): void
    {
        $fake = new FakeProcessRunner(static fn(array $command): ProcessResult => new ProcessResult(0, '', ''));
        $fake->auditResponse = new ProcessResult(2, '', 'Composer could not find a lock file.');
        $result = (new ComposerAuditScan($fake))->scan('/tmp/app');

        self::assertFalse($result->ok);
        self::assertStringContainsString('exited 2 without a JSON report', (string) $result->error);
    }

    public function test_exit_2_for_abandoned_packages_is_a_result_not_a_failure(): void
    {
        // What Composer 2.8 and later print for an app whose only findings are
        // abandoned packages: exit 2 (the abandoned bit) and a full report.
        $report = '{"advisories": [], "abandoned": {"doctrine/annotations": "", "doctrine/cache": "", "doctrine/common": "doctrine/persistence"}}';
        $fake = new FakeProcessRunner(static fn(array $command): ProcessResult => new ProcessResult(0, '', ''));
        $fake->auditResponse = new ProcessResult(2, $report, '');
        $result = (new ComposerAuditScan($fake))->scan('/tmp/app');

        self::assertTrue($result->ok);
        self::assertNull($result->error);
        self::assertSame(['doctrine/annotations' => '', 'doctrine/cache' => '', 'doctrine/common' => 'doctrine/persistence'], $result->abandoned);

        $lines = implode("\n", (new TextWriter())->composerAudit($result));
        self::assertStringNotContainsString('could not run', $lines);
        self::assertStringContainsString('Composer audit after this hop: 0 advisories, 3 abandoned packages', $lines);
        self::assertStringContainsString('doctrine/common is abandoned (use doctrine/persistence instead).', $lines);
    }

    public function test_exit_3_carries_advisories_and_abandoned_packages(): void
    {
        $fake = new FakeProcessRunner(static fn(array $command): ProcessResult => new ProcessResult(0, '', ''));
        $fake->auditResponse = new ProcessResult(3, self::FINDINGS_JSON, '');
        $result = (new ComposerAuditScan($fake))->scan('/tmp/app');

        self::assertTrue($result->ok);
        self::assertCount(2, $result->advisories);
        self::assertCount(2, $result->abandoned);
    }

    public function test_a_crashing_process_runner_is_an_error_not_an_exception(): void
    {
        $runner = new class implements ProcessRunner {
            public function run(array $command, ?string $cwd = null, array $env = [], int $timeoutSeconds = 600): ProcessResult
            {
                throw new RuntimeException('the runner caught fire');
            }
        };
        $result = (new ComposerAuditScan($runner))->scan('/tmp/app');

        self::assertFalse($result->ok);
        self::assertStringContainsString('The composer audit crashed: the runner caught fire', (string) $result->error);
        self::assertStringContainsString('Run it by hand: composer audit --format=json --no-interaction', (string) $result->error);
    }
}
