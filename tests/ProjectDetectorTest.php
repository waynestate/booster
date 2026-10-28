<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Project\AppType;
use Waynestate\Booster\Project\ProjectDetector;
use Waynestate\Booster\ToolError;

final class ProjectDetectorTest extends TestCase
{
    /**
     * @return array<string, array{string, AppType, ?string, ?string, ?string}>
     */
    public static function examples(): array
    {
        return [
            'raw php' => ['raw-php', AppType::RawPhp, '7.4', null, null],
            'raw php with a Vue 2 SPA' => ['vue-spa', AppType::RawPhpVue, '8.0', null, '2'],
            'laravel blade' => ['laravel-blade', AppType::LaravelBlade, '8.2', '10', null],
            'laravel blade with vue islands' => ['laravel-blade-vue', AppType::LaravelBladeVue, '8.3', '12', '3'],
            'laravel inertia vue' => ['laravel-inertia-vue', AppType::LaravelInertiaVue, '8.3', '12', '3'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('examples')]
    public function test_each_stack_permutation_is_recognised(string $example, AppType $type, ?string $php, ?string $laravel, ?string $vue): void
    {
        $project = (new ProjectDetector())->detect($this->example($example));

        self::assertSame($type, $project->type);
        self::assertSame($php, $project->phpVersion);
        self::assertSame($laravel, $project->laravelMajor);
        self::assertSame($vue, $project->vueMajor);
    }

    public function test_wayne_cas_is_flagged(): void
    {
        $project = (new ProjectDetector())->detect($this->example('laravel-wayne-cas'));

        self::assertTrue($project->usesWayneCas);
        self::assertFalse((new ProjectDetector())->detect($this->example('raw-php'))->usesWayneCas);
    }

    public function test_an_app_without_composer_is_raw_php_with_unknown_version(): void
    {
        $project = (new ProjectDetector())->detect($this->tempDir());

        self::assertFalse($project->hasComposer);
        self::assertSame(AppType::RawPhp, $project->type);
        self::assertNull($project->phpVersion);
    }

    public function test_a_wide_open_php_constraint_is_unknown(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/composer.json', '{"require":{"php":"*"}}');

        self::assertNull((new ProjectDetector())->detect($dir)->phpVersion);
    }

    public function test_alternatives_use_the_lowest_bound(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/composer.json', '{"require":{"php":"^7.4 || ^8.0"}}');

        self::assertSame('7.4', (new ProjectDetector())->detect($dir)->phpVersion);
    }

    public function test_broken_json_and_missing_directory_are_tool_errors(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/composer.json', '{broken');

        try {
            (new ProjectDetector())->detect($dir);
            self::fail('Expected a ToolError for broken composer.json.');
        } catch (ToolError) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(ToolError::class);
        (new ProjectDetector())->detect($dir . '/missing');
    }
}
