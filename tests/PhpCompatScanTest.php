<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Packaging\PharFiles;
use Waynestate\Booster\Run\ProcessResult;
use Waynestate\Booster\Run\SymfonyProcessRunner;
use Waynestate\Booster\Scan\PhpCompatScan;

final class PhpCompatScanTest extends TestCase
{
    /**
     * @param array<string, mixed> $files
     *
     * @return array<string, mixed>
     */
    private function phpcsJson(array $files): array
    {
        return ['totals' => ['errors' => 0, 'warnings' => 0, 'fixable' => 0], 'files' => $files];
    }

    public function test_a_finding_is_parsed_relative_to_the_app(): void
    {
        $app = $this->tempDir();
        $json = $this->phpcsJson([
            $app . '/broken.php' => [
                'errors' => 1,
                'warnings' => 1,
                'messages' => [
                    ['message' => 'Function each() is deprecated since PHP 7.2 and removed since PHP 8.0; Use a foreach loop or ArrayIterator instead', 'source' => 'PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved', 'severity' => 5, 'fixable' => false, 'type' => 'ERROR', 'line' => 10, 'column' => 12],
                    ['message' => 'Using ${var} in strings is deprecated since PHP 8.2, use {$var} instead. Found: ${name}', 'source' => 'PHPCompatibility.TextStrings.RemovedDollarBraceStringEmbeds.DeprecatedVariableSyntax', 'severity' => 5, 'fixable' => false, 'type' => 'WARNING', 'line' => 26, 'column' => 9],
                ],
            ],
        ]);
        $fake = $this->phpcsFake(static fn(): ProcessResult => new ProcessResult(1, (string) json_encode($json), ''));

        $scan = (new PhpCompatScan($fake))->scan($app, '8.0');

        self::assertTrue($scan->ok);
        self::assertSame('8.0', $scan->testVersion);
        self::assertSame(1, $scan->errorCount());
        self::assertSame(1, $scan->warningCount());
        self::assertSame('broken.php', $scan->findings[0]->file);
        self::assertSame(10, $scan->findings[0]->line);
        self::assertSame('error', $scan->findings[0]->severity);
        self::assertSame('PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved', $scan->findings[0]->source);
        self::assertSame('warning', $scan->findings[1]->severity);
    }

    public function test_the_command_asks_for_the_exact_target_version(): void
    {
        $app = $this->tempDir();
        $json = $this->phpcsJson([]);
        $fake = $this->phpcsFake(static fn(): ProcessResult => new ProcessResult(0, (string) json_encode($json), ''));

        (new PhpCompatScan($fake))->scan($app, '8.4');

        // The first process call is the `phpcs -i` discovery check; the scan
        // itself is the one that carries --standard=PHPCompatibility.
        $scanCommands = $fake->commandsContaining('--standard=PHPCompatibility');
        self::assertCount(1, $scanCommands);
        $command = $scanCommands[0];
        self::assertContains('--standard=PHPCompatibility', $command);
        $i = array_search('--runtime-set', $command, true);
        self::assertFalse($i === false);
        self::assertSame('testVersion', $command[$i + 1]);
        self::assertSame('8.4', $command[$i + 2]);
        self::assertContains('--report=json', $command);
        self::assertContains('--extensions=php,inc', $command);
        self::assertContains('--ignore=(?:^|/)(?:vendor|node_modules|storage)(?:/|\z)', $command);
    }

    public function test_boosters_own_phpcs_gets_the_installed_paths_flag(): void
    {
        // The app has no phpcs of its own, so discovery lands on Booster's
        // vendor/bin/phpcs (present once Booster's dependencies are
        // installed). Both the -i probe and the scan must point phpcs at the
        // bundled standard with --runtime-set installed_paths.
        $app = $this->tempDir();
        $json = $this->phpcsJson([]);
        $fake = $this->phpcsFake(static fn(): ProcessResult => new ProcessResult(0, (string) json_encode($json), ''));

        (new PhpCompatScan($fake))->scan($app, '8.0');

        $own = PharFiles::path('vendor') . '/bin/phpcs';
        self::assertContains($own, $fake->calls[0]['command']);
        $expected = PharFiles::path('vendor') . '/phpcompatibility/php-compatibility';
        $commands = array_merge($fake->commandsContaining('-i'), $fake->commandsContaining('--standard=PHPCompatibility'));
        self::assertCount(2, $commands);
        foreach ($commands as $command) {
            $i = array_search('installed_paths', $command, true);
            self::assertFalse($i === false);
            self::assertSame('--runtime-set', $command[$i - 1]);
            self::assertSame($expected, $command[$i + 1]);
        }
    }

