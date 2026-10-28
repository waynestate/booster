<?php

declare(strict_types=1);

namespace Waynestate\Booster\Policy;

use Waynestate\Booster\ToolError;

/**
 * Thrown when a policy file is unreadable or malformed, or when a version is
 * looked up that a valid policy does not contain.
 */
final class PolicyException extends ToolError {}
