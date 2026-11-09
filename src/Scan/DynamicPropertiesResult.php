<?php

declare(strict_types=1);

namespace Waynestate\Booster\Scan;

/**
 * What DynamicPropertiesScan found: the undeclared properties the app's
 * classes create, and the classes it could not check because a parent or a
 * trait lives outside the app's own code.
 */
final readonly class DynamicPropertiesResult
{
    /**
     * @param list<DynamicPropertyFinding> $findings
     * @param list<string>                 $unchecked "Class (Parent, which is not in the app's own code)"
     * @param int                          $classes   how many classes the scan read
     */
    public function __construct(
        public array $findings,
        public array $unchecked,
        public int $classes,
    ) {}

    /** @return array{deprecated_in: string, findings: list<array{file: string, line: int, class: string, property: string}>, not_checked: list<string>, classes: int} */
    public function toArray(): array
    {
        return [
            'deprecated_in' => DynamicPropertiesScan::DEPRECATED_IN,
            'findings' => array_map(static fn(DynamicPropertyFinding $f): array => $f->toArray(), $this->findings),
            'not_checked' => $this->unchecked,
            'classes' => $this->classes,
        ];
    }
}
