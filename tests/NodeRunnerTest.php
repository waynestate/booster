<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Run\CacheMount;
use Waynestate\Booster\Run\NodeRunner;
use Waynestate\Booster\Run\ProcessResult;

final class NodeRunnerTest extends TestCase
{
    public function test_the_app_is_mounted_and_run_in_a_node_container(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(0, '', ''));
        $runner = new NodeRunner($fake);

        $result = $runner->run('/srv/my app', ['npm', 'run', 'build']);

        self::assertTrue($result->successful());
        self::assertSame(
            ['docker', 'run', '--rm', '-v', '/srv/my app:/app', '-w', '/app', 'node:24-bookworm-slim', 'npm', 'run', 'build'],
            $fake->calls[0]['command'],
        );
        self::assertNull($fake->calls[0]['cwd']);
    }

    public function test_the_image_major_follows_the_constructor_argument(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(0, '', ''));
        $command = (new NodeRunner($fake, 26))->runCommand('/app', ['npm', 'ci']);

        self::assertSame('node:26-bookworm-slim', $command[7]);
    }

    public function test_an_npm_cache_volume_is_mounted_at_root_npm(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(0, '', ''));
        $cache = CacheMount::parse(CacheMount::NPM, 'stack-npm-cache');
        $command = (new NodeRunner($fake, 24, npmCache: $cache))->runCommand('/srv/app', ['npm', 'ci']);

        self::assertSame(
            ['docker', 'run', '--rm', '-v', '/srv/app:/app', '-v', 'stack-npm-cache:/root/.npm', '-w', '/app', 'node:24-bookworm-slim', 'npm', 'ci'],
            $command,
        );
    }

    public function test_an_npm_cache_folder_is_mounted_as_is(): void
    {
        $dir = sys_get_temp_dir() . '/booster-npm-cache-' . bin2hex(random_bytes(4));
        $cache = CacheMount::parse(CacheMount::NPM, $dir);
        self::assertNotNull($cache);
        self::assertFalse($cache->volume);
        $command = (new NodeRunner(new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(0, '', '')), npmCache: $cache))->runCommand('/srv/app', ['npm', 'ci']);

        self::assertSame(['-v', $cache->source . ':/root/.npm'], array_slice($command, 5, 2));
        self::assertDirectoryExists($dir);
        @rmdir($dir);
    }

    public function test_no_npm_cache_is_mounted_by_default(): void
    {
        $command = (new NodeRunner(new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(0, '', ''))))->runCommand('/srv/app', ['npm', 'ci']);

        self::assertSame(['docker', 'run', '--rm', '-v', '/srv/app:/app', '-w', '/app', 'node:24-bookworm-slim', 'npm', 'ci'], $command);
    }

    public function test_a_non_zero_exit_is_reported_not_thrown(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(1, '', 'npm ERR!'));
        $result = (new NodeRunner($fake))->run('/app', ['npm', 'ci']);

        self::assertFalse($result->successful());
        self::assertSame(1, $result->exitCode);
        self::assertSame('npm ERR!', $result->stderr);
    }
}
