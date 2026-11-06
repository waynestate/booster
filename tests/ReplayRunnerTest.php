<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Replay\ReplayFile;
use Waynestate\Booster\Replay\ReplayRunner;
use Waynestate\Booster\Run\ProcessResult;
use Waynestate\Booster\Run\TargetImageRunner;

final class ReplayRunnerTest extends TestCase
{
    private function runner(FakeProcessRunner $fake): ReplayRunner
    {
        return new ReplayRunner(new TargetImageRunner($fake, dirname(__DIR__) . '/docker'), $fake);
    }

    public function test_the_replay_container_has_no_network_and_gets_only_the_files_fake_settings(): void
    {
        $file = ReplayFile::load(__DIR__ . '/fixtures/replay-app/booster-replay.json');
        $command = $this->runner(ReplayFake::runner([]))->runCommand('/srv/app', '/tmp/scratch', 'booster-target:7.4-abc', $file, null, 1000, 1000);

        self::assertSame(['docker', 'run', '--rm', '--network', 'none', '-v', '/srv/app:/app', '-v', '/tmp/scratch:/booster-replay', '-w', '/app', '-e', 'HOME=/tmp', '-e', 'NOTES_DB=/tmp/notes.sqlite', '-e', 'BOOSTER_REPLAY_SETUP=php replay/seed.php', '-e', 'BOOSTER_REPLAY_FRONT=index.php', '--user', '1000:1000', 'booster-target:7.4-abc', 'sh', '-c'], array_slice($command, 0, 24));
    }

    public function test_the_container_script_runs_setup_then_the_built_in_server_then_the_client(): void
    {
        $file = ReplayFile::load(__DIR__ . '/fixtures/replay-app/booster-replay.json');
        $script = ReplayRunner::containerScript($file);

        self::assertStringContainsString('sh -c "$BOOSTER_REPLAY_SETUP" >"$setup_log" 2>&1', $script);
        self::assertStringContainsString("'php' '-d' 'display_errors=0' '-d' 'log_errors=1' '-d' 'error_log=/booster-replay/php-errors.log' '-d' 'error_reporting=-1' '-d' 'date.timezone=UTC' '-S' '127.0.0.1:8765' '-t' 'public' '/booster-replay/router.php' >/booster-replay/server.log 2>&1 &", $script);
        self::assertStringContainsString('php /booster-replay/client.php /booster-replay/plan.json /booster-replay/result.json 8765', $script);
        self::assertLessThan(strpos($script, 'client.php'), strpos($script, 'BOOSTER_REPLAY_SETUP'));
    }

    public function test_without_a_front_controller_the_server_serves_the_docroot_as_files(): void
    {
        $script = ReplayRunner::containerScript(ReplayFile::fromArray(['requests' => [['path' => '/index.php']]]));

        self::assertStringContainsString("'-S' '127.0.0.1:8765' '-t' '.' >/booster-replay/server.log", $script);
        self::assertStringNotContainsString('router.php', $script);
    }

    public function test_fixture_services_get_a_private_internal_network_that_is_removed_afterwards(): void
    {
        $file = ReplayFile::fromArray([
            'serve' => ['services' => [['name' => 'db', 'image' => 'mysql:8.4', 'env' => ['MYSQL_ROOT_PASSWORD' => 'fake-only']]]],
            'requests' => [['path' => '/']],
        ]);
        $fake = ReplayFake::runner([[[200, 'ok']]]);

        $run = $this->runner($fake)->replay($this->tempDir(), '7.4', $file);

        self::assertTrue($run->served);
        $calls = array_map(static fn(array $c): string => implode(' ', array_slice($c['command'], 0, 6)), $fake->calls);
        $create = array_values(array_filter($calls, static fn(string $c): bool => str_starts_with($c, 'docker network create --internal booster-replay-')));
        self::assertCount(1, $create);
        $network = substr($create[0], strlen('docker network create --internal '));
        self::assertContains('docker run -d --rm --name ' . $network . '-db', $calls);
        $replay = array_values(array_filter($fake->calls, static fn(array $c): bool => in_array('--network', $c['command'], true) && in_array('sh', $c['command'], true)));
        self::assertSame($network, $replay[0]['command'][4]);
        self::assertContains('docker rm -f ' . $network . '-db', $calls);
        self::assertContains('docker network rm ' . $network, $calls);
    }

