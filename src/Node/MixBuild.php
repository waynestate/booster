<?php

declare(strict_types=1);

namespace Waynestate\Booster\Node;

/**
 * What Booster learned from a simple webpack.mix.js: the asset entries in
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
     * with) to the input that produces it. Directory outputs are resolved to
     * the file Mix would actually write, so the map matches real layouts.
     *
     * @return array<string, string>
     */
    public function outputMap(): array
    {
        $map = [];
        foreach ($this->entries as $entry) {
            $map[$entry->resolvedOutput()] = $entry->input;
        }

        return $map;
    }
}
