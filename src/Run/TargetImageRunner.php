<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

use Waynestate\Booster\Packaging\PharFiles;
use Waynestate\Booster\ToolError;

/**
 * The gate: runs the app's own test suite inside a container on the target
 * PHP, so a green result means "green on the version the app is moving to".
 * The image comes from docker/Dockerfile (PHP CLI plus Composer), built once
 * per PHP version. Nothing runs on the host and nothing is pushed anywhere.
 *
 * From the phar, docker/ is copied out to a temp directory first (PharFiles),
 * because `docker build` needs a real directory, not a phar:// path.
 */
final class TargetImageRunner
{
    public const IMAGE_PREFIX = 'booster-target:';

    /** Composer 2.3 and later require PHP 7.2.5, so PHP below 7.2 builds get the 2.2 LTS line. */
    public const COMPOSER_VERSION_LEGACY = '2.2';

    /** The recipe default, and what every PHP 7.2 and up build gets. */
    public const COMPOSER_VERSION_CURRENT = '2.10.3';

    /**
     * The --image images found locally or pulled, so each resolved image is
     * checked once per run.
     *
     * @var array<string, true>
     */
    private array $customImagesReady = [];

    public function __construct(
        private readonly ProcessRunner $processes,
        private readonly ?string $dockerDir = null,
        private readonly string $docker = 'docker',
    ) {}

    /**
     * The image tag for a PHP version, stamped with the first 12 hex chars of
     * the Dockerfile's sha256 so a changed recipe gets a new tag and an old,
     * cached booster-target image is never reused by mistake.
     */
    public function imageTag(string $php): string
    {
        return self::IMAGE_PREFIX . $php . '-' . $this->recipeStamp();
    }

    private function recipeStamp(): string
    {
        $dir = $this->dockerDir ?? PharFiles::path('docker');
        $contents = @file_get_contents($dir . '/Dockerfile');
        if ($contents === false) {
            throw new ToolError(sprintf('Could not read the target-image Dockerfile at %s/Dockerfile.', $dir));
        }

        return substr(hash('sha256', $contents), 0, 12);
    }

    /**
     * The shell command run inside the container: install, then test.
     */
    public static function containerScript(string $testCommand, ?string $extraCommand): string
    {
        $script = 'composer install --no-interaction --no-progress --prefer-dist && ' . $testCommand;

        return $extraCommand !== null && $extraCommand !== '' ? $script . ' && ' . $extraCommand : $script;
    }

    /**
     * The argument array for `docker run`, exposed so tests can assert on it.
     *
     * @return list<string>
     */
    public function runCommand(string $appPath, string $php, string $script, ?int $uid = null, ?int $gid = null, ?string $image = null): array
    {
        $command = [$this->docker, 'run', '--rm', '-v', $appPath . ':/app', '-w', '/app', '-e', 'COMPOSER_HOME=/tmp/composer', '-e', 'HOME=/tmp'];
        if ($uid !== null && $gid !== null) {
            // Keep files the container writes owned by the person running Booster.
            array_push($command, '--user', $uid . ':' . $gid);
        }
        array_push($command, $image ?? $this->imageTag($php), 'sh', '-c', $script);

        return $command;
    }

    public function ensureImage(string $php): void
    {
        $tag = $this->imageTag($php);
        if ($this->processes->run([$this->docker, 'image', 'inspect', $tag], null, [], 60)->successful()) {
            return;
        }

        $composer = version_compare($php, '7.2', '<') ? self::COMPOSER_VERSION_LEGACY : self::COMPOSER_VERSION_CURRENT;
        $dir = $this->dockerDir ?? PharFiles::path('docker');
        $build = $this->processes->run([$this->docker, 'build', '--build-arg', 'PHP_VERSION=' . $php, '--build-arg', 'COMPOSER_VERSION=' . $composer, '-t', $tag, $dir], null, [], 1800);
        if (!$build->successful()) {
            throw new ToolError(sprintf("Could not build %s.\n%s", $tag, trim($build->stderr)));
        }
    }

    public function run(string $appPath, string $php, string $testCommand, ?string $extraCommand = null, ?GateImage $image = null): ProcessResult
    {
        $version = $this->processes->run([$this->docker, 'version', '--format', '{{.Server.Version}}'], null, [], 30);
        if (!$version->successful()) {
            throw new ToolError('Docker is not available, so the target-image gate cannot run. Start Docker, or pass --skip-gate and run the tests yourself.');
        }

        $resolved = null;
        if ($image === null) {
            $this->ensureImage($php);
        } else {
            // The option may be a template or a map, so the image is resolved
            // per call from the PHP version of this baseline or gate.
            $resolved = $image->forPhp($php);
            $this->ensureCustomImage($resolved);
        }

        $uid = function_exists('posix_getuid') ? posix_getuid() : null;
        $gid = function_exists('posix_getgid') ? posix_getgid() : null;

        return $this->processes->run(
            $this->runCommand($appPath, $php, self::containerScript($testCommand, $extraCommand), $uid, $gid, $resolved),
            null,
            [],
            3600,
        );
    }

    /**
     * A --image image belongs to the user, so Booster never builds one. Each
     * resolved image must exist locally before its first hop uses it; pull it
     * once if it does not.
     */
    private function ensureCustomImage(string $image): void
    {
        if (isset($this->customImagesReady[$image])) {
            return;
        }
        if (!$this->processes->run([$this->docker, 'image', 'inspect', $image], null, [], 60)->successful()) {
            $pull = $this->processes->run([$this->docker, 'pull', $image], null, [], 1800);
            if (!$pull->successful()) {
                throw new ToolError(sprintf('The gate image %s was not found locally and could not be pulled.', $image));
            }
        }

        $this->customImagesReady[$image] = true;
    }
}
