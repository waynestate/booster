<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Plan\Plan;
use Waynestate\Booster\Plan\PlanOptions;
use Waynestate\Booster\Plan\Planner;
use Waynestate\Booster\Policy\PolicyLocator;
use Waynestate\Booster\Project\ProjectDetector;
use Waynestate\Booster\ToolError;

final class PlannerTest extends TestCase
{
    private function plan(string $example, PlanOptions $options = new PlanOptions()): Plan
    {
        $project = (new ProjectDetector())->detect($this->example($example));

        return (new Planner($this->policy(), new HopRegistry()))->plan($project, $options, $this->today());
    }

    /**
     * @return list<string>
     */
    private function ids(Plan $plan): array
    {
        return array_map(static fn($s): string => $s->hop->id, $plan->steps);
    }

    /**
     * Copies an example into a temp dir, merges $composer into its composer.json, and plans the result.
     *
     * @param array<string, mixed> $composer
     */
    private function planWithComposer(string $example, array $composer, PlanOptions $options = new PlanOptions()): Plan
    {
        $dir = $this->copyExample($example);
        $file = $dir . '/composer.json';
        $data = json_decode((string) file_get_contents($file), true);
        self::assertIsArray($data);
        $data = array_replace_recursive($data, $composer);
        file_put_contents($file, (string) json_encode($data));

        $project = (new ProjectDetector())->detect($dir);

        return (new Planner($this->policy(), new HopRegistry()))->plan($project, $options, $this->today());
    }

    public function test_php_74_to_84_lists_each_minor_step_with_its_gate(): void
    {
        $plan = $this->plan('raw-php', new PlanOptions(toPhp: '8.4'));

        self::assertSame(['php-74-to-80', 'php-80-to-81', 'php-81-to-82', 'php-82-to-83', 'php-83-to-84'], $this->ids($plan));
        foreach ($plan->steps as $step) {
            self::assertSame(['target-image-tests'], $step->gates);
        }
        self::assertSame('8.4', $plan->steps[4]->phpAfter);
        self::assertTrue($plan->steps[0]->hop->extraReview);
    }

    public function test_default_target_is_the_newest_php_the_policy_supports(): void
    {
        $plan = $this->plan('raw-php');

        self::assertSame('8.5', $plan->targetPhp);
        self::assertSame('php-84-to-85', $this->ids($plan)[5]);
    }

    public function test_a_raw_php_app_gets_no_laravel_hops(): void
    {
        foreach ($this->ids($this->plan('raw-php')) as $id) {
            self::assertStringStartsNotWith('laravel-', $id);
        }
    }

    public function test_laravel_and_php_hops_are_interleaved_inside_the_support_overlap(): void
    {
        $plan = $this->plan('laravel-blade');

        // Laravel 10 on PHP 8.2 with PHPUnit 10: Laravel 11 wants PHPUnit 11, so that hop goes
        // first; Laravel 11 and 12 run on 8.2, but 13 needs 8.3 first; PHPUnit 12 waits for 8.3
        // and 13 for 8.4.
        self::assertSame(
            ['phpunit-10-to-11', 'laravel-10-to-11', 'laravel-11-to-12', 'php-82-to-83', 'phpunit-11-to-12', 'laravel-12-to-13', 'php-83-to-84', 'phpunit-12-to-13', 'php-84-to-85'],
            $this->ids($plan),
        );
        self::assertSame('13', $plan->targetLaravel);
        self::assertSame('13', $plan->targetPhpunit);
    }

    public function test_an_old_app_never_rests_on_a_pair_laravel_does_not_support(): void
    {
        $plan = $this->plan('laravel-blade', new PlanOptions(fromPhp: '8.1', fromLaravel: '9', toPhp: '8.3', toLaravel: '13'));
        $registry = new HopRegistry();

        foreach ($plan->steps as $step) {
            self::assertTrue($registry->laravelSupportsPhp((string) $step->laravelAfter, (string) $step->phpAfter), $step->hop->id);
        }
        self::assertSame(
            ['laravel-9-to-10', 'php-81-to-82', 'phpunit-10-to-11', 'laravel-10-to-11', 'laravel-11-to-12', 'php-82-to-83', 'phpunit-11-to-12', 'laravel-12-to-13'],
            $this->ids($plan),
        );
    }

    public function test_a_phpunit_9_app_plans_up_to_the_newest_major_its_target_php_runs(): void
    {
        $plan = $this->planWithComposer(
            'raw-php',
            ['require-dev' => ['phpunit/phpunit' => '^9.0']],
            new PlanOptions(toPhp: '8.4'),
        );

        // Target PHP 8.4 runs PHPUnit 13 (floor 8.4), so the plan walks 9 to 13; each
        // PHPUnit hop lands only once a PHP hop has raised the floor high enough.
        self::assertSame(
            ['php-74-to-80', 'php-80-to-81', 'phpunit-9-to-10', 'php-81-to-82', 'phpunit-10-to-11', 'php-82-to-83', 'phpunit-11-to-12', 'php-83-to-84', 'phpunit-12-to-13'],
            $this->ids($plan),
        );
        self::assertSame('13', $plan->targetPhpunit);
    }

