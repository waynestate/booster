<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Run\ProctorComparison;
use Waynestate\Booster\Run\ProctorResult;

final class ProctorComparisonTest extends TestCase
{
    /**
     * @return array{check: string, rule: string, message: string, path: ?string, line: int}
     */
    private function failure(string $check, string $rule, string $message, ?string $path = null, int $line = 1): array
    {
        return ['check' => $check, 'rule' => $rule, 'message' => $message, 'path' => $path, 'line' => $line];
    }

    private function proctorResult(string $status, array $failures, ?string $error = null): ProctorResult
    {
        $exitCode = match ($status) {
            ProctorResult::PASSED => 0,
            ProctorResult::FAILED => 1,
            default => 2,
        };

        return new ProctorResult($status, $exitCode, '8.3', $failures, $error);
    }

    public function test_a_finding_only_in_the_final_run_is_new_and_blocks(): void
    {
        $baseline = $this->proctorResult(ProctorResult::PASSED, []);
        $final = $this->proctorResult(ProctorResult::FAILED, [
            $this->failure('lock-committed', 'proctor/lock-committed', 'composer.lock is missing', 'composer.lock'),
        ]);

        $comparison = ProctorComparison::compare($baseline, $final);

        self::assertCount(1, $comparison->new);
        self::assertSame('lock-committed', $comparison->new[0]['check']);
        self::assertSame([], $comparison->alreadyPresent);
        self::assertSame([], $comparison->fixed);
        self::assertTrue($comparison->blocking);
        self::assertTrue($comparison->baselineTaken);
        self::assertNull($comparison->baselineError);
    }

    public function test_a_finding_present_before_and_after_is_already_present_and_does_not_block(): void
    {
        $finding = $this->failure('php-floor', 'proctor/php-floor', 'php floor 7.4 is eol', 'composer.json');
        $baseline = $this->proctorResult(ProctorResult::FAILED, [$finding]);
        $final = $this->proctorResult(ProctorResult::FAILED, [$finding]);

        $comparison = ProctorComparison::compare($baseline, $final);

        self::assertSame([], $comparison->new);
        self::assertCount(1, $comparison->alreadyPresent);
        self::assertSame([], $comparison->fixed);
        self::assertFalse($comparison->blocking);
    }

    public function test_line_numbers_are_ignored_when_matching_findings(): void
    {
        $baseline = $this->proctorResult(ProctorResult::FAILED, [
            $this->failure('env-outside-config', 'proctor/env-outside-config', 'env() is used outside config files', 'src/App.php', 12),
        ]);
        $final = $this->proctorResult(ProctorResult::FAILED, [
            $this->failure('env-outside-config', 'proctor/env-outside-config', 'env() is used outside config files', 'src/App.php', 42),
        ]);

        $comparison = ProctorComparison::compare($baseline, $final);

        self::assertSame([], $comparison->new);
        self::assertCount(1, $comparison->alreadyPresent);
        self::assertSame(42, $comparison->alreadyPresent[0]['line']);
    }

    public function test_the_same_check_in_two_files_counts_as_two_findings(): void
    {
        $baseline = $this->proctorResult(ProctorResult::PASSED, []);
        $final = $this->proctorResult(ProctorResult::FAILED, [
            $this->failure('unbounded-constraints', 'proctor/unbounded-constraints/dev-master', 'require uses dev-master', 'composer.json'),
            $this->failure('unbounded-constraints', 'proctor/unbounded-constraints/dev-master', 'require-dev uses dev-master', 'composer.json'),
        ]);

        $comparison = ProctorComparison::compare($baseline, $final);

        self::assertCount(2, $comparison->new);
        self::assertTrue($comparison->blocking);
    }

    public function test_findings_gone_after_the_hops_are_fixed(): void
    {
        $floor = $this->failure('php-floor', 'proctor/php-floor', 'php floor 7.4 is eol', 'composer.json');
        $lock = $this->failure('lock-committed', 'proctor/lock-committed', 'composer.lock is missing', 'composer.lock');
        $baseline = $this->proctorResult(ProctorResult::FAILED, [$floor, $lock]);
        $final = $this->proctorResult(ProctorResult::PASSED, []);

        $comparison = ProctorComparison::compare($baseline, $final);

        self::assertSame([], $comparison->new);
        self::assertSame([], $comparison->alreadyPresent);
        self::assertCount(2, $comparison->fixed);
        self::assertFalse($comparison->blocking);
        self::assertTrue($comparison->phpFloorFixed());
    }

