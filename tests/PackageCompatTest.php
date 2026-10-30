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
 *
 * The four public fixtures are real Packagist p2 captures (trimmed to their
 * newest versions): fideloper/proxy caps at Laravel 9, laravelcollective/
 * remote caps at Laravel 8 and is abandoned, fzaninotto/faker is abandoned
 * with no replacement and needs PHP 7 or lower, beyondcode/laravel-dump-
 * server requires only illuminate components and caps at Laravel 12.
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
     * @param array<string, string|array<string, mixed>> $files packagist package name => fixture file or p2 document
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
        $fetcher = $this->fetcher(['fideloper/proxy' => 'fideloper-proxy.json']);
        $plan = $this->planWith(['require' => ['fideloper/proxy' => '^4.4']], $fetcher);

        self::assertSame(['https://repo.packagist.org/p2/fideloper/proxy.json'], $fetcher->fetched);
        self::assertCount(1, $plan->packageChecks);
        $check = $plan->packageChecks[0];
        self::assertSame('fideloper/proxy', $check->package);
        self::assertSame('4.4.2', $check->newest);
        // The app sits on Laravel 10, already past the package's ceiling of 9,
        // so its newest release cannot follow the plan at all: the very first
        // hop is the one it blocks.
        self::assertSame('phpunit-10-to-11', $check->blocks);
        self::assertFalse($check->abandoned);
        self::assertTrue($check->checked);
        self::assertContains(
            'fideloper/proxy 4.4.2 supports Laravel up to 9 (or PHP up to 9), so it blocks phpunit-10-to-11. Replace or upgrade it first.',
            $plan->warnings,
        );
        self::assertSame([
            ['package' => 'fideloper/proxy', 'newest' => '4.4.2', 'blocks' => 'phpunit-10-to-11', 'abandoned' => false, 'checked' => true],
        ], $plan->toArray()['package_checks']);
    }

    public function test_an_illuminate_only_package_is_judged_by_its_component_constraints(): void
    {
        $fetcher = $this->fetcher(['beyondcode/laravel-dump-server' => 'beyondcode-laravel-dump-server.json']);
        $plan = $this->planWith(['require' => ['beyondcode/laravel-dump-server' => '^2.0']], $fetcher);

        $check = $plan->packageChecks[0];
        self::assertSame('2.1.0', $check->newest);
        // Its illuminate/console, illuminate/http and illuminate/support
        // constraints all cap at Laravel 12, so the hop into 13 is the first
        // one its newest release cannot reach.
        self::assertSame('laravel-12-to-13', $check->blocks);
        self::assertContains(
            'beyondcode/laravel-dump-server 2.1.0 supports Laravel up to 12 (or PHP up to 9), so it blocks laravel-12-to-13. Replace or upgrade it first.',
            $plan->warnings,
        );
    }

    public function test_an_abandoned_package_warns_and_names_its_replacement(): void
    {
        $fetcher = $this->fetcher(['laravelcollective/remote' => 'laravelcollective-remote.json']);
        $plan = $this->planWith(['require' => ['laravelcollective/remote' => '^6.0']], $fetcher);

        $check = $plan->packageChecks[0];
        self::assertSame('v6.2.1', $check->newest);
        // The app sits on Laravel 10, past the package's ceiling of 8, so it
        // blocks from the first hop, exactly like a package that is already
        // out of reach.
        self::assertSame('phpunit-10-to-11', $check->blocks);
        self::assertTrue($check->abandoned);
        self::assertSame('spatie/ssh', $check->replacement);
        self::assertContains(
            'laravelcollective/remote v6.2.1 supports Laravel up to 8 (or PHP up to 9), so it blocks phpunit-10-to-11. Replace or upgrade it first.',
            $plan->warnings,
        );
        self::assertContains('laravelcollective/remote is abandoned (use spatie/ssh instead).', $plan->warnings);
        self::assertNotContains('Not checked: laravelcollective/remote (offline).', $plan->warnings);
    }

    public function test_an_abandoned_package_without_a_replacement_says_so(): void
    {
        $fetcher = $this->fetcher(['fzaninotto/faker' => 'fzaninotto-faker.json']);
        $plan = $this->planWith(['require-dev' => ['fzaninotto/faker' => '^1.9']], $fetcher);

        $check = $plan->packageChecks[0];
        self::assertSame('v1.9.2', $check->newest);
        // It cannot run on any PHP Booster plans, so it blocks the very first
        // hop of the plan.
        self::assertSame('phpunit-10-to-11', $check->blocks);
        self::assertTrue($check->abandoned);
        self::assertNull($check->replacement);
        self::assertContains(
            'fzaninotto/faker v1.9.2 supports PHP up to 7, so it blocks phpunit-10-to-11. Replace or upgrade it first.',
            $plan->warnings,
        );
        self::assertContains('fzaninotto/faker is abandoned with no replacement.', $plan->warnings);
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
        $fetcher = $this->fetcher(['fideloper/proxy' => 'fideloper-proxy.json']);
        $plan = $this->planWith(
            ['require' => ['fideloper/proxy' => '^4.4']],
            $fetcher,
            new PlanOptions(noPackageCheck: true),
        );

        self::assertSame([], $fetcher->fetched);
        self::assertSame([], $plan->packageChecks);
        foreach ($plan->warnings as $warning) {
            self::assertStringNotContainsString('fideloper/', $warning);
        }
    }

    public function test_planning_without_a_fetcher_never_checks_packages(): void
    {
        $plan = $this->planWith(['require' => ['fideloper/proxy' => '^4.4']], null);

        self::assertSame([], $plan->packageChecks);
    }
}
