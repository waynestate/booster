<?php

declare(strict_types=1);

namespace Waynestate\Booster\Strangler;

use Waynestate\Booster\ToolError;

/**
 * The strangler's state (design section 6), booster-strangler.json at the
 * app root:
 *
 *     {"framework": "slim", "mounted_at": "...", "step0": "done",
 *      "units": [{"id": "GET /users", "state": "laravel", "moved_at": "..."}]}
 *
 * Booster never commits, so the ledger rides in each run's diff and is the
 * only memory a later run has: which units moved, which a person ported
 * (hand), which failed their gate. A unit's state is legacy, laravel, hand
 * or failed. The file is written in one stable shape (two-space JSON, units
 * in move order) so a diff of it reads like a changelog of the move. Like a
 * fig strangling its host one branch at a time, except this one keeps notes.
 */
final class Ledger
{
    public const FILE = 'booster-strangler.json';

    public const LEGACY = 'legacy';

    public const LARAVEL = 'laravel';

    public const HAND = 'hand';

    public const FAILED = 'failed';

    public const STEP0_PENDING = 'pending';

    public const STEP0_DONE = 'done';

    private const STATES = [self::LEGACY, self::LARAVEL, self::HAND, self::FAILED];

    /**
     * @param list<array{id: string, state: string, moved_at?: string}> $units
     */
    private function __construct(
        public readonly string $framework,
        public readonly string $mountedAt,
        private string $step0,
        private array $units,
    ) {}

    /**
     * A new ledger right after the mount: every unit still answers from
     * legacy.
     *
     * @param list<Unit> $units in move order
     */
    public static function start(string $framework, string $mountedAt, string $step0, array $units): self
    {
        self::assertFramework($framework);
        self::assertStep0($step0);
        $ledger = new self($framework, $mountedAt, $step0, []);
        $ledger->sync($units);

        return $ledger;
    }

    public static function path(string $appRoot): string
    {
        return rtrim($appRoot, '/\\') . '/' . self::FILE;
    }

    public static function exists(string $appRoot): bool
    {
        return is_file(self::path($appRoot));
    }

    /** The ledger at $appRoot, or null when the app has none (not mounted yet). */
    public static function load(string $appRoot): ?self
    {
        $file = self::path($appRoot);
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            throw new ToolError(sprintf('%s is not valid JSON (%s). It is the strangler\'s state: put it back from git before the next run.', self::FILE, json_last_error_msg()));
        }

        return self::fromArray($data);
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $framework = $data['framework'] ?? null;
        $mountedAt = $data['mounted_at'] ?? null;
        $step0 = $data['step0'] ?? null;
        $units = $data['units'] ?? null;
        if (!is_string($framework) || !is_string($mountedAt) || !is_string($step0) || !is_array($units)) {
            throw new ToolError(sprintf('%s needs "framework", "mounted_at", "step0" and "units".', self::FILE));
        }
        self::assertFramework($framework);
        self::assertStep0($step0);
        $list = [];
        $seen = [];
        foreach ($units as $i => $unit) {
            if (!is_array($unit) || !is_string($unit['id'] ?? null) || !is_string($unit['state'] ?? null)) {
                throw new ToolError(sprintf('%s: units[%s] needs a string "id" and "state".', self::FILE, (string) $i));
            }
            if (!in_array($unit['state'], self::STATES, true)) {
                throw new ToolError(sprintf('%s: unit "%s" has state "%s"; the states are %s.', self::FILE, $unit['id'], $unit['state'], implode(', ', self::STATES)));
            }
            if (isset($seen[$unit['id']])) {
                throw new ToolError(sprintf('%s lists unit "%s" twice.', self::FILE, $unit['id']));
            }
            $seen[$unit['id']] = true;
            $entry = ['id' => $unit['id'], 'state' => $unit['state']];
            if (is_string($unit['moved_at'] ?? null)) {
                $entry['moved_at'] = $unit['moved_at'];
            }
            $list[] = $entry;
        }

