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
 *
 * The lock range is the PHP span composer.lock allows: the highest lower bound
 * and the lowest upper bound across the locked packages' require.php
 * constraints, raised by a platform pin if composer.json carries one. The
 * ceiling is exclusive ("8.1" means below 8.1). manifestPhp keeps
 * composer.json's own floor so the warnings can name both sides.
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
        public bool $hasBoost = false,
        public ?string $manifestPhp = null,
        public ?string $lockPhpFloor = null,
        public ?string $lockPhpCeiling = null,
        public ?string $lockFloorPackage = null,
        public ?string $lockFloorConstraint = null,
        public ?string $lockCeilingPackage = null,
        public ?string $lockCeilingConstraint = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = [
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
            'boost' => $this->hasBoost,
        ];
        if ($this->lockPhpFloor !== null || $this->lockPhpCeiling !== null) {
            $array['php_lock_range'] = ['floor' => $this->lockPhpFloor, 'ceiling' => $this->lockPhpCeiling];
        }

        return $array;
    }

    /**
     * The warning to show when composer.json's PHP floor is below what the
     * lock allows: the detected PHP moved up to the lock's floor. Null when
     * the lock does not raise the floor.
     */
    public function lockFloorWarning(): ?string
    {
        if ($this->lockPhpFloor === null || $this->manifestPhp === null) {
            return null;
        }
        if (version_compare($this->manifestPhp, $this->lockPhpFloor, '>=')) {
            return null;
        }

        return sprintf(
            'composer.json allows PHP %s, but the lock needs PHP %s or newer (%s). Planning from PHP %s.',
            $this->manifestPhp,
            $this->lockPhpFloor,
            self::sourceText($this->lockFloorPackage, $this->lockFloorConstraint),
            $this->lockPhpFloor,
        );
    }

    /**
     * The warning to show when the first PHP hop lands at or above the lock's
     * exclusive ceiling, where the lock can no longer install without a
     * composer update. Null when the lock has no ceiling or the hop stays
     * inside it.
     */
    public function lockCeilingWarning(?string $firstTarget): ?string
    {
        if ($this->lockPhpCeiling === null || $firstTarget === null) {
            return null;
        }
        if (version_compare($firstTarget, $this->lockPhpCeiling, '<')) {
            return null;
        }

        return sprintf(
            'The lock also needs PHP below %s (%s); the first PHP hop will need composer update for it.',
            $this->lockPhpCeiling,
            self::sourceText($this->lockCeilingPackage, $this->lockCeilingConstraint),
        );
    }

    private static function sourceText(?string $package, ?string $constraint): string
    {
        if ($package === null || $constraint === null) {
            return 'the locked packages';
        }
        if ($package === 'composer.json') {
            return sprintf('composer.json pins PHP %s as the platform', $constraint);
        }

        return $package . ' requires ' . $constraint;
    }
}
