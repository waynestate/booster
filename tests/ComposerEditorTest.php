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
        self::assertSame('^0.3', $require['waynestate/sesame']);
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

    public function test_advisory_unblock_writes_a_scoped_entry(): void
    {
        $dir = $this->copyExample('laravel-blade');
        $editor = new ComposerEditor();

        $changes = $editor->advisoryUnblock($dir, 'laravel/framework', '^13.0', ['PKSA-2026-0001', 'PKSA-2026-0002']);

        self::assertCount(1, $changes);
        self::assertStringContainsString('config.policy.advisories.ignore.laravel/framework', $changes[0]);
        self::assertStringContainsString('^13.0', $changes[0]);
        $rule = $this->read($dir)['config']['policy']['advisories']['ignore']['laravel/framework'][0];
        self::assertSame('^13.0', $rule['constraint']);
        self::assertTrue($rule['on-block']);
        self::assertFalse($rule['on-audit']);
        self::assertStringContainsString('Booster temporary unblock', $rule['reason']);
        self::assertStringContainsString('PKSA-2026-0001, PKSA-2026-0002', $rule['reason']);
    }

    public function test_advisory_unblock_without_ids_names_the_branch_fallback(): void
    {
        $dir = $this->copyExample('laravel-blade');
        $editor = new ComposerEditor();

        $editor->advisoryUnblock($dir, 'laravel/framework', '^9.0', []);

        $rule = $this->read($dir)['config']['policy']['advisories']['ignore']['laravel/framework'][0];
        self::assertStringContainsString('Booster temporary unblock', $rule['reason']);
        self::assertStringContainsString('branch version', $rule['reason']);
        self::assertStringNotContainsString('PKSA', $rule['reason']);
    }

    public function test_temporary_unblocks_reads_back_its_own_entries(): void
    {
        $dir = $this->copyExample('laravel-blade');
        $editor = new ComposerEditor();
        self::assertSame([], $editor->temporaryUnblocks($dir));

        $editor->advisoryUnblock($dir, 'laravel/framework', '^13.0', ['PKSA-2026-0001']);
        $editor->advisoryUnblock($dir, 'phpunit/phpunit', '^11.0', []);

        self::assertSame(['laravel/framework', 'phpunit/phpunit'], $editor->temporaryUnblocks($dir));
    }

    public function test_temporary_unblocks_still_reads_the_legacy_prefix(): void
    {
        $dir = $this->copyExample('laravel-blade');
        $data = $this->read($dir);
        $data['config']['policy']['advisories']['ignore'] = [
            'laravel/framework' => [[
                'constraint' => '^12.0',
                'reason' => 'BOOSTER temporary unblock: Packagist advisories PKSA-2026-0001 block the tagged releases of laravel/framework needed for the upgrade. Remove this entry once the app is off them.',
                'on-block' => true,
                'on-audit' => false,
            ]],
        ];
        file_put_contents($dir . '/composer.json', json_encode($data));

        self::assertSame(['laravel/framework'], (new ComposerEditor())->temporaryUnblocks($dir));
    }

    public function test_temporary_unblocks_ignore_foreign_entries_and_odd_shapes(): void
    {
        $dir = $this->copyExample('laravel-blade');
        $data = $this->read($dir);
        $data['config']['policy']['advisories']['ignore'] = [
            'doctrine/dbal' => 'legacy reason',
            'monolog/monolog' => null,
            'symfony/console' => [['constraint' => '^7.0', 'reason' => 'our own exemption', 'on-block' => true, 'on-audit' => false]],
            'laravel/framework' => [['constraint' => '^13.0', 'reason' => 'booster temporary unblock: lowercase is not ours', 'on-block' => true, 'on-audit' => false]],
        ];
        file_put_contents($dir . '/composer.json', json_encode($data));

        self::assertSame([], (new ComposerEditor())->temporaryUnblocks($dir));
    }

    public function test_temporary_unblocks_survive_a_missing_or_broken_manifest(): void
    {
        $editor = new ComposerEditor();
        self::assertSame([], $editor->temporaryUnblocks($this->tempDir()));

        $dir = $this->tempDir();
        file_put_contents($dir . '/composer.json', '{nope');
        self::assertSame([], $editor->temporaryUnblocks($dir));
    }

    public function test_advisory_unblock_is_idempotent(): void
    {
        $dir = $this->copyExample('laravel-blade');
        $editor = new ComposerEditor();
        $ids = ['PKSA-2026-0001'];

        self::assertCount(1, $editor->advisoryUnblock($dir, 'laravel/framework', '^13.0', $ids));
        $afterFirst = file_get_contents($dir . '/composer.json');
        self::assertSame([], $editor->advisoryUnblock($dir, 'laravel/framework', '^13.0', $ids));
        self::assertSame($afterFirst, file_get_contents($dir . '/composer.json'));
    }

    public function test_advisory_unblock_preserves_an_existing_map_entry(): void
    {
        $dir = $this->copyExample('laravel-blade');
        $data = $this->read($dir);
        $data['config']['policy']['advisories']['ignore'] = ['doctrine/dbal' => 'legacy reason'];
        file_put_contents($dir . '/composer.json', json_encode($data));

        (new ComposerEditor())->advisoryUnblock($dir, 'laravel/framework', '^13.0', ['PKSA-2026-0001']);

        $ignore = $this->read($dir)['config']['policy']['advisories']['ignore'];
        self::assertSame('legacy reason', $ignore['doctrine/dbal']);
        self::assertCount(1, $ignore['laravel/framework']);
        self::assertSame('^13.0', $ignore['laravel/framework'][0]['constraint']);
    }

    public function test_advisory_unblock_normalises_a_plain_list_of_names(): void
    {
        $dir = $this->copyExample('laravel-blade');
        $data = $this->read($dir);
        // The other allowed shape: a plain list of package names.
        $data['config']['policy']['advisories']['ignore'] = ['monolog/monolog'];
        file_put_contents($dir . '/composer.json', json_encode($data));

        (new ComposerEditor())->advisoryUnblock($dir, 'laravel/framework', '^13.0', ['PKSA-2026-0001']);

        $ignore = $this->read($dir)['config']['policy']['advisories']['ignore'];
        self::assertNull($ignore['monolog/monolog']);
        self::assertCount(1, $ignore['laravel/framework']);
        self::assertSame('^13.0', $ignore['laravel/framework'][0]['constraint']);
    }
}
