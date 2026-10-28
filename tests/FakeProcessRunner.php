<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Run\ProcessResult;
use Waynestate\Booster\Run\ProcessRunner;

/**
 * Records every command and answers from a handler, so no test starts Docker,
 * git or Rector.
 */
final class FakeProcessRunner implements ProcessRunner
{
    /** @var list<array{command: list<string>, cwd: ?string, env: array<string, string>}> */
    public array $calls = [];

    /**
     * @param callable(list<string>, array<string, string>, ?string): ProcessResult $handler
     */
    public function __construct(private $handler) {}

    public function run(array $command, ?string $cwd = null, array $env = [], int $timeoutSeconds = 600): ProcessResult
    {
        $this->calls[] = ['command' => $command, 'cwd' => $cwd, 'env' => $env];

        return ($this->handler)($command, $env, $cwd);
    }

    /**
     * @return list<list<string>> the commands whose first word (after any path) contains $needle
     */
    public function commandsContaining(string $needle): array
    {
        $out = [];
        foreach ($this->calls as $call) {
            if (in_array($needle, $call['command'], true)) {
                $out[] = $call['command'];
            }
        }

        return $out;
    }
}
