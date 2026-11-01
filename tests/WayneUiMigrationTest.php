<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Waynestate\Booster\Run\WayneUiMigration;
use Waynestate\Booster\Run\WayneUiMigrationResult;

/**
 * The WayneUI 1.x to 2.x hop engine: it rewrites the mechanical class tokens,
 * Font Awesome icons and brand colours, and turns every context dependent row
 * of the mapping into a manual line that names the file and line.
 */
final class WayneUiMigrationTest extends TestCase
{
    public function test_it_rewrites_every_class_rule_once(): void
    {
        $dir = $this->fixture('markup');
        $result = $this->migrate($dir);
        $layout = $this->read($dir, 'resources/views/layout.blade.php');
        $actions = $this->read($dir, 'resources/views/partials/actions.blade.php');

        self::assertStringContainsString('class="wsu-app-bar__brand"', $layout);
        self::assertStringContainsString('class="wsu-app-bar__mark"', $layout);
        self::assertStringContainsString('class="wsu-footer footer"', $layout);
        self::assertStringContainsString('class="wsu-btn wsu-btn--outline"', $layout);
        self::assertStringContainsString('class="wsu-btn wsu-btn--primary"', $layout);
        self::assertStringContainsString('class="wsu-btn wsu-btn--ghost wsu-btn--sm"', $layout);
        self::assertStringContainsString('class="wsu-btn wsu-btn--outline wsu-btn--sm"', $layout);
        self::assertStringContainsString('class="wsu-btn wsu-btn--lg"', $layout);
        self::assertStringContainsString('class="wsu-badge wsu-badge--success"', $layout);
        self::assertStringContainsString('class="wsu-badge">', $layout);
        self::assertStringContainsString('class="wsu-table-wrap"', $layout);
        self::assertStringContainsString('class="wsu-field has-error"', $layout);
        self::assertStringContainsString('class="wsu-field__label"', $layout);
        self::assertStringContainsString('class="wsu-checkbox"', $layout);
        self::assertStringContainsString('class="wsu-radio"', $layout);
        self::assertStringContainsString('class="wsu-sr-only"', $layout);
        self::assertStringContainsString('class="wsu-btn {{ $state }}"', $actions);
        self::assertStringContainsString('class="wsu-badge"', $actions);
        self::assertStringContainsString('class="wsu-badge wsu-badge--success label-info"', $actions);
        self::assertStringContainsString('class="wsu-btn wsu-btn--sm"', $actions);

        foreach (['btn-default', 'btn-primary', 'btn-link', 'btn-outline', 'btn-xs', 'btn-sm', 'btn-lg', 'navbar-brand', 'navbar-logo', 'wayne-ui-footer', 'table-responsive', 'form-group', 'control-label', 'label-success'] as $old) {
            self::assertStringNotContainsString($old, $layout);
            self::assertStringNotContainsString($old, $actions);
        }
        self::assertStringNotContainsString('class="btn"', $layout);
        self::assertStringNotContainsString('class="btn ', $layout);
        self::assertStringNotContainsString('class="btn"', $actions);
        self::assertStringNotContainsString('class="btn ', $actions);
    }

    public function test_btn_group_and_wsu_btn_are_untouched(): void
    {
        $dir = $this->fixture('markup');
        $this->migrate($dir);
        $actions = $this->read($dir, 'resources/views/partials/actions.blade.php');

        self::assertStringContainsString('<div class="btn-group">', $actions);
        self::assertStringContainsString('<button type="button" class="wsu-btn">Already New</button>', $actions);
    }

    public function test_a_blade_expression_in_a_class_attribute_survives(): void
    {
        $dir = $this->fixture('markup');
        $this->migrate($dir);
        $actions = $this->read($dir, 'resources/views/partials/actions.blade.php');

        self::assertStringContainsString('<button type="button" class="wsu-btn {{ $state }}">Save</button>', $actions);
    }

    public function test_a_label_element_is_untouched(): void
    {
        $dir = $this->fixture('markup');
        $this->migrate($dir);
        $actions = $this->read($dir, 'resources/views/partials/actions.blade.php');

        self::assertStringContainsString('<label for="name">Name</label>', $actions);
    }

