<?php
// src/Strangler/Mover.php
declare(strict_types=1);

namespace Waynestate\Booster\Strangler;

interface Mover
{
    public function framework(): string;

    /** Move one unit to the Laravel half (files only; RouteTable::add is the caller's job after the gate). */
    public function move(Unit $unit, string $appRoot): MoveResult;
}
