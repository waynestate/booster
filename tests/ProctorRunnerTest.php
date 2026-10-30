<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Run\ProcessResult;
use Waynestate\Booster\Run\ProctorResult;
use Waynestate\Booster\Run\ProctorRunner;
use Waynestate\Booster\ToolError;

final class ProctorRunnerTest extends TestCase
{
    private function runner(FakeProcessRunner $fake): ProctorRunner
    {
        return new ProctorRunner($fake, '/fake/proctor', 'php');
    }

    public function test_the_command_covers_path_format_policy_date_and_php_runtime(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(0, self::proctorFixture('pass-report.json'), ''));
        $result = $this->runner($fake)->run('/srv/app/', '8.3', '/srv/policy.json', '2026-10-28');

        self::assertSame(['php', '/fake/proctor', 'check', '--path=/srv/app', '--format=json', '--policy=/srv/policy.json', '--today=2026-10-28', '--php-runtime=8.3.0'], $fake->calls[0]['command']);
        self::assertNull($fake->calls[0]['cwd']);
        self::assertSame(ProctorResult::PASSED, $result->status);
    }

    public function test_a_three_part_php_target_is_passed_through(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(0, self::proctorFixture('pass-report.json'), ''));
        $this->runner($fake)->run('/srv/app', '8.4.1', '/srv/policy.json', '2026-10-28');

        self::assertContains('--php-runtime=8.4.1', $fake->calls[0]['command']);
    }

    public function test_an_invalid_php_target_is_a_tool_error(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(0, '', ''));

        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('Invalid PHP target "8"');
        $this->runner($fake)->run('/srv/app', '8', '/srv/policy.json', '2026-10-28');
    }

    public function test_the_bundled_snapshot_uses_boosters_own_policy_copy(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(0, self::proctorFixture('pass-report.json'), ''));
        $this->runner($fake)->run('/srv/app', '8.3', 'bundled snapshot', '2026-10-28');

        self::assertContains('--policy=' . dirname(__DIR__) . '/src/Creed/policy.json', $fake->calls[0]['command']);
    }

    public function test_a_passing_report_is_passed(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(0, self::proctorFixture('pass-report.json'), ''));
        $result = $this->runner($fake)->run('/srv/app', '8.3', '/srv/policy.json', '2026-10-28');

        self::assertSame(ProctorResult::PASSED, $result->status);
        self::assertSame(0, $result->exitCode);
        self::assertSame('8.3', $result->php);
        self::assertSame([], $result->failures);
        self::assertFalse($result->blocking());
    }

    public function test_a_failing_report_carries_the_failing_findings_in_order(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(1, self::proctorFixture('fail-report.json'), ''));
        $result = $this->runner($fake)->run('/srv/app', '8.3', '/srv/policy.json', '2026-10-28');

        self::assertSame(ProctorResult::FAILED, $result->status);
        self::assertSame(1, $result->exitCode);
        self::assertTrue($result->blocking());
        self::assertCount(2, $result->failures);
        self::assertSame('php-floor', $result->failures[0]['check']);
        self::assertSame('php floor 7.4 is eol', $result->failures[0]['message']);
        self::assertSame('composer.json', $result->failures[0]['path']);
        self::assertSame(1, $result->failures[0]['line']);
        self::assertSame('lock-committed', $result->failures[1]['check']);
        self::assertSame('composer.lock is missing', $result->failures[1]['message']);
    }

    public function test_exit_two_is_tripped_with_proctors_words(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(2, '', 'PROCTOR could not read composer.json'));
        $result = $this->runner($fake)->run('/srv/app', '8.3', '/srv/policy.json', '2026-10-28');

        self::assertSame(ProctorResult::TRIPPED, $result->status);
        self::assertSame(2, $result->exitCode);
        self::assertSame([], $result->failures);
        self::assertStringContainsString('could not read composer.json', (string) $result->error);
        self::assertTrue($result->blocking());
    }

    public function test_a_missing_json_report_is_a_tool_error(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(1, 'no json here', 'boom'));

        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('produced no JSON report');
        $this->runner($fake)->run('/srv/app', '8.3', '/srv/policy.json', '2026-10-28');
    }
}
