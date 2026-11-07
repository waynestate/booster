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
    /** The clean composer audit report, what a real audit prints with no findings. */
    public const CLEAN_AUDIT_JSON = '{"advisories": [], "abandoned": [], "filter": []}';

    /** @var list<array{command: list<string>, cwd: ?string, env: array<string, string>}> */
    public array $calls = [];

    /**
     * The canned answer for the advisory composer audit, so a test seeds one
     * report for every audit in the run instead of branching in its handler.
     * Null answers clean: a hop that changed no composer file never reaches
     * it, and a real run without findings stays silent either way.
     */
    public ?ProcessResult $auditResponse = null;

    /**
     * @param callable(list<string>, array<string, string>, ?string): ProcessResult $handler
     */
    public function __construct(private $handler) {}

    public function run(array $command, ?string $cwd = null, array $env = [], int $timeoutSeconds = 600): ProcessResult
    {
        $this->calls[] = ['command' => $command, 'cwd' => $cwd, 'env' => $env];
        if (self::isComposerAudit($command)) {
            return $this->auditResponse ??= new ProcessResult(0, self::CLEAN_AUDIT_JSON, '');
        }

        return ($this->handler)($command, $env, $cwd);
    }

    /** Whether $command is the advisory composer audit (composer audit ...). */
    public static function isComposerAudit(array $command): bool
    {
        return ($command[0] ?? '') === 'composer' && ($command[1] ?? '') === 'audit';
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
