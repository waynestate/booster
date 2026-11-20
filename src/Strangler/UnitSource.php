<?php

// src/Strangler/UnitSource.php
declare(strict_types=1);

namespace Waynestate\Booster\Strangler;

interface UnitSource
{
    /** 'slim' or 'cakephp' */
    public function framework(): string;

    /** @return list<Unit> every legacy unit, in move order, read from the app under $appRoot/legacy */
    public function units(string $appRoot): array;
}
