<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Run\ProcessResult;
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
}
