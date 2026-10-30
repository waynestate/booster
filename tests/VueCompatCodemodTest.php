<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Plan\Plan;
use Waynestate\Booster\Plan\PlanOptions;
use Waynestate\Booster\Plan\PlanStep;
use Waynestate\Booster\Plan\Planner;
use Waynestate\Booster\Project\ProjectDetector;
use Waynestate\Booster\Run\ComposerEditor;
use Waynestate\Booster\Run\HopExecutor;
use Waynestate\Booster\Run\NodeRunner;
use Waynestate\Booster\Run\ProcessResult;
use Waynestate\Booster\Run\RectorRunner;
use Waynestate\Booster\Run\RunOptions;
use Waynestate\Booster\Run\RunReport;
use Waynestate\Booster\Run\StepResult;
use Waynestate\Booster\Run\TargetImageRunner;

/**
 * The vue-compat-codemod hop: the planner offers it after the compat build,
 * and the executor runs vue-upgrade-tool in the Node container, keeps every
 * manual item the tool lists as a message, and gates on the app's build.
 */
final class VueCompatCodemodTest extends TestCase
{
    public function test_the_planner_offers_the_codemod_after_the_compat_hop(): void
    {
        $dir = $this->copyExample('vue-spa');
        $plan = (new Planner($this->policy(), new HopRegistry()))->plan((new ProjectDetector())->detect($dir), new PlanOptions(), $this->today());
        $ids = $this->ids($plan);
        $compat = array_search(HopRegistry::VUE_27_TO_COMPAT_ID, $ids, true);

        self::assertIsInt($compat);
        self::assertSame(HopRegistry::VUE_COMPAT_CODEMOD_ID, $ids[$compat + 1]);
    }

    public function test_the_happy_path_runs_the_codemod_and_keeps_the_manual_items(): void
    {
        $dir = $this->tempDir();
        $this->makeApp($dir);
        $fake = $this->fake(
            $dir,
            "vue-upgrade-tool 1.0.37\n" . "src/App.vue: replace Vue.component with defineComponent\n" . 'src/main.js: rewrite the Vue.use(VueRouter) call' . "\n" . 'Done.',
        );
        $report = $this->runCodemod($fake, new RunOptions(), $dir);

        self::assertSame([StepResult::APPLIED], $this->statuses($report));
        self::assertFalse($report->failed());
        self::assertSame([], $report->results[0]->files);
        $messages = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString('vue-upgrade-tool@1.0.37 ran in the Node container; 2 manual item(s) remain.', $messages);
        self::assertStringContainsString('Manual item: src/App.vue: replace Vue.component with defineComponent', $messages);
        self::assertStringContainsString('Manual item: src/main.js: rewrite the Vue.use(VueRouter) call', $messages);
        self::assertStringContainsString('Done.', $messages);
        self::assertStringContainsString('Build gate passed: npm ci and npm run build are green in the Node container, and 2 manual item(s) remain from the codemod.', $messages);
        self::assertStringContainsString('vue-upgrade-tool is lightly maintained', $messages);

        $commands = array_map(static fn(array $call): array => $call['command'], $fake->calls);
        self::assertContains(['docker', 'run', '--rm', '-v', $dir . ':/app', '-w', '/app', 'node:24-bookworm-slim', 'npx', '--yes', 'vue-upgrade-tool@1.0.37', '--files', 'src/**/*'], $commands);
        self::assertContains(['docker', 'run', '--rm', '-v', $dir . ':/app', '-w', '/app', 'node:24-bookworm-slim', 'npm', 'ci'], $commands);
        self::assertContains(['docker', 'run', '--rm', '-v', $dir . ':/app', '-w', '/app', 'node:24-bookworm-slim', 'npm', 'run', 'build'], $commands);
        self::assertSame([], array_filter($commands, static fn(array $command): bool => in_array('npm', $command, true) && in_array('test', $command, true)));
    }

    public function test_an_app_without_the_compat_build_is_refused(): void
    {
        $dir = $this->tempDir();
        $this->makeApp($dir, false);
        $fake = $this->fake($dir);
        $report = $this->runCodemod($fake, new RunOptions(), $dir);

        self::assertSame([StepResult::ERROR], $this->statuses($report));
        self::assertTrue($report->failed());
        self::assertStringContainsString('needs vue-27-to-compat first', implode("\n", $report->results[0]->messages));
        self::assertSame([], $fake->commandsContaining('npx'));
    }

