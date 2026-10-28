<?php

declare(strict_types=1);

namespace Waynestate\Booster\Policy;

/**
 * The four support states a component version can be in on a given date.
 */
final class State
{
    public const ACTIVE = 'active';

    public const SECURITY = 'security';

    public const EOL = 'eol';

    public const EOL_GRACE_EXPIRED = 'eol_grace_expired';

    private function __construct() {}
}
