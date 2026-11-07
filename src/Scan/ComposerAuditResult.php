<?php

declare(strict_types=1);

namespace Waynestate\Booster\Scan;

/**
 * The outcome of one composer audit. Either ok, with the advisories and
 * abandoned packages Composer reported, or error, when the audit itself could
 * not run (missing composer, crash, unreadable report). Findings never fail a
 * hop; only the audit's own health lives here.
 */
final readonly class ComposerAuditResult
{
    /**
     * @param list<array{package: string, id: string, cve: string, affected_versions: string, title: string}> $advisories
     * @param array<string, string> $abandoned package name to replacement, '' when Composer names none
     */
    private function __construct(
        public bool $ok,
        public ?string $error,
        public array $advisories,
        public array $abandoned,
    ) {}

    /**
     * @param list<array{package: string, id: string, cve: string, affected_versions: string, title: string}> $advisories
     * @param array<string, string> $abandoned
     */
    public static function ok(array $advisories, array $abandoned): self
    {
        return new self(true, null, $advisories, $abandoned);
    }

    public static function error(string $error): self
    {
        return new self(false, $error, [], []);
    }

    /** Whether the audit ran and found anything worth printing. */
    public function hasFindings(): bool
    {
        return $this->ok && ($this->advisories !== [] || $this->abandoned !== []);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        if (!$this->ok) {
            return ['status' => 'error', 'error' => $this->error];
        }

        return [
            'status' => 'ok',
            'advisory_count' => count($this->advisories),
            'abandoned_count' => count($this->abandoned),
            'advisories' => $this->advisories,
            'abandoned' => $this->abandoned,
        ];
    }
}
