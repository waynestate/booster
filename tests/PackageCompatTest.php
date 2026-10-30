<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Plan\Plan;
use Waynestate\Booster\Plan\PlanOptions;
use Waynestate\Booster\Plan\Planner;
use Waynestate\Booster\Project\ProjectDetector;

/**
 * The dependency check on a plan: every direct third-party requirement is
 * asked of Packagist (here: of fixtures), and the first hop its newest stable
 * release cannot reach becomes a warning. All fetches go through
 * FixturePackageFetcher, so nothing in this file touches the network.
 */
final class PackageCompatTest extends TestCase
{
    /**
     * Copies the laravel-blade example (Laravel 10, PHP 8.2, PHPUnit 10) into
     * a temp dir, merges $composer into its composer.json, and plans it with
     * $fetcher (null plans without any package check at all).
     *
     * @param array<string, mixed> $composer
     */
    private function planWith(array $composer, ?FixturePackageFetcher $fetcher, PlanOptions $options = new PlanOptions()): Plan
    {
        $dir = $this->copyExample('laravel-blade');
        $file = $dir . '/composer.json';
        $data = json_decode((string) file_get_contents($file), true);
        self::assertIsArray($data);
        $data = array_replace_recursive($data, $composer);
        file_put_contents($file, (string) json_encode($data));

        $project = (new ProjectDetector())->detect($dir);
        $planner = $fetcher === null
            ? new Planner($this->policy(), new HopRegistry())
            : new Planner($this->policy(), new HopRegistry(), $fetcher);

        return $planner->plan($project, $options, $this->today());
    }

    /**
     * @param array<string, string> $files packagist package name => fixture file
     */
    private function fetcher(array $files): FixturePackageFetcher
    {
        $map = [];
        foreach ($files as $package => $file) {
            $map['https://repo.packagist.org/p2/' . $package . '.json'] = $file;
        }

        return new FixturePackageFetcher($map);
    }

    public function test_a_package_capped_below_the_target_blocks_the_first_hop_it_cannot_reach(): void
    {
        $fetcher = $this->fetcher(['acme/capped-laravel' => 'acme-capped-laravel.json']);
        $plan = $this->planWith(['require' => ['acme/capped-laravel' => '^2.0']], $fetcher);

        self::assertSame(['https://repo.packagist.org/p2/acme/capped-laravel.json'], $fetcher->fetched);
        self::assertCount(1, $plan->packageChecks);
        $check = $plan->packageChecks[0];
        self::assertSame('acme/capped-laravel', $check->package);
        self::assertSame('2.3.0', $check->newest);
        self::assertSame('laravel-10-to-11', $check->blocks);
        self::assertFalse($check->abandoned);
        self::assertTrue($check->checked);
        self::assertContains(
            'acme/capped-laravel 2.3.0 supports Laravel up to 10 (or PHP up to 8.3), so it blocks laravel-10-to-11. Replace or upgrade it first.',
            $plan->warnings,
        );
        self::assertSame([
            ['package' => 'acme/capped-laravel', 'newest' => '2.3.0', 'blocks' => 'laravel-10-to-11', 'abandoned' => false, 'checked' => true],
        ], $plan->toArray()['package_checks']);
    }

    public function test_an_illuminate_only_package_is_judged_by_its_loosest_constraint(): void
    {
        $fetcher = $this->fetcher(['acme/illuminate-user' => 'acme-illuminate-user.json']);
        $plan = $this->planWith(['require' => ['acme/illuminate-user' => '^3.0']], $fetcher);

        $check = $plan->packageChecks[0];
        self::assertSame('3.1.0', $check->newest);
        // illuminate/auth ^10.0 would cap at Laravel 10; the loosest constraint
        // (illuminate/database >=10.0 <12.0) caps at 11, and that is what the
        // warning names.
        self::assertSame('laravel-11-to-12', $check->blocks);
        self::assertContains(
            'acme/illuminate-user 3.1.0 supports Laravel up to 11 (or PHP up to 8.3), so it blocks laravel-11-to-12. Replace or upgrade it first.',
            $plan->warnings,
        );
    }

    public function test_an_abandoned_package_warns_but_does_not_block(): void
    {
        $fetcher = $this->fetcher(['acme/abandoned' => 'acme-abandoned.json']);
        $plan = $this->planWith(['require' => ['acme/abandoned' => '^1.0']], $fetcher);

        $check = $plan->packageChecks[0];
        self::assertSame('1.4.0', $check->newest);
        self::assertNull($check->blocks);
        self::assertTrue($check->abandoned);
        self::assertSame('acme/successor', $check->replacement);
        self::assertContains('acme/abandoned is abandoned (use acme/successor instead).', $plan->warnings);
        self::assertNotContains('Not checked: acme/abandoned (offline).', $plan->warnings);
    }

    public function test_a_package_behind_a_vcs_repository_is_not_fetched_and_is_labelled(): void
    {
        $fetcher = $this->fetcher([]);
        $plan = $this->planWith([
            'require' => ['internal/tool' => '^1.0'],
            'repositories' => [['type' => 'git', 'url' => 'https://git.example.com/internal/tool.git']],
        ], $fetcher);

        // Packagist was tried once and nothing else: VCS repositories are not fetched.
        self::assertSame(['https://repo.packagist.org/p2/internal/tool.json'], $fetcher->fetched);
        $check = $plan->packageChecks[0];
        self::assertFalse($check->checked);
        self::assertNull($check->newest);
        self::assertNull($check->blocks);
        self::assertContains('Not checked: internal/tool (VCS repository).', $plan->warnings);
    }

    public function test_a_package_packagist_does_not_know_about_is_labelled_offline(): void
    {
        $fetcher = $this->fetcher([]);
        $plan = $this->planWith(['require' => ['acme/missing' => '^1.0']], $fetcher);

        $check = $plan->packageChecks[0];
        self::assertFalse($check->checked);
        self::assertNull($check->blocks);
        self::assertContains('Not checked: acme/missing (offline).', $plan->warnings);
    }

    public function test_a_composer_type_repository_is_tried_after_packagist(): void
    {
        $fetcher = new FixturePackageFetcher([
            'https://packages.internal.example.com/p2/internal/pkg.json' => 'internal-pkg.json',
        ]);
        $plan = $this->planWith([
            'require' => ['internal/pkg' => '^1.0'],
            'repositories' => [['type' => 'composer', 'url' => 'https://packages.internal.example.com/']],
        ], $fetcher);

        self::assertSame([
            'https://repo.packagist.org/p2/internal/pkg.json',
            'https://packages.internal.example.com/p2/internal/pkg.json',
        ], $fetcher->fetched);
        $check = $plan->packageChecks[0];
        self::assertTrue($check->checked);
        self::assertSame('1.2.0', $check->newest);
        self::assertNull($check->blocks);
    }

    public function test_no_package_check_skips_every_fetch(): void
    {
        $fetcher = $this->fetcher(['acme/capped-laravel' => 'acme-capped-laravel.json']);
        $plan = $this->planWith(
            ['require' => ['acme/capped-laravel' => '^2.0']],
            $fetcher,
            new PlanOptions(noPackageCheck: true),
        );

        self::assertSame([], $fetcher->fetched);
        self::assertSame([], $plan->packageChecks);
        foreach ($plan->warnings as $warning) {
            self::assertStringNotContainsString('acme/', $warning);
        }
    }

    public function test_planning_without_a_fetcher_never_checks_packages(): void
    {
        $plan = $this->planWith(['require' => ['acme/capped-laravel' => '^2.0']], null);

        self::assertSame([], $plan->packageChecks);
    }
}
