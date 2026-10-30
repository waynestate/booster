<?php

declare(strict_types=1);

namespace Waynestate\Booster\Project;

/**
 * What Booster learned about an app by reading its manifests.
 *
 * Versions are "major.minor" strings for PHP ("7.4") and plain majors for
 * Laravel, Vue and Node ("10", "2", "24"). A null means the manifest does not
 * say; nodeMajor is the lowest major engines.node allows, null when there is
 * no package.json or no engines.node.
 */
final readonly class Project
{
    public function __construct(
        public string $path,
        public bool $hasComposer,
        public ?string $phpVersion,
        public ?string $laravelMajor,
        public ?string $phpunitMajor,
        public ?string $vueMajor,
        public AppType $type,
        public bool $usesWayneCas,
        public bool $hasTestScript,
        public bool $hasPackageJson = false,
        public ?string $nodeMajor = null,
        public bool $hasMixFile = false,
        public ?string $vueMinor = null,
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
            'phpunit' => $this->phpunitMajor,
            'vue' => $this->vueMajor,
            'node' => $this->nodeMajor,
            'mix_file' => $this->hasMixFile,
            'wayne_cas' => $this->usesWayneCas,
        ];
    }
}
