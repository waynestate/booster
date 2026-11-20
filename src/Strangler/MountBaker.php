<?php

declare(strict_types=1);

namespace Waynestate\Booster\Strangler;

/**
 * Bakes the Laravel half into an empty folder. The real one runs Sourdough's
 * own CLI (MountSourdough); tests hand the mount a fake that writes a small
 * Laravel-shaped tree.
 */
interface MountBaker
{
    /**
     * @param string $targetDir an empty folder that exists
     * @param string $name      the app name Sourdough records
     */
    public function bake(string $targetDir, string $name): void;

    /** One line for the report: what baked the Laravel half. */
    public function describe(): string;
}