    public function test_the_app_and_path_launchers_get_no_installed_paths_flag(): void
    {
        $json = $this->phpcsJson([]);
        $clean = static fn(): ProcessResult => new ProcessResult(0, (string) json_encode($json), '');
        $own = PharFiles::path('vendor') . '/bin/phpcs';

        // The app's own phpcs wins discovery: neither its -i probe nor the
        // scan may carry the flag.
        $app = $this->tempDir();
        mkdir($app . '/vendor/bin', 0775, true);
        file_put_contents($app . '/vendor/bin/phpcs', '');
        $appFake = $this->phpcsFake($clean);

        (new PhpCompatScan($appFake))->scan($app, '8.0');

        self::assertContains($app . '/vendor/bin/phpcs', $appFake->calls[0]['command']);
        self::assertCount(0, $appFake->commandsContaining('installed_paths'));

        // With Booster's own phpcs unable to run, discovery falls through to
        // phpcs on the PATH. The probe of Booster's own copy keeps the flag
        // (it is Booster's launcher); the PATH launcher's probe and scan must
        // not carry it.
        $bare = $this->tempDir();
        $pathFake = new FakeProcessRunner(static function (array $command) use ($clean, $own): ProcessResult {
            if (in_array('-i', $command, true)) {
                if (in_array($own, $command, true)) {
                    return new ProcessResult(1, '', 'could not run');
                }

                return new ProcessResult(0, self::PHPCOMPAT_STANDARDS, '');
            }

            return $clean();
        });

        (new PhpCompatScan($pathFake))->scan($bare, '8.0');

        self::assertContains('phpcs', $pathFake->calls[1]['command']);
        self::assertContains('installed_paths', $pathFake->calls[0]['command']);
        self::assertNotContains('installed_paths', $pathFake->calls[1]['command']);
        self::assertNotContains('installed_paths', $pathFake->calls[2]['command']);
    }

    public function test_a_clean_app_has_no_findings(): void
    {
        $app = $this->tempDir();
        $json = $this->phpcsJson([]);
        $fake = $this->phpcsFake(static fn(): ProcessResult => new ProcessResult(0, (string) json_encode($json), ''));

        $scan = (new PhpCompatScan($fake))->scan($app, '8.5');

        self::assertTrue($scan->ok);
        self::assertSame([], $scan->findings);
        self::assertSame(0, $scan->errorCount());
        self::assertSame(0, $scan->warningCount());
    }

    public function test_a_missing_binary_is_reported_not_thrown(): void
    {
        // The real runner against a path that does not exist. phpcs runs under
        // Booster's own PHP, so a missing binary is PHP's "Could not open
        // input file" (exit 1, no JSON): the scan turns that into a scan error
        // instead of an exception.
        $scan = (new PhpCompatScan(new SymfonyProcessRunner(), '/nonexistent/phpcs'))->scan('/srv/app', '8.0');

        self::assertFalse($scan->ok);
        self::assertStringContainsString('without a JSON report', (string) $scan->error);
        self::assertStringContainsString('/nonexistent/phpcs', (string) $scan->error);
    }

    public function test_a_running_phpcs_without_the_standard_points_at_reinstalling_boosters_dependencies(): void
    {
        $app = $this->tempDir();
        // The app's own phpcs is present: it runs, but its standard list
        // lacks PHPCompatibility, as does every other candidate's.
        mkdir($app . '/vendor/bin', 0775, true);
        file_put_contents($app . '/vendor/bin/phpcs', '');
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(0, "The installed standards are PEAR, PSR1, PSR2, PSR12, Squiz, Zend\n", ''));

        $scan = (new PhpCompatScan($fake))->scan($app, '8.0');

