<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Console\RunCommand;
use Waynestate\Booster\Hop\EngineId;
use Waynestate\Booster\Run\Cake\CakeUpgradeMigration;
use Waynestate\Booster\Run\ComposerEditor;
use Waynestate\Booster\Run\ProcessResult;
use Waynestate\Booster\Run\Slim\SlimUpgradeMigration;

/**
 * `booster run` hands the executor the SlimUpgrade engine, so a hop that
 * names it finds it. A band with no drummer still plays; it just plays badly.
 */
final class SlimUpgradeWiringTest extends TestCase
{
    public function test_run_command_registers_the_slim_upgrade_engine(): void
    {
        $engines = RunCommand::frameworkEngines(
            new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(0, '', '')),
            new ComposerEditor(),
        );

        self::assertArrayHasKey(EngineId::SlimUpgrade->value, $engines);
        self::assertInstanceOf(SlimUpgradeMigration::class, $engines['slim-upgrade']);
        self::assertInstanceOf(CakeUpgradeMigration::class, $engines[EngineId::CakeUpgrade->value] ?? null, 'the CakePHP engine rides in the same map');

        // The map reaches the executor the run command builds.
        $source = (string) file_get_contents(dirname(__DIR__) . '/src/Console/RunCommand.php');
        self::assertStringContainsString('frameworkEngines: self::frameworkEngines($this->processes, $composerEditor),', $source);
    }
}
