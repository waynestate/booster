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

    /** The update list that means "refresh the lock only" (composer update --lock). */
    public const LOCK_REFRESH = '--lock';

    /** Composer 2.3 and later require PHP 7.2.5, so PHP below 7.2 builds get the 2.2 LTS line. */
    public const COMPOSER_VERSION_LEGACY = '2.2';

    /** The recipe default, and what every PHP 7.2 and up build gets (pinned by its multi-arch list digest). */
    public const COMPOSER_VERSION_CURRENT = '2.11.1@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0';

    /**
     * The --image images found locally or pulled, so each resolved image is
     * checked once per run.
     *
     * @var array<string, true>
     */
    private array $customImagesReady = [];

    /** Where the opt-in Composer cache (BOOSTER_COMPOSER_CACHE) is mounted in the gate container. */
    public const COMPOSER_CACHE_MOUNT = '/tmp/composer-cache';

    /**
     * The per-user folders already made inside a cache volume this run, so
     * the one-off root container runs once per volume and user, not per gate.
     *
     * @var array<string, true>
     */
    private array $cacheDirsReady = [];

    public function __construct(
        private readonly ProcessRunner $processes,
        private readonly ?string $dockerDir = null,
        private readonly string $docker = 'docker',
        private readonly ?CacheMount $composerCache = null,
    ) {}

    /**
     * COMPOSER_CACHE_DIR inside the container. A host folder belongs to the
     * person running Booster, and so does the container user, so the mount
     * root works as it is. A Docker volume belongs to root, and on Linux and
     * macOS the gate runs as the person's own uid, which may not write there
     * (Composer then carries on without a cache and says nothing). So a
     * non-root user gets a folder of its own inside the volume; whatever
     * else the volume holds (a shared stack cache) is left as it is.
     */
    public function composerCacheDir(?int $uid): string
    {
        if ($this->composerCache !== null && $this->composerCache->volume && $uid !== null && $uid !== 0) {
            return self::COMPOSER_CACHE_MOUNT . '/booster-uid-' . $uid;
        }

        return self::COMPOSER_CACHE_MOUNT;
    }

    /**
     * The one-off root container that makes the user's folder inside a cache
     * volume and hands it to that user, or null when none is needed (no
     * cache, a host folder, or a root or unknown user). Exposed so tests can
     * assert on it.
     *
     * @return list<string>|null
     */
    public function cacheDirCommand(string $image, ?int $uid, ?int $gid): ?array
    {
        $dir = $this->composerCacheDir($uid);
        if ($this->composerCache === null || $dir === self::COMPOSER_CACHE_MOUNT || $gid === null) {
            return null;
        }

        return [
            $this->docker, 'run', '--rm', '--user', '0:0',
            ...$this->composerCache->mountArgs(self::COMPOSER_CACHE_MOUNT),
            $image, 'sh', '-c', sprintf('mkdir -p %1$s && chown %2$d:%3$d %1$s', $dir, (int) $uid, $gid),
        ];
    }

    /**
     * Makes the user's folder in the cache volume, once per run. A failure is
     * not fatal: Composer then runs without the cache, as it did before the
     * cache existed, and the gate's own result still stands.
     */
    public function ensureCacheDir(string $image, ?int $uid, ?int $gid): void
    {
        $command = $this->cacheDirCommand($image, $uid, $gid);
        if ($command === null) {
            return;
        }
        $key = $image . "\n" . $this->composerCacheDir($uid);
        if (isset($this->cacheDirsReady[$key])) {
            return;
        }
        $this->processes->run($command, null, [], 120);
        $this->cacheDirsReady[$key] = true;
    }

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
     * The shell command run inside the container: Composer (skipped when the
     * app has no composer.json), then test. The two phases report separately: each writes its output to a log file, and on
     * failure the script prints the last 30 lines of that phase's log plus
     * marker lines (BOOSTER_GATE_PHASE, BOOSTER_GATE_EXIT) that
     * gateOutcome() reads back. A stale lock (Composer exit 4) is therefore
     * reported as a Composer failure, never as the test command exiting 4.
     *
     * With $updatePackages the Composer phase is `composer update -W` for
     * those packages instead of a plain install: Laravel and PHPUnit hops
     * raise a package requirement in composer.json, so a plain install would
     * hold the old lock (or fail on its stale content hash). The app is
     * mounted at /app, so the refreshed lock lands in the app itself.
     *
     * $updatePackages === [self::LOCK_REFRESH] runs `composer update --lock`
     * instead: no package moves, but the lock's content hash and its
     * platform-overrides catch up with composer.json (a PHP hop that moved
     * config.platform.php needs exactly that; see HopExecutor).
     *
     * @param list<string> $updatePackages
     */
    public static function containerScript(string $testCommand, ?string $extraCommand, array $updatePackages = []): string
    {
        $composerPhase = match (true) {
            $updatePackages === [] => 'composer install --no-interaction --no-progress --prefer-dist',
            $updatePackages === [self::LOCK_REFRESH] => 'composer update --lock --no-interaction --no-progress --prefer-dist',
            default => sprintf('composer update -W --no-interaction --no-progress %s', implode(' ', $updatePackages)),
        };
        $lines = [
            'install_log=/tmp/booster-gate-install.log',
            // An app with no composer.json (a 2012 app that hand-copied its
            // libraries) has nothing to install, and Composer would fail on
            // the missing file before the syntax check ever ran.
            'install_exit=0',
            'if [ -f composer.json ]; then',
            '  ' . $composerPhase . ' >"$install_log" 2>&1',
            '  install_exit=$?',
            'fi',
            'if [ "$install_exit" -ne 0 ]; then',
            '  tail -n 30 "$install_log"',
            '  echo "BOOSTER_GATE_PHASE=install"',
            '  echo "BOOSTER_GATE_EXIT=$install_exit"',
            '  exit "$install_exit"',
            'fi',
            'tests_log=/tmp/booster-gate-tests.log',
            $testCommand . ' >"$tests_log" 2>&1',
            'test_exit=$?',
        ];
        if ($extraCommand !== null && $extraCommand !== '') {
            $lines[] = 'if [ "$test_exit" -eq 0 ]; then';
            $lines[] = $extraCommand . ' >>"$tests_log" 2>&1';
            $lines[] = 'extra_exit=$?';
            $lines[] = 'if [ "$extra_exit" -ne 0 ]; then test_exit=$extra_exit; fi';
            $lines[] = 'fi';
        }
        $lines[] = 'if [ "$test_exit" -ne 0 ]; then';
        $lines[] = '  tail -n 30 "$tests_log"';
        $lines[] = '  echo "BOOSTER_GATE_PHASE=tests"';
        $lines[] = '  echo "BOOSTER_GATE_EXIT=$test_exit"';
        $lines[] = '  exit "$test_exit"';
        $lines[] = 'fi';
        $lines[] = 'echo "BOOSTER_GATE_PHASE=tests"';
        $lines[] = 'exit 0';

        return implode("\n", $lines);
    }

    /**
     * Reads the phase markers out of one gate run's output. A run whose output
     * carries no markers (a custom image that ignores the script) is read as
     * the tests phase with the process's own exit code.
     */
    public static function gateOutcome(ProcessResult $result): GateOutcome
    {
        $phase = GateOutcome::PHASE_TESTS;
        $exitCode = $result->exitCode;
        $output = [];
        foreach (explode("\n", trim($result->stdout . "\n" . $result->stderr)) as $line) {
            if (preg_match('/^BOOSTER_GATE_PHASE=(\S+)$/', $line, $m) === 1) {
                $phase = $m[1];
                continue;
            }
            if (preg_match('/^BOOSTER_GATE_EXIT=(-?\d+)$/', $line, $m) === 1) {
                $exitCode = (int) $m[1];
                continue;
            }
            $output[] = $line;
        }
        while ($output !== [] && end($output) === '') {
            array_pop($output);
        }

        return new GateOutcome($phase, $exitCode, $output);
    }

    /**
     * The argument array for `docker run`, exposed so tests can assert on it.
     *
     * @param list<string> $env environment variables (KEY=VALUE) passed to the container with -e
     * @return list<string>
     */
    public function runCommand(string $appPath, string $php, string $script, ?int $uid = null, ?int $gid = null, ?string $image = null, array $env = []): array
    {
        // COMPOSER_PROCESS_TIMEOUT=0: Composer cuts a script such as `composer test`
        // off at 300 seconds, which failed a real app's slow suite (2026-11-06);
        // the gate's own one-hour limit is the one that should govern.
        $command = [$this->docker, 'run', '--rm', '-v', $appPath . ':/app', '-w', '/app', '-e', 'COMPOSER_HOME=/tmp/composer', '-e', 'HOME=/tmp', '-e', 'COMPOSER_PROCESS_TIMEOUT=0'];
        foreach ($env as $pair) {
            array_push($command, '-e', $pair);
        }
        if ($this->composerCache !== null) {
            // Opt-in (BOOSTER_COMPOSER_CACHE): the baseline fills the cache and
            // every later gate reads from it instead of downloading again.
            array_push($command, ...$this->composerCache->mountArgs(self::COMPOSER_CACHE_MOUNT));
            array_push($command, '-e', 'COMPOSER_CACHE_DIR=' . $this->composerCacheDir($uid !== null && $gid !== null ? $uid : null));
        }
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
            // BuildKit prints its whole progress log on stderr; the failing step is at the end.
            $lines = preg_split('/\R/', trim($build->stderr . "\n" . $build->stdout)) ?: [];
            $tail = implode("\n", array_slice($lines, -40));
            throw new ToolError(sprintf(
                "Could not build %s (docker build exit %d), so the gate cannot run. Fix the build (the end of its output is below), pass --image to use your own image, or pass --skip-gate and run the tests yourself.\n%s",
                $tag,
                $build->exitCode,
                $tail,
            ));
        }
    }

    /**
     * @param list<string> $updatePackages see containerScript()
     * @param list<string> $env            environment variables (KEY=VALUE) passed to the container with -e
     */
    public function run(string $appPath, string $php, string $testCommand, ?string $extraCommand = null, ?GateImage $image = null, array $updatePackages = [], array $env = []): ProcessResult
    {
        $prepared = $this->prepareImage($php, $image);
        $resolved = $image === null ? null : $prepared;

        $uid = function_exists('posix_getuid') ? posix_getuid() : null;
        $gid = function_exists('posix_getgid') ? posix_getgid() : null;
        $this->ensureCacheDir($prepared, $uid, $gid);

        return $this->processes->run(
            $this->runCommand($appPath, $php, self::containerScript($testCommand, $extraCommand, $updatePackages), $uid, $gid, $resolved, $env),
            null,
            [],
            3600,
        );
    }

    /**
     * Checks that Docker answers and that the image for this PHP version
     * exists (building booster-target or pulling a --image image as needed),
     * and returns the image to run. The replay gate shares it, so the app is
     * served in exactly the image its tests ran in.
     */
    public function prepareImage(string $php, ?GateImage $image = null): string
    {
        $version = $this->processes->run([$this->docker, 'version', '--format', '{{.Server.Version}}'], null, [], 30);
        if (!$version->successful()) {
            throw new ToolError('Docker is not available, so the target-image gate cannot run. Start Docker, or pass --skip-gate and run the tests yourself.');
        }
        if ($image === null) {
            $this->ensureImage($php);

            return $this->imageTag($php);
        }
        // The option may be a template or a map, so the image is resolved
        // per call from the PHP version of this baseline or gate.
        $resolved = $image->forPhp($php);
        $this->ensureCustomImage($resolved);

        return $resolved;
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
