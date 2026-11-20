<?php

declare(strict_types=1);

namespace App\Http\Controllers\Legacy\Support;

use Closure;

/**
 * A Slim route closure moved by Booster's strangler. App-owned. handler()
 * returns the closure as the legacy routes file wrote it; SlimAdapter binds
 * it to the legacy container, as Slim 4 did, so `$this->get(...)` inside it
 * still works.
 */
abstract class LegacyClosure
{
    abstract public function handler(): Closure;
}
