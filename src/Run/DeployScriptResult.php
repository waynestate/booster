<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

/**
 * What a real run says about the app's next deploy: the deployments/ script
 * it wrote (or null), and the report lines that explain the call.
 */
final readonly class DeployScriptResult
{
    /**
     * @param list<string> $lines
     */
    public function __construct(
        public ?string $script,
        public array $lines,
    ) {}
}
