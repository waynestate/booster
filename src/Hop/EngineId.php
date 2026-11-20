<?php

declare(strict_types=1);

namespace Waynestate\Booster\Hop;

/**
 * The engine a framework hop runs through (see FrameworkEngine). The value
 * is what the hop JSON writes under "engine".
 */
enum EngineId: string
{
    case Vendored = 'vendored';
    case CakeUpgrade = 'cake-upgrade';
    case SlimUpgrade = 'slim-upgrade';
}
