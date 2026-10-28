<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

/**
 * Runs one subprocess from an argument array (never a shell string), so a
 * path with spaces or quotes cannot change the command. A fake replaces it in
 * tests.
 */
interface ProcessRunner
{
    /**
     * @param list<string>          $command
     * @param array<string, string> $env     extra environment variables
     */
    public function run(array $command, ?string $cwd = null, array $env = [], int $timeoutSeconds = 600): ProcessResult;
}
