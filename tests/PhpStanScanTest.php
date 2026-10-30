<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Run\ProcessResult;
use Waynestate\Booster\Run\SymfonyProcessRunner;
use Waynestate\Booster\Scan\PhpStanScan;

final class PhpStanScanTest extends TestCase
{
    /**
     * @param array<string, mixed> $files
     *
     * @return array<string, mixed>
     */
    private function phpstanJson(array $files): array
    {
        return ['totals' => ['errors' => 0, 'file_errors' => 0], 'files' => $files, 'errors' => []];
    }

    /**
     * A FakeProcessRunner that records the temporary configuration each scan
     * writes and answers the rest from $scan. The capture is an object so the
     * caller sees what the runner recorded after the scan ran.
     */
    private function capturingFake(callable $scan): array
    {
        $captured = new \stdClass();
        $captured->neon = null;
        $captured->command = null;
        $captured->cwd = null;
        $fake = new FakeProcessRunner(static function (array $command, array $env, ?string $cwd) use ($scan, $captured): ProcessResult {
            if (in_array('--version', $command, true)) {
                return new ProcessResult(0, "PHPStan - PHP Static Analysis Tool 2.3.5\n", '');
            }
            foreach ($command as $argument) {
                if (str_starts_with($argument, '--configuration=')) {
                    $captured->neon = (string) file_get_contents(substr($argument, strlen('--configuration=')));
                }
            }
            $captured->command = $command;
            $captured->cwd = $cwd;

            return $scan();
        });

        return [$fake, $captured];
    }

    public function test_a_finding_is_parsed_relative_to_the_app(): void
    {
        $app = $this->tempDir();
        $json = $this->phpstanJson([
            $app . '/broken.php' => [
                'errors' => 2,
                'messages' => [
                    ['message' => 'Function each() does not exist.', 'line' => 10, 'ignorable' => true, 'identifier' => 'function.notFound'],
                    ['message' => 'Function create_function() does not exist.', 'line' => 20, 'ignorable' => true, 'identifier' => 'function.notFound'],
                ],
            ],
        ]);
        $fake = $this->phpstanFake(static fn(): ProcessResult => new ProcessResult(1, (string) json_encode($json), ''));

        $scan = (new PhpStanScan($fake))->scan($app, '8.3');

        self::assertTrue($scan->ok);
        self::assertSame('8.3', $scan->testVersion);
        self::assertSame(2, $scan->errorCount());
        self::assertSame(0, $scan->warningCount());
        self::assertSame('broken.php', $scan->findings[0]->file);
        self::assertSame(10, $scan->findings[0]->line);
        self::assertSame('Function each() does not exist.', $scan->findings[0]->message);
        self::assertSame('error', $scan->findings[0]->severity);
        self::assertSame('function.notFound', $scan->findings[0]->source);
    }

    public function test_exit_one_with_a_json_report_is_findings_not_an_error(): void
    {
        // PHPStan exits 1 both when it found something and when it failed to
        // start; a parseable report must win over the exit code.
        $app = $this->tempDir();
        $json = $this->phpstanJson([
            $app . '/a.php' => [
                'errors' => 1,
                'messages' => [['message' => 'Parameter $x has no type specified.', 'line' => 3, 'ignorable' => true, 'identifier' => 'missingType.parameter']],
            ],
        ]);
        $fake = $this->phpstanFake(static fn(): ProcessResult => new ProcessResult(1, (string) json_encode($json), ''));

        $scan = (new PhpStanScan($fake))->scan($app, '8.4');

        self::assertTrue($scan->ok);
        self::assertCount(1, $scan->findings);
        self::assertSame('missingType.parameter', $scan->findings[0]->source);
    }