    public function test_to_phpunit_stops_the_phpunit_hops_where_asked(): void
    {
        $plan = $this->planWithComposer(
            'raw-php',
            ['require-dev' => ['phpunit/phpunit' => '^9.0']],
            new PlanOptions(toPhp: '8.4', toPhpunit: '11'),
        );

        self::assertSame(
            ['php-74-to-80', 'php-80-to-81', 'phpunit-9-to-10', 'php-81-to-82', 'phpunit-10-to-11', 'php-82-to-83', 'php-83-to-84'],
            $this->ids($plan),
        );
        self::assertSame('11', $plan->targetPhpunit);
    }

    public function test_an_app_already_on_the_newest_phpunit_major_plans_no_phpunit_hop(): void
    {
        $plan = $this->planWithComposer(
            'raw-php',
            ['require' => ['php' => '^8.3'], 'require-dev' => ['phpunit/phpunit' => '^13.0']],
            new PlanOptions(toPhp: '8.4'),
        );

        self::assertSame(['php-83-to-84'], $this->ids($plan));
        foreach ($this->ids($plan) as $id) {
            self::assertStringStartsNotWith('phpunit-', $id);
        }
        self::assertSame('13', $plan->targetPhpunit);
    }

    public function test_a_laravel_target_above_the_apps_phpunit_plans_phpunit_hops_in_laravel_then_phpunit_then_php_order(): void
    {
        $plan = $this->plan('laravel-blade', new PlanOptions(toPhp: '8.4', toLaravel: '13', toPhpunit: '10'));

        // Laravel 13 ships PHPUnit 12, above the app's PHPUnit 10, so the requested
        // target of 10 is raised to 12. Today's order tries a Laravel hop first at
        // every step, then a PHPUnit hop, then a PHP hop.
        self::assertSame('12', $plan->targetPhpunit);
        self::assertSame(
            ['phpunit-10-to-11', 'laravel-10-to-11', 'laravel-11-to-12', 'php-82-to-83', 'phpunit-11-to-12', 'laravel-12-to-13', 'php-83-to-84'],
            $this->ids($plan),
        );
    }

    public function test_the_vue_2_hop_appears_only_for_vue_2_apps(): void
    {
        self::assertContains('vue-2-to-3', $this->ids($this->plan('vue-spa')));
        self::assertNotContains('vue-2-to-3', $this->ids($this->plan('laravel-inertia-vue')));
    }

    public function test_vue_hop_is_a_manual_step_with_a_manual_gate(): void
    {
        $plan = $this->plan('vue-spa');
        $last = $plan->steps[count($plan->steps) - 1];

        self::assertSame('vue-2-to-3', $last->hop->id);
        self::assertTrue($last->hop->isManual());
        self::assertSame(['manual-review'], $last->gates);
    }

    public function test_apps_with_a_package_json_get_the_node_pin_and_others_do_not(): void
    {
        self::assertContains(HopRegistry::NODE_PIN_ID, $this->ids($this->plan('vue-spa')));
        self::assertContains(HopRegistry::NODE_PIN_ID, $this->ids($this->plan('laravel-blade-vue')));
        self::assertNotContains(HopRegistry::NODE_PIN_ID, $this->ids($this->plan('raw-php')));
        self::assertNotContains(HopRegistry::NODE_PIN_ID, $this->ids($this->plan('laravel-blade')));
    }

