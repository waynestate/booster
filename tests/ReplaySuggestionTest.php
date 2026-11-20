<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Plan\PlanOptions;
use Waynestate\Booster\Plan\Planner;
use Waynestate\Booster\Project\AppType;
use Waynestate\Booster\Project\Project;
use Waynestate\Booster\Replay\ReplaySuggestion;
use Waynestate\Booster\Run\BaselineResult;

/**
 * Bug 9 (proof run 2026-11-20): the replay nudge gave every plan the PHP
 * 8.0 hop's reasons, so a Slim or CakePHP app with no tests was told its
 * framework move was about loose comparisons.
 */
final class ReplaySuggestionTest extends TestCase
{
    public function test_a_slim_plan_names_each_risky_hop_with_its_own_reason(): void
    {
        // The fleet's Slim 3 shape on PHP 7.4, no tests: the syntax gate.
        $dir = $this->tempDir();
        file_put_contents($dir . '/composer.json', (string) json_encode([
            'require' => ['php' => '>=7.4', 'slim/slim' => '^3.12'],
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
        ]));
        $project = new Project(path: $dir, hasComposer: true, phpVersion: '7.4', laravelMajor: null, phpunitMajor: null, vueMajor: null, type: AppType::RawPhp, usesWayneCas: false, hasTestScript: false, framework: 'slim', frameworkVersion: '3.12');
        $plan = (new Planner($this->policy(), new HopRegistry()))->plan($project, new PlanOptions(toPhp: '8.5'), $this->today());
        $baseline = new BaselineResult(BaselineResult::PASSED, '7.4', 0, gateKind: BaselineResult::GATE_SYNTAX);

        $suggestion = ReplaySuggestion::for($plan, $baseline, $dir, false, false);

        self::assertNotNull($suggestion);
        $ids = array_map(static fn(array $risky): string => $risky[1], $suggestion->riskySteps);
        // Plan order: the Slim move runs on PHP 7.4, before the PHP 8.0 hop.
        self::assertSame([HopRegistry::SLIM_3_TO_4_ID, 'php-74-to-80'], $ids);
        // The JSON keys keep naming the first one.
        self::assertSame($suggestion->riskySteps[0][0], $suggestion->riskiestStep);
        self::assertSame(HopRegistry::SLIM_3_TO_4_ID, $suggestion->riskiestHop);

        $text = implode("\n", $suggestion->lines('booster', $dir));
        self::assertStringContainsString(sprintf('Step %d (php-74-to-80) is one of the hops most likely to change an answer: loose comparisons of numbers and strings, and warnings that now throw.', $suggestion->riskySteps[1][0]), $text);
        self::assertStringContainsString(sprintf('Step %d (slim-3-to-4) is one of the hops most likely to change an answer: a framework major: the bootstrap, routes, request and response, error pages and middleware all move to Slim 4.', $suggestion->riskySteps[0][0]), $text);
    }

    public function test_the_cakephp_5_hop_gets_its_own_reason(): void
    {
        $suggestion = new ReplaySuggestion(ReplaySuggestion::REASON_SYNTAX, true, 3, HopRegistry::CAKEPHP_46_TO_5_ID, [[3, HopRegistry::CAKEPHP_46_TO_5_ID]]);

        $text = implode("\n", $suggestion->lines('booster', '.'));

        self::assertStringContainsString('Step 3 (cakephp-4.6-to-5) is the hop most likely to change an answer: a framework major: sign-in moves from AuthComponent to the Authentication plugin', $text);
        self::assertStringNotContainsString('loose comparisons', $text);
    }
}
