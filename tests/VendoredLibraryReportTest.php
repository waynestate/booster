<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Plan\Plan;
use Waynestate\Booster\Plan\PlanOptions;
use Waynestate\Booster\Plan\Planner;
use Waynestate\Booster\Project\ProjectDetector;
use Waynestate\Booster\Report\TextWriter;
use Waynestate\Booster\Run\BaselineResult;
use Waynestate\Booster\Run\RunReport;

final class VendoredLibraryReportTest extends TestCase
{
    /** The heading line for a plan whose target PHP is $targetPhp. */
    private function heading(?string $targetPhp): string
    {
        return sprintf('Warning: hand-copied libraries found. Booster never rewrites them and cannot show they run on PHP %s:', $targetPhp ?? 'the target PHP');
    }

    /**
     * A temp app with no composer.json and three hand-copied libraries under
     * vendor/: Doctrine 2.2.2, Smarty 3.1.8 and an unknown acme-thing.
     */
    private function vendoredApp(): string
    {
        $dir = $this->tempDir();
        mkdir($dir . '/vendor/Doctrine/Doctrine/ORM', 0775, true);
        file_put_contents($dir . '/vendor/Doctrine/Doctrine/ORM/Version.php', "<?php\nconst VERSION = '2.2.2';\n");
        mkdir($dir . '/vendor/Smarty', 0775, true);
        file_put_contents($dir . '/vendor/Smarty/Smarty.class.php', "<?php\nconst SMARTY_VERSION = 'Smarty-3.1.8';\n");
        mkdir($dir . '/vendor/acme-thing', 0775, true);
        file_put_contents($dir . '/vendor/acme-thing/x.php', "<?php\n");

        return $dir;
    }

    private function planFor(string $dir, PlanOptions $options): Plan
    {
        $project = (new ProjectDetector())->detect($dir);

        return (new Planner($this->policy(), new HopRegistry()))->plan($project, $options, $this->today());
    }

    public function test_the_plan_names_the_hand_copied_libraries_and_the_from_php(): void
    {
        $plan = $this->planFor($this->vendoredApp(), new PlanOptions(fromPhp: '5.5'));

        self::assertCount(3, $plan->vendoredLibraries);
        $text = (new TextWriter())->plan($plan);
        // The default target on the test policy date; derived from the plan so
        // the test follows the policy if its top moves.
        self::assertStringContainsString($this->heading($plan->targetPhp), $text);
        self::assertStringContainsString('  - Doctrine 2.2.2 (vendor/Doctrine): replace with the Composer package doctrine/orm', $text);
        self::assertStringContainsString('  - Smarty 3.1.8 (vendor/Smarty): replace with the Composer package smarty/smarty', $text);
        self::assertStringContainsString('  - acme-thing, version unknown (vendor/acme-thing)', $text);
        self::assertStringContainsString("Libraries copied in by hand are often written for an older PHP and can crash even when Booster's changes to your own code are clean. Replace them with Composer packages, then run your tests on the new PHP.", $text);
        // With no composer.json the plan starts where --from-php says it does,
        // not at "unknown".
        self::assertStringContainsString('PHP: 5.5 -> ', $text);
    }

    public function test_a_plan_without_vendor_libraries_prints_no_block_and_carries_no_keys(): void
    {
        $plan = $this->planFor($this->copyExample('raw-php'), new PlanOptions());
        $array = $plan->toArray();

        self::assertStringNotContainsString('hand-copied', (new TextWriter())->plan($plan));
        self::assertArrayNotHasKey('vendored_libraries', $array);
        self::assertArrayNotHasKey('from_php', $array);
    }

    public function test_from_php_is_omitted_when_it_matches_the_apps_own_php(): void
    {
        $plan = $this->planFor($this->copyExample('raw-php'), new PlanOptions(fromPhp: '7.4'));

        self::assertSame('7.4', $plan->project->phpVersion);
        self::assertSame('7.4', $plan->fromPhp);
        self::assertArrayNotHasKey('from_php', $plan->toArray());
    }

    public function test_the_run_report_carries_the_block_before_the_result_line(): void
    {
        $plan = $this->planFor($this->vendoredApp(), new PlanOptions(fromPhp: '5.5'));
        $report = new RunReport($plan, false, new BaselineResult(BaselineResult::PASSED, '5.5', 0), []);
        $lines = explode("\n", (new TextWriter())->run($report));

        $heading = array_search($this->heading($plan->targetPhp), $lines, true);
        $result = null;
        foreach ($lines as $i => $line) {
            if (str_starts_with($line, 'RESULT:')) {
                $result = $i;
                break;
            }
        }
        self::assertIsInt($heading);
        self::assertIsInt($result);
        self::assertLessThan($result, $heading);
        self::assertContains("Libraries copied in by hand are often written for an older PHP and can crash even when Booster's changes to your own code are clean. Replace them with Composer packages, then run your tests on the new PHP.", $lines);
    }

    public function test_to_array_carries_the_library_shapes_as_json(): void
    {
        $plan = $this->planFor($this->vendoredApp(), new PlanOptions(fromPhp: '5.5'));
        $json = json_decode((string) json_encode($plan->toArray()), true);
        self::assertIsArray($json);

        self::assertSame([
            ['name' => 'Doctrine', 'path' => 'vendor/Doctrine', 'version' => '2.2.2', 'replace_with' => 'doctrine/orm'],
            ['name' => 'Smarty', 'path' => 'vendor/Smarty', 'version' => '3.1.8', 'replace_with' => 'smarty/smarty'],
            ['name' => 'acme-thing', 'path' => 'vendor/acme-thing', 'version' => null, 'replace_with' => null],
        ], $json['vendored_libraries']);
        self::assertSame('5.5', $json['from_php']);
    }
}
