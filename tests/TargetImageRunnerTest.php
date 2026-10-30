<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Run\ProcessResult;
use Waynestate\Booster\Run\TargetImageRunner;
use Waynestate\Booster\ToolError;

final class TargetImageRunnerTest extends TestCase
{
    private const DOCKER_DIR = __DIR__ . '/../docker';

    /**
     * The first 12 hex chars of the Dockerfile's sha256, as the tag carries them.
     */
    private function stamp(string $dir = self::DOCKER_DIR): string
    {
        return substr(hash('sha256', file_get_contents($dir . '/Dockerfile')), 0, 12);
    }

    public function test_the_app_is_mounted_and_tested_on_the_target_php(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(0, '', ''));
        $runner = new TargetImageRunner($fake, self::DOCKER_DIR);

        $result = $runner->run('/srv/my app', '8.4', 'composer test', 'vendor/bin/proctor check');

        self::assertTrue($result->successful());
        $last = $fake->calls[count($fake->calls) - 1]['command'];
        self::assertSame(['docker', 'run', '--rm', '-v', '/srv/my app:/app', '-w', '/app'], array_slice($last, 0, 7));
        self::assertContains('booster-target:8.4-' . $this->stamp(), $last);
        self::assertSame(
            'composer install --no-interaction --no-progress --prefer-dist && composer test && vendor/bin/proctor check',
            $last[count($last) - 1],
        );
    }

    public function test_the_image_is_built_when_it_is_missing(): void
    {
        $fake = new FakeProcessRunner(static fn(array $command): ProcessResult => new ProcessResult(
            in_array('inspect', $command, true) ? 1 : 0,
            '',
            '',
        ));
        (new TargetImageRunner($fake, self::DOCKER_DIR))->ensureImage('8.5');

        $build = $fake->calls[1]['command'];
        self::assertSame(['docker', 'build', '--build-arg', 'PHP_VERSION=8.5', '-t', 'booster-target:8.5-' . $this->stamp(), self::DOCKER_DIR], $build);
    }

    public function test_the_tag_carries_the_recipe_stamp(): void
    {
        $runner = new TargetImageRunner(new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(0, '', '')), self::DOCKER_DIR);

        self::assertSame('booster-target:8.4-' . $this->stamp(), $runner->imageTag('8.4'));
    }

    public function test_a_changed_dockerfile_gives_a_different_tag(): void
    {
        $alt = sys_get_temp_dir() . '/booster-alt-docker-' . bin2hex(random_bytes(4));
        mkdir($alt);
        file_put_contents($alt . '/Dockerfile', file_get_contents(self::DOCKER_DIR . '/Dockerfile') . "# changed\n");
        try {
            $processes = static fn(): FakeProcessRunner => new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(0, '', ''));
            self::assertNotSame(
                (new TargetImageRunner($processes(), self::DOCKER_DIR))->imageTag('8.4'),
                (new TargetImageRunner($processes(), $alt))->imageTag('8.4'),
            );
        } finally {
            unlink($alt . '/Dockerfile');
            rmdir($alt);
        }
    }

    public function test_an_existing_image_is_not_rebuilt(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(0, '', ''));
        (new TargetImageRunner($fake))->ensureImage('8.3');

        self::assertCount(1, $fake->calls);
    }

    public function test_a_failed_build_is_a_tool_error(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(1, '', 'no space left'));

        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('no space left');
        (new TargetImageRunner($fake))->ensureImage('8.3');
    }

    public function test_missing_docker_fails_closed_with_the_way_out(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(127, '', 'docker: not found'));

        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('--skip-gate');
        (new TargetImageRunner($fake))->run('/app', '8.4', 'composer test');
    }

    public function test_the_user_flag_keeps_file_ownership(): void
    {
        $command = (new TargetImageRunner(new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(0, '', ''))))
            ->runCommand('/app', '8.4', 'true', 1000, 1000);

        self::assertContains('--user', $command);
        self::assertContains('1000:1000', $command);
    }
}
