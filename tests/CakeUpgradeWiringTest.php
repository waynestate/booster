<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Console\RunCommand;
use Waynestate\Booster\Hop\EngineId;
use Waynestate\Booster\Run\Cake\CakeUpgradeMigration;
use Waynestate\Booster\Run\ComposerEditor;
use Waynestate\Booster\Run\ProcessResult;

/**
 * `booster run` hands the executor the CakeUpgrade engine, so a hop that
 * names it never meets "No engine is registered".
 */
final class CakeUpgradeWiringTest extends TestCase
{
    public function test_run_command_registers_the_cake_upgrade_engine(): void
    {
        $engines = RunCommand::frameworkEngines(
            new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(0, '', '')),
            new ComposerEditor(),
        );

        self::assertArrayHasKey(EngineId::CakeUpgrade->value, $engines);
        self::assertInstanceOf(CakeUpgradeMigration::class, $engines[EngineId::CakeUpgrade->value]);
        self::assertArrayNotHasKey(EngineId::Vendored->value, $engines, 'the vendored engine stays the executor\'s own default');
    }
}
