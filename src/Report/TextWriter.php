<?php

declare(strict_types=1);

namespace Waynestate\Booster\Report;

use Waynestate\Booster\Plan\Plan;
use Waynestate\Booster\Run\BaselineResult;
use Waynestate\Booster\Run\RunReport;
use Waynestate\Booster\Scan\ScanResult;

/**
 * Plain text for people. Everything printed from an app (paths, diffs) is
 * stripped of control characters first, so a hostile file name cannot move the
 * terminal cursor or rewrite the report.
 */
final class TextWriter
{
    /** How many findings the text report shows before it says "and more". */
    private const MAX_FINDINGS = 20;

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
        if ($plan->targetPhpunit !== null) {
            $lines[] = sprintf('  PHPUnit: %s -> %s', $p->phpunitMajor ?? 'unknown', $plan->targetPhpunit);
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

    /**
     * The advisory PHPCompatibility section that follows a plan: what will
     * break on the first PHP hop's target, or why the scan could not run.
     */
    public function phpCompat(ScanResult $scan): string
    {
        if (!$scan->ok) {
            $lines = ['PHPCompatibility scan: could not run'];
            foreach (explode("\n", self::clean((string) $scan->error)) as $line) {
                $lines[] = '   ' . $line;
            }

            return implode("\n", $lines);
        }

        if ($scan->findings === []) {
            return sprintf('Will break on PHP %s: no findings.', $scan->testVersion);
        }

        $lines = [sprintf(
            'Will break on PHP %s: %d errors, %d warnings.',
            $scan->testVersion,
            $scan->errorCount(),
            $scan->warningCount(),
        )];
        foreach (array_slice($scan->findings, 0, self::MAX_FINDINGS) as $finding) {
            $lines[] = sprintf('   %s:%d [%s] %s', self::clean($finding->file), $finding->line, $finding->severity, self::clean($finding->message));
        }
        $hidden = count($scan->findings) - self::MAX_FINDINGS;
        if ($hidden > 0) {
            $lines[] = sprintf('   (%d more findings...)', $hidden);
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
        $previewedFiles = [];
        foreach ($report->results as $r) {
            $count = $r->incomplete ? '(incomplete)' : sprintf('(%d files)', count($r->files));
            $lines[] = sprintf('%d. %s: %s %s', $r->step, $r->hopId, strtoupper($r->status), $count);
            foreach ($r->messages as $message) {
                foreach (explode("\n", self::clean($message)) as $line) {
                    $lines[] = '   ' . $line;
                }
            }
            if ($report->dryRun) {
                foreach ($r->files as $file) {
                    $previewedFiles[$file['file']] = true;
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
            if ($r->prepass !== []) {
                $lines[] = '   Pre-pass fixes:';
                foreach ($r->prepass as $fix) {
                    $lines[] = sprintf('      %s: %d %s', self::clean($fix['file']), $fix['rewrites'], $fix['rewrites'] === 1 ? 'rewrite' : 'rewrites');
                }
            }
        }
        $lines[] = '';
        if ($report->dryRun && $previewedFiles !== []) {
            $lines[] = sprintf('Unique files across hops: %d. Later hops were previewed against the original tree, so the same file can appear in several hops.', count($previewedFiles));
            $lines[] = '';
        }
        $lines[] = 'RESULT: ' . ($report->failed() ? 'FAIL' : 'PASS');
        if ($report->dryRun && $previewedFiles !== []) {
            $lines[] = 'Dry run: these changes were previewed, none were applied.';
        } elseif (!$report->dryRun && !$report->failed()) {
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
