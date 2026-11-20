<?php

declare(strict_types=1);

namespace Waynestate\Booster\Strangler;

/**
 * What SignInStep::migrate() did. Non-empty failures means nothing was written.
 *
 * `step0Done` is what ENGINE records as the ledger's `step0: done`.
 * `replayChanges` are the request paths whose answer changed on purpose (the
 * sign-in hand-off now goes through SESAME), for ReplayGate's `signInChanges`.
 * `replayEnv` holds the fake settings migrate() wrote into the replay file's
 * `serve.env` (booster-replay.json), so the gate after it runs with the serve
 * change named.
 */
final class SignInResult
{
    /**
     * @param list<string>          $changedFiles  app-relative
     * @param list<string>          $changes       human-readable, one per change
     * @param list<string>          $manual        MANUAL lines for the report
     * @param list<string>          $failures      non-empty means nothing was written
     * @param list<string>          $replayChanges request paths whose answer changed on purpose
     * @param array<string, string> $replayEnv     serve.env entries written (fake values only)
     */
    public function __construct(
        public readonly bool $step0Done,
        public readonly bool $laravelWired,
        public readonly array $changedFiles,
        public readonly array $changes,
        public readonly array $manual,
        public readonly array $failures,
        public readonly array $replayChanges = [],
        public readonly array $replayEnv = [],
    ) {}
}
