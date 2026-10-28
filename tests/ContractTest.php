<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Waynestate\Booster\Hop\HopKind;
use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Plan\PlanOptions;
use Waynestate\Booster\Plan\Planner;
use Waynestate\Booster\Project\ProjectDetector;
use Waynestate\Booster\Run\RectorRunner;
use Waynestate\Booster\Run\SymfonyProcessRunner;

/**
 * Runs contract/expectations.json against the examples. The Rector part starts
 * the real Rector (a dry run), so a Rector or rector-laravel release that
 * changes what a hop does shows up here.
 */
final class ContractTest extends TestCase
{
    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function examples(): array
    {
        $raw = file_get_contents(dirname(__DIR__) . '/contract/expectations.json');
        $contract = json_decode((string) $raw, true);
        self::assertIsArray($contract);

        $cases = [];
        foreach ($contract['examples'] as $name => $expectation) {
            $cases[$name] = [$name, $expectation];
        }

        return $cases;
    }

    /**
     * Only the examples whose contract names Rector hops.
     *
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function rectorExamples(): array
    {
        return array_filter(self::examples(), static fn(array $case): bool => $case[1]['rector'] !== []);
    }

    /**
     * @param array<string, mixed> $expect
     */
    #[DataProvider('examples')]
    public function test_detection_and_plan_match_the_contract(string $name, array $expect): void
    {
        $project = (new ProjectDetector())->detect($this->example($name));

        self::assertSame($expect['app_type'], $project->type->value);
        self::assertSame($expect['php'], $project->phpVersion);
        self::assertSame($expect['laravel'], $project->laravelMajor);
        self::assertSame($expect['vue'], $project->vueMajor);

        $plan = (new Planner($this->policy(), new HopRegistry()))->plan(
            $project,
            new PlanOptions(sesame: (bool) ($expect['sesame'] ?? false)),
            $this->today(),
        );
        self::assertSame($expect['plan'], array_map(static fn($s): string => $s->hop->id, $plan->steps));
    }

    /**
     * @param array<string, mixed> $expect
     */
    #[DataProvider('rectorExamples')]
    public function test_rector_changes_the_files_the_contract_names(string $name, array $expect): void
    {
        $dir = $this->copyExample($name);
        if (($expect['stub_vendor'] ?? false) === true) {
            // The smallest autoloader that lets rector-laravel recognise an Eloquent model.
            mkdir($dir . '/vendor', 0775, true);
            file_put_contents($dir . '/vendor/autoload.php', "<?php\nnamespace Illuminate\\Database\\Eloquent { class Model {} }\n");
        }

        $registry = new HopRegistry();
        $project = (new ProjectDetector())->detect($dir);
        $plan = (new Planner($this->policy(), $registry))->plan($project, new PlanOptions(sesame: (bool) ($expect['sesame'] ?? false)), $this->today());
        $runner = new RectorRunner(new SymfonyProcessRunner());

        foreach ($expect['rector'] as $hopId => $files) {
            $step = null;
            foreach ($plan->steps as $candidate) {
                if ($candidate->hop->id === $hopId) {
                    $step = $candidate;
                }
            }
            self::assertNotNull($step, "{$hopId} is not in the plan for {$name}.");

            // A PHPUnit hop pins the synthetic installed.json to the hop's target major so the
            // version-bound rules apply; other hops leave PHPUnit at the app's own version.
            $phpunitTarget = $step->hop->kind === HopKind::Phpunit ? $step->phpunitAfter : null;
            $result = $runner->run($step->hop, (string) realpath($dir), true, (string) $step->phpAfter, $step->laravelAfter, $phpunitTarget);
            $changed = array_map(static fn(array $f): string => $f['file'], $result->files);
            sort($changed);

            self::assertSame([], $result->errors, "{$hopId} on {$name}");
            self::assertSame($files, $changed, "{$hopId} on {$name}");
        }
    }
}
