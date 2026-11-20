<?php
// src/Strangler/MoveResult.php
declare(strict_types=1);

namespace Waynestate\Booster\Strangler;

final class MoveResult
{
    /**
     * @param list<string>                                       $changedFiles app-relative
     * @param list<array{file: string, line: int, reason: string}> $refusals     non-empty means not moved
     * @param list<string>                                       $manual       MANUAL lines for the report
     */
    public function __construct(
        public readonly bool $moved,
        public readonly array $changedFiles,
        public readonly array $refusals,
        public readonly array $manual,
    ) {
    }
}
