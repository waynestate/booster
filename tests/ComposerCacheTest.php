<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Run\CacheMount;
use Waynestate\Booster\Run\ProcessResult;
use Waynestate\Booster\Run\TargetImageRunner;
use Waynestate\Booster\ToolError;

/**
 * The opt-in Composer cache (BOOSTER_COMPOSER_CACHE): unset, the gate
 * container is exactly what it was; set, the cache is mounted as
 * COMPOSER_CACHE_DIR in the baseline and every gate, so the packages the
 * baseline downloaded are not downloaded again by each hop.
 */
final class ComposerCacheTest extends TestCase
{
    private const DOCKER_DIR = __DIR__ . '/../docker';

    public function test_without_the_variable_nothing_is_mounted_and_no_cache_dir_is_set(): void
    {
        self::assertNull(CacheMount::parse(CacheMount::COMPOSER, null));
        self::assertNull(CacheMount::parse(CacheMount::COMPOSER, '   '));

        $command = $this->runner(null)->runCommand('/app', '8.4', 'true', 1000, 1000);

        self::assertSame(['/app:/app'], array_values(array_filter($command, static fn(string $arg): bool => str_contains($arg, ':/'))));
        foreach ($command as $arg) {
            self::assertStringNotContainsString('COMPOSER_CACHE_DIR', $arg);
        }
        self::assertContains('COMPOSER_HOME=/tmp/composer', $command);
    }

    public function test_a_plain_name_is_a_docker_volume(): void
    {
        $cache = CacheMount::parse(CacheMount::COMPOSER, 'stack-composer-cache');

        self::assertNotNull($cache);
        self::assertTrue($cache->volume);
        self::assertSame('stack-composer-cache', $cache->source);
        self::assertSame(['-v', 'stack-composer-cache:/tmp/composer-cache'], $cache->mountArgs(TargetImageRunner::COMPOSER_CACHE_MOUNT));
        self::assertSame('the Docker volume stack-composer-cache (BOOSTER_COMPOSER_CACHE)', $cache->describe());
    }

    public function test_a_path_is_a_host_folder_and_is_created_when_missing(): void
    {
        $dir = $this->tempDir() . '/cache/composer';
        $cache = CacheMount::parse(CacheMount::COMPOSER, $dir);

        self::assertNotNull($cache);
        self::assertFalse($cache->volume);
        self::assertDirectoryExists($dir);
        self::assertSame(realpath($dir), $cache->source);
    }

    public function test_a_relative_path_is_made_absolute(): void
    {
        $base = $this->tempDir();
        $cwd = getcwd();
        chdir($base);
        try {
            $cache = CacheMount::parse(CacheMount::COMPOSER, './composer-cache');
        } finally {
            chdir((string) $cwd);
        }

        self::assertNotNull($cache);
        self::assertSame(realpath($base . '/composer-cache'), $cache->source);
    }

    public function test_a_folder_path_with_a_colon_is_refused_by_name(): void
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('BOOSTER_COMPOSER_CACHE=/tmp/a:b');