    public function test_the_node_pin_comes_after_the_php_hops_and_before_the_vue_playbook(): void
    {
        $ids = $this->ids($this->plan('vue-spa'));
        $pin = array_search(HopRegistry::NODE_PIN_ID, $ids, true);

        self::assertIsInt($pin);
        self::assertStringStartsWith('php-', $ids[$pin - 1]);
        self::assertSame('vue-2-to-3', $ids[$pin + 1]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nodeEngineFloorsAtOrAboveTheTarget(): array
    {
        $target = (new HopRegistry())->nodePinTarget();

        return ['plain floor' => ['>=' . $target], 'caret' => ['^' . $target], 'wildcard' => [$target . '.x'], 'higher floor' => ['>=' . ((int) $target + 2)]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nodeEngineFloorsAtOrAboveTheTarget')]
    public function test_an_app_already_pinned_to_the_target_or_newer_gets_no_node_pin(string $engine): void
    {
        $dir = $this->copyExample('vue-spa');
        $file = $dir . '/package.json';
        $data = json_decode((string) file_get_contents($file), true);
        self::assertIsArray($data);
        $data['engines']['node'] = $engine;
        file_put_contents($file, (string) json_encode($data));

        $project = (new ProjectDetector())->detect($dir);
        $plan = (new Planner($this->policy(), new HopRegistry()))->plan($project, new PlanOptions(), $this->today());

        self::assertNotContains(HopRegistry::NODE_PIN_ID, $this->ids($plan));
    }

    public function test_sesame_swap_is_opt_in_and_goes_last(): void
    {
        $without = $this->plan('laravel-wayne-cas');
        self::assertNotContains('cas-to-sesame', $this->ids($without));
        self::assertStringContainsString('--sesame', implode(' ', $without->warnings));

        $with = $this->plan('laravel-wayne-cas', new PlanOptions(sesame: true));
        $ids = $this->ids($with);
        self::assertSame('cas-to-sesame', $ids[count($ids) - 1]);
    }

    public function test_sesame_without_wayne_cas_is_skipped_with_a_warning(): void
    {
        $plan = $this->plan('raw-php', new PlanOptions(sesame: true));

        self::assertNotContains('cas-to-sesame', $this->ids($plan));
        self::assertStringContainsString('wayne-cas', implode(' ', $plan->warnings));
    }

    public function test_an_eol_target_is_refused(): void
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('past security support');
        $this->plan('raw-php', new PlanOptions(toPhp: '8.1'));
    }

    public function test_a_security_only_target_is_allowed_with_a_warning(): void
    {
        $plan = $this->plan('raw-php', new PlanOptions(toPhp: '8.3'));

        self::assertStringContainsString('security fixes', implode(' ', $plan->warnings));
    }

    public function test_a_target_ahead_of_the_policy_is_treated_as_current_with_a_warning(): void
    {
        $file = $this->tempDir() . '/ahead.json';
        file_put_contents($file, (string) json_encode([
            'schema_version' => 1,
            'components' => [
                'php' => [
                    'versions' => [
                        '8.1' => ['active_until' => '2023-11-23', 'security_until' => '2024-12-31'],
                        '8.2' => ['active_until' => '2024-12-31', 'security_until' => '2026-12-31'],
                        '8.3' => ['active_until' => '2025-12-31', 'security_until' => '2027-12-31'],
                    ],
                ],
            ],
            'rules' => ['eol_grace_days' => 90],
        ]));
        $project = (new ProjectDetector())->detect($this->example('raw-php'));
        $planner = new Planner(PolicyLocator::locate($file, null), new HopRegistry());

        $plan = $planner->plan($project, new PlanOptions(fromPhp: '8.1', toPhp: '8.4'), $this->today());

        self::assertSame('8.4', $plan->targetPhp);
        self::assertSame(['PHP 8.4 is newer than CREED\'s policy; treated as current.'], $plan->warnings);
    }

    public function test_a_php_laravel_target_pair_that_cannot_exist_is_refused(): void
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('does not run on PHP');
        $this->plan('laravel-blade', new PlanOptions(toPhp: '8.2', toLaravel: '13'));
    }

    public function test_going_backwards_is_refused(): void
    {
        $this->expectException(ToolError::class);
        $this->plan('raw-php', new PlanOptions(toPhp: '7.4'));
    }

    public function test_unknown_php_versions_are_refused(): void
    {
        $this->expectException(ToolError::class);
        $this->plan('raw-php', new PlanOptions(fromPhp: '9.9'));
    }

    public function test_an_app_already_at_the_target_has_no_steps(): void
    {
        $plan = $this->plan('raw-php', new PlanOptions(fromPhp: '8.5', toPhp: '8.5'));

        self::assertSame([], $plan->steps);
        self::assertStringContainsString('Nothing to do', implode(' ', $plan->warnings));
    }

    public function test_an_app_already_at_the_php_target_still_gets_the_node_pin(): void
    {
        $plan = $this->plan('laravel-inertia-vue', new PlanOptions(fromPhp: '8.5', toPhp: '8.5', fromLaravel: '13', toLaravel: '13'));

        self::assertSame([HopRegistry::NODE_PIN_ID], $this->ids($plan));
    }

    public function test_an_unknown_php_floor_asks_for_from_php(): void
    {
        $project = (new ProjectDetector())->detect($this->tempDir());

        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('--from-php');
        (new Planner($this->policy(), new HopRegistry()))->plan($project, new PlanOptions(), $this->today());
    }

    public function test_skip_removes_a_hop_and_renumbers(): void
    {
        $plan = $this->plan('raw-php', new PlanOptions(skip: ['php-80-to-81']));

        self::assertNotContains('php-80-to-81', $this->ids($plan));
        self::assertSame(range(1, count($plan->steps)), array_map(static fn($s): int => $s->number, $plan->steps));
    }

    public function test_skip_of_an_unknown_hop_is_refused(): void
    {
        $this->expectException(ToolError::class);
        $this->plan('raw-php', new PlanOptions(skip: ['php-99-to-100']));
    }

    public function test_the_plan_follows_the_policy_date(): void
    {
        $project = (new ProjectDetector())->detect($this->example('raw-php'));
        $planner = new Planner($this->policy(), new HopRegistry());

        // In 2030 PHP 8.3 is EOL, so asking for it is refused.
        $this->expectException(ToolError::class);
        $planner->plan($project, new PlanOptions(toPhp: '8.3'), new \DateTimeImmutable('2030-01-01'));
    }
}
