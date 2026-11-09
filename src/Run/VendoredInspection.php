<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

use Waynestate\Booster\Scan\VendoredLibrary;

/**
 * What the vendored-to-Composer move found in an app, read-only: the
 * hand-copied libraries it knows how to move, the ones it leaves alone, and
 * the stop signs that make it refuse. The planner reads it to place the hop;
 * the hop reads it again before it writes anything.
 */
final readonly class VendoredInspection
{
    /**
     * @param list<VendoredLibrary> $moves     libraries under the app root's vendor/ with a recipe
     * @param list<VendoredLibrary> $leftAlone libraries Booster has no recipe for, or that sit in a nested vendor/
     * @param list<string>          $refusals  stop signs; any one makes the hop refuse and change nothing
     * @param string|null           $floor     the lowest PHP every moved library's Composer release runs on
     */
    public function __construct(
        public array $moves,
        public array $leftAlone,
        public array $refusals,
        public ?string $floor,
    ) {}

    /** True when there is something to move and nothing stops the move. */
    public function movable(): bool
    {
        return $this->moves !== [] && $this->refusals === [];
    }

    /** "Doctrine 2.2.2, Slim 1.6.0 and Smarty 3.1.8", for warnings and messages. */
    public function summary(): string
    {
        $names = array_map(
            static fn(VendoredLibrary $l): string => $l->name . ($l->version !== null ? ' ' . $l->version : ''),
            $this->moves,
        );
        if (count($names) < 2) {
            return implode('', $names);
        }
        $last = array_pop($names);

        return implode(', ', $names) . ' and ' . $last;
    }
}