    public function test_a_mixed_run_splits_into_all_three_groups(): void
    {
        $floor = $this->failure('php-floor', 'proctor/php-floor', 'php floor 7.4 is eol', 'composer.json');
        $lock = $this->failure('lock-committed', 'proctor/lock-committed', 'composer.lock is missing', 'composer.lock');
        $env = $this->failure('env-outside-config', 'proctor/env-outside-config', 'env() is used outside config files', 'src/App.php');
        $baseline = $this->proctorResult(ProctorResult::FAILED, [$floor, $lock]);
        $final = $this->proctorResult(ProctorResult::FAILED, [$lock, $env]);

        $comparison = ProctorComparison::compare($baseline, $final);

        self::assertCount(1, $comparison->new);
        self::assertSame('env-outside-config', $comparison->new[0]['check']);
        self::assertCount(1, $comparison->alreadyPresent);
        self::assertSame('lock-committed', $comparison->alreadyPresent[0]['check']);
        self::assertCount(1, $comparison->fixed);
        self::assertSame('php-floor', $comparison->fixed[0]['check']);
        self::assertTrue($comparison->blocking);
        self::assertTrue($comparison->phpFloorFixed());
    }

    public function test_php_floor_is_not_fixed_while_it_still_fails(): void
    {
        $floor = $this->failure('php-floor', 'proctor/php-floor', 'php floor 7.4 is eol', 'composer.json');
        $baseline = $this->proctorResult(ProctorResult::FAILED, [$floor]);
        $final = $this->proctorResult(ProctorResult::FAILED, [$floor]);

        $comparison = ProctorComparison::compare($baseline, $final);

        self::assertFalse($comparison->phpFloorFixed());
    }

    public function test_a_tripped_baseline_falls_back_to_blocking_on_any_fail(): void
    {
        $lock = $this->failure('lock-committed', 'proctor/lock-committed', 'composer.lock is missing', 'composer.lock');
        $baseline = $this->proctorResult(ProctorResult::TRIPPED, [], 'PROCTOR could not read composer.json');
        $final = $this->proctorResult(ProctorResult::FAILED, [$lock]);

        $comparison = ProctorComparison::compare($baseline, $final);

        self::assertCount(1, $comparison->new);
        self::assertSame([], $comparison->alreadyPresent);
        self::assertSame([], $comparison->fixed);
        self::assertTrue($comparison->blocking);
        self::assertFalse($comparison->baselineTaken);
        self::assertStringContainsString('could not read composer.json', (string) $comparison->baselineError);
    }

    public function test_a_tripped_baseline_with_a_passing_final_run_does_not_block(): void
    {
        $baseline = $this->proctorResult(ProctorResult::TRIPPED, [], 'PROCTOR could not read composer.json');
        $final = $this->proctorResult(ProctorResult::PASSED, []);

        $comparison = ProctorComparison::compare($baseline, $final);

        self::assertFalse($comparison->blocking);
        self::assertFalse($comparison->baselineTaken);
        self::assertNotNull($comparison->baselineError);
    }

    public function test_a_missing_baseline_falls_back_to_blocking_on_any_fail(): void
    {
        $lock = $this->failure('lock-committed', 'proctor/lock-committed', 'composer.lock is missing', 'composer.lock');
        $final = $this->proctorResult(ProctorResult::FAILED, [$lock]);

        $comparison = ProctorComparison::compare(null, $final);

        self::assertCount(1, $comparison->new);
        self::assertTrue($comparison->blocking);
        self::assertFalse($comparison->baselineTaken);
        self::assertSame('no baseline was taken', $comparison->baselineError);
    }

    public function test_a_tripped_final_run_blocks_even_without_new_findings(): void
    {
        $baseline = $this->proctorResult(ProctorResult::PASSED, []);
        $final = $this->proctorResult(ProctorResult::TRIPPED, [], 'PROCTOR could not read composer.json');

        $comparison = ProctorComparison::compare($baseline, $final);

        self::assertTrue($comparison->blocking);
        self::assertSame([], $comparison->new);
        self::assertTrue($comparison->baselineTaken);
    }

    public function test_multi_line_baseline_errors_are_flattened_to_one_line(): void
    {
        $baseline = $this->proctorResult(ProctorResult::TRIPPED, [], "PROCTOR produced no JSON report (exit 255).\nboom");
        $final = $this->proctorResult(ProctorResult::PASSED, []);

        $comparison = ProctorComparison::compare($baseline, $final);

        self::assertStringNotContainsString("\n", (string) $comparison->baselineError);
        self::assertStringContainsString('produced no JSON report', (string) $comparison->baselineError);
    }
}