        self::assertFalse($scan->ok);
        self::assertSame("PHPCompatibility is not registered. Booster ships the tool with its dependencies; reinstall Booster's dependencies with: composer install in Booster's folder, or download the PHAR again", (string) $scan->error);
    }

    public function test_no_running_phpcs_points_at_reinstalling_boosters_dependencies(): void
    {
        $app = $this->tempDir();
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(1, '', 'phpcs: command not found'));

        $scan = (new PhpCompatScan($fake))->scan($app, '8.0');

        self::assertFalse($scan->ok);
        self::assertSame("PHP compatibility scan skipped: no PHP_CodeSniffer carrying PHPCompatibility could run. Booster ships the tool; reinstall Booster's dependencies with: composer install in Booster's folder, or download the PHAR again", (string) $scan->error);
    }

    public function test_a_crash_without_json_is_a_scan_error(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(4, '', 'segfault'));

        $scan = (new PhpCompatScan($fake, '/bin/true'))->scan('/srv/app', '8.0');

        self::assertFalse($scan->ok);
        self::assertStringContainsString('exited 4', (string) $scan->error);
    }

    public function test_internal_no_code_found_is_dropped_from_findings(): void
    {
        $app = $this->tempDir();
        $json = $this->phpcsJson([
            $app . '/include.html' => [
                'errors' => 1,
                'warnings' => 0,
                'messages' => [
                    ['message' => 'No code found in this file', 'source' => 'Internal.NoCodeFound', 'severity' => 5, 'fixable' => false, 'type' => 'ERROR', 'line' => 1, 'column' => 1],
                ],
            ],
            $app . '/broken.php' => [
                'errors' => 1,
                'warnings' => 0,
                'messages' => [
                    ['message' => 'Function each() is deprecated since PHP 7.2 and removed since PHP 8.0; Use a foreach loop or ArrayIterator instead', 'source' => 'PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved', 'severity' => 5, 'fixable' => false, 'type' => 'ERROR', 'line' => 10, 'column' => 12],
                ],
            ],
        ]);
        $fake = $this->phpcsFake(static fn(): ProcessResult => new ProcessResult(1, (string) json_encode($json), ''));

        $scan = (new PhpCompatScan($fake))->scan($app, '8.0');

        self::assertTrue($scan->ok);
        self::assertCount(1, $scan->findings);
        self::assertSame('broken.php', $scan->findings[0]->file);
        self::assertSame('PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved', $scan->findings[0]->source);
    }

    public function test_the_result_serialises_for_json_output(): void
    {
        $app = $this->tempDir();
        $json = $this->phpcsJson([
            $app . '/a.php' => [
                'errors' => 1,
                'warnings' => 0,
                'messages' => [['message' => 'm', 'source' => 's', 'severity' => 5, 'fixable' => false, 'type' => 'ERROR', 'line' => 3, 'column' => 1]],
            ],
        ]);
        $fake = $this->phpcsFake(static fn(): ProcessResult => new ProcessResult(1, (string) json_encode($json), ''));

        $array = (new PhpCompatScan($fake))->scan($app, '8.1')->toArray();

        self::assertSame('ok', $array['status']);
        self::assertSame('8.1', $array['test_version']);
        self::assertNull($array['error']);
        self::assertSame(1, $array['errors']);
        self::assertSame(0, $array['warnings']);
        self::assertSame('a.php', $array['findings'][0]['file']);
        self::assertSame(3, $array['findings'][0]['line']);
    }

    public function test_oci8_declaration_edge_cases(): void
    {
        $makeJson = function (string $app): array {
            return $this->phpcsJson([
                $app . '/oracle-check.php' => [
                    'errors' => 2,
                    'warnings' => 0,
                    'messages' => [
                        ['message' => 'Function oci_error() is removed since PHP 8.4', 'source' => 'PHPCompatibility.FunctionUse.RemovedFunctions.oci_errorRemoved', 'severity' => 5, 'fixable' => false, 'type' => 'ERROR', 'line' => 181, 'column' => 9],
                        ['message' => 'Function mysql_connect() is removed since PHP 7.0', 'source' => 'PHPCompatibility.FunctionUse.RemovedFunctions.mysql_connectRemoved', 'severity' => 5, 'fixable' => false, 'type' => 'ERROR', 'line' => 200, 'column' => 9],
                    ],
                ],
            ]);
        };

        // require-dev declares oci8 too.
        $app = $this->tempDir();
        file_put_contents($app . '/composer.json', (string) json_encode(['require-dev' => ['ext-oci8' => '*']]));
        $fake = $this->phpcsFake(static fn(): ProcessResult => new ProcessResult(1, (string) json_encode($makeJson($app)), ''));
        $scan = (new PhpCompatScan($fake))->scan($app, '8.4');
        self::assertTrue($scan->ok);
        self::assertCount(1, $scan->findings);
        self::assertSame('PHPCompatibility.FunctionUse.RemovedFunctions.mysql_connectRemoved', $scan->findings[0]->source);

        // Invalid JSON behaves exactly as today: oci finding kept.
        $app = $this->tempDir();
        file_put_contents($app . '/composer.json', '{not json');
        $fake = $this->phpcsFake(static fn(): ProcessResult => new ProcessResult(1, (string) json_encode($makeJson($app)), ''));
        $scan = (new PhpCompatScan($fake))->scan($app, '8.4');
        self::assertTrue($scan->ok);
        self::assertCount(2, $scan->findings);

        // ext-oci8 in another section does not declare it: oci finding kept.
        $app = $this->tempDir();
        file_put_contents($app . '/composer.json', (string) json_encode(['suggest' => ['ext-oci8' => 'Oracle support']]));
        $fake = $this->phpcsFake(static fn(): ProcessResult => new ProcessResult(1, (string) json_encode($makeJson($app)), ''));
        $scan = (new PhpCompatScan($fake))->scan($app, '8.4');
        self::assertTrue($scan->ok);
        self::assertCount(2, $scan->findings);

        // The key alone declares it, even with a null value.
        $app = $this->tempDir();
        file_put_contents($app . '/composer.json', (string) json_encode(['config' => ['platform' => ['ext-oci8' => null]]]));
        $fake = $this->phpcsFake(static fn(): ProcessResult => new ProcessResult(1, (string) json_encode($makeJson($app)), ''));
        $scan = (new PhpCompatScan($fake))->scan($app, '8.4');
        self::assertTrue($scan->ok);
        self::assertCount(1, $scan->findings);

        // Match is case insensitive, and only the exact removed-since pattern drops.
        $app = $this->tempDir();
        file_put_contents($app . '/composer.json', (string) json_encode(['require' => ['ext-oci8' => '*']]));
        $json = $this->phpcsJson([
            $app . '/oracle-check.php' => [
                'errors' => 3,
                'warnings' => 0,
                'messages' => [
                    ['message' => 'Function OCI_ERROR() is removed since PHP 8.4', 'source' => 's1', 'severity' => 5, 'fixable' => false, 'type' => 'ERROR', 'line' => 1, 'column' => 1],
                    ['message' => 'Function oci_fetch_all() is deprecated since PHP 5.5 and removed since PHP 8.4', 'source' => 's2', 'severity' => 5, 'fixable' => false, 'type' => 'ERROR', 'line' => 2, 'column' => 1],
                    ['message' => 'The function oci_error() is removed since PHP 8.4', 'source' => 's3', 'severity' => 5, 'fixable' => false, 'type' => 'ERROR', 'line' => 3, 'column' => 1],
                ],
            ],
        ]);
        $fake = $this->phpcsFake(static fn(): ProcessResult => new ProcessResult(1, (string) json_encode($json), ''));
        $scan = (new PhpCompatScan($fake))->scan($app, '8.4');
        self::assertTrue($scan->ok);
        self::assertCount(2, $scan->findings);
        self::assertSame('s2', $scan->findings[0]->source);
        self::assertSame('s3', $scan->findings[1]->source);

        // Dropping findings changes nothing about the result shape.
        $array = $scan->toArray();
        self::assertSame(['status', 'test_version', 'error', 'errors', 'warnings', 'findings', 'hints'], array_keys($array));
        self::assertSame('ok', $array['status']);
        self::assertSame('8.4', $array['test_version']);
        self::assertNull($array['error']);
        self::assertSame(2, $array['errors']);
        self::assertSame(0, $array['warnings']);
        self::assertSame([], $array['hints']);
    }

    public function test_config_platform_ext_oci8_drops_oci_removed_findings(): void
    {
        $app = $this->tempDir();
        file_put_contents($app . '/composer.json', (string) json_encode([
            'name' => 'wayne/oracle-check',
            'config' => ['platform' => ['php' => '8.3.0', 'ext-oci8' => '8.4']],
        ]));
        $json = $this->phpcsJson([
            $app . '/oracle-check.php' => [
                'errors' => 2,
                'warnings' => 0,
                'messages' => [
                    ['message' => 'Function oci_error() is removed since PHP 8.4', 'source' => 'PHPCompatibility.FunctionUse.RemovedFunctions.oci_errorRemoved', 'severity' => 5, 'fixable' => false, 'type' => 'ERROR', 'line' => 181, 'column' => 9],
                    ['message' => 'Function mysql_connect() is removed since PHP 7.0', 'source' => 'PHPCompatibility.FunctionUse.RemovedFunctions.mysql_connectRemoved', 'severity' => 5, 'fixable' => false, 'type' => 'ERROR', 'line' => 200, 'column' => 9],
                ],
            ],
        ]);
        $fake = $this->phpcsFake(static fn(): ProcessResult => new ProcessResult(1, (string) json_encode($json), ''));

        $scan = (new PhpCompatScan($fake))->scan($app, '8.4');

        self::assertTrue($scan->ok);
        self::assertCount(1, $scan->findings);
        self::assertSame('oracle-check.php', $scan->findings[0]->file);
        self::assertSame(200, $scan->findings[0]->line);
        self::assertSame('PHPCompatibility.FunctionUse.RemovedFunctions.mysql_connectRemoved', $scan->findings[0]->source);
    }

    public function test_require_ext_oci8_drops_oci_removed_findings(): void
    {
        $app = $this->tempDir();
        file_put_contents($app . '/composer.json', (string) json_encode([
            'name' => 'wayne/oracle-check',
            'require' => ['php' => '^8.3', 'ext-oci8' => '*'],
        ]));
        $json = $this->phpcsJson([
            $app . '/oracle-check.php' => [
                'errors' => 2,
                'warnings' => 0,
                'messages' => [
                    ['message' => 'Function oci_error() is removed since PHP 8.4', 'source' => 'PHPCompatibility.FunctionUse.RemovedFunctions.oci_errorRemoved', 'severity' => 5, 'fixable' => false, 'type' => 'ERROR', 'line' => 181, 'column' => 9],
                    ['message' => 'Function mysql_connect() is removed since PHP 7.0', 'source' => 'PHPCompatibility.FunctionUse.RemovedFunctions.mysql_connectRemoved', 'severity' => 5, 'fixable' => false, 'type' => 'ERROR', 'line' => 200, 'column' => 9],
                ],
            ],
        ]);
        $fake = $this->phpcsFake(static fn(): ProcessResult => new ProcessResult(1, (string) json_encode($json), ''));

        $scan = (new PhpCompatScan($fake))->scan($app, '8.4');

        self::assertTrue($scan->ok);
        self::assertCount(1, $scan->findings);
        self::assertSame('oracle-check.php', $scan->findings[0]->file);
        self::assertSame(200, $scan->findings[0]->line);
        self::assertSame('PHPCompatibility.FunctionUse.RemovedFunctions.mysql_connectRemoved', $scan->findings[0]->source);
    }

    public function test_without_ext_oci8_the_oci_removed_finding_is_kept(): void
    {
        $app = $this->tempDir();
        $json = $this->phpcsJson([
            $app . '/oracle-check.php' => [
                'errors' => 2,
                'warnings' => 0,
                'messages' => [
                    ['message' => 'Function oci_error() is removed since PHP 8.4', 'source' => 'PHPCompatibility.FunctionUse.RemovedFunctions.oci_errorRemoved', 'severity' => 5, 'fixable' => false, 'type' => 'ERROR', 'line' => 181, 'column' => 9],
                    ['message' => 'Function mysql_connect() is removed since PHP 7.0', 'source' => 'PHPCompatibility.FunctionUse.RemovedFunctions.mysql_connectRemoved', 'severity' => 5, 'fixable' => false, 'type' => 'ERROR', 'line' => 200, 'column' => 9],
                ],
            ],
        ]);
        $fake = $this->phpcsFake(static fn(): ProcessResult => new ProcessResult(1, (string) json_encode($json), ''));

        $scan = (new PhpCompatScan($fake))->scan($app, '8.4');

        self::assertTrue($scan->ok);
        self::assertCount(2, $scan->findings);
        self::assertSame('PHPCompatibility.FunctionUse.RemovedFunctions.oci_errorRemoved', $scan->findings[0]->source);
        self::assertSame('PHPCompatibility.FunctionUse.RemovedFunctions.mysql_connectRemoved', $scan->findings[1]->source);
    }
}
