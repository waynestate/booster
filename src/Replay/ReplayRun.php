<?php

declare(strict_types=1);

namespace Waynestate\Booster\Replay;

/**
 * One trip through the container: the answers when the app could be served,
 * or the phase that stopped it (Composer, the setup command, the server) and
 * what it printed.
 */
final readonly class ReplayRun
{
    public const PHASE_INSTALL = 'install';

    public const PHASE_SETUP = 'setup';

    public const PHASE_SERVER = 'server';

    public const PHASE_REPLAY = 'replay';

    /**
     * @param list<ReplayAnswer> $answers
     * @param list<string>       $lines     why it failed, when it did
     * @param list<string>       $phpErrors distinct messages PHP logged while serving, with counts
     * @param list<string>       $serverLog the end of the built-in server's log
     */
    public function __construct(
        public bool $served,
        public string $phase,
        public array $answers = [],
        public array $lines = [],
        public array $phpErrors = [],
        public array $serverLog = [],
    ) {}

    /**
     * @param list<string> $lines
     */
    public static function failed(string $phase, array $lines): self
    {
        return new self(false, $phase, [], $lines);
    }
}
