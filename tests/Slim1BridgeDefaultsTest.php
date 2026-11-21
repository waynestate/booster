<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Run\ProcessResult;
use Waynestate\Booster\Run\RectorRunner;

/**
 * The defaults the Slim 1 bridge the vendored-to-composer hop writes into a
 * migrated app: debug stays off, so a migrated app never shows Slim's debug
 * error pages unless its own settings turn them on, and a bridge an earlier
 * Booster release wrote is still left alone by Rector.
 */
final class Slim1BridgeDefaultsTest extends TestCase
{
    private const BRIDGE = __DIR__ . '/../resources/slim1/slim1-bridge.php';

    public function test_the_bridge_defaults_debug_to_off(): void
    {
        require_once self::BRIDGE;

        self::assertSame(false, \Slim::getDefaultSettings()['debug']);
        self::assertSame('development', \Slim::getDefaultSettings()['mode']);
    }

    public function test_rector_stays_off_a_bridge_an_earlier_booster_wrote(): void
    {
        $bridge = (string) file_get_contents(self::BRIDGE);
        $earlier = preg_replace("/^(\s*)'debug' => false,$/m", "$1'debug' => true,", $bridge, 1, $count);
        self::assertNotFalse($earlier);
        self::assertSame(1, $count, 'the shipped bridge must carry the one debug line the earlier file differs on');

        // The earlier release wrote the same file with CRLF line endings in
        // this app, so the hash must match after normalizing to LF.
        $app = $this->tempDir();
        file_put_contents($app . '/slim1-bridge.php', str_replace("\n", "\r\n", $earlier));
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(2, '{"file_diffs": [], "errors": []}', ''));
        (new RectorRunner($fake, '/fake/rector'))->run((new HopRegistry())->get('php-80-to-81'), $app, true, '8.1');

        $process = $fake->callsWith('process');
        self::assertCount(1, $process);
        self::assertSame([$app . '/slim1-bridge.php'], json_decode($process[0]['env']['BOOSTER_RECTOR_SKIP_FILES'], true));
    }
}
