<?php

declare(strict_types=1);

namespace Waynestate\Booster\Scan;

/**
 * One use of a removed function or construct, at one line of one file.
 */
final readonly class RemovalFinding
{
    public function __construct(
        /** Path relative to the scanned app, forward slashes. */
        public string $file,
        public int $line,
        /** The removals.json entry id, e.g. mysql-functions. */
        public string $id,
        public string $label,
        public string $replacement,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'line' => $this->line,
            'id' => $this->id,
            'label' => $this->label,
            'replacement' => $this->replacement,
        ];
    }
}
