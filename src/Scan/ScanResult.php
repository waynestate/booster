<?php

declare(strict_types=1);

namespace Waynestate\Booster\Scan;

/**
 * The outcome of one advisory scan. Either ok, with the findings phpcs
 * reported, or error, when the scan itself could not run (missing phpcs,
 * crash, unreadable report). Findings never fail a hop; only the scan's own
 * health lives here.
 */
final readonly class ScanResult
{
    /**
     * @param list<Finding> $findings
     * @param list<string> $hints
     */
    private function __construct(
        public bool $ok,
        public string $testVersion,
        public ?string $error,
        public array $findings,
        public array $hints = [],
    ) {}

    /**
     * @param list<Finding> $findings
     * @param list<string> $hints
     */
    public static function ok(string $testVersion, array $findings, array $hints = []): self
    {
        return new self(true, $testVersion, null, $findings, $hints);
    }

    public static function error(string $testVersion, string $error): self
    {
        return new self(false, $testVersion, $error, []);
    }

    public function errorCount(): int
    {
        return count(array_filter($this->findings, static fn(Finding $finding): bool => $finding->severity === 'error'));
    }

    public function warningCount(): int
    {
        return count(array_filter($this->findings, static fn(Finding $finding): bool => $finding->severity === 'warning'));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->ok ? 'ok' : 'error',
            'test_version' => $this->testVersion,
            'error' => $this->error,
            'errors' => $this->errorCount(),
            'warnings' => $this->warningCount(),
            'findings' => array_map(static fn(Finding $finding): array => $finding->toArray(), $this->findings),
            'hints' => $this->hints,
        ];
    }
}
