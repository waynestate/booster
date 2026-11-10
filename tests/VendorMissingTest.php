<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Plan\Plan;
use Waynestate\Booster\Plan\Planner;
use Waynestate\Booster\Plan\PlanOptions;
use Waynestate\Booster\Project\Project;
use Waynestate\Booster\Project\ProjectDetector;
use Waynestate\Booster\Report\TextWriter;
use Waynestate\Booster\Run\BaselineResult;
use Waynestate\Booster\Run\RunReport;

/**
 * A preview on a checkout without vendor/ sees less: Rector cannot tell which
 * properties a framework class gives the app's models, and PHPStan cannot
 * resolve anything the app requires. Found on a real Laravel app: 10 files
 * and 3387 PHPStan findings without vendor/, 21 files and 707 findings with
 * it, and not a word said. Now the preview opens with a warning.
 */
final class VendorMissingTest extends TestCase
{
    private const WARNING = 'Warning: ' . Project::VENDOR_MISSING_WARNING;

    /** Plans a copy of an example, installed (a vendor/autoload.php) or not. */
    private function plan(string $example, bool $installed): Plan
    {
        $dir = $this->copyExample($example);
        if ($installed) {
            mkdir($dir . '/vendor', 0775, true);
            file_put_contents($dir . '/vendor/autoload.php', "<?php\n");
        }
        $project = (new ProjectDetector())->detect($dir);

        return (new Planner($this->policy(), new HopRegistry()))->plan($project, new PlanOptions(noPackageCheck: true), $this->today());
    }

    public function test_detect_flags_a_composer_app_without_vendor(): void
    {
        $missing = (new ProjectDetector())->detect($this->copyExample('laravel-inertia-vue'));
        self::assertTrue($missing->vendorMissing);
        self::assertTrue($missing->toArray()['vendor_missing']);

        $installed = $this->plan('laravel-inertia-vue', true)->project;
        self::assertFalse($installed->vendorMissing);
        self::assertArrayNotHasKey('vendor_missing', $installed->toArray());
    }

    public function test_an_app_that_requires_only_php_and_extensions_is_not_flagged(): void
    {
        // composer install would build no vendor/ worth reading, so no warning.
        $dir = $this->tempDir();
        file_put_contents($dir . '/composer.json', '{"require": {"php": "^8.3", "ext-pdo": "*"}}');

        self::assertFalse((new ProjectDetector())->detect($dir)->vendorMissing);
    }

    public function test_an_app_with_no_composer_json_is_not_flagged(): void
    {
        self::assertFalse((new ProjectDetector())->detect($this->tempDir())->vendorMissing);
    }

    public function test_the_plan_opens_with_the_warning_before_the_steps(): void
    {
        $text = (new TextWriter())->plan($this->plan('laravel-inertia-vue', false));

        self::assertStringContainsString(self::WARNING, $text);
        $warning = strpos($text, self::WARNING);
        $firstStep = strpos($text, "\n1. ");
        self::assertNotFalse($firstStep);
        self::assertLessThan($firstStep, $warning, 'the warning comes before the first step');
    }

    public function test_an_installed_app_gets_no_warning(): void
    {
        self::assertStringNotContainsString(self::WARNING, (new TextWriter())->plan($this->plan('laravel-inertia-vue', true)));
    }

    public function test_a_dry_run_opens_with_the_warning(): void
    {
        $plan = $this->plan('laravel-inertia-vue', false);
        $report = new RunReport($plan, true, new BaselineResult(BaselineResult::SKIPPED, '8.3', null), []);

        self::assertStringStartsWith("BOOSTER dry run (nothing was changed)\n\n" . self::WARNING . "\n", (new TextWriter())->run($report));
    }

    public function test_a_real_run_whose_baseline_installed_vendor_gets_no_warning(): void
    {
        // The baseline's composer install writes vendor/ into the app before
        // the first hop, so Rector sees the whole tree.
        $plan = $this->plan('laravel-inertia-vue', false);
        $report = new RunReport($plan, false, new BaselineResult(BaselineResult::PASSED, '8.3', 0), []);

        self::assertStringNotContainsString(self::WARNING, (new TextWriter())->run($report));
    }
}