    public function test_a_clean_app_has_no_findings(): void
    {
        $app = $this->tempDir();
        $json = $this->phpstanJson([]);
        $fake = $this->phpstanFake(static fn(): ProcessResult => new ProcessResult(0, (string) json_encode($json), ''));

        $scan = (new PhpStanScan($fake))->scan($app, '8.5');

        self::assertTrue($scan->ok);
        self::assertSame([], $scan->findings);
        self::assertSame(0, $scan->errorCount());
    }

    public function test_non_json_output_is_a_skip_with_the_reason(): void
    {
        $fake = $this->phpstanFake(static fn(): ProcessResult => new ProcessResult(1, '', "Invalid configuration: Unexpected item 'parameters › strictRules'."));

        $scan = (new PhpStanScan($fake))->scan($this->tempDir(), '8.3');

        self::assertFalse($scan->ok);
        self::assertStringContainsString('without a JSON report', (string) $scan->error);
        self::assertStringContainsString('Invalid configuration', (string) $scan->error);
    }

    public function test_a_missing_binary_is_reported_not_thrown(): void
    {
        // The real runner against a path that does not exist. phpstan runs
        // under Booster's own PHP, so a missing binary is PHP's "Could not
        // open input file" (no JSON on stdout): the scan turns that into a
        // scan error instead of an exception.
        $scan = (new PhpStanScan(new SymfonyProcessRunner(), '/nonexistent/phpstan'))->scan('/srv/app', '8.0');

        self::assertFalse($scan->ok);
        self::assertStringContainsString('without a JSON report', (string) $scan->error);
        self::assertStringContainsString('/nonexistent/phpstan', (string) $scan->error);
    }

    public function test_no_running_phpstan_keeps_the_install_hint(): void
    {
        $app = $this->tempDir();
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(127, '', 'phpstan: command not found'));

        $scan = (new PhpStanScan($fake, null, null, $this->tempDir()))->scan($app, '8.0');