    public function test_a_dry_run_only_previews(): void
    {
        $dir = $this->tempDir();
        $this->makeApp($dir);
        $fake = $this->fake($dir);
        $report = $this->runCodemod($fake, new RunOptions(dryRun: true), $dir);

        self::assertSame([StepResult::PREVIEWED], $this->statuses($report));
        self::assertStringContainsString('Dry run: would run npx --yes vue-upgrade-tool@1.0.37 --files src/**/* in the Node container and gate on the build.', implode("\n", $report->results[0]->messages));
        self::assertSame([], $fake->calls);
    }

    public function test_manual_items_are_capped_at_twenty_lines(): void
    {
        $dir = $this->tempDir();
        $this->makeApp($dir);
        $lines = [];
        for ($i = 1; $i <= 25; $i++) {
            $lines[] = sprintf('src/File%d.vue: manual change %d', $i, $i);
        }
        $fake = $this->fake($dir, implode("\n", $lines));
        $report = $this->runCodemod($fake, new RunOptions(), $dir);

        self::assertSame([StepResult::APPLIED], $this->statuses($report));
        $messages = $report->results[0]->messages;
        self::assertContains('Manual item: src/File1.vue: manual change 1', $messages);
        self::assertContains('Manual item: src/File20.vue: manual change 20', $messages);
        self::assertNotContains('Manual item: src/File21.vue: manual change 21', $messages);
        self::assertContains('and 5 more', $messages);
        self::assertContains('vue-upgrade-tool@1.0.37 ran in the Node container; 25 manual item(s) remain.', $messages);
    }

    public function test_unrecognised_codemod_output_is_kept_whole_and_counts_nothing(): void
    {
        $dir = $this->tempDir();
        $this->makeApp($dir);
        $prose = "The upgrade could not be completed.\nPlease review your components by hand.\nGood luck!";
        $fake = $this->fake($dir, $prose);
        $report = $this->runCodemod($fake, new RunOptions(), $dir);

        self::assertSame([StepResult::APPLIED], $this->statuses($report));
        self::assertFalse($report->failed());
        $messages = $report->results[0]->messages;
        self::assertContains('codemod ran; manual items could not be counted, see the tool output', $messages);
        self::assertContains($prose, $messages);
        self::assertStringNotContainsString('manual item(s) remain', implode("\n", $messages));
    }

    public function test_long_unrecognised_codemod_output_is_trimmed_to_the_cap(): void
    {
        $dir = $this->tempDir();
        $this->makeApp($dir);
        $prose = str_repeat('Chatty line about migrating components. ', 300) . 'END-MARKER-OF-OUTPUT';
        $fake = $this->fake($dir, $prose);
        $report = $this->runCodemod($fake, new RunOptions(), $dir);

        self::assertSame([StepResult::APPLIED], $this->statuses($report));
        $raw = null;
        foreach ($report->results[0]->messages as $message) {
            if (str_contains($message, 'Chatty line')) {
                $raw = $message;
                break;
            }
        }
        self::assertIsString($raw);
        self::assertStringEndsWith('...', (string) $raw);
        self::assertLessThanOrEqual(4003, strlen((string) $raw));
        self::assertStringNotContainsString('END-MARKER-OF-OUTPUT', implode("\n", $report->results[0]->messages));
    }

    public function test_a_failing_build_gate_fails_the_step_but_keeps_the_items(): void
    {
        $dir = $this->tempDir();
        $this->makeApp($dir);
        $fake = $this->fake($dir, "src/App.vue: replace Vue.component with defineComponent\n", 0, 1);
        $report = $this->runCodemod($fake, new RunOptions(), $dir);

        self::assertSame([StepResult::GATE_FAILED], $this->statuses($report));
        self::assertTrue($report->failed());
        $messages = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString('Manual item: src/App.vue: replace Vue.component with defineComponent', $messages);
        self::assertStringContainsString('npm run build', $messages);
        self::assertStringContainsString('vite build failed', $messages);
        self::assertStringContainsString('Undo with: git checkout . (inside the app).', $messages);
    }

    public function test_npm_test_runs_only_when_the_app_has_it(): void
    {
        $dir = $this->tempDir();
        $this->makeApp($dir, true, true);
        $fake = $this->fake($dir);
        $report = $this->runCodemod($fake, new RunOptions(), $dir);

        self::assertSame([StepResult::APPLIED], $this->statuses($report));
        $commands = array_map(static fn(array $call): array => $call['command'], $fake->calls);
        self::assertContains(['docker', 'run', '--rm', '-v', $dir . ':/app', '-w', '/app', 'node:24-bookworm-slim', 'npm', 'test'], $commands);
    }

