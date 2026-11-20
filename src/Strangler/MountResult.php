<?php

declare(strict_types=1);

namespace Waynestate\Booster\Strangler;

/**
 * What the mount step did: the dual-root layout, the dispatcher, the empty
 * route table, the install hook and the replay file's new serve block. A
 * non-empty $refusals means nothing was changed.
 */
final readonly class MountResult
{
    /**
     * @param list<string>                                        $changedFiles  app-relative; `legacy/` stands for the moved Composer root
     * @param list<array{file: string, line: int, reason: string}> $refusals     non-empty means not mounted (nothing changed)
     * @param list<string>                                        $manual        MANUAL lines for the report, the Citadel line first
     * @param list<string>                                        $notes         what the report says about the mount, not a to-do
     * @param list<string>                                        $ignoreCookies the cookie names the replay should leave out of the compared names
     */
    public function __construct(
        public bool $mounted,
        public array $changedFiles,
        public array $refusals,
        public array $manual,
        public array $notes = [],
        public string $composerRoot = '.',
        public string $legacyDocroot = '',
        public string $oldPublicDir = '',
        public array $ignoreCookies = [],
        public ?string $replayFile = null,
    ) {}

    /**
     * @param list<array{file: string, line: int, reason: string}> $refusals
     */
    public static function refused(array $refusals): self
    {
        return new self(false, [], $refusals, []);
    }
}
