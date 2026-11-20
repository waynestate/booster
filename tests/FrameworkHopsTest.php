<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Symfony\Component\Console\Tester\ApplicationTester;
use Waynestate\Booster\Console\BoosterApplication;
use Waynestate\Booster\Hop\EngineId;
use Waynestate\Booster\Hop\HopKind;
use Waynestate\Booster\Hop\HopRegistry;

/**
 * The CakePHP and Slim hops' faces: what the registry, `booster hops` and the
 * hop JSON say about them. Their engines land in later slices.
 */
final class FrameworkHopsTest extends TestCase
{
    public function test_the_three_framework_hops_are_registered_with_their_engines(): void
    {
        $registry = new HopRegistry();
        $expected = [
            HopRegistry::CAKEPHP_4_TO_46_ID => ['4', '4.6', EngineId::CakeUpgrade],
            HopRegistry::CAKEPHP_46_TO_5_ID => ['4.6', '5.4', EngineId::CakeUpgrade],
            HopRegistry::SLIM_3_TO_4_ID => ['3', '4.15', EngineId::SlimUpgrade],
        ];

        self::assertSame(['cakephp-4-to-4.6', 'cakephp-4.6-to-5', 'slim-3-to-4'], array_keys($expected));
        foreach ($expected as $id => [$from, $to, $engine]) {
            $hop = $registry->get($id);
            self::assertSame(HopKind::Framework, $hop->kind, $id);
            self::assertSame([$from, $to], [$hop->from, $hop->to], $id);
            self::assertSame($engine, $hop->engine, $id);
            self::assertSame($engine->value, $hop->toArray()['engine'], $id);
            self::assertNotSame([], $hop->notes, $id);
            self::assertNotSame([], $hop->links, $id);
        }

        self::assertSame(['cakephp41', 'cakephp42', 'cakephp43', 'cakephp44', 'cakephp45', 'migrations45'], $registry->get(HopRegistry::CAKEPHP_4_TO_46_ID)->rectorSets);
        self::assertSame(['cakephp50', 'cakephp51', 'cakephp52', 'cakephp53', 'cakephp54', 'chronos3', 'migrations50'], $registry->get(HopRegistry::CAKEPHP_46_TO_5_ID)->rectorSets);
        self::assertSame([], $registry->get(HopRegistry::SLIM_3_TO_4_ID)->rectorSets);
        self::assertFalse($registry->get(HopRegistry::CAKEPHP_4_TO_46_ID)->extraReview);
        self::assertTrue($registry->get(HopRegistry::CAKEPHP_46_TO_5_ID)->extraReview);
        self::assertTrue($registry->get(HopRegistry::SLIM_3_TO_4_ID)->extraReview);
        self::assertSame(
            'Move a CakePHP 4 app to the last 4.x release (4.6) with CakePHP\'s own upgrade rules, so every deprecation is flagged before the jump to 5.',
            $registry->get(HopRegistry::CAKEPHP_4_TO_46_ID)->summary,
        );
        self::assertSame(['https://book.cakephp.org/4/en/appendices/4-6-migration-guide.html'], $registry->get(HopRegistry::CAKEPHP_4_TO_46_ID)->links);
        self::assertSame(['https://book.cakephp.org/5/en/appendices/5-0-migration-guide.html'], $registry->get(HopRegistry::CAKEPHP_46_TO_5_ID)->links);
        self::assertSame(['https://www.slimframework.com/docs/v4/start/upgrade.html'], $registry->get(HopRegistry::SLIM_3_TO_4_ID)->links);
    }

    public function test_framework_hops_are_not_manual(): void
    {
        $registry = new HopRegistry();
        foreach ([HopRegistry::CAKEPHP_4_TO_46_ID, HopRegistry::CAKEPHP_46_TO_5_ID, HopRegistry::SLIM_3_TO_4_ID] as $id) {
            $hop = $registry->get($id);
            self::assertFalse($hop->isManual(), $id . ' runs through its engine, so it is not a playbook');
            self::assertFalse($hop->toArray()['manual'], $id);
        }
        // The Slim hop carries no rule sets and no class renames, the shape
        // of a playbook for any other kind; the framework kind keeps it automatic.
        self::assertSame([], $registry->get(HopRegistry::SLIM_3_TO_4_ID)->classRenames);
    }

    public function test_cakephp_hop_composer_sets_include_companion_pins(): void
    {
        $registry = new HopRegistry();

        $to46 = $registry->get(HopRegistry::CAKEPHP_4_TO_46_ID);
        self::assertSame(['cakephp/cakephp' => '^4.6'], $to46->composerSet);
        self::assertSame($registry->frameworkCompanionPins('cakephp', '4.6'), $to46->composerSetIfPresent);
        self::assertSame('^3.7', $to46->composerSetIfPresent['cakephp/migrations']);

        $to5 = $registry->get(HopRegistry::CAKEPHP_46_TO_5_ID);
        self::assertSame(['cakephp/cakephp' => '^5.4'], $to5->composerSet);
        self::assertSame($registry->frameworkCompanionPins('cakephp', '5.4'), $to5->composerSetIfPresent);
        self::assertSame('^3.3', $to5->composerSetIfPresent['cakephp/authentication']);

        $slim = $registry->get(HopRegistry::SLIM_3_TO_4_ID);
        self::assertSame(['slim/slim' => '^4.15'], $slim->composerSet);
        self::assertSame($registry->frameworkCompanionPins('slim', '4.15'), $slim->composerSetIfPresent);
        self::assertArrayHasKey('slim/psr7', $slim->composerSetIfPresent);
    }

    public function test_hops_listing_includes_framework_hops(): void
    {
        $tester = new ApplicationTester(self::app());
        $tester->run(['command' => 'hops']);
        $text = $tester->getDisplay();

        $lines = explode("\n", $text);
        $vendored = null;
        foreach ($lines as $i => $line) {
            if (str_starts_with($line, 'vendored-to-composer ')) {
                $vendored = $i;
                break;
            }
        }
        self::assertIsInt($vendored, 'the framework hops are listed next to the vendored hop');
        self::assertStringStartsWith('cakephp-4-to-4.6   Move a CakePHP 4 app to the last 4.x release (4.6)', $lines[$vendored + 1]);
        self::assertStringStartsWith('cakephp-4.6-to-5   Move a CakePHP 4.6 app to CakePHP 5.4', $lines[$vendored + 2]);
        self::assertStringStartsWith('slim-3-to-4        Move a Slim 3 app to Slim 4.15', $lines[$vendored + 3]);
        foreach ([1, 2, 3] as $offset) {
            self::assertStringNotContainsString('[playbook]', $lines[$vendored + $offset]);
        }

        $tester->run(['command' => 'hops', '--format' => 'json']);
        $hops = json_decode($tester->getDisplay(), true)['hops'];
        $byId = array_column($hops, null, 'id');
        self::assertSame('cake-upgrade', $byId['cakephp-4-to-4.6']['engine']);
        self::assertSame('cake-upgrade', $byId['cakephp-4.6-to-5']['engine']);
        self::assertSame('slim-upgrade', $byId['slim-3-to-4']['engine']);
        self::assertSame('vendored', $byId['vendored-to-composer']['engine']);
        self::assertArrayNotHasKey('engine', $byId['php-84-to-85']);
    }

    private static function app(): BoosterApplication
    {
        $app = new BoosterApplication();
        $app->setAutoExit(false);

        return $app;
    }
}
