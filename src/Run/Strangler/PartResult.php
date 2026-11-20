<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run\Strangler;

/**
 * What one of the other lanes' parts answered the engine (Step 0's inspect
 * and migrate, the mount, the finish's sign-in move), in one shape the
 * executor branch can report: done, already done, or refused with the stop
 * signs. Each part's own result type maps onto this in StranglerParts.
 */
final readonly class PartResult
{
    /** The part changed files (or, for an inspect, can make its change). */
    public const OK = 'ok';

    /** Nothing to do: the app is already there (Step 0 on an app already on SESAME). */
    public const ALREADY = 'already';

    /** A stop sign: nothing changed. */
    public const REFUSED = 'refused';

    /**
     * @param list<string> $changedFiles app-relative
     * @param list<string> $refusals     stop signs, one line each (file:line first where there is one)
     * @param list<string> $manual       MANUAL lines for the report
     * @param list<string> $lines        what the part did, for the report
     * @param list<string> $replayChanges request paths whose sign-in hand-off the part changed on purpose (SignInResult->replayChanges), which the Step 0 gate accepts as a redirect to sign-in
     */
    public function __construct(
        public string $status,
        public array $changedFiles = [],
        public array $refusals = [],
        public array $manual = [],
        public array $lines = [],
        public array $replayChanges = [],
    ) {}

    /** @param list<string> $refusals */
    public static function refused(array $refusals): self
    {
        return new self(self::REFUSED, [], $refusals);
    }

    public function refusedNow(): bool
    {
        return $this->status === self::REFUSED;
    }
}
