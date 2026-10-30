<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

/**
 * Splits the final PROCTOR run's failing findings against the baseline that
 * was measured before the first hop, on the PHP the app started on. Legacy
 * apps carry findings that have nothing to do with the upgrade (env() outside
 * config files, dev-master constraints), so only findings that are new after
 * the hops block the run.
 *
 * A finding is identified by (check, rule, path, message). Line numbers stay
 * out of the key because the hops shift lines. When no usable baseline exists
 * (it tripped or could not run at all), the comparison falls back to the old
 * behaviour: every failing finding blocks.
 */
final readonly class ProctorComparison
{
    /**
     * @param list<array{check: string, rule: string, message: string, path: ?string, line: int}> $new findings that appear only after the hops; these block
     * @param list<array{check: string, rule: string, message: string, path: ?string, line: int}> $alreadyPresent findings that were failing before the hops; never block
     * @param list<array{check: string, rule: string, message: string, path: ?string, line: int}> $fixed findings that were failing before the hops and are gone now
     * @param bool $blocking whether the PROCTOR result fails the run when it is not advisory
     * @param bool $baselineTaken whether a usable baseline measurement exists
     * @param string|null $baselineError why the baseline could not be taken, when it was not
     */
    public function __construct(
        public array $new,
        public array $alreadyPresent,
        public array $fixed,
        public bool $blocking,
        public bool $baselineTaken,
        public ?string $baselineError,
    ) {}

    /**
     * Compares the final run against the baseline and decides what blocks.
     */
    public static function compare(?ProctorResult $baseline, ProctorResult $final): self
    {
        if ($baseline === null || $baseline->status === ProctorResult::TRIPPED) {
            // No usable baseline: fall back to blocking on any failure, and
            // say why the baseline could not be taken.
            $error = trim((string) preg_replace('/\s+/u', ' ', (string) $baseline?->error));
            $reason = $baseline === null
                ? 'no baseline was taken'
                : 'the baseline run tripped' . ($error !== '' ? ': ' . $error : '');

            return new self($final->failures, [], [], $final->blocking(), false, $reason);
        }

        $baselineKeys = [];
        foreach ($baseline->failures as $failure) {
            $baselineKeys[self::key($failure)] = true;
        }

        $new = [];
        $alreadyPresent = [];
        $finalKeys = [];
        foreach ($final->failures as $failure) {
            $key = self::key($failure);
            $finalKeys[$key] = true;
            if (isset($baselineKeys[$key])) {
                $alreadyPresent[] = $failure;
            } else {
                $new[] = $failure;
            }
        }

        $fixed = [];
        foreach ($baseline->failures as $failure) {
            if (!isset($finalKeys[self::key($failure)])) {
                $fixed[] = $failure;
            }
        }

        return new self(
            $new,
            $alreadyPresent,
            $fixed,
            $final->status === ProctorResult::TRIPPED || $new !== [],
            true,
            null,
        );
    }

    /** Whether the baseline failed the php-floor check (EOL PHP) and the final run passed it. */
    public function phpFloorFixed(): bool
    {
        foreach ($this->fixed as $failure) {
            if ($failure['check'] === 'php-floor') {
                return true;
            }
        }

        return false;
    }

    /**
     * The identity of a finding: (check, rule, path, message). Line numbers
     * shift during the hops, so they stay out of the key.
     *
     * @param array{check: string, rule: string, message: string, path: ?string, line: int} $failure
     */
    private static function key(array $failure): string
    {
        return implode("\x1f", [$failure['check'], $failure['rule'], $failure['path'] ?? '', $failure['message']]);
    }
}
