<?php

declare(strict_types=1);

namespace Waynestate\Booster\Scan;

/**
 * One thing PHPCompatibility flagged: a removed or deprecated construct at a
 * known line. The message comes straight from the sniff, so it says what to
 * use instead.
 */
final readonly class Finding
{
    public function __construct(
        /** Path relative to the scanned app, forward slashes. */
        public string $file,
        public int $line,
        public string $message,
        /** 'error' (removed in the target version) or 'warning' (deprecated). */
        public string $severity,
        /** The sniff code, e.g. PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved. */
        public string $source,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'line' => $this->line,
            'message' => $this->message,
            'severity' => $this->severity,
            'source' => $this->source,
        ];
    }
}