        self::assertFalse($scan->ok);
        self::assertSame('PHPStan scan skipped: PHPStan is not installed. Enable it with: composer require --dev phpstan/phpstan waynestate/pinstripe', (string) $scan->error);
    }

    public function test_a_missing_preset_is_reported_with_the_install_command(): void
    {
        $app = $this->tempDir();
        // A vendor tree with neither Pinstripe nor PHPStan stands in for
        // Booster's, so discovery finds nothing and the preset search ends
        // empty.
        $fake = $this->phpstanFake(static fn(): ProcessResult => new ProcessResult(0, '{}', ''));

        $scan = (new PhpStanScan($fake, null, null, $this->tempDir()))->scan($app, '8.0');

        self::assertFalse($scan->ok);
        self::assertSame('PHPStan scan skipped: the Pinstripe PHPStan preset is not installed. Enable it with: composer require --dev waynestate/pinstripe', (string) $scan->error);
    }

    public function test_the_command_asks_for_the_exact_target_version_and_shape(): void
    {
        $app = $this->tempDir();
        $json = $this->phpstanJson([]);
        [$fake] = $this->capturingFake(static fn(): ProcessResult => new ProcessResult(0, (string) json_encode($json), ''));

        (new PhpStanScan($fake))->scan($app, '8.5');

        self::assertNotNull($fake->calls);
        $scanCommands = $fake->commandsContaining('analyse');
        self::assertCount(1, $scanCommands);
        $command = $scanCommands[0];
        self::assertContains('--error-format=json', $command);
        self::assertContains('--no-progress', $command);
        self::assertContains('--memory-limit=2G', $command);
        self::assertContains((string) realpath($app), $command);
        // The scan runs from inside the app, so the app's autoloader and any
        // extension-installer of its own are what PHPStan picks up.
        self::assertSame((string) realpath($app), $fake->calls[count($fake->calls) - 1]['cwd']);
    }

    public function test_the_temp_neon_carries_the_preset_the_rules_and_the_excludes(): void
    {
        $app = $this->tempDir();
        $json = $this->phpstanJson([]);
        [$fake, $captured] = $this->capturingFake(static fn(): ProcessResult => new ProcessResult(0, (string) json_encode($json), ''));

        (new PhpStanScan($fake))->scan($app, '8.5');

        self::assertNotNull($captured->neon);
        self::assertStringContainsString('waynestate/pinstripe/phpstan/base.neon', (string) $captured->neon);
        self::assertStringContainsString('phpVersion: 80500', (string) $captured->neon);
        // The app has no extension-installer, so both rule neons are included
        // before the preset.
        self::assertStringContainsString('phpstan-strict-rules/rules.neon', (string) $captured->neon);
        self::assertStringContainsString('phpstan-deprecation-rules/rules.neon', (string) $captured->neon);
        self::assertStringContainsString('*/vendor/*', (string) $captured->neon);
        self::assertStringContainsString('*/node_modules/*', (string) $captured->neon);
        self::assertStringContainsString('*/storage/*', (string) $captured->neon);
        // The preset sets level 8, so no fallback level is written.
        self::assertStringNotContainsString('level: 5', (string) $captured->neon);
    }

    public function test_the_temp_neon_skips_rules_the_apps_installer_already_loads(): void
    {
        $app = $this->tempDir();
        mkdir($app . '/vendor/phpstan/extension-installer/src', 0775, true);
        file_put_contents(
            $app . '/vendor/phpstan/extension-installer/src/GeneratedConfig.php',
            "<?php\n\nnamespace PHPStan\\ExtensionInstaller;\n\nfinal class GeneratedConfig\n{\n\tconst EXTENSIONS = ['phpstan/phpstan-strict-rules'];\n}\n",
        );
        $json = $this->phpstanJson([]);
        [$fake, $captured] = $this->capturingFake(static fn(): ProcessResult => new ProcessResult(0, (string) json_encode($json), ''));

        (new PhpStanScan($fake))->scan($app, '8.5');

        self::assertNotNull($captured->neon);
        // Including strict-rules again would make PHPStan refuse to start.
        self::assertStringNotContainsString('phpstan-strict-rules/rules.neon', (string) $captured->neon);
        self::assertStringContainsString('phpstan-deprecation-rules/rules.neon', (string) $captured->neon);
    }

    public function test_the_temp_neon_is_removed_after_the_scan(): void
    {
        $app = $this->tempDir();
        $json = $this->phpstanJson([]);
        [$fake] = $this->capturingFake(static fn(): ProcessResult => new ProcessResult(0, (string) json_encode($json), ''));

        (new PhpStanScan($fake))->scan($app, '8.5');

        $configPath = null;
        foreach ($fake->calls as $call) {
            foreach ($call['command'] as $argument) {
                if (str_starts_with($argument, '--configuration=')) {
                    $configPath = substr($argument, strlen('--configuration='));
                }
            }
        }
        self::assertNotNull($configPath);
        self::assertFileDoesNotExist((string) $configPath);
    }

    public function test_the_result_serialises_for_json_output(): void
    {
        $app = $this->tempDir();
        $json = $this->phpstanJson([
            $app . '/a.php' => [
                'errors' => 1,
                'messages' => [['message' => 'm', 'line' => 3, 'ignorable' => true, 'identifier' => 'argument.type']],
            ],
        ]);
        $fake = $this->phpstanFake(static fn(): ProcessResult => new ProcessResult(1, (string) json_encode($json), ''));

        $array = (new PhpStanScan($fake))->scan($app, '8.1')->toArray();

        self::assertSame('ok', $array['status']);
        self::assertSame('8.1', $array['test_version']);
        self::assertNull($array['error']);
        self::assertSame(1, $array['errors']);
        self::assertSame(0, $array['warnings']);
        self::assertSame('a.php', $array['findings'][0]['file']);
        self::assertSame(3, $array['findings'][0]['line']);
        self::assertSame('argument.type', $array['findings'][0]['source']);
    }
}
