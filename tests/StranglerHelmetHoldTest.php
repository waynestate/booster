<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use PHPUnit\Framework\TestCase;
use Waynestate\Booster\Strangler\HelmetHold;
use Waynestate\Booster\ToolError;

final class StranglerHelmetHoldTest extends TestCase
{
    public function test_sourdoughs_override_file_gains_a_web_scope_once(): void
    {
        $text = "<?php\n\n\$policyPath = null;\n\nreturn [\n    'policy_path' => \$policyPath,\n];\n";
        $once = HelmetHold::withWebScope($text);

        self::assertStringContainsString("return [\n    // booster:strangler", $once);
        self::assertStringContainsString("    'scope' => 'web',\n    'policy_path' => \$policyPath,", $once);
        self::assertSame($once, HelmetHold::withWebScope($once), 'a second move leaves it alone');
    }

    public function test_an_existing_scope_is_replaced_not_doubled(): void
    {
        $text = "<?php\n\nreturn [\n    'scope' => env('HELMET_SCOPE', 'global'),\n];\n";
        $new = HelmetHold::withWebScope($text);

        self::assertSame(1, substr_count($new, "'scope' =>"));
        self::assertStringContainsString("'scope' => 'web',", $new);
    }

    public function test_a_config_without_a_return_array_is_named(): void
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionMessage("add 'scope' => 'web' to it by hand");

        HelmetHold::withWebScope("<?php\n\nreturn require __DIR__.'/other.php';\n");
    }

    public function test_no_helmet_config_is_left_alone(): void
    {
        $dir = sys_get_temp_dir() . '/booster-helmet-' . bin2hex(random_bytes(4));
        mkdir($dir);

        self::assertFalse(HelmetHold::scopeToWeb($dir));
        self::assertFileDoesNotExist($dir . '/config/helmet.php');
        rmdir($dir);
    }

    public function test_the_skip_names_all_three_helmet_middlewares(): void
    {
        foreach (['SecurityHeaders', 'InjectRibbon', 'RequestForgeryGuard'] as $class) {
            self::assertStringContainsString('\\Waynestate\\Helmet\\Laravel\\' . $class . '::class', HelmetHold::SKIP);
        }
    }
}
