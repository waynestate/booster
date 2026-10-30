<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Run\ComposerEditor;
use Waynestate\Booster\ToolError;

final class ComposerEditorTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function read(string $dir): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) file_get_contents($dir . '/composer.json'), true);
    }

    public function test_a_php_hop_raises_the_php_constraint(): void
    {
        $dir = $this->copyExample('raw-php');
        $changes = (new ComposerEditor())->apply((new HopRegistry())->get('php-74-to-80'), $dir);

        self::assertSame(['Set php to ^8.0 in require.'], $changes);
        self::assertSame('^8.0', $this->read($dir)['require']['php']);
    }

    public function test_a_constraint_already_past_the_target_is_left_alone(): void
    {
        $dir = $this->copyExample('laravel-blade-vue');
        $before = file_get_contents($dir . '/composer.json');

        self::assertSame([], (new ComposerEditor())->apply((new HopRegistry())->get('php-74-to-80'), $dir));
        self::assertSame($before, file_get_contents($dir . '/composer.json'));
    }

    public function test_a_laravel_hop_raises_the_framework_and_moves_the_platform_pin(): void
    {
        $dir = $this->copyExample('laravel-blade');
        $data = $this->read($dir);
        $data['config']['platform']['php'] = '8.2.0';
        file_put_contents($dir . '/composer.json', json_encode($data));

        (new ComposerEditor())->apply((new HopRegistry())->get('laravel-10-to-11'), $dir);
        (new ComposerEditor())->apply((new HopRegistry())->get('php-82-to-83'), $dir);

        $after = $this->read($dir);
        self::assertSame('^11.0', $after['require']['laravel/framework']);
        self::assertSame('^8.3', $after['require']['php']);
        self::assertSame('8.3.0', $after['config']['platform']['php']);
    }

    public function test_the_sesame_swap_replaces_the_cas_package(): void
    {
        $dir = $this->copyExample('laravel-wayne-cas');
        (new ComposerEditor())->apply((new HopRegistry())->get('cas-to-sesame'), $dir);

        $require = $this->read($dir)['require'];
        self::assertArrayNotHasKey('cit-web-infra/wayne-cas', $require);
        self::assertSame('^0.2', $require['waynestate/sesame']);
    }

    public function test_a_php_hop_never_adds_a_laravel_requirement(): void
    {
        $dir = $this->copyExample('raw-php');
        (new ComposerEditor())->apply((new HopRegistry())->get('laravel-10-to-11'), $dir);

        self::assertArrayNotHasKey('laravel/framework', $this->read($dir)['require']);
    }

    public function test_no_composer_json_is_not_an_error(): void
    {
        $changes = (new ComposerEditor())->apply((new HopRegistry())->get('php-74-to-80'), $this->tempDir());

        self::assertCount(1, $changes);
        self::assertStringContainsString('No composer.json', $changes[0]);
    }

    public function test_broken_composer_json_is_a_tool_error(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/composer.json', '{nope');

        $this->expectException(ToolError::class);
        (new ComposerEditor())->apply((new HopRegistry())->get('php-74-to-80'), $dir);
    }

    public function test_a_manual_hop_changes_nothing(): void
    {
        $dir = $this->copyExample('vue-spa');
        $before = file_get_contents($dir . '/composer.json');

        self::assertSame([], (new ComposerEditor())->apply((new HopRegistry())->get('vue-2-to-3'), $dir));
        self::assertSame($before, file_get_contents($dir . '/composer.json'));
    }
}
