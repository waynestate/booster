<?php

declare(strict_types=1);

namespace Waynestate\Booster\Strangler;

/**
 * A Mover whose last move added packages to the Laravel root's composer.json
 * (a runtime the moved code needs). When the move's changed files include
 * composer.json, the engine runs `composer update -W` with these package
 * names on the Laravel root before the gate; a failed update marks the unit
 * failed with Composer's first error line.
 */
interface AddsComposerPackages
{
    /**
     * The packages the last move() added or changed, package name to
     * constraint; empty when it added none.
     *
     * @return array<string, string>
     */
    public function composerAdds(): array;
}
