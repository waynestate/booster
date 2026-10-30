<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Run\GateImage;
use Waynestate\Booster\Run\GateOutcome;
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
        $script = $last[count($last) - 1];
        self::assertStringContainsString('composer install --no-interaction --no-progress --prefer-dist >"$install_log" 2>&1', $script);
        self::assertStringContainsString('composer test >"$tests_log" 2>&1', $script);
        self::assertStringContainsString('vendor/bin/proctor check >>"$tests_log" 2>&1', $script);
        self::assertStringContainsString('tail -n 30 "$install_log"', $script);
        self::assertStringContainsString('BOOSTER_GATE_PHASE=install', $script);
        self::assertStringContainsString('BOOSTER_GATE_PHASE=tests', $script);
        self::assertLessThan(strpos($script, 'composer test'), strpos($script, 'composer install'));
    }

    public function test_the_container_script_reports_the_install_and_test_phases_separately(): void
    {
        $script = TargetImageRunner::containerScript('vendor/bin/phpunit', null);

        // Install runs first and bails with its own phase marker; the tests
        // only run when the install succeeded.
        self::assertLessThan(strpos($script, 'vendor/bin/phpunit'), strpos($script, 'composer install'));
        self::assertStringContainsString('if [ "$install_exit" -ne 0 ]; then', $script);
        self::assertStringContainsString('exit "$install_exit"', $script);
        self::assertStringNotContainsString('proctor', $script);
    }

    public function test_gate_outcome_reads_an_install_failure_from_the_markers(): void
    {
        $stdout = "Problem 1 of 1\n- The lock file is not up to date with the latest changes in composer.json.\nBOOSTER_GATE_PHASE=install\nBOOSTER_GATE_EXIT=4\n";
        $outcome = TargetImageRunner::gateOutcome(new ProcessResult(4, $stdout, ''));

        self::assertSame(GateOutcome::PHASE_INSTALL, $outcome->phase);
        self::assertSame(4, $outcome->exitCode);
        self::assertFalse($outcome->successful());
        self::assertContains('Problem 1 of 1', $outcome->output);
        foreach ($outcome->output as $line) {
            self::assertStringStartsNotWith('BOOSTER_GATE_', $line);
        }
    }

    public function test_gate_outcome_reads_a_test_failure_from_the_markers(): void
    {
        $stdout = "FAILURES!\nBOOSTER_GATE_PHASE=tests\nBOOSTER_GATE_EXIT=1\n";
        $outcome = TargetImageRunner::gateOutcome(new ProcessResult(1, $stdout, ''));

        self::assertSame(GateOutcome::PHASE_TESTS, $outcome->phase);
        self::assertSame(1, $outcome->exitCode);
        self::assertContains('FAILURES!', $outcome->output);
    }

    public function test_gate_outcome_defaults_to_the_tests_phase_without_markers(): void
    {
        $outcome = TargetImageRunner::gateOutcome(new ProcessResult(3, 'some raw output', 'more output'));

        self::assertSame(GateOutcome::PHASE_TESTS, $outcome->phase);
        self::assertSame(3, $outcome->exitCode);
        self::assertSame(['some raw output', 'more output'], $outcome->output);
    }

    public function test_gate_outcome_reads_a_success(): void
    {
        $outcome = TargetImageRunner::gateOutcome(new ProcessResult(0, "BOOSTER_GATE_PHASE=tests\n", ''));

        self::assertSame(GateOutcome::PHASE_TESTS, $outcome->phase);
        self::assertSame(0, $outcome->exitCode);
        self::assertTrue($outcome->successful());
        self::assertSame([], $outcome->output);
    }

    public function test_the_image_is_built_when_it_is_missing(): void
    {
        $fake = new FakeProcessRunner(static fn(array $command): ProcessResult => new ProcessResult(
            in_array('inspect', $command, true) ? 1 : 0,
            '',
            '',
        ));
        (new TargetImageRunner($fake, self::DOCKER_DIR))->ensureImage('8.4');

        $build = $fake->calls[1]['command'];
        self::assertSame(
            ['docker', 'build', '--build-arg', 'PHP_VERSION=8.4', '--build-arg', 'COMPOSER_VERSION=2.10.3', '-t', 'booster-target:8.4-' . $this->stamp(), self::DOCKER_DIR],
            $build,
        );
    }

    public function test_php_below_7_2_builds_with_composer_2_2(): void
    {
        $fake = new FakeProcessRunner(static fn(array $command): ProcessResult => new ProcessResult(
            in_array('inspect', $command, true) ? 1 : 0,
            '',
            '',
        ));
        (new TargetImageRunner($fake, self::DOCKER_DIR))->ensureImage('7.1');

        $build = $fake->calls[1]['command'];
        self::assertSame(
            ['docker', 'build', '--build-arg', 'PHP_VERSION=7.1', '--build-arg', 'COMPOSER_VERSION=2.2', '-t', 'booster-target:7.1-' . $this->stamp(), self::DOCKER_DIR],
            $build,
        );
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

    public function test_the_given_image_is_used_and_nothing_is_built(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(0, '', ''));
        $runner = new TargetImageRunner($fake, self::DOCKER_DIR);

        $result = $runner->run('/srv/my app', '8.4', 'composer test', null, GateImage::fromOption('myapp/gate:8.4'));

        self::assertTrue($result->successful());
        $last = $fake->calls[count($fake->calls) - 1]['command'];
        self::assertSame(['docker', 'run', '--rm', '-v', '/srv/my app:/app', '-w', '/app'], array_slice($last, 0, 7));
        self::assertContains('myapp/gate:8.4', $last);
        self::assertCount(0, $fake->commandsContaining('build'));
    }

    public function test_a_missing_gate_image_is_pulled_once_before_the_first_hop(): void
    {
        $fake = new FakeProcessRunner(static fn(array $command): ProcessResult => new ProcessResult(
            in_array('inspect', $command, true) ? 1 : 0,
            '',
            '',
        ));
        $runner = new TargetImageRunner($fake, self::DOCKER_DIR);

        $runner->run('/app', '8.4', 'composer test', null, GateImage::fromOption('myapp/gate:8.4'));
        $runner->run('/app', '8.5', 'composer test', null, GateImage::fromOption('myapp/gate:8.4'));

        self::assertSame([['docker', 'pull', 'myapp/gate:8.4']], $fake->commandsContaining('pull'));
    }

    public function test_an_unpullable_gate_image_is_a_tool_error(): void
    {
        $fake = new FakeProcessRunner(static fn(array $command): ProcessResult => new ProcessResult(
            in_array('version', $command, true) ? 0 : 1,
            '',
            '',
        ));

        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('The gate image myapp/gate:8.4 was not found locally and could not be pulled.');
        (new TargetImageRunner($fake, self::DOCKER_DIR))->run('/app', '8.4', 'composer test', null, GateImage::fromOption('myapp/gate:8.4'));
    }

    public function test_a_template_image_resolves_per_php_version(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(0, '', ''));
        $runner = new TargetImageRunner($fake, self::DOCKER_DIR);

        $runner->run('/app', '8.1', 'composer test', null, GateImage::fromOption('registry/php-apache:{php}'));

        $last = $fake->calls[count($fake->calls) - 1]['command'];
        self::assertContains('registry/php-apache:8.1', $last);
    }

    public function test_a_map_image_resolves_the_named_php_version(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(0, '', ''));
        $runner = new TargetImageRunner($fake, self::DOCKER_DIR);

        $runner->run('/app', '8.1', 'composer test', null, GateImage::fromOption('8.0=foo:1,8.1=bar:2'));

        $last = $fake->calls[count($fake->calls) - 1]['command'];
        self::assertContains('bar:2', $last);
    }

    public function test_a_map_without_the_php_version_is_a_tool_error(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(0, '', ''));

        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('--image has no image for PHP 8.2; add 8.2=<image> or use a {php} template.');
        (new TargetImageRunner($fake, self::DOCKER_DIR))->run('/app', '8.2', 'composer test', null, GateImage::fromOption('8.0=foo:1'));
    }

    public function test_each_distinct_resolved_image_is_pulled_once(): void
    {
        $fake = new FakeProcessRunner(static fn(array $command): ProcessResult => new ProcessResult(
            in_array('inspect', $command, true) ? 1 : 0,
            '',
            '',
        ));
        $runner = new TargetImageRunner($fake, self::DOCKER_DIR);

        $runner->run('/app', '8.4', 'composer test', null, GateImage::fromOption('reg/php:{php}'));
        $runner->run('/app', '8.5', 'composer test', null, GateImage::fromOption('reg/php:{php}'));

        self::assertSame(
            [['docker', 'pull', 'reg/php:8.4'], ['docker', 'pull', 'reg/php:8.5']],
            $fake->commandsContaining('pull'),
        );
    }
}
