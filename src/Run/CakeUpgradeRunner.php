<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

use InvalidArgumentException;
use Waynestate\Booster\Packaging\PharFiles;
use Waynestate\Booster\ToolError;

/**
 * Runs CakePHP's own upgrade tool (cakephp/upgrade) against the app in its
 * own container, with the app mounted at /app. The tool must run as its own
 * app, not inside the upgraded app's vendor, and it pins its own Rector, so
 * its image is built from docker/cakephp-upgrade/Dockerfile once per ref:
 * cakephp/upgrade has no tagged releases, so the commit is pinned here.
 * (Booster does the heavy lifting; the tool only does the CakePHP parts.)
 */
final class CakeUpgradeRunner
{
    /**
     * cakephp/upgrade 5.x at 2026-11-19. Pinned by commit because the project
     * ships no tagged releases; a later slice moves it to data/versions.json.
     */
    public const UPGRADE_REF = '804ce4739f28e9d7fc1f6092e4dfb9a69f683819';

    /** Where the app's own vendor/ is mounted, read-only, for Rector's autoloader. */
    public const VENDOR_MOUNT = '/app/vendor';

    /** Where the opt-in Composer cache (BOOSTER_COMPOSER_CACHE) is mounted: the tool's own downloads, not the app's. */
    public const COMPOSER_CACHE_MOUNT = '/tmp/composer-cache';

    public function __construct(
        private readonly ProcessRunner $processes,
        private readonly string $docker = 'docker',
        private readonly ?CacheMount $composerCache = null,
    ) {}

    /**
     * The image tag for a ref, stamped with the first 12 hex chars of the
     * Dockerfile's sha256 plus the ref, so a changed recipe or a moved ref
     * gets a new tag and an old, cached image is never reused by mistake.
     */
    public static function imageTag(string $ref = self::UPGRADE_REF): string
    {
        $contents = @file_get_contents(self::dockerfilePath());
        if ($contents === false) {
            throw new ToolError(sprintf('Could not read the cakephp-upgrade Dockerfile at %s.', self::dockerfilePath()));
        }

        return 'booster-cakephp-upgrade:' . substr(hash('sha256', $contents . $ref), 0, 12);
    }

    /**
     * The directory that holds the Dockerfile and builds the image. From the
     * phar it is the unpacked temp copy (PharFiles), because `docker build`
     * needs a real directory, not a phar:// path.
     */
    private static function dockerDir(): string
    {
        return PharFiles::path('docker/cakephp-upgrade');
    }

    private static function dockerfilePath(): string
    {
        return self::dockerDir() . '/Dockerfile';
    }

    /**
     * Checks that the image exists, building it once when it does not. A
     * failed build is a stop: the tool cannot run without its image.
     */
    public function ensureImage(): void
    {
        $tag = self::imageTag();
        if ($this->processes->run([$this->docker, 'image', 'inspect', $tag], null, [], 60)->successful()) {
            return;
        }

        $build = $this->processes->run([
            $this->docker, 'build',
            '--build-arg', 'COMPOSER_VERSION=' . TargetImageRunner::COMPOSER_VERSION_CURRENT,
            '--build-arg', 'UPGRADE_REF=' . self::UPGRADE_REF,
            '-t', $tag,
            self::dockerDir(),
        ], null, [], 1800);
        if (!$build->successful()) {
            // BuildKit prints its whole progress log on stderr; the failing step is at the end.
            $lines = preg_split('/\R/', trim($build->stderr . "\n" . $build->stdout)) ?: [];
            $tail = implode("\n", array_slice($lines, -20));
            throw new ToolError(sprintf(
                "Could not build %s (docker build exit %d), so cakephp/upgrade cannot run. Check that Docker is running and the network reaches github.com and packagist.org, or run cakephp/upgrade yourself, see https://github.com/cakephp/upgrade.\n%s",
                $tag,
                $build->exitCode,
                $tail,
            ));
        }
    }

    /**
     * The argument array for `docker run` that applies one ruleset to a
     * folder of the app, exposed so tests can assert on it.
     *
     * Rector needs the app's autoloader to know its classes (and without one
     * cakephp/upgrade exits 1 before running a rule), so the app's own
     * vendor/ is mounted read-only over /app/vendor and named with
     * --autoload. Rector only writes the path it is given (/app/<subdir>,
     * never vendor), and the read-only mount makes sure of it.
     *
     * @return list<string>
     */
    public function rectorCommand(string $appPath, string $ruleSet, string $subdir, string $vendorPath): array
    {
        if (preg_match('/^[a-z0-9_-]+$/', $ruleSet) !== 1) {
            throw new InvalidArgumentException(sprintf('The rule set "%s" is not a valid cakephp/upgrade ruleset name (letters, digits, dashes and underscores only).', $ruleSet));
        }
        if (preg_match('/^[A-Za-z0-9_\/.-]+$/', $subdir) !== 1 || str_starts_with($subdir, '/') || in_array('..', explode('/', $subdir), true)) {
            throw new InvalidArgumentException(sprintf('The subdirectory "%s" is not a safe relative path inside the app.', $subdir));
        }

        return [
            $this->docker, 'run', '--rm',
            '-v', rtrim($appPath, '/\\') . ':/app',
            '-v', rtrim($vendorPath, '/\\') . ':' . self::VENDOR_MOUNT . ':ro',
            ...$this->cacheMountArgs(),
            self::imageTag(),
            'bin/cake', 'upgrade', 'rector', '--rules', $ruleSet, '--autoload', self::VENDOR_MOUNT . '/autoload.php', '/app/' . $subdir,
        ];
    }

    /**
     * The argument array for `docker run` that renames the framework's
     * template or locale files, exposed so tests can assert on it.
     *
     * @return list<string>
     */
    public function fileRenameCommand(string $appPath, string $what): array
    {
        if (!in_array($what, ['templates', 'locales'], true)) {
            throw new InvalidArgumentException(sprintf('The file_rename target "%s" must be "templates" or "locales".', $what));
        }

        return [
            $this->docker, 'run', '--rm',
            '-v', rtrim($appPath, '/\\') . ':/app',
            ...$this->cacheMountArgs(),
            self::imageTag(),
            'bin/cake', 'upgrade', 'file_rename', $what, '/app',
        ];
    }

    /**
     * Runs one of the commands the two builders make. Rector over a big app
     * takes a while, so the limit is generous.
     *
     * @param list<string> $argv
     */
    public function run(array $argv): ProcessResult
    {
        return $this->processes->run($argv, null, [], 3600);
    }

    /**
     * The mount args for the opt-in Composer cache, the same way the gate
     * mounts its own: a volume or a host folder at /tmp/composer-cache, with
     * COMPOSER_CACHE_DIR pointed at it so the tool's composer install reads
     * and fills it.
     *
     * @return list<string>
     */
    private function cacheMountArgs(): array
    {
        if ($this->composerCache === null) {
            return [];
        }

        return [...$this->composerCache->mountArgs(self::COMPOSER_CACHE_MOUNT), '-e', 'COMPOSER_CACHE_DIR=' . self::COMPOSER_CACHE_MOUNT];
    }
}
