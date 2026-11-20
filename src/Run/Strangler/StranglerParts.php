<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run\Strangler;

use Waynestate\Booster\Strangler\Mover;
use Waynestate\Booster\Strangler\Unit;
use Waynestate\Booster\Strangler\UnitSource;

/**
 * The seam between the strangler engine (HopExecutor's strangler branch)
 * and the parts the other lanes own, one method per contract signature in
 * the 1.21.0 brief:
 *
 *  - REPLAY: ReplayFile::usableForStrangler(appRoot, answers, file): ?string
 *  - SIGNIN: SignInStep::inspect() and ::migrate(), called twice: Step 0
 *    before the mount, then the Laravel half's wiring right after it; and
 *    SignInStep::finish(appRoot, framework): SignInResult, the finish
 *    step's move of /login to Laravel (signInFinish)
 *  - MOUNT:  Mount::run() and RouteTable::load()->add/remove/has/save
 *  - SLIM and CAKE: a UnitSource and a Mover per framework
 *
 * The engine only ever talks to this interface, so its tests use doubles and
 * the real wiring (LaneStranglerParts) is one class that maps each part's own
 * result type onto PartResult.
 */
interface StranglerParts
{
    /**
     * Why the replay cannot gate a strangler, or null when it can
     * (ReplayFile::usableForStrangler): no request file, one that does not
     * parse or has todo items, or, given this run's baseline answers, one
     * whose every answer is the same sign-in redirect or an error.
     *
     * @param list<\Waynestate\Booster\Replay\ReplayAnswer>|null $answers the baseline answers this run recorded
     */
    public function replayUnusable(string $appRoot, ?array $answers, ?string $file): ?string;

    /** Step 0's look before it changes anything: OK (it can move sign-in onto SESAME), ALREADY (on SESAME already) or REFUSED (a stop sign such as LTI). */
    public function signInInspect(string $appRoot, string $framework): PartResult;

    /** Step 0: the legacy half signs in through SESAME. */
    public function signInMigrate(string $appRoot, string $framework): PartResult;

    /** The mount: Laravel at the root, the old Composer root in legacy/, the dispatcher, the empty route table, the replay serve rewrite. */
    public function mount(string $appRoot, string $framework): PartResult;

    /** The finish step's sign-in move: /login and /logout go to Laravel (out of /_laravel/). */
    public function signInFinish(string $appRoot, string $framework): PartResult;

    public function unitSource(string $framework): UnitSource;

    public function mover(string $framework): Mover;

    /** RouteTable::load($appRoot), ->add($unit), ->save(). */
    public function routeAdd(string $appRoot, Unit $unit): void;

    /** RouteTable::load($appRoot), ->remove($unitId), ->save(). */
    public function routeRemove(string $appRoot, string $unitId): void;

    public function routeHas(string $appRoot, string $unitId): bool;
}