    public function test_icon_rewrites_and_manual_lines(): void
    {
        $dir = $this->fixture('markup');
        $result = $this->migrate($dir);
        $layout = $this->read($dir, 'resources/views/layout.blade.php');
        $manual = implode("\n", $result->manual);

        self::assertStringContainsString('<x-wsu::icon name="search" />', $layout);
        self::assertStringContainsString('<x-wsu::icon name="trash-2" />', $layout);
        self::assertStringContainsString('<x-wsu::icon name="menu" />', $layout);
        self::assertStringContainsString('<x-wsu::icon name="loader-circle" />', $layout);
        self::assertStringContainsString('<x-wsu::icon name="log-out" />', $layout);
        self::assertStringNotContainsString('fa fa-', $layout);

        self::assertStringContainsString('fa fa-trash fa-lg cannot be mapped automatically; move it to x-wsu::icon by hand', $manual);
        self::assertStringContainsString('fa-heart is not in the 2.x icon subset; adding it means editing the icon table, so pick another icon', $manual);
        self::assertStringContainsString('fa-unknown-icon has no row in the WayneUI 2.x icon table; choose the Lucide icon by hand', $manual);
        self::assertStringContainsString('glyphicon-star has no mapping table; map it to a Lucide icon by hand', $manual);
        self::assertStringContainsString('fa fa-book carries inner text; move it to x-wsu::icon by hand', $manual);
    }

    public function test_hex_rules(): void
    {
        $dir = $this->fixture('markup');
        $result = $this->migrate($dir);
        $css = $this->read($dir, 'resources/css/app.css');
        $actions = $this->read($dir, 'resources/views/partials/actions.blade.php');
        $manual = implode("\n", $result->manual);

        self::assertStringContainsString('color: var(--wsu-brand);', $css);
        self::assertStringContainsString('background: var(--wsu-brand-subtle);', $css);
        self::assertStringContainsString('border-color: var(--wsu-brand-hover);', $css);
        self::assertStringContainsString('background: var(--wsu-brand-active);', $css);
        self::assertStringContainsString('color: #066666;', $css);
        self::assertStringContainsString('style="color: var(--wsu-brand-hover); border-color: #066666;"', $actions);
        self::assertStringContainsString('#8B2145 becomes var(--wsu-nonproduction), with var(--wsu-on-nonproduction) for text', $manual);
        self::assertStringContainsString('#093f39 belongs to the official header component; delete it', $manual);
        self::assertStringContainsString('#cedddb has no single token; use var(--wsu-green-50) or var(--wsu-text-on-inverse)', $manual);
    }

    public function test_sass_gets_manual_lines_only(): void
    {
        $dir = $this->fixture('markup');
        $before = $this->read($dir, 'resources/scss/theme.scss');
        $result = $this->migrate($dir);
        $manual = implode("\n", $result->manual);

        self::assertSame($before, $this->read($dir, 'resources/scss/theme.scss'));
        self::assertStringContainsString('resources/scss/theme.scss:1: #006666 becomes var(--wsu-brand); Booster does not rewrite Sass, so change it by hand', $manual);
        self::assertStringContainsString('resources/scss/theme.scss:4: #006666 becomes var(--wsu-brand); Booster does not rewrite Sass, so change it by hand', $manual);
    }

    public function test_dry_run_writes_nothing_and_prefixes_changes(): void
    {
        $dir = $this->fixture('markup');
        $before = $this->snapshot($dir);
        $result = $this->migrate($dir, true);

        self::assertSame($before, $this->snapshot($dir));
        self::assertCount(3, $result->changes);
        foreach ($result->changes as $change) {
            self::assertStringStartsWith('Dry run: would rewrite ', $change);
        }
        self::assertCount(3, $result->files);
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $dir = $this->fixture('markup');
        $this->migrate($dir);
        $afterFirst = $this->snapshot($dir);
        $second = $this->migrate($dir);

        self::assertSame([], $second->changes);
        self::assertSame([], $second->files);
        self::assertSame(0, $second->rewrites);
        self::assertSame($afterFirst, $this->snapshot($dir));
    }

