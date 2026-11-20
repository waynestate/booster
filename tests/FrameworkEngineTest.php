<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Hop\EngineId;
use Waynestate\Booster\Hop\Hop;
use Waynestate\Booster\Hop\HopKind;
use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Plan\Plan;
use Waynestate\Booster\Plan\PlanOptions;
use Waynestate\Booster\Plan\Planner;
use Waynestate\Booster\Plan\PlanStep;
use Waynestate\Booster\Project\ProjectDetector;
use Waynestate\Booster\Run\ComposerEditor;
use Waynestate\Booster\Run\FrameworkEngine;
use Waynestate\Booster\Run\HopExecutor;
use Waynestate\Booster\Run\ProcessResult;
use Waynestate\Booster\Run\RectorRunner;
use Waynestate\Booster\Run\RunOptions;
use Waynestate\Booster\Run\RunReport;
use Waynestate\Booster\Run\StepResult;
use Waynestate\Booster\Run\TargetImageRunner;

/**
 * Framework hops name their engine, and the executor runs the one the hop
 * names. The hop is the menu; the engine is the kitchen.
 */
final class FrameworkEngineTest extends TestCase
{
    public function test_hop_to_array_has_no_engine_key_when_null(): void
    {
        $hop = new Hop(id: 'php-84-to-85', kind: HopKind::Php, from: '8.4', to: '8.5', summary: 'PHP 8.5.');

        self::assertNull($hop->engine);
        self::assertArrayNotHasKey('engine', $hop->toArray());
        self::assertArrayNotHasKey('engine', (new HopRegistry())->get('php-84-to-85')->toArray());
    }

    public function test_hop_to_array_names_the_engine(): void
    {
        $hop = new Hop(id: 'a-framework-hop', kind: HopKind::Framework, from: '4', to: '4.6', summary: 'A framework hop.', engine: EngineId::CakeUpgrade);

        self::assertSame('cake-upgrade', $hop->toArray()['engine']);
        self::assertFalse($hop->isManual(), 'A framework hop runs through its engine, so it is never a playbook.');
        self::assertSame(['vendored', 'cake-upgrade', 'slim-upgrade'], array_map(static fn(EngineId $id): string => $id->value, EngineId::cases()));
    }

    public function test_vendored_hop_declares_the_vendored_engine(): void
    {
        $hop = (new HopRegistry())->get(HopRegistry::VENDORED_TO_COMPOSER_ID);

        self::assertSame(HopKind::Framework, $hop->kind);
        self::assertSame(EngineId::Vendored, $hop->engine);
        self::assertSame('vendored', $hop->toArray()['engine']);
    }

    public function test_framework_hop_runs_the_registered_engine(): void
    {
        $dir = $this->app();
        $engine = self::fakeEngine();
        $hop = self::hopWith($this->vendoredPlan($dir)->steps[0]->hop, 'fake-framework-hop', EngineId::CakeUpgrade);

        $report = $this->execute($this->withHop($this->vendoredPlan($dir), $hop), [EngineId::CakeUpgrade->value => $engine]);

        $result = $report->results[0];
        self::assertSame(['fake-framework-hop', StepResult::PREVIEWED], [$result->hopId, $result->status]);
        self::assertContains('Dry run: would move the fake framework.', $result->messages);
        self::assertSame($hop, $engine->received);
        self::assertTrue($engine->dryRun);
        self::assertSame('7.4', $engine->php);
        self::assertDirectoryExists($dir . '/vendor/Slim', 'The vendored engine never ran.');
    }

    public function test_framework_hop_with_no_registered_engine_errors(): void
    {
        $dir = $this->app();
        $hop = self::hopWith($this->vendoredPlan($dir)->steps[0]->hop, 'fake-slim-hop', EngineId::SlimUpgrade);

        $report = $this->execute($this->withHop($this->vendoredPlan($dir), $hop), [EngineId::CakeUpgrade->value => self::fakeEngine()]);

        $result = $report->results[0];
        self::assertSame(['fake-slim-hop', StepResult::ERROR], [$result->hopId, $result->status]);
        self::assertSame('No engine is registered for the slim-upgrade hop engine, so nothing changed.', $result->messages[0]);
        self::assertSame([], $result->files);
    }

