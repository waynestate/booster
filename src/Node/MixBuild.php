<?php

declare(strict_types=1);

namespace Waynestate\Booster\Node;

/**
 * What BOOSTER learned from a simple webpack.mix.js: the asset entries in
 * file order and whether the front end compiles Vue components.
 */
final readonly class MixBuild
{
    /**
     * @param list<MixEntry> $entries
     */
    public function __construct(
        public array $entries,
        public bool $vue,
    ) {}

    /**
     * Every input path, in file order. These become the Vite inputs.
     *
     * @return list<string>
     */
    public function inputs(): array
    {
        return array_map(static fn(MixEntry $entry): string => $entry->input, $this->entries);
    }

    /**
     * Output path relative to public/ (what Blade's mix() helper is called
     * with) to the input that produces it.
     *
     * @return array<string, string>
     */
    public function outputMap(): array
    {
        $map = [];
        foreach ($this->entries as $entry) {
            $relative = preg_replace('#^public/#', '', $entry->output) ?? $entry->output;
            $map[$relative] = $entry->input;
        }

        return $map;
    }
}