    public function test_vendor_and_node_modules_are_skipped(): void
    {
        $dir = $this->fixture('markup');
        $result = $this->migrate($dir);

        self::assertStringContainsString('#006666', $this->read($dir, 'vendor/cit-web-infra/wayne-ui/css/wayne-ui.css'));
        self::assertStringContainsString('#006666', $this->read($dir, 'node_modules/bootstrap/dist/css/bootstrap.css'));
        self::assertStringContainsString('#006666', $this->read($dir, 'public/vendor/wayne-ui/css/wayne-ui.css'));
        foreach ($result->files as $file) {
            self::assertStringNotContainsString('vendor/', $file['file']);
            self::assertStringNotContainsString('node_modules/', $file['file']);
        }
    }

    public function test_manual_lines_are_capped_at_twenty_five_per_rule(): void
    {
        $dir = $this->tempDir();
        mkdir($dir . '/resources/views', 0775, true);
        $lines = '';
        for ($i = 0; $i < 30; $i++) {
            $lines .= '<div class="panel"></div>' . "\n";
        }
        file_put_contents($dir . '/resources/views/many.blade.php', $lines);

        $result = $this->migrate($dir);
        $kept = array_filter($result->manual, static fn(string $line): bool => str_contains($line, 'panel becomes .wsu-card'));

        self::assertCount(25, $kept);
        self::assertContains('card: and 5 more', $result->manual);
    }

    public function test_data_hooks_and_script_tags_are_manual(): void
    {
        $dir = $this->fixture('markup');
        $result = $this->migrate($dir);
        $manual = implode("\n", $result->manual);

        self::assertStringContainsString('resources/views/layout.blade.php:7: the jQuery script tag does nothing in 2.x; there is no jQuery, so drop it', $manual);
        self::assertStringContainsString('resources/views/layout.blade.php:8: the Bootstrap script tag does nothing in 2.x; drop it', $manual);
        self::assertStringContainsString('resources/views/layout.blade.php:17: data-toggle does nothing in 2.x; it becomes data-wsu-drawer-toggle where it drives a drawer, else remove it', $manual);
        self::assertStringContainsString('resources/views/layout.blade.php:17: data-target does nothing in 2.x; point the 2.x equivalent at the element, or remove it', $manual);
        self::assertStringContainsString('resources/views/layout.blade.php:32: data-dismiss does nothing in 2.x; it becomes data-wsu-drawer-close where it closes a drawer, else remove it', $manual);
    }

    public function test_the_markup_fixture_totals(): void
    {
        $dir = $this->fixture('markup');
        $result = $this->migrate($dir);

        self::assertSame(3, $result->changedCount());
        self::assertSame(['resources/css/app.css', 'resources/views/layout.blade.php', 'resources/views/partials/actions.blade.php'], array_column($result->files, 'file'));
        self::assertSame(48, $result->rewrites);
        self::assertSame(58, count($result->manual));
        self::assertSame(['resources/css/app.css: 5 colours.', 'resources/views/layout.blade.php: 28 class tokens, 5 icons, 2 colours.', 'resources/views/partials/actions.blade.php: 7 class tokens, 1 colour.'], $result->changes);
    }

    /** Copies a wayneui1 fixture app into a fresh temp directory and returns it. */
    private function fixture(string $name): string
    {
        $dir = $this->tempDir();
        $this->copyTree(__DIR__ . '/fixtures/wayneui1/' . $name, $dir);

        return $dir;
    }

    private function copyTree(string $from, string $to): void
    {
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir($from . '/' . $entry)) {
                mkdir($to . '/' . $entry, 0775, true);
                $this->copyTree($from . '/' . $entry, $to . '/' . $entry);
            } else {
                copy($from . '/' . $entry, $to . '/' . $entry);
            }
        }
    }

    private function migrate(string $dir, bool $dryRun = false): WayneUiMigrationResult
    {
        return (new WayneUiMigration())->migrate($dir, $dryRun);
    }

    private function read(string $dir, string $relative): string
    {
        $contents = file_get_contents($dir . '/' . $relative);
        self::assertIsString($contents);

        return $contents;
    }

    /** @return array<string, string> relative path => content */
    private function snapshot(string $dir): array
    {
        $out = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile()) {
                $absolute = str_replace('\\', '/', $file->getPathname());
                $out[substr($absolute, strlen($dir) + 1)] = (string) file_get_contents($absolute);
            }
        }
        ksort($out);

        return $out;
    }
}
