<?php

declare(strict_types=1);

namespace Waynestate\Booster\Project;

/**
 * What BOOSTER learned about an app by reading its manifests.
 *
 * Versions are "major.minor" strings for PHP ("7.4") and plain majors for
 * Laravel and Vue ("10", "2"). A null means the manifest does not say.
 */
final readonly class Project
{
    public function __construct(
        public string $path,
        public bool $hasComposer,
        public ?string $phpVersion,
        public ?string $laravelMajor,
        public ?string $vueMajor,
        public AppType $type,
        public bool $usesWayneCas,
        public bool $hasTestScript,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'app_type' => $this->type->value,
            'composer_json' => $this->hasComposer,
            'php' => $this->phpVersion,
            'laravel' => $this->laravelMajor,
            'vue' => $this->vueMajor,
            'wayne_cas' => $this->usesWayneCas,
        ];
    }
}
