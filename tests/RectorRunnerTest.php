<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Run\ProcessResult;
use Waynestate\Booster\Run\RectorResult;
use Waynestate\Booster\Run\RectorRunner;
use Waynestate\Booster\ToolError;

final class RectorRunnerTest extends TestCase
{
    public function test_the_default_memory_limit_is_two_gigabytes(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(2, '{"file_diffs": [], "errors": []}', ''));
        (new RectorRunner($fake, '/fake/rector'))->run((new HopRegistry())->get('php-74-to-80'), '/srv/acme-app', true, '8.0');

        self::assertSame('2G', RectorRunner::DEFAULT_MEMORY_LIMIT);
        self::assertCount(1, $fake->commandsContaining('--memory-limit=2G'));
    }

    public function test_a_custom_memory_limit_is_passed_to_rector(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(2, '{"file_diffs": [], "errors": []}', ''));
        (new RectorRunner($fake, '/fake/rector'))->run((new HopRegistry())->get('php-74-to-80'), '/srv/acme-app', true, '8.0', null, null, '4G');

        self::assertCount(1, $fake->commandsContaining('--memory-limit=4G'));
    }

    public function test_unlimited_memory_is_allowed(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(2, '{"file_diffs": [], "errors": []}', ''));
        (new RectorRunner($fake, '/fake/rector'))->run((new HopRegistry())->get('php-74-to-80'), '/srv/acme-app', true, '8.0', null, null, '-1');

        self::assertCount(1, $fake->commandsContaining('--memory-limit=-1'));
    }

    public function test_an_invalid_memory_limit_is_refused_naming_the_option(): void
    {
        foreach (['banana', '1.5G', '2T', '2 g', '1G1'] as $bad) {
            try {
                RectorRunner::assertMemoryLimit($bad);
                self::fail($bad . ' should have been refused');
            } catch (ToolError $e) {
                self::assertStringContainsString('--memory-limit', $e->getMessage());
                self::assertStringContainsString($bad, $e->getMessage());
            }
        }
    }

    public function test_run_refuses_an_invalid_memory_limit_before_running_anything(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => self::fail('Rector should not run'));
        $this->expectException(ToolError::class);
        $this->expectExceptionMessageMatches('/--memory-limit/');
        (new RectorRunner($fake, '/fake/rector'))->run((new HopRegistry())->get('php-74-to-80'), '/srv/acme-app', true, '8.0', null, null, 'lots');
    }

    public function test_empty_stdout_points_at_the_memory_limit(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(255, '', 'Fatal error: Allowed memory size of 2147483648 bytes exhausted'));
        try {
            (new RectorRunner($fake, '/fake/rector'))->run((new HopRegistry())->get('php-74-to-80'), '/srv/acme-app', true, '8.0');
            self::fail('expected a ToolError');
        } catch (ToolError $e) {
            self::assertStringContainsString('Rector produced no JSON report (exit 255)', $e->getMessage());
            self::assertStringContainsString('--memory-limit=2G', $e->getMessage());
            self::assertStringContainsString('--memory-limit can raise the limit', $e->getMessage());
            self::assertStringContainsString('Allowed memory size', $e->getMessage());
        }
    }

    public function test_empty_stdout_names_a_custom_memory_limit(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(255, '', ''));
        try {
            (new RectorRunner($fake, '/fake/rector'))->run((new HopRegistry())->get('php-74-to-80'), '/srv/acme-app', true, '8.0', null, null, '4G');
            self::fail('expected a ToolError');
        } catch (ToolError $e) {
            self::assertStringContainsString('--memory-limit=4G', $e->getMessage());
        }
    }

    public function test_hopeless_stdout_throws_a_clear_tool_error(): void
    {
        $stderr = "first-line-of-stderr\n" . str_repeat('padding line\n', 100) . 'last-line-of-stderr';
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(255, 'this is not json at all', $stderr));
        try {
            (new RectorRunner($fake, '/fake/rector'))->run((new HopRegistry())->get('php-74-to-80'), '/srv/acme-app', true, '8.0');
            self::fail('expected a ToolError');
        } catch (ToolError $e) {
            $message = $e->getMessage();
            self::assertStringContainsString('Rector output was not valid JSON', $message);
            self::assertStringContainsString('Syntax error', $message);
            self::assertStringContainsString('exit 255', $message);
            self::assertStringContainsString('first-line-of-stderr', $message);
            // The stderr excerpt is capped at 500 characters.
            self::assertStringNotContainsString('last-line-of-stderr', $message);
        }
    }

    public function test_invalid_utf8_in_the_report_is_repaired_and_named(): void
    {
        // Lone 0xFF and 0xFE bytes are not valid UTF-8, so strict json_decode fails
        // with JSON_ERROR_UTF8. chr() keeps the raw bytes out of this source file.
        $stdout = '{"file_diffs": [{"file": "/srv/acme-app/src/Bad.php", "diff": "+@@@"}], "errors": []}';
        $stdout = str_replace('@@@', chr(255) . chr(254), $stdout);
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(2, $stdout, ''));
        $result = (new RectorRunner($fake, '/fake/rector'))->run((new HopRegistry())->get('php-74-to-80'), '/srv/acme-app', true, '8.0');

        self::assertCount(1, $result->files);
        self::assertSame('src/Bad.php', $result->files[0]['file']);
        self::assertStringContainsString("\u{FFFD}", $result->files[0]['diff']);
        self::assertSame([RectorResult::REPAIRED_UTF8_NOTE . ' (src/Bad.php)'], $result->errors);
    }

    public function test_repaired_utf8_outside_the_diffs_gets_a_bare_note(): void
    {
        $stdout = '{"file_diffs": [], "errors": ["bad @@@ byte"]}';
        $stdout = str_replace('@@@', chr(255), $stdout);
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(2, $stdout, ''));
        $result = (new RectorRunner($fake, '/fake/rector'))->run((new HopRegistry())->get('php-74-to-80'), '/srv/acme-app', true, '8.0');

        self::assertSame([], $result->files);
        self::assertSame(RectorResult::REPAIRED_UTF8_NOTE, $result->errors[0]);
        self::assertCount(2, $result->errors);
        self::assertStringContainsString("\u{FFFD}", $result->errors[1]);
    }
}