    public function test_hop_without_an_engine_uses_the_vendored_migration(): void
    {
        $dir = $this->app();
        $engine = self::fakeEngine();
        $hop = self::hopWith($this->vendoredPlan($dir)->steps[0]->hop, HopRegistry::VENDORED_TO_COMPOSER_ID, null);
        self::assertNull($hop->engine);

        $report = $this->execute($this->withHop($this->vendoredPlan($dir), $hop), [EngineId::CakeUpgrade->value => $engine]);

        $result = $report->results[0];
        self::assertSame([HopRegistry::VENDORED_TO_COMPOSER_ID, StepResult::PREVIEWED], [$result->hopId, $result->status]);
        self::assertStringStartsWith('Dry run: would replace the hand-copied Doctrine 2.2.2', $result->messages[0]);
        self::assertNull($engine->received, 'A hop with no engine runs VendoredMigration, not another registered engine.');
    }

    /** A copy of the 2012 fixture app, the one the vendored hop tests run on. */
    private function app(): string
    {
        $dir = $this->tempDir();
        $from = __DIR__ . '/fixtures/vendored-2012';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            self::assertInstanceOf(\SplFileInfo::class, $file);
            $target = $dir . substr($file->getPathname(), strlen($from));
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0775, true);
            }
            copy($file->getPathname(), $target);
        }

        return $dir;
    }

    /** The one-step plan with the vendored-to-composer hop, on PHP 7.4. */
    private function vendoredPlan(string $dir): Plan
    {
        $plan = (new Planner($this->policy(), new HopRegistry()))->plan((new ProjectDetector())->detect($dir), new PlanOptions(fromPhp: '7.4', toPhp: '7.4', vendored: true), $this->today());
        self::assertSame([HopRegistry::VENDORED_TO_COMPOSER_ID], array_map(static fn(PlanStep $s): string => $s->hop->id, $plan->steps));

        return $plan;
    }

    /** The same plan with its one step's hop swapped for $hop. */
    private function withHop(Plan $plan, Hop $hop): Plan
    {
        $step = $plan->steps[0];
        $steps = [new PlanStep($step->number, $hop, $step->phpBefore, $step->phpAfter, $step->laravelBefore, $step->laravelAfter, $step->phpunitBefore, $step->phpunitAfter, $step->gates, $step->frameworkBefore, $step->frameworkAfter)];

        return new Plan(
            project: $plan->project,
            today: $plan->today,
            policyOrigin: $plan->policyOrigin,
            targetPhp: $plan->targetPhp,
            targetLaravel: $plan->targetLaravel,
            targetPhpunit: $plan->targetPhpunit,
            steps: $steps,
            warnings: $plan->warnings,
            packageChecks: $plan->packageChecks,
            stopsEarly: $plan->stopsEarly,
            vendoredLibraries: $plan->vendoredLibraries,
            fromPhp: $plan->fromPhp,
            frameworkTarget: $plan->frameworkTarget,
        );
    }

    private static function hopWith(Hop $hop, string $id, ?EngineId $engine): Hop
    {
        return new Hop(
            id: $id,
            kind: HopKind::Framework,
            from: $hop->from,
            to: $hop->to,
            summary: $hop->summary,
            notes: $hop->notes,
            links: $hop->links,
            engine: $engine,
        );
    }

    /**
     * A dry run through HopExecutor with a FakeProcessRunner: no Docker, no
     * Rector, no git.
     *
     * @param array<string, FrameworkEngine> $engines
     */
    private function execute(Plan $plan, array $engines): RunReport
    {
        // The same process seam as VendoredHopExecutorTest: Docker and git
        // answer through ReplayFake, so the baseline passes.
        $docker = ReplayFake::runner([]);
        $fake = new FakeProcessRunner(static function (array $command) use ($docker): ProcessResult {
            if (in_array($command[0], ['git', 'docker'], true)) {
                return $docker->run($command);
            }

            return new ProcessResult(0, (string) json_encode(['file_diffs' => [], 'errors' => []]), '');
        });

        return (new HopExecutor(
            new RectorRunner($fake, '/fake/rector'),
            new ComposerEditor(),
            new TargetImageRunner($fake, dirname(__DIR__) . '/docker'),
            $fake,
            frameworkEngines: $engines,
        ))->execute($plan, new RunOptions(dryRun: true));
    }

    /** An engine that records what it was handed and reports one change. */
    private static function fakeEngine(): FakeFrameworkEngine
    {
        return new FakeFrameworkEngine();
    }
}
