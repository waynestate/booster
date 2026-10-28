<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\Process;

final class SymfonyProcessRunner implements ProcessRunner
{
    public function run(array $command, ?string $cwd = null, array $env = [], int $timeoutSeconds = 600): ProcessResult
    {
        $process = new Process($command, $cwd, $env === [] ? null : $env, null, (float) $timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return new ProcessResult(124, $process->getOutput(), sprintf("Timed out after %d seconds.\n", $timeoutSeconds) . $process->getErrorOutput());
        } catch (ProcessRuntimeException $e) {
            // The binary is missing or could not start: report it as a failed command.
            return new ProcessResult(127, '', $e->getMessage());
        }

        return new ProcessResult($process->getExitCode() ?? 1, $process->getOutput(), $process->getErrorOutput());
    }
}
