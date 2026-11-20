<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run\Strangler;

use Waynestate\Booster\Strangler\Mover;
use Waynestate\Booster\Strangler\Unit;
use Waynestate\Booster\Strangler\UnitSource;
use Waynestate\Booster\ToolError;

/**
 * The strangler parts in a build that does not carry them yet: every part
 * refuses (or throws, for the ones that return a class), so the engine stops
 * at a stop sign and nothing changes. The merged build swaps in the parts
 * that call SignInStep, Mount, RouteTable and the framework lanes' units and
 * movers (design section 6, "the other lanes' classes through the
 * signatures in the brief").
 */
final class UnwiredStranglerParts implements StranglerParts
{
    private const WHY = 'This build of Booster does not carry the strangler\'s %s yet.';

    public function replayUnusable(string $appRoot, ?array $answers, ?string $file): ?string
    {
        return null;
    }

    public function signInInspect(string $appRoot, string $framework): PartResult
    {
        return PartResult::refused([sprintf(self::WHY, 'sign-in step (Step 0)')]);
    }

    public function signInMigrate(string $appRoot, string $framework): PartResult
    {
        return PartResult::refused([sprintf(self::WHY, 'sign-in step (Step 0)')]);
    }

    public function mount(string $appRoot, string $framework): PartResult
    {
        return PartResult::refused([sprintf(self::WHY, 'mount')]);
    }

    public function signInFinish(string $appRoot, string $framework): PartResult
    {
        return PartResult::refused([sprintf(self::WHY, 'sign-in finish')]);
    }

    public function unitSource(string $framework): UnitSource
    {
        throw new ToolError(sprintf(self::WHY, $framework . ' unit source'));
    }

    public function mover(string $framework): Mover
    {
        throw new ToolError(sprintf(self::WHY, $framework . ' mover'));
    }

    public function routeAdd(string $appRoot, Unit $unit): void
    {
        throw new ToolError(sprintf(self::WHY, 'route table'));
    }

    public function routeRemove(string $appRoot, string $unitId): void
    {
        throw new ToolError(sprintf(self::WHY, 'route table'));
    }

    public function routeHas(string $appRoot, string $unitId): bool
    {
        return false;
    }
}
