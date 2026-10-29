<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Node\MixBuild;
use Waynestate\Booster\Node\MixEntry;
use Waynestate\Booster\Node\MixFile;
use Waynestate\Booster\ToolError;

final class MixFileTest extends TestCase
{
    public function test_it_parses_a_simple_js_file(): void
    {
        $build = MixFile::parse(<<<'JS'
        const mix = require('laravel-mix');

        mix.js('resources/js/app.js', 'public/js/app.js');
        JS);

        self::assertFalse($build->vue);
        self::assertCount(1, $build->entries);
        self::assertSame(MixEntry::JS, $build->entries[0]->type);
        self::assertSame('resources/js/app.js', $build->entries[0]->input);
        self::assertSame('public/js/app.js', $build->entries[0]->output);
        self::assertSame(['resources/js/app.js'], $build->inputs());
    }

    public function test_it_parses_js_with_vue_and_sass_on_one_chain(): void
    {
        $build = MixFile::parse(<<<'JS'
        const mix = require('laravel-mix');

        mix.js('resources/js/app.js', 'public/js/app.js')
            .vue()
            .sass('resources/sass/app.scss', 'public/css/app.css');
        JS);

        self::assertTrue($build->vue);
        self::assertSame([MixEntry::JS, MixEntry::SASS], array_map(static fn(MixEntry $e): string => $e->type, $build->entries));
        self::assertSame('resources/sass/app.scss', $build->entries[1]->input);
        self::assertSame('public/css/app.css', $build->entries[1]->output);
    }

    public function test_it_parses_postcss_with_a_plugin_list_and_version(): void
    {
        $build = MixFile::parse(<<<'JS'
        const mix = require('laravel-mix');

        mix.js('resources/js/app.js', 'public/js/app.js')
            .postCss('resources/css/tailwind.css', 'public/css/tailwind.css', [require('autoprefixer')])
            .version();
        JS);

        self::assertFalse($build->vue);
        self::assertCount(2, $build->entries);
        self::assertSame(MixEntry::POSTCSS, $build->entries[1]->type);
        self::assertSame('resources/css/tailwind.css', $build->entries[1]->input);
        self::assertSame('public/css/tailwind.css', $build->entries[1]->output);
    }

    public function test_it_parses_double_quoted_paths_and_multiple_entries(): void
    {
        $build = MixFile::parse(<<<'JS'
        const mix = require('laravel-mix');

        mix.js("resources/js/app.js", "public/js/app.js");
        mix.js("resources/js/admin.js", "public/js/admin.js").version();
        JS);

        self::assertCount(2, $build->entries);
        self::assertSame(['resources/js/app.js', 'resources/js/admin.js'], $build->inputs());
    }

    public function test_the_output_map_keys_outputs_relative_to_public(): void
    {
        $build = MixFile::parse(<<<'JS'
        const mix = require('laravel-mix');

        mix.js('resources/js/app.js', 'public/js/app.js')
            .sass('resources/sass/app.scss', 'public/css/app.css');
        JS);

        self::assertSame([
            'js/app.js' => 'resources/js/app.js',
            'css/app.css' => 'resources/sass/app.scss',
        ], $build->outputMap());
    }

    public function test_it_refuses_webpack_config_naming_the_call(): void
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionMessageMatches('/unsupported Mix call "webpackConfig"/');

        MixFile::parse(<<<'JS'
        const mix = require('laravel-mix');

        mix.js('resources/js/app.js', 'public/js/app.js')
            .webpackConfig(webpack => {
                return webpack;
            });
        JS);
    }

    public function test_it_refuses_extract_naming_the_call(): void
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionMessageMatches('/unsupported Mix call "extract"/');

        MixFile::parse(<<<'JS'
        const mix = require('laravel-mix');

        mix.js('resources/js/app.js', 'public/js/app.js')
            .extract(['lodash']);
        JS);
    }

    public function test_it_refuses_copy_naming_the_call(): void
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionMessageMatches('/unsupported Mix call "copy"/');

        MixFile::parse(<<<'JS'
        const mix = require('laravel-mix');

        mix.copy('resources/images/logo.png', 'public/images/logo.png');
        mix.js('resources/js/app.js', 'public/js/app.js');
        JS);
    }

    public function test_it_refuses_mix_options_naming_the_call(): void
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionMessageMatches('/unsupported Mix call "options"/');

        MixFile::parse(<<<'JS'
        const mix = require('laravel-mix');

        mix.options.process = false;
        mix.js('resources/js/app.js', 'public/js/app.js');
        JS);
    }

    public function test_it_refuses_a_file_without_a_js_entry(): void
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionMessageMatches('/no mix\.js\(/');

        MixFile::parse(<<<'JS'
        const mix = require('laravel-mix');

        mix.sass('resources/sass/app.scss', 'public/css/app.css');
        JS);
    }

    public function test_it_names_the_first_unsupported_call_in_file_order(): void
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionMessageMatches('/unsupported Mix call "copy"/');

        MixFile::parse(<<<'JS'
        const mix = require('laravel-mix');

        mix.copy('a.png', 'public/a.png');
        mix.js('resources/js/app.js', 'public/js/app.js').extract(['lodash']);
        JS);
    }

    public function test_comments_do_not_trigger_a_refusal(): void
    {
        $build = MixFile::parse(<<<'JS'
        const mix = require('laravel-mix');

        // mix.copy('old.png', 'public/old.png') used to live here.
        /* mix.extract(['moment']) */
        mix.js('resources/js/app.js', 'public/js/app.js');
        JS);

        self::assertInstanceOf(MixBuild::class, $build);
        self::assertCount(1, $build->entries);
    }
}
