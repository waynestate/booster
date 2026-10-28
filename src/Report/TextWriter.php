<?php

declare(strict_types=1);

namespace Waynestate\Booster\Report;

use Waynestate\Booster\Plan\Plan;
use Waynestate\Booster\Run\BaselineResult;
use Waynestate\Booster\Run\RunReport;

/**
 * Plain text for people. Everything printed from an app (paths, diffs) is
 * stripped of control characters first, so a hostile file name cannot move the
 * terminal cursor or rewrite the report.
 */
final class TextWriter
{
    public function plan(Plan $plan): string
    {
        $p = $plan->project;
        $lines = [
            sprintf('BOOSTER plan for %s', self::clean($p->path)),
            sprintf('  app type: %s', $p->type->value),
            sprintf('  PHP: %s -> %s', $p->phpVersion ?? 'unknown', $plan->targetPhp ?? '?'),
        ];
        if ($plan->targetLaravel !== null) {
            $lines[] = sprintf('  Laravel: %s -> %s', $p->laravelMajor ?? 'unknown', $plan->targetLaravel);
        }
        $lines[] = sprintf('  policy: %s (today %s)', self::clean($plan->policyOrigin), $plan->today);
        $lines[] = '';

        foreach ($plan->steps as $step) {
            $lines[] = sprintf('%d. %s  [%s]', $step->number, $step->hop->id, implode(', ', $step->gates));
            $lines[] = '   ' . $step->hop->summary;
            if ($step->hop->extraReview) {
                $lines[] = '   Give this hop the most review time.';
            }
        }
        if ($plan->steps === []) {
            $lines[] = '(no steps)';
        }
        foreach ($plan->warnings as $warning) {
            $lines[] = '';
            $lines[] = 'Warning: ' . $warning;
        }

        return implode("\n", $lines);
    }

    public function run(RunReport $report): string
    {
        $lines = [$report->dryRun ? 'BOOSTER dry run (nothing was changed)' : 'BOOSTER run', ''];
        foreach ($this->baselineLines($report->baseline) as $line) {
            $lines[] = $line;
        }
        $lines[] = '';
        foreach ($report->results as $r) {
            $lines[] = sprintf('%d. %s: %s (%d files)', $r->step, $r->hopId, strtoupper($r->status), count($r->files));
            foreach ($r->messages as $message) {
                foreach (explode("\n", self::clean($message)) as $line) {
                    $lines[] = '   ' . $line;
                }
            }
            if ($report->dryRun) {
                foreach ($r->files as $file) {
                    $lines[] = '   --- ' . self::clean($file['file']);
                    foreach (explode("\n", self::clean($file['diff'])) as $line) {
                        $lines[] = '   ' . $line;
                    }
                }
            } else {
                foreach ($r->files as $file) {
                    $lines[] = '   changed ' . self::clean($file['file']);
                }
            }
        }
        $lines[] = '';
        $lines[] = 'RESULT: ' . ($report->failed() ? 'FAIL' : 'PASS');
        if (!$report->dryRun && !$report->failed()) {
            $lines[] = 'Review with `git diff`, run your own checks, then commit. BOOSTER never commits or pushes.';
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function baselineLines(BaselineResult $baseline): array
    {
        $php = $baseline->php ?? '?';
        $lines = match ($baseline->status) {
            BaselineResult::PASSED => [sprintf('Baseline: PASS on PHP %s (tests green before any change).', $php)],
            BaselineResult::FAILED => [
                sprintf('Baseline: FAIL on PHP %s (exit %d).', $php, $baseline->exitCode ?? -1),
                "The app's tests fail before any change; fix them or pass --allow-failing-baseline.",
            ],
            BaselineResult::SKIPPED => ['Baseline: SKIPPED (no gate in this run).'],
            default => [sprintf('Baseline: %s.', $baseline->status)],
        };
        if ($baseline->status === BaselineResult::FAILED) {
            foreach ($baseline->output as $line) {
                $lines[] = '   ' . self::clean($line);
            }
        }

        return $lines;
    }

    /** Drops control characters except newline and tab. */
    public static function clean(string $text): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $text);
    }
}