        return new self($framework, $mountedAt, $step0, $list);
    }

    public function step0(): string
    {
        return $this->step0;
    }

    public function markStep0Done(): void
    {
        $this->step0 = self::STEP0_DONE;
    }

    /** @return list<array{id: string, state: string, moved_at?: string}> */
    public function units(): array
    {
        return $this->units;
    }

    public function has(string $id): bool
    {
        return $this->state($id) !== null;
    }

    public function state(string $id): ?string
    {
        foreach ($this->units as $unit) {
            if ($unit['id'] === $id) {
                return $unit['state'];
            }
        }

        return null;
    }

    /**
     * Sets one unit's state. Moving to laravel or hand stamps moved_at;
     * going back to legacy or failed drops it.
     */
    public function mark(string $id, string $state, ?string $at = null): void
    {
        if (!in_array($state, self::STATES, true)) {
            throw new ToolError(sprintf('Unknown strangler unit state "%s".', $state));
        }
        foreach ($this->units as $i => $unit) {
            if ($unit['id'] !== $id) {
                continue;
            }
            $entry = ['id' => $id, 'state' => $state];
            if (($state === self::LARAVEL || $state === self::HAND) && $at !== null) {
                $entry['moved_at'] = $at;
            }
            $this->units[$i] = $entry;

            return;
        }
        throw new ToolError(sprintf('%s has no unit "%s".', self::FILE, $id));
    }

    /**
     * Brings the ledger in line with what the legacy half defines now: a
     * unit the legacy app gained since the mount joins as legacy, in move
     * order; a known unit keeps its state. A unit the legacy app no longer
     * defines stays listed while it is laravel or hand (it moved), and leaves
     * while it is legacy or failed (it is gone from both halves). Returns
     * the ids added and dropped, for the report.
     *
     * @param list<Unit> $units in move order
     *
     * @return array{added: list<string>, dropped: list<string>}
     */
    public function sync(array $units): array
    {
        $known = [];
        foreach ($this->units as $unit) {
            $known[$unit['id']] = $unit;
        }
        $current = [];
        foreach ($units as $unit) {
            $current[$unit->id] = true;
        }
        $added = [];
        $dropped = [];
        $next = [];
        foreach ($units as $unit) {
            if (isset($known[$unit->id])) {
                $next[] = $known[$unit->id];
                unset($known[$unit->id]);
            } else {
                $next[] = ['id' => $unit->id, 'state' => self::LEGACY];
                $added[] = $unit->id;
            }
        }
        foreach ($known as $id => $unit) {
            if ($unit['state'] === self::LARAVEL || $unit['state'] === self::HAND) {
                $next[] = $unit;
            } else {
                $dropped[] = $id;
            }
        }
        $this->units = $next;

        return ['added' => $added, 'dropped' => $dropped];
    }

    /**
     * The ids still answered by legacy (legacy or failed), in move order.
     *
     * @return list<string>
     */
    public function left(): array
    {
        $left = [];
        foreach ($this->units as $unit) {
            if ($unit['state'] === self::LEGACY || $unit['state'] === self::FAILED) {
                $left[] = $unit['id'];
            }
        }

        return $left;
    }

    /** @return array{legacy: int, laravel: int, hand: int, failed: int} */
    public function counts(): array
    {
        $states = array_count_values(array_map(static fn(array $unit): string => $unit['state'], $this->units));

        return [
            self::LEGACY => $states[self::LEGACY] ?? 0,
            self::LARAVEL => $states[self::LARAVEL] ?? 0,
            self::HAND => $states[self::HAND] ?? 0,
            self::FAILED => $states[self::FAILED] ?? 0,
        ];
    }

    /** @return array{framework: string, mounted_at: string, step0: string, units: list<array{id: string, state: string, moved_at?: string}>} */
    public function toArray(): array
    {
        return [
            'framework' => $this->framework,
            'mounted_at' => $this->mountedAt,
            'step0' => $this->step0,
            'units' => $this->units,
        ];
    }

    public function encode(): string
    {
        $json = json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        // PHP pretty-prints with four spaces; the app's other JSON files use two.
        $json = (string) preg_replace_callback('/^( +)/m', static fn(array $m): string => str_repeat(' ', intdiv(strlen($m[1]), 2)), $json);

        return $json . "\n";
    }

    public function save(string $appRoot): string
    {
        $file = self::path($appRoot);
        if (file_put_contents($file, $this->encode()) === false) {
            throw new ToolError(sprintf('Could not write %s.', $file));
        }

        return $file;
    }

    private static function assertFramework(string $framework): void
    {
        if ($framework !== 'cakephp' && $framework !== 'slim') {
            throw new ToolError(sprintf('%s: "framework" must be cakephp or slim, not "%s".', self::FILE, $framework));
        }
    }

    private static function assertStep0(string $step0): void
    {
        if ($step0 !== self::STEP0_PENDING && $step0 !== self::STEP0_DONE) {
            throw new ToolError(sprintf('%s: "step0" must be pending or done, not "%s".', self::FILE, $step0));
        }
    }
}
