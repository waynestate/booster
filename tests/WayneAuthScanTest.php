<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Plan\PlanOptions;
use Waynestate\Booster\Plan\Planner;
use Waynestate\Booster\Project\ProjectDetector;
use Waynestate\Booster\Scan\WayneAuthScan;

/**
 * The Wayne Auth stop sign: an app that still signs people in through the
 * old Wayne Auth service loses its sign-in on the first PHP hop off 5.6, so
 * detect and plan say so before anything else, and point at SESAME's bridge.
 * The fixture is a 2012-shaped raw PHP app with fake values only. Houston,
 * we have a sign-in problem.
 */
final class WayneAuthScanTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/fixtures/wayne-auth-2012';

    public function test_the_classic_wayne_auth_app_is_found_line_by_line(): void
    {
        $hits = WayneAuthScan::find(self::FIXTURE);

        self::assertContains('config/config.ini.example:2 points at the Wayne Auth server (sso_host)', $hits);
        self::assertContains('lib/WayneAuth/WayneAuth.php:3 declares the Wayne Auth class', $hits);
        self::assertContains('public/index.php:7 signs people in through Wayne Auth', $hits);
        self::assertNotEmpty(preg_grep('/^lib\/WayneAuth\/WayneAuth\.php:\d+ reads the Wayne Auth validUser cookie$/', $hits));
        // Sorted by file, so the stop sign names the config first.
        self::assertStringStartsWith('config/', $hits[0]);
    }

    public function test_an_app_on_sesames_bridge_has_no_stop_sign(): void
    {
        $app = $this->copyFixture();
        unlink($app . '/lib/WayneAuth/WayneAuth.php');
        rmdir($app . '/lib/WayneAuth');
        file_put_contents($app . '/config/sesame.php', "<?php\n\nuse Waynestate\\Sesame\\WayneAuth\\WayneAuth;\n\nWayneAuth::boot(['driver' => 'fake', 'environment' => 'local']);\n");
        $index = (string) file_get_contents($app . '/public/index.php');
        file_put_contents($app . '/public/index.php', str_replace("require_once('../lib/WayneAuth/WayneAuth.php');", "require_once('../config/sesame.php');", $index));

        // The bridge keeps new WayneAuth(...) and the sso_host key working on
        // purpose, so neither counts once it boots.
        self::assertSame([], WayneAuthScan::find($app));
        self::assertSame([], WayneAuthScan::stopSign([]));
    }

    public function test_a_half_done_swap_still_names_the_old_class(): void
    {
        $app = $this->copyFixture();
        file_put_contents($app . '/config/sesame.php', "<?php\n\nWaynestate\\Sesame\\WayneAuth\\WayneAuth::boot([]);\n");

        $hits = WayneAuthScan::find($app);

        self::assertContains('lib/WayneAuth/WayneAuth.php:3 declares the Wayne Auth class', $hits);
        self::assertNotContains('public/index.php:7 signs people in through Wayne Auth', $hits);
        self::assertNotContains('config/config.ini.example:2 points at the Wayne Auth server (sso_host)', $hits);
    }

    public function test_the_wayne_cas_middleware_is_not_wayne_auth(): void
    {
        $app = $this->tempDir();
        mkdir($app . '/app/Http', 0775, true);
        file_put_contents($app . '/app/Http/Kernel.php', "<?php\n\nuse WSU\\WayneCas\\WayneAuthenticate;\n\nclass WayneAuthenticateProxy extends WayneAuthenticate {}\n\$m = new WayneAuthenticate(\$cas);\n");

        self::assertSame([], WayneAuthScan::find($app));
    }

    public function test_the_stop_sign_names_three_hits_and_points_at_the_bridge(): void
    {
        $lines = WayneAuthScan::stopSign(['a.php:1 one', 'b.php:2 two', 'c.php:3 three', 'd.php:4 four', 'e.php:5 five']);

        self::assertSame('Stop sign: the app signs people in through Wayne Auth (a.php:1 one; b.php:2 two; c.php:3 three; and 2 more).', $lines[0]);
        self::assertStringContainsString('once the app leaves PHP 5.6 nobody can sign in', $lines[1]);
        self::assertStringEndsWith(WayneAuthScan::GUIDE, $lines[1]);
        self::assertStringNotContainsString("\u{2014}", implode(' ', $lines));
    }

    public function test_detect_shows_the_stop_sign_and_the_key(): void
    {
        $project = (new ProjectDetector())->detect(self::FIXTURE);

        self::assertTrue($project->toArray()['wayne_auth']);
        self::assertFalse((new ProjectDetector())->detect($this->example('raw-php'))->toArray()['wayne_auth']);
    }

    public function test_plan_puts_the_stop_sign_first(): void
    {
        $project = (new ProjectDetector())->detect(self::FIXTURE);
        $plan = (new Planner($this->policy(), new HopRegistry()))->plan($project, new PlanOptions(fromPhp: '5.6'), $this->today());

        self::assertNotSame([], $plan->steps, 'the stop sign warns; it does not take the PHP hops out');
        self::assertStringStartsWith('Stop sign: the app signs people in through Wayne Auth (config/config.ini.example:2', $plan->warnings[0]);
        self::assertStringContainsString("SESAME's Wayne Auth bridge", $plan->warnings[0]);
    }

    private function copyFixture(): string
    {
        $app = $this->tempDir();
        foreach (['lib/WayneAuth/WayneAuth.php', 'public/index.php', 'config/config.ini.example', 'README.md'] as $file) {
            if (!is_dir(dirname($app . '/' . $file))) {
                mkdir(dirname($app . '/' . $file), 0775, true);
            }
            copy(self::FIXTURE . '/' . $file, $app . '/' . $file);
        }

        return $app;
    }
}
