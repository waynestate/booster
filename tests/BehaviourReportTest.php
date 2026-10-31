<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Symfony\Component\Console\Tester\ApplicationTester;
use Waynestate\Booster\Console\BoosterApplication;
use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Plan\Plan;
use Waynestate\Booster\Plan\PlanStep;
use Waynestate\Booster\Plan\Planner;
use Waynestate\Booster\Project\ProjectDetector;
use Waynestate\Booster\Report\TextWriter;
use Waynestate\Booster\Scan\BehaviourEntry;
use Waynestate\Booster\Scan\BehaviourResult;

final class BehaviourReportTest extends TestCase
{
    /**
     * @param array<string, mixed> $input
     *
     * @return array{int, string}
     */
    private function booster(array $input): array
    {
        $app = new BoosterApplication();
        $app->setAutoExit(false);
        $tester = new ApplicationTester($app);
        $exit = $tester->run($input, ['capture_stderr_separately' => true]);

        return [$exit, $tester->getDisplay() . $tester->getErrorOutput()];
    }

    /** A result with one entry of every kind, so each printed shape is covered. */
    private function sampleResult(): BehaviourResult
    {
        return new BehaviourResult('laravel-10-to-11', [
            new BehaviourEntry('e-fn', 'function', 'Legacy call removed', 'legacy_call();', 'Confirm nothing calls it.', 'https://example.com/', ['src/a.php:3'], 1),
            new BehaviourEntry('e-many', 'method', 'Many hits', null, 'Check them.', 'https://example.com/', ['a.php:1', 'a.php:2', 'b.php:9'], 7),
            new BehaviourEntry('e-cfg', 'config', 'Config key needed', null, 'Add the key.', 'https://example.com/', ['config/app.php'], 1),
            new BehaviourEntry('e-pkg', 'package', 'Package below the floor', null, 'Update it.', 'https://example.com/', ['composer.json'], 1),
            new BehaviourEntry('e-always', 'always', 'Minimum PHP raised', null, 'Check the runtime.', 'https://example.com/', [], 1),
        ], 3, 0);
    }

    public function test_behaviour_block_renders_each_entry_kind(): void
    {
        self::assertSame([
            'Behaviour changes to check:',
            'Legacy call removed (1 in 1 file: src/a.php:3)',
            '  keep old: legacy_call();',
            '  check: Confirm nothing calls it.',
            'Many hits (7 in 2 files: a.php:1, a.php:2, b.php:9 ...)',
            '  check: Check them.',
            'Config key needed (config/app.php)',
            '  check: Add the key.',
            'Package below the floor (composer.json)',
            '  check: Update it.',
            'Minimum PHP raised',
            '  check: Check the runtime.',
        ], (new TextWriter())->behaviour($this->sampleResult()));
    }

    public function test_behaviour_block_is_empty_when_nothing_matched(): void
    {
        self::assertSame([], (new TextWriter())->behaviour(new BehaviourResult('php-80-to-81', [], 2, 0)));
    }

    public function test_plan_text_prints_the_behaviour_block_under_its_step(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/index.php', "<?php\n");
        $hop = (new HopRegistry())->get('php-74-to-80');
        $step = new PlanStep(1, $hop, '7.4', '8.0', null, null, null, null, [Planner::GATE_TESTS]);
        $plan = new Plan((new ProjectDetector())->detect($dir), self::TODAY, 'test', '8.0', null, null, [$step], []);

        $text = (new TextWriter())->plan($plan, [], [1 => $this->sampleResult()]);

        self::assertStringContainsString('1. php-74-to-80', $text);
        self::assertStringContainsString('   Behaviour changes to check:', $text);
        self::assertStringContainsString('   Legacy call removed (1 in 1 file: src/a.php:3)', $text);
        self::assertStringContainsString('     keep old: legacy_call();', $text);
        self::assertStringContainsString('     check: Confirm nothing calls it.', $text);
        self::assertStringContainsString('   Many hits (7 in 2 files: a.php:1, a.php:2, b.php:9 ...)', $text);
        self::assertStringContainsString('   Config key needed (config/app.php)', $text);
        self::assertStringContainsString('   Package below the floor (composer.json)', $text);
        self::assertStringContainsString('   Minimum PHP raised', $text);
    }

    public function test_plan_json_always_carries_behaviour_changes(): void
    {
        [$exit, $out] = $this->booster(['command' => 'plan', '--path' => $this->example('raw-php'), '--today' => self::TODAY, '--no-scan' => true, '--no-package-check' => true, '--format' => 'json']);
        $data = json_decode($out, true);

        self::assertSame(0, $exit);
        // The bundled data is curated per release: the raw-php example trips
        // exactly the two always rows of its first hop (php-74-to-80) and none
        // of the lexed kinds, because src/Legacy.php calls none of them.
        self::assertArrayHasKey('behaviour_changes', $data);
        $ids = [];
        foreach ($data['behaviour_changes'] as $block) {
            foreach ($block['entries'] as $entry) {
                $ids[] = $entry['id'];
            }
        }
        sort($ids);
        self::assertSame(['php80-error-reporting-eall', 'php80-string-number-compare'], $ids);
    }
}
