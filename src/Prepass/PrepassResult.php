<?php

declare(strict_types=1);

namespace Waynestate\Booster\Prepass;

/**
 * What one pre-pass run rewrote: each file, how many times it was rewritten,
 * and the original bytes of every written file so a dry run can put them back.
 */
final readonly class PrepassResult
{
    /**
     * @param list<array{file: string, rewrites: int, original: ?string, absolute: string}> $entries
     */
    public function __construct(private array $entries) {}

    /**
     * @return list<array{file: string, rewrites: int}>
     */
    public function files(): array
    {
        $out = [];
        foreach ($this->entries as $entry) {
            $out[] = ['file' => $entry['file'], 'rewrites' => $entry['rewrites']];
        }

        return $out;
    }

    /** How many files the pre-pass wrote. */
    public function changedCount(): int
    {
        return count($this->entries);
    }

    /** Puts the original bytes back over every file the pre-pass wrote. */
    public function restore(): void
    {
        foreach ($this->entries as $entry) {
            if ($entry['original'] !== null) {
                file_put_contents($entry['absolute'], $entry['original']);
            }
        }
    }
}
