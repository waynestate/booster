<?php

declare(strict_types=1);

namespace Waynestate\Booster;

use RuntimeException;

/**
 * A problem with how BOOSTER was used or what it was pointed at (bad option,
 * unreadable policy, malformed composer.json). The CLI turns it into exit 2.
 */
class ToolError extends RuntimeException {}