        CacheMount::parse(CacheMount::COMPOSER, '/tmp/a:b');
    }

    public function test_a_volume_is_mounted_as_the_cache_dir_for_a_root_or_unknown_user(): void
    {
        $runner = $this->runner('stack-composer-cache');

        foreach ([[null, null], [0, 0]] as [$uid, $gid]) {
            $command = $runner->runCommand('/app', '8.4', 'true', $uid, $gid);
            $i = array_search('stack-composer-cache:/tmp/composer-cache', $command, true);
            self::assertNotFalse($i);
            self::assertSame('-v', $command[$i - 1]);
            self::assertContains('COMPOSER_CACHE_DIR=/tmp/composer-cache', $command);
            // The app mount and the image stay where every other test expects them.
            self::assertSame(['docker', 'run', '--rm', '-v', '/app:/app', '-w', '/app'], array_slice($command, 0, 7));
        }
    }

    public function test_a_non_root_user_gets_a_folder_of_its_own_inside_the_volume(): void
    {
        // A volume belongs to root; the gate runs as the person's uid on Linux
        // and macOS, so the volume root may not be writable. Composer would
        // then carry on without a cache and say nothing.
        $runner = $this->runner('stack-composer-cache');
        $command = $runner->runCommand('/app', '8.4', 'true', 1000, 1000);

        self::assertContains('COMPOSER_CACHE_DIR=/tmp/composer-cache/booster-uid-1000', $command);
        self::assertSame(
            ['docker', 'run', '--rm', '--user', '0:0', '-v', 'stack-composer-cache:/tmp/composer-cache', 'booster-target:8.4', 'sh', '-c', 'mkdir -p /tmp/composer-cache/booster-uid-1000 && chown 1000:1000 /tmp/composer-cache/booster-uid-1000'],
            $runner->cacheDirCommand('booster-target:8.4', 1000, 1000),
        );
        self::assertNull($runner->cacheDirCommand('booster-target:8.4', 0, 0));
        self::assertNull($runner->cacheDirCommand('booster-target:8.4', null, null));
    }

    public function test_a_host_folder_is_used_as_it_is_for_any_user(): void
    {
        $dir = $this->tempDir();
        $runner = $this->runner($dir);

        $command = $runner->runCommand('/app', '8.4', 'true', 1000, 1000);

        self::assertContains(realpath($dir) . ':/tmp/composer-cache', $command);
        self::assertContains('COMPOSER_CACHE_DIR=/tmp/composer-cache', $command);
        self::assertNull($runner->cacheDirCommand('booster-target:8.4', 1000, 1000));
    }

    public function test_every_gate_run_mounts_the_cache(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(0, '', ''));
        $runner = new TargetImageRunner($fake, self::DOCKER_DIR, composerCache: CacheMount::parse(CacheMount::COMPOSER, 'stack-composer-cache'));

        $runner->run('/app', '8.3', 'composer test');
        $runner->run('/app', '8.4', 'composer test', null, null, [TargetImageRunner::LOCK_REFRESH]);

        $gates = array_values(array_filter($fake->calls, static fn(array $c): bool => ($c['command'][1] ?? '') === 'run' && in_array('/app:/app', $c['command'], true)));
        self::assertCount(2, $gates);
        foreach ($gates as $gate) {
            self::assertContains('stack-composer-cache:/tmp/composer-cache', $gate['command']);
        }
    }

    public function test_the_volume_folder_is_made_once_per_image_not_once_per_gate(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(0, '', ''));
        $runner = new TargetImageRunner($fake, self::DOCKER_DIR, composerCache: CacheMount::parse(CacheMount::COMPOSER, 'stack-composer-cache'));

        $runner->ensureCacheDir('booster-target:8.4', 1000, 1000);
        $runner->ensureCacheDir('booster-target:8.4', 1000, 1000);
        $runner->ensureCacheDir('booster-target:8.5', 1000, 1000);
        // Root and a host without POSIX ids need no folder at all.
        $runner->ensureCacheDir('booster-target:8.4', 0, 0);
        $runner->ensureCacheDir('booster-target:8.4', null, null);

        self::assertCount(2, $fake->calls);
    }

    public function test_a_failed_folder_step_does_not_stop_the_gate(): void
    {
        // Composer then runs without the cache, as it did before the cache existed.
        $fake = new FakeProcessRunner(static fn(array $command): ProcessResult => str_contains(implode(' ', $command), 'chown') ? new ProcessResult(125, '', 'no chown here') : new ProcessResult(0, '', ''));
        $runner = new TargetImageRunner($fake, self::DOCKER_DIR, composerCache: CacheMount::parse(CacheMount::COMPOSER, 'stack-composer-cache'));

        $runner->ensureCacheDir('booster-target:8.4', 1000, 1000);
        $result = $runner->run('/app', '8.4', 'composer test');

        self::assertTrue($result->successful());
    }

    private function runner(?string $cache): TargetImageRunner
    {
        return new TargetImageRunner(
            new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(0, '', '')),
            self::DOCKER_DIR,
            composerCache: CacheMount::parse(CacheMount::COMPOSER, $cache),
        );
    }
}
