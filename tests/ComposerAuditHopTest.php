<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Plan\PlanOptions;
use Waynestate\Booster\Plan\Planner;
use Waynestate\Booster\Project\ProjectDetector;
use Waynestate\Booster\Report\TextWriter;
use Waynestate\Booster\Run\ComposerEditor;
use Waynestate\Booster\Run\HopExecutor;
use Waynestate\Booster\Run\ProcessResult;
use Waynestate\Booster\Run\RectorRunner;
use Waynestate\Booster\Run\RunOptions;
use Waynestate\Booster\Run\RunReport;
use Waynestate\Booster\Run\TargetImageRunner;

/**
 * The advisory composer audit at hop level: when it runs, what it shows in
 * the text and JSON reports, and that it never changes the outcome.
 */
final class ComposerAuditHopTest extends TestCase
{
    /** One advisory for guzzle/guzzle, shaped like a real audit report. */
    private const ADVISORY_JSON = <<<'JSON'
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
                    }
                ]
            },
            "abandoned": [],
            "filter": []
        }
        JSON;

    /**
     * @return list<list<string>> the audit commands the run issued, in order
     */
    private function auditRuns(FakeProcessRunner $fake): array
    {
        return array_map(
            static fn(array $c): array => $c['command'],
            array_values(array_filter($fake->calls, static fn(array $c): bool => self::isComposerAudit($c['command']))),
        );
    }

    /**
     * @param callable(string): void|null $modify tweaks the copied example before the plan runs
     */
    private function execute(FakeProcessRunner $fake, RunOptions $options, string $example = 'raw-php', ?PlanOptions $plan = null, ?callable $modify = null): RunReport
    {
        $dir = $this->copyExample($example);
        if ($modify !== null) {
            $modify($dir);
        }
        $project = (new ProjectDetector())->detect($dir);
        $plan = (new Planner($this->policy(), new HopRegistry()))->plan($project, $plan ?? new PlanOptions(fromPhp: '8.1', toPhp: '8.3'), $this->today());
        $executor = new HopExecutor(
            new RectorRunner($fake, '/fake/rector'),
            new ComposerEditor(),
            new TargetImageRunner($fake),
            $fake,
        );

        return $executor->execute($plan, $options);
    }

    public function test_a_hop_that_changed_composer_files_shows_the_audit_in_both_reports(): void
    {
        $fake = $this->fake();
        $fake->auditResponse = new ProcessResult(1, self::ADVISORY_JSON, '');
        $report = $this->execute($fake, new RunOptions());

        // The audit is advisory: both PHP hops still apply and the run passes.
        self::assertSame(['applied', 'applied'], array_map(static fn($r): string => $r->status, $report->results));
        self::assertFalse($report->failed());

        // Each PHP hop rewrote composer.json, so each one audited afterwards.
        self::assertCount(2, $this->auditRuns($fake));

        $text = (new TextWriter())->run($report);
        self::assertStringContainsString('Composer audit after this hop: 1 advisory, 0 abandoned packages (hints, not upgrade blockers).', $text);
        self::assertStringContainsString('guzzle/guzzle: GHSA-27h6-jg56-68r3 (CVE-2021-42378) affects < 6.5.9: Guzzle 4/5/6 SSRF and HTTP Request Smuggling', $text);

        $json = $report->toArray();
        self::assertSame('ok', $json['results'][0]['composer_audit']['status']);
        self::assertSame(1, $json['results'][0]['composer_audit']['advisory_count']);
        self::assertSame('GHSA-27h6-jg56-68r3', $json['results'][0]['composer_audit']['advisories'][0]['id']);
        self::assertSame('CVE-2021-42378', $json['results'][0]['composer_audit']['advisories'][0]['cve']);
        self::assertSame('ok', $json['results'][1]['composer_audit']['status']);
    }

    public function test_an_audit_that_fails_to_start_is_reported_not_fatal(): void
    {
        $fake = $this->fake();
        $fake->auditResponse = new ProcessResult(127, '', 'sh: composer: command not found');
        $report = $this->execute($fake, new RunOptions());

        self::assertSame(['applied', 'applied'], array_map(static fn($r): string => $r->status, $report->results));
        self::assertFalse($report->failed());

        $text = (new TextWriter())->run($report);
        self::assertStringContainsString('Composer audit: could not run', $text);
        self::assertStringContainsString('sh: composer: command not found', $text);
        self::assertStringContainsString('Run it by hand: composer audit --format=json --no-interaction', $text);

        $json = $report->toArray();
        self::assertSame('error', $json['results'][0]['composer_audit']['status']);
        self::assertStringContainsString('exited 127 without a JSON report', (string) $json['results'][0]['composer_audit']['error']);
    }

    public function testDryRunPrintsNoComposerAuditLine(): void
    {
        $fake = $this->fake();
        $fake->auditResponse = new ProcessResult(1, self::ADVISORY_JSON, '');
        $report = $this->execute($fake, new RunOptions(dryRun: true));

        // No hop audits in a dry run, even one whose preview would raise a
        // composer constraint.
        self::assertSame([], $this->auditRuns($fake));
        foreach ($report->results as $result) {
            self::assertNull($result->composerAudit);
            self::assertStringNotContainsString('Composer audit', implode(' ', $result->messages));
        }
        $json = $report->toArray();
        self::assertArrayNotHasKey('composer_audit', $json['results'][0]);
    }

    public function test_a_hop_with_no_composer_changes_does_not_run_the_audit(): void
    {
        // A real run whose hops raise nothing: the app already floors at the
        // target PHP, so no hop changes a composer file and each one says the
        // audit was skipped.
        $fake = $this->fake();
        $fake->auditResponse = new ProcessResult(1, self::ADVISORY_JSON, '');
        $report = $this->execute($fake, new RunOptions(), 'raw-php', null, static function (string $dir): void {
            file_put_contents($dir . '/composer.json', json_encode(['name' => 'example/raw-php', 'require' => ['php' => '^8.3']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        });

        self::assertSame(['applied', 'applied'], array_map(static fn($r): string => $r->status, $report->results));
        self::assertSame([], $this->auditRuns($fake));
        foreach ($report->results as $result) {
            self::assertStringContainsString('Composer audit: skipped (this hop changed no composer file).', implode(' ', $result->messages));
        }
        $json = $report->toArray();
        self::assertArrayNotHasKey('composer_audit', $json['results'][0]);
    }

    public function test_a_clean_audit_says_clean_in_one_line(): void
    {
        $fake = $this->fake();
        $report = $this->execute($fake, new RunOptions());

        // The hops changed composer files, so the audit ran...
        self::assertCount(2, $this->auditRuns($fake));
        // ...and a clean report says so in one line per hop, next to the
        // "skipped" a hop that changed no composer file prints.
        $text = (new TextWriter())->run($report);
        self::assertSame(2, substr_count($text, 'Composer audit: clean (no advisories, no abandoned packages).'));
        $json = $report->toArray();
        self::assertArrayNotHasKey('composer_audit', $json['results'][0]);
    }

    /**
     * A permissive process fake: clean git tree, passing gates, one file
     * changed per Rector run. Audit commands answer from the fake's
     * auditResponse, set by the test.
     */
    private function fake(): FakeProcessRunner
    {
        return new FakeProcessRunner(static function (array $command): ProcessResult {
            if ($command[0] === 'git') {
                return new ProcessResult(0, '', '');
            }
            if ($command[0] === 'docker') {
                return new ProcessResult(0, '', '');
            }
            $files = [['file' => '/tmp/x/src/F0.php', 'diff' => "--- a\n+++ b"]];

            return new ProcessResult(2, (string) json_encode(['file_diffs' => $files, 'errors' => []]), '');
        });
    }
}
