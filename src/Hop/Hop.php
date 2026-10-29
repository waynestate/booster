<?php

declare(strict_types=1);

namespace Waynestate\Booster\Hop;

/**
 * One upgrade step: one PHP minor, one Laravel major, the CAS-to-SESAME swap,
 * or a manual playbook. A hop is data; the planner orders hops and the runner
 * executes them.
 */
final readonly class Hop
{
    /**
     * @param list<string>          $rectorSets    constant names such as "Rector\Set\ValueObject\SetList::PHP_80"
     * @param array<string, string> $classRenames  old class name to new class name, applied with Rector's RenameClassRector
     * @param array<string, string> $composerSet   package name to the constraint to require
     * @param list<string>          $composerRemove packages to drop from require
     * @param list<string>          $notes         what Rector cannot do; the person reviewing the diff reads these
     * @param list<string>          $links         official migration notes
     * @param array<string, string> $npmSet      package.json dot-path to value, for example "engines.node" to ">=24"
     */
    public function __construct(
        public string $id,
        public HopKind $kind,
        public string $from,
        public string $to,
        public string $summary,
        public array $rectorSets = [],
        public array $classRenames = [],
        public array $composerSet = [],
        public array $composerRemove = [],
        public array $notes = [],
        public array $links = [],
        public bool $extraReview = false,
        public array $npmSet = [],
        public bool $buildGate = false,
    ) {}

    /** A hop with no Rector rules and no manifest edit is a playbook a person follows. */
    public function isManual(): bool
    {
        return $this->rectorSets === [] && $this->classRenames === [] && $this->npmSet === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'from' => $this->from,
            'to' => $this->to,
            'summary' => $this->summary,
            'manual' => $this->isManual(),
            'rector_sets' => $this->rectorSets,
            'notes' => $this->notes,
            'links' => $this->links,
        ];
    }
}
