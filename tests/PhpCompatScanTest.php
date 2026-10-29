<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

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

    public function test_a_running_phpcs_without_the_standard_points_at_the_blocked_plugin(): void
    {
        $app = $this->tempDir();
        // The app's own phpcs is present, as after a `composer require` whose
        // installer plugin Composer blocked: it runs, but its standard list
        // lacks PHPCompatibility.
        mkdir($app . '/vendor/bin', 0775, true);
        file_put_contents($app . '/vendor/bin/phpcs', '');
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(0, "The installed standards are PEAR, PSR1, PSR2, PSR12, Squiz, Zend\n", ''));

        $scan = (new PhpCompatScan($fake))->scan($app, '8.0');

        self::assertFalse($scan->ok);
        self::assertSame('PHPCompatibility is not registered. If composer blocked a plugin, run: composer config allow-plugins.dealerdirect/phpcodesniffer-composer-installer true, then composer require --dev phpcompatibility/php-compatibility:"^10.0@alpha"', (string) $scan->error);
    }

    public function test_no_running_phpcs_keeps_the_install_hint_with_the_plugin_approval(): void
    {
        $app = $this->tempDir();
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(1, '', 'phpcs: command not found'));

        $scan = (new PhpCompatScan($fake))->scan($app, '8.0');

        self::assertFalse($scan->ok);
        self::assertSame('PHP compatibility scan skipped: PHPCompatibility is not installed. Enable it with: composer config allow-plugins.dealerdirect/phpcodesniffer-composer-installer true, then composer require --dev phpcompatibility/php-compatibility:"^10.0@alpha"', (string) $scan->error);
    }

    public function test_a_crash_without_json_is_a_scan_error(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(4, '', 'segfault'));

        $scan = (new PhpCompatScan($fake, '/bin/true'))->scan('/srv/app', '8.0');

        self::assertFalse($scan->ok);
        self::assertStringContainsString('exited 4', (string) $scan->error);
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
}
