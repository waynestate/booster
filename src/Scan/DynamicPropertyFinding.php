<?php

declare(strict_types=1);

namespace Waynestate\Booster\Scan;

/** One property an app's class creates without declaring it, where it first does. */
final readonly class DynamicPropertyFinding
{
    public function __construct(
        /** Path relative to the app root, forward slashes. */
        public string $file,
        public int $line,
        /** The class, with its namespace. */
        public string $class,
        public string $property,
    ) {}

    /** @return array{file: string, line: int, class: string, property: string} */
    public function toArray(): array
    {
        return ['file' => $this->file, 'line' => $this->line, 'class' => $this->class, 'property' => $this->property];
    }
}
