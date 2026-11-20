<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Run\Strangler\LaneStranglerParts;
use Waynestate\Booster\Run\Strangler\PartResult;
use Waynestate\Booster\Strangler\AddsComposerPackages;
use Waynestate\Booster\Strangler\CakeMover;
use Waynestate\Booster\Strangler\CakeUnitSource;
use Waynestate\Booster\Strangler\MountBaker;
use Waynestate\Booster\Strangler\SlimUnitSource;
use Waynestate\Booster\Strangler\Unit;
use Waynestate\Booster\ToolError;

/**
 * LaneStranglerParts, the adapter from ENGINE's seam to the other lanes'
 * classes (design section 6): each part's own result maps onto PartResult,
 * and the framework picks the unit source and mover.
 */
final class StranglerLanePartsTest extends TestCase
{
    private static function parts(?MountBaker $baker = null): LaneStranglerParts
    {
        return new LaneStranglerParts($baker ?? self::failingBaker('not used'), dirname(__DIR__) . '/resources/strangler');
    }

    private static function failingBaker(string $why): MountBaker
    {
        return new class ($why) implements MountBaker {
            public function __construct(private readonly string $why) {}

            public function bake(string $targetDir, string $name): void
            {
                throw new ToolError($this->why);
            }

            public function describe(): string
            {
                return 'a baker that fails';
            }
        };
    }

    public function test_the_framework_picks_the_unit_source_and_mover(): void
    {
        $parts = self::parts();
        self::assertInstanceOf(SlimUnitSource::class, $parts->unitSource('slim'));
        self::assertInstanceOf(CakeUnitSource::class, $parts->unitSource('cakephp'));
        self::assertInstanceOf(CakeMover::class, $parts->mover('cakephp'));
        $slim = $parts->mover('slim');
        self::assertSame('slim', $slim->framework());
        // The engine runs composer update for what a Slim move added only through AddsComposerPackages.
        self::assertInstanceOf(AddsComposerPackages::class, $slim);
        self::assertSame([], $slim->composerAdds());

        $this->expectException(ToolError::class);
        $parts->unitSource('symfony');
    }

    public function test_the_route_table_round_trip(): void
    {
        $dir = $this->tempDir();
        $parts = self::parts();
        $unit = new Unit('GET /users/{id}', 'route', ['GET'], 'users/{id}', 'users.view', 'page', false, 'app/routes.php', 12);
        self::assertFalse($parts->routeHas($dir, $unit->id));
        $parts->routeAdd($dir, $unit);
        self::assertTrue($parts->routeHas($dir, $unit->id));
        self::assertFileExists($dir . '/bootstrap/booster-moved.php');
        $rows = require $dir . '/bootstrap/booster-moved.php';
        self::assertSame('GET /users/{id}', $rows[0]['unit']);
        self::assertSame(1, preg_match($rows[0]['regex'], '/users/7'));
        $parts->routeRemove($dir, $unit->id);
        self::assertFalse($parts->routeHas($dir, $unit->id));
    }

    public function test_a_mount_whose_baker_fails_is_refused_with_its_reason(): void
    {
        $dir = $this->tempDir();
        self::copyTree(dirname(__DIR__) . '/tests/fixtures/strangler/slim4/before', $dir);
        $result = self::parts(self::failingBaker('Sourdough could not bake the Laravel half (exit 1).'))->mount($dir, 'slim');

        self::assertSame(PartResult::REFUSED, $result->status);
        self::assertNotSame([], $result->refusals);
        self::assertStringContainsString('Sourdough could not bake', implode("\n", $result->refusals));
        self::assertFileDoesNotExist($dir . '/legacy', 'a refused mount moves nothing');
    }

    public function test_an_app_with_no_sign_in_is_already_done_and_lti_is_a_stop_sign(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/composer.json', json_encode(['require' => ['slim/slim' => '^4.15']]));
        @mkdir($dir . '/public');
        file_put_contents($dir . '/public/index.php', "<?php\n\$app = Slim\\Factory\\AppFactory::create();\n\$app->get('/', fn (\$req, \$res) => \$res);\n\$app->run();\n");
        $inspect = self::parts()->signInInspect($dir, 'slim');
        self::assertSame(PartResult::ALREADY, $inspect->status, implode("\n", $inspect->refusals));

        file_put_contents($dir . '/composer.json', json_encode(['require' => ['slim/slim' => '^4.15', 'celtic/lti' => '^5.0']]));
        $lti = self::parts()->signInInspect($dir, 'slim');
        self::assertSame(PartResult::REFUSED, $lti->status);
        self::assertStringContainsString('No strangler yet for LTI apps', implode("\n", $lti->refusals));
    }

    public function test_the_replay_check_says_why_a_missing_request_file_cannot_gate(): void
    {
        $dir = $this->tempDir();
        self::assertNotNull(self::parts()->replayUnusable($dir, null, null));
    }

    private static function copyTree(string $from, string $to): void
    {
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir($from . '/' . $entry)) {
                @mkdir($to . '/' . $entry, 0775, true);
                self::copyTree($from . '/' . $entry, $to . '/' . $entry);
            } else {
                copy($from . '/' . $entry, $to . '/' . $entry);
            }
        }
    }
}
