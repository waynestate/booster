<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

use Waynestate\Booster\Report\HopRecord;

/**
 * The hop that stopped the last real run at its gate, read back from the hop
 * record that run left at the app root (booster-hop.json).
 *
 * A stopped hop keeps its edits, and Booster plans from the app as it is, so
 * on the rerun the hop has either left the plan (its target is reached) or
 * comes back with nothing to edit. Without this, both read as "done" and the
 * gate that went red was never run green. Houston, we have a re-gate.
 */
final readonly class StoppedHop
{
    public function __construct(
        public string $hopId,
        public int $step,
    ) {}

    /**
     * The stopped hop, or null when there is no record, the record is a dry
     * run or a pass, or no hop stopped at its gate. A record whose own
     * re-gate went red names that hop, so a third run re-gates it again.
     * Only a red gate counts: a Rector error or a capped hop changed
     * nothing, so the rerun simply runs that hop again.
     */
    public static function read(string $path): ?self
    {
        $file = rtrim($path, '/\\') . '/' . HopRecord::FILE_NAME;
        if (!is_file($file)) {
            return null;
        }
        $raw = file_get_contents($file);
        $record = $raw === false ? null : json_decode($raw, true);
        if (!is_array($record) || ($record['dry_run'] ?? false) !== false || ($record['result'] ?? null) !== 'fail') {
            return null;
        }
        $regate = $record['regate'] ?? null;
        if (is_array($regate) && ($regate['status'] ?? null) === StepResult::GATE_FAILED && is_string($regate['hop'] ?? null) && $regate['hop'] !== '') {
            return new self($regate['hop'], 0);
        }
        foreach (is_array($record['hops'] ?? null) ? $record['hops'] : [] as $hop) {
            if (is_array($hop) && ($hop['status'] ?? null) === StepResult::GATE_FAILED && is_string($hop['hop'] ?? null) && $hop['hop'] !== '') {
                return new self($hop['hop'], is_int($hop['step'] ?? null) ? $hop['step'] : 0);
            }
        }

        return null;
    }
}