    public function test_install_runs_in_a_gate_container_with_the_network_before_the_replay(): void
    {
        $app = $this->tempDir();
        file_put_contents($app . '/composer.json', '{}');
        $fake = ReplayFake::runner([[[200, 'ok']]]);

        $this->runner($fake)->replay($app, '8.4', ReplayFile::fromArray(['requests' => [['path' => '/']]]), null, true);

        $runs = array_values(array_filter($fake->calls, static fn(array $c): bool => ($c['command'][1] ?? '') === 'run'));
        self::assertCount(2, $runs);
        self::assertNotContains('--network', $runs[0]['command']);
        self::assertStringContainsString('composer install', (string) end($runs[0]['command']));
        self::assertContains('--network', $runs[1]['command']);
    }

    public function test_a_failed_install_stops_before_the_app_is_served(): void
    {
        $app = $this->tempDir();
        file_put_contents($app . '/composer.json', '{}');
        $fake = new FakeProcessRunner(static fn(array $command): ProcessResult => ($command[1] ?? '') === 'run'
            ? new ProcessResult(2, "Your lock file is out of date\nBOOSTER_GATE_PHASE=install\nBOOSTER_GATE_EXIT=4\n", '')
            : new ProcessResult(0, '', ''));

        $run = $this->runner($fake)->replay($app, '8.4', ReplayFile::fromArray(['requests' => [['path' => '/']]]), null, true);

        self::assertFalse($run->served);
        self::assertSame('composer install failed (exit 4) on PHP 8.4, so the app could not be served.', $run->lines[0]);
    }

    public function test_the_scratch_folder_is_removed_after_the_replay(): void
    {
        $fake = ReplayFake::runner([[[200, 'ok']]]);
        $this->runner($fake)->replay($this->tempDir(), '8.4', ReplayFile::fromArray(['requests' => [['path' => '/']]]));

        $replay = array_values(array_filter($fake->calls, static fn(array $c): bool => in_array('--network', $c['command'], true)));
        $mount = (string) $replay[0]['command'][8];
        self::assertDirectoryDoesNotExist(substr($mount, 0, -strlen(':/booster-replay')));
    }

    /**
     * The client and router run inside every gate image, PHP 5.5 included, so
     * they must not use syntax newer than 5.5. A token scan catches the usual
     * slips; the Docker end-to-end test runs them for real.
     */
    public function test_the_client_and_router_stay_php_5_5_compatible(): void
    {
        foreach (['client.php', 'router.php'] as $name) {
            $code = (string) file_get_contents(dirname(__DIR__) . '/resources/replay/' . $name);
            $tokens = token_get_all($code);
            foreach ($tokens as $token) {
                if (is_array($token)) {
                    self::assertNotContains($token[0], [T_FN, T_COALESCE, T_COALESCE_EQUAL, T_MATCH, T_ELLIPSIS, T_NULLSAFE_OBJECT_OPERATOR, T_SPACESHIP], $name . ' uses ' . token_name($token[0]));
                }
            }
            self::assertDoesNotMatchRegularExpression('/function\s+\w+\s*\([^)]*\)\s*:/', $code, $name . ' declares a return type');
            self::assertDoesNotMatchRegularExpression('/function\s+\w+\s*\(\s*(?:int|string|bool|float|array|\?)\s*\$/', $code, $name . ' declares a scalar parameter type');
            self::assertStringNotContainsString('declare(strict_types', $code);
        }
    }
}
