<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run\Strangler;

use Waynestate\Booster\Strangler\Ledger;
use Waynestate\Booster\Strangler\Unit;

/**
 * The strangler's part of a run report (design section 6, "Report"), in
 * text and JSON: the units moved this run with their replay line, the units
 * left (hand ones marked), the refusals with file:line, the MANUAL checklist
 * grouped as in section 4, and the next command.
 */
final class StranglerReport
{
    /** The MANUAL groups, in section 4's order. */
    public const GROUPS = ['sign-in', 'mount', 'route moves', 'finish'];

    public string $status = '';

    public string $step0 = Ledger::STEP0_PENDING;

    /** @var list<array{id: string, state: string, replay: string}> */
    public array $moved = [];

    /** @var list<array{unit: string, file: string, line: int, reason: string}> */
    public array $refusals = [];

    /** @var list<array{unit: string, reason: string}> units that moved but failed before the gate (a composer update) */
    public array $failed = [];

    /** @var list<string> */
    public array $stopSigns = [];

    /** @var array<string, list<string>> */
    public array $manual = [];

    /** @var list<array{id: string, state: string, order?: string}> */
    public array $left = [];

    /** @var array{legacy: int, laravel: int, hand: int, failed: int}|null */
    public ?array $counts = null;

    public ?string $next = null;

    public function __construct(
        public readonly string $phase,
        public readonly string $framework,
    ) {}

    public function withNext(?string $next): self
    {
        $this->next = $next;

        return $this;
    }

    /** @param list<string> $lines */
    public function addManual(string $group, array $lines): void
    {
        foreach ($lines as $line) {
            $line = preg_replace('/^MANUAL:\s*/', '', $line) ?? $line;
            if (!in_array($line, $this->manual[$group] ?? [], true)) {
                $this->manual[$group][] = $line;
            }
        }
    }

    public function fail(string $unit, string $reason): void
    {
        $this->failed[] = ['unit' => $unit, 'reason' => $reason];
    }

    public function refuse(string $unit, string $file, int $line, string $reason): void
    {
        $this->refusals[] = ['unit' => $unit, 'file' => $file, 'line' => $line, 'reason' => $reason];
    }

    /**
     * The units still answered by legacy, from the ledger, in move order.
     *
     * @param list<Unit> $units the units as the legacy app defines them now (for their move order)
     */
    public function ledger(Ledger $ledger, array $units): void
    {
        $order = [];
        foreach ($units as $unit) {
            $order[$unit->id] = $unit->order;
        }
        $this->step0 = $ledger->step0();
        $this->counts = $ledger->counts();
        $this->left = [];
        foreach ($ledger->units() as $entry) {
            if ($entry['state'] !== Ledger::LEGACY && $entry['state'] !== Ledger::FAILED) {
                continue;
            }
            $row = ['id' => $entry['id'], 'state' => $entry['state']];
            if (isset($order[$entry['id']])) {
                $row['order'] = $order[$entry['id']];
            }
            $this->left[] = $row;
        }
    }

    /** @return list<string> */
    public function refusalLines(): array
    {
        $lines = [];
        foreach ($this->refusals as $r) {
            $where = $r['file'] === '' ? '' : ($r['line'] > 0 ? $r['file'] . ':' . $r['line'] . ': ' : $r['file'] . ': ');
            $lines[] = sprintf('Refused %s: %s%s. Port it by hand, then run --hand --route "%s".', $r['unit'], $where, $r['reason'], $r['unit']);
        }

        return $lines;
    }

    /** @return list<string> */
    public function leftLines(): array
    {
        if ($this->counts === null) {
            return [];
        }
        $c = $this->counts;
        $lines = [sprintf(
            'Units: %d on Laravel (%d moved by Booster, %d by hand), %d left in legacy%s.',
            $c[Ledger::LARAVEL] + $c[Ledger::HAND],
            $c[Ledger::LARAVEL],
            $c[Ledger::HAND],
            $c[Ledger::LEGACY] + $c[Ledger::FAILED],
            $c[Ledger::FAILED] > 0 ? sprintf(' (%d failed a gate)', $c[Ledger::FAILED]) : '',
        )];
        $shown = array_slice($this->left, 0, 10);
        foreach ($shown as $row) {
            $lines[] = sprintf('  %s%s', $row['id'], $row['state'] === Ledger::FAILED ? ' (failed)' : (isset($row['order']) ? ' (' . $row['order'] . ')' : ''));
        }
        if (count($this->left) > count($shown)) {
            $lines[] = sprintf('  ... and %d more (all in %s).', count($this->left) - count($shown), Ledger::FILE);
        }

        return $lines;
    }

    /** @return list<string> the MANUAL checklist, grouped */
    public function manualLines(): array
    {
        $lines = [];
        foreach (self::GROUPS as $group) {
            foreach ($this->manual[$group] ?? [] as $line) {
                $lines[] = sprintf('MANUAL (%s): %s', $group, $line);
            }
        }
        foreach ($this->manual as $group => $groupLines) {
            if (in_array($group, self::GROUPS, true)) {
                continue;
            }
            foreach ($groupLines as $line) {
                $lines[] = sprintf('MANUAL (%s): %s', $group, $line);
            }
        }

        return $lines;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $manual = [];
        foreach ([...self::GROUPS, ...array_keys($this->manual)] as $group) {
            if (isset($this->manual[$group]) && !isset($manual[$group])) {
                $manual[$group] = $this->manual[$group];
            }
        }

        return [
            'phase' => $this->phase,
            'framework' => $this->framework,
            'status' => $this->status,
            'step0' => $this->step0,
            'moved' => $this->moved,
            'left' => ['count' => count($this->left), 'units' => $this->left],
            'counts' => $this->counts,
            'refusals' => $this->refusals,
            'failed' => $this->failed,
            'stop_signs' => $this->stopSigns,
            'manual' => $manual === [] ? new \stdClass() : $manual,
            'next' => $this->next,
        ];
    }
}
