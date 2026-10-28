<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Plan\Plan;
use Waynestate\Booster\Plan\PlanOptions;
use Waynestate\Booster\Plan\Planner;
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

        // Laravel 10 on PHP 8.2: 11 and 12 run on 8.2, but 13 needs 8.3 first.
        self::assertSame(
            ['laravel-10-to-11', 'laravel-11-to-12', 'php-82-to-83', 'laravel-12-to-13', 'php-83-to-84', 'php-84-to-85'],
            $this->ids($plan),
        );
        self::assertSame('13', $plan->targetLaravel);
    }

    public function test_an_old_app_never_rests_on_a_pair_laravel_does_not_support(): void
    {
        $plan = $this->plan('laravel-blade', new PlanOptions(fromPhp: '8.1', fromLaravel: '9', toPhp: '8.3', toLaravel: '13'));

        foreach ($plan->steps as $step) {
            self::assertTrue(HopRegistry::laravelSupportsPhp((string) $step->laravelAfter, (string) $step->phpAfter), $step->hop->id);
        }
        self::assertSame(
            ['laravel-9-to-10', 'php-81-to-82', 'laravel-10-to-11', 'laravel-11-to-12', 'php-82-to-83', 'laravel-12-to-13'],
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
        $plan = $this->plan('laravel-inertia-vue', new PlanOptions(fromPhp: '8.5', toPhp: '8.5', fromLaravel: '13', toLaravel: '13'));

        self::assertSame([], $plan->steps);
        self::assertStringContainsString('Nothing to do', implode(' ', $plan->warnings));
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