    public function test_a_crashing_codemod_fails_the_step(): void
    {
        $dir = $this->tempDir();
        $this->makeApp($dir);
        $fake = $this->fake($dir, 'boom', 1);
        $report = $this->runCodemod($fake, new RunOptions(), $dir);

        self::assertSame([StepResult::ERROR], $this->statuses($report));
        self::assertTrue($report->failed());
        $messages = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString('exited 1', $messages);
        self::assertStringContainsString('boom', $messages);
        self::assertStringContainsString('codemod crashed', $messages);
        self::assertStringContainsString('Undo with: git checkout . (inside the app).', $messages);
    }

    public function test_without_a_node_runner_the_step_errors(): void
    {
        $dir = $this->tempDir();
        $this->makeApp($dir);
        $fake = $this->fake($dir);
        $report = $this->runCodemod($fake, new RunOptions(), $dir, false);

        self::assertSame([StepResult::ERROR], $this->statuses($report));
        self::assertStringContainsString('needs a Node runner', implode("\n", $report->results[0]->messages));
    }

    /** A small Laravel app with a Vue 2 front end that has the compat build. */
    private function makeApp(string $dir, bool $withCompat = true, bool $withTestScript = false): void
    {
        file_put_contents($dir . '/composer.json', (string) json_encode([
            'name' => 'example/vue-app',
            'require' => [
                'php' => '^8.3',
                'laravel/framework' => '^11.0',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $scripts = ['build' => 'vite build'];
        if ($withTestScript) {
            $scripts['test'] = 'vitest run';
        }
        $dependencies = ['vue' => '^2.7.16'];
        if ($withCompat) {
            $dependencies['@vue/compat'] = '^3.5';
        }
        file_put_contents($dir . '/package.json', (string) json_encode([
            'name' => 'example-vue-app',
            'private' => true,
            'dependencies' => $dependencies,
            'devDependencies' => ['vite' => '^8.0.0'],
            'scripts' => $scripts,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        mkdir($dir . '/src', 0775, true);
        file_put_contents($dir . '/src/App.vue', "<template>\n    <div>app</div>\n</template>\n");
    }

    /**
     * git answers clean, the npx call answers from $codemodExit and
     * $codemodStdout, npm answers from $buildExit for the build, and every
     * other Docker command (the PHP test baseline) passes.
     */
    private function fake(string $dir, string $codemodStdout = '', int $codemodExit = 0, int $buildExit = 0): FakeProcessRunner
    {
        return new FakeProcessRunner(static function (array $command) use ($codemodStdout, $codemodExit, $buildExit): ProcessResult {
            if ($command[0] === 'git') {
                return new ProcessResult(0, '', '');
            }
            if ($command[0] === 'docker' && in_array('npx', $command, true)) {
                return new ProcessResult($codemodExit, $codemodStdout, $codemodExit !== 0 ? 'codemod crashed' : '');
            }
            if ($command[0] === 'docker' && in_array('npm', $command, true)) {
                $isBuild = in_array('build', $command, true);

                return new ProcessResult($isBuild ? $buildExit : 0, $isBuild && $buildExit !== 0 ? 'vite build failed' : '', '');
            }
            if ($command[0] === 'docker') {
                return new ProcessResult(0, '', '');
            }

            return new ProcessResult(0, '', '');
        });
    }

    private function runCodemod(FakeProcessRunner $fake, RunOptions $options, string $dir, bool $withNode = true): RunReport
    {
        $step = new PlanStep(1, (new HopRegistry())->get(HopRegistry::VUE_COMPAT_CODEMOD_ID), '8.3', '8.3', '11', '11', null, null, [Planner::GATE_TESTS]);
        $plan = new Plan((new ProjectDetector())->detect($dir), self::TODAY, 'test', '8.3', null, null, [$step], []);
        $executor = $withNode
            ? new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake), $fake, new NodeRunner($fake))
            : new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake), $fake);

        return $executor->execute($plan, $options);
    }

    /**
     * @return list<string>
     */
    private function statuses(RunReport $report): array
    {
        return array_map(static fn(StepResult $result): string => $result->status, $report->results);
    }

    /**
     * @return list<string>
     */
    private function ids(Plan $plan): array
    {
        return array_map(static fn(PlanStep $step): string => $step->hop->id, $plan->steps);
    }
}
