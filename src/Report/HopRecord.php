<?php

declare(strict_types=1);

namespace Waynestate\Booster\Report;

use Waynestate\Booster\Console\BoosterApplication;
use Waynestate\Booster\Run\RunReport;
use Waynestate\Booster\Run\StepResult;
use Waynestate\Booster\ToolError;

/**
 * The hop record: the slim machine-readable summary of one real `booster run`,
 * written to booster-hop.json at the app root for ROLLCALL to read from the app
 * repo. Real runs only: a dry run changes nothing, so it writes no record.
 * Booster never commits the file; the developer commits it with the hop.
 */
final class HopRecord
{
    /** The file name the record is written under, at the app root. */
    public const FILE_NAME = 'booster-hop.json';

    /** The rule packages whose installed versions the record carries. */
    public const RULE_PACKAGES = ['rector/rector', 'driftingly/rector-laravel'];

    /**
     * Builds the record with its keys in their fixed order. An empty map is
     * encoded as a JSON object, not an array.
     *
     * @param array<string, string> $rulePackages installed rule-package versions, by package name
     *
     * @return array<string, mixed>
     */
    public static function build(RunReport $report, string $startedAt, string $finishedAt, array $rulePackages): array
    {
        $plan = $report->plan;
        $hops = [];
        foreach ($report->results as $result) {
            $hops[] = [
                'step' => $result->step,
                'hop' => $result->hopId,
                'status' => $result->status,
                'rules' => (object) $result->rules,
            ];
        }

        $record = [
            'tool' => 'booster',
            'version' => BoosterApplication::VERSION,
            'dry_run' => $report->dryRun,
            'result' => $report->failed() ? 'fail' : 'pass',
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'php_from' => $plan->fromPhp ?? $plan->project->phpVersion,
            'php_to' => $plan->targetPhp,
            'laravel_from' => $plan->project->laravelMajor,
            'laravel_to' => $plan->targetLaravel,
            'rule_packages' => (object) $rulePackages,
            'hops' => $hops,
        ];
        // A rerun after a stopped hop says how that hop's gate went this
        // time, so a run after a red re-gate knows to re-gate it again.
        if ($report->regate !== null) {
            $record['regate'] = [
                'hop' => $report->regate->hopId,
                'status' => $report->regate->passed ? 'passed' : StepResult::GATE_FAILED,
            ];
        }

        return $record;
    }

    /**
     * Writes the record to booster-hop.json at the app root, overwriting any
     * earlier one, and returns the file name.
     *
     * @param array<string, mixed> $record
     */
    public static function write(string $appPath, array $record): string
    {
        $json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $file = rtrim($appPath, '/\\') . '/' . self::FILE_NAME;
        if (@file_put_contents($file, $json) === false) {
            throw new ToolError(sprintf('Could not write the hop record to "%s".', $file));
        }

        return self::FILE_NAME;
    }
}
