<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use RuntimeException;
use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Plan\Plan;
use Waynestate\Booster\Plan\PlanOptions;
use Waynestate\Booster\Plan\Planner;
use Waynestate\Booster\Plan\WayneCasReachability;
use Waynestate\Booster\Project\ProjectDetector;
use Waynestate\Booster\Run\ProcessResult;

/**
 * When the app requires wayne-cas, has no vendor/ and its repository cannot
 * be reached, the swap to SESAME goes first in the plan, because the Laravel
 * hops need composer to install and composer is blocked by that repository.
 */
final class SesameFirstTest extends TestCase
{
    /**
     * A temp Laravel 9 app (PHP 8.1) that requires cit-web-infra/wayne-cas,
     * optionally from a vcs repository on git.invalid.
     */
    private function wayneCasApp(bool $vcsRepository = true): string
    {
        $dir = $this->copyExample('laravel-wayne-cas');
        $file = $dir . '/composer.json';
        $data = json_decode((string) file_get_contents($file), true);
        self::assertIsArray($data);
        $merge = ['require' => ['php' => '^8.1', 'laravel/framework' => '^9.0']];
        if ($vcsRepository) {
            $merge['repositories'] = [['type' => 'vcs', 'url' => 'https://git.invalid/cit-web-infra/wayne-cas.git']];
        }
        $data = array_replace_recursive($data, $merge);
        file_put_contents($file, (string) json_encode($data));

        return $dir;
    }

    private function plan(string $dir, FakeProcessRunner $fake, PlanOptions $options = new PlanOptions()): Plan
    {
        $project = (new ProjectDetector())->detect($dir);

        return (new Planner($this->policy(), new HopRegistry(), null, new WayneCasReachability($fake)))->plan($project, $options, $this->today());
    }

    /** The same app planned without the reachability probe (the old behaviour). */
    private function baselinePlan(string $dir, PlanOptions $options = new PlanOptions()): Plan
    {
        $project = (new ProjectDetector())->detect($dir);

        return (new Planner($this->policy(), new HopRegistry()))->plan($project, $options, $this->today());
    }

    /** @return list<string> */
    private function ids(Plan $plan): array
    {
        return array_map(static fn($s): string => $s->hop->id, $plan->steps);
    }

    private function fakeWith(int $exitCode): FakeProcessRunner
    {
        return new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult($exitCode, '', ''));
    }

    public function test_unreachable_wayne_cas_puts_sesame_first_and_keeps_the_rest_of_the_plan(): void
    {
        $dir = $this->wayneCasApp();
        $fake = $this->fakeWith(2);

        $plan = $this->plan($dir, $fake);
        $baseline = $this->baselinePlan($dir);

        self::assertSame('cas-to-sesame', $this->ids($plan)[0]);
        self::assertCount(1, array_keys($this->ids($plan), 'cas-to-sesame'));
        self::assertSame($this->ids($baseline), array_slice($this->ids($plan), 1));
        self::assertCount(1, $fake->commandsContaining('ls-remote'));
        $warnings = implode("\n", $plan->warnings);
        self::assertStringContainsString('cas-to-sesame goes first: the wayne-cas repository cannot be reached (git ls-remote could not reach the wayne-cas repository (exit 2))', $warnings);
        self::assertStringNotContainsString('Add --sesame', $warnings);
        self::assertStringNotContainsString('git.invalid', $warnings);
    }

    public function test_reachable_wayne_cas_leaves_the_plan_unchanged(): void
    {
        $dir = $this->wayneCasApp();
        $fake = $this->fakeWith(0);

        $plan = $this->plan($dir, $fake);
        $baseline = $this->baselinePlan($dir);

        self::assertSame($this->ids($baseline), $this->ids($plan));
        self::assertNotContains('cas-to-sesame', $this->ids($plan));
        self::assertCount(1, $fake->commandsContaining('ls-remote'));
        self::assertStringContainsString('Add --sesame', implode("\n", $plan->warnings));
    }

    public function test_an_installed_vendor_tree_skips_the_probe(): void
    {
        $dir = $this->wayneCasApp();
        mkdir($dir . '/vendor', 0775, true);
        file_put_contents($dir . '/vendor/autoload.php', "<?php\n");
        $fake = $this->fakeWith(2);

        $plan = $this->plan($dir, $fake);
        $baseline = $this->baselinePlan($dir);

        self::assertSame($this->ids($baseline), $this->ids($plan));
        self::assertNotContains('cas-to-sesame', $this->ids($plan));
        self::assertSame([], $fake->calls);
    }

    public function test_skipping_cas_to_sesame_turns_off_the_first_placement(): void
    {
        $dir = $this->wayneCasApp();
        $fake = $this->fakeWith(2);
        $options = new PlanOptions(skip: ['cas-to-sesame']);

        $plan = $this->plan($dir, $fake, $options);
        $baseline = $this->baselinePlan($dir, $options);

        self::assertSame($this->ids($baseline), $this->ids($plan));
        self::assertNotContains('cas-to-sesame', $this->ids($plan));
        self::assertSame([], $fake->calls);
    }

    public function test_a_package_from_packagist_is_reachable_by_assumption(): void
    {
        $dir = $this->wayneCasApp(false);
        $fake = $this->fakeWith(2);

        $plan = $this->plan($dir, $fake);
        $baseline = $this->baselinePlan($dir);

        self::assertSame($this->ids($baseline), $this->ids($plan));
        self::assertNotContains('cas-to-sesame', $this->ids($plan));
        self::assertSame([], $fake->calls);
    }

    public function test_a_path_repository_to_a_missing_directory_goes_first(): void
    {
        $dir = $this->wayneCasApp(false);
        $file = $dir . '/composer.json';
        $data = json_decode((string) file_get_contents($file), true);
        self::assertIsArray($data);
        $data['repositories'] = [['type' => 'path', 'url' => '../wayne-cas-missing']];
        file_put_contents($file, (string) json_encode($data));
        $fake = $this->fakeWith(2);

        $plan = $this->plan($dir, $fake);

        self::assertSame('cas-to-sesame', $this->ids($plan)[0]);
        self::assertSame([], $fake->calls);
        self::assertStringContainsString('path repository "../wayne-cas-missing" does not exist', implode("\n", $plan->warnings));
    }

    public function test_a_probe_exception_counts_as_unreachable_without_printing_the_url(): void
    {
        $dir = $this->wayneCasApp();
        $fake = new FakeProcessRunner(static fn(): ProcessResult => throw new RuntimeException("connection timed out after 10 seconds\nsecond line"));

        $plan = $this->plan($dir, $fake);
        $warnings = implode("\n", $plan->warnings);

        self::assertSame('cas-to-sesame', $this->ids($plan)[0]);
        self::assertStringContainsString('connection timed out after 10 seconds', $warnings);
        self::assertStringNotContainsString('second line', $warnings);
        self::assertStringNotContainsString('git.invalid', $warnings);
    }
}
