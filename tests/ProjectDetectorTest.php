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

    public function test_the_node_floor_comes_from_engines_node(): void
    {
        $spa = (new ProjectDetector())->detect($this->example('vue-spa'));
        self::assertTrue($spa->hasPackageJson);
        self::assertNull($spa->nodeMajor);

        $dir = $this->tempDir();
        file_put_contents($dir . '/package.json', '{"engines":{"node":"^20"}}');
        self::assertSame('20', (new ProjectDetector())->detect($dir)->nodeMajor);

        $plain = (new ProjectDetector())->detect($this->example('raw-php'));
        self::assertFalse($plain->hasPackageJson);
        self::assertNull($plain->nodeMajor);
    }

    public function test_wayne_cas_is_flagged(): void
    {
        $project = (new ProjectDetector())->detect($this->example('laravel-wayne-cas'));

        self::assertTrue($project->usesWayneCas);
        self::assertFalse((new ProjectDetector())->detect($this->example('raw-php'))->usesWayneCas);
    }

    public function test_boost_is_flagged_from_the_manifest_or_the_lock(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/composer.json', '{"require":{"laravel/framework":"^11.0","laravel/boost":"^1.0"}}');
        self::assertTrue((new ProjectDetector())->detect($dir)->hasBoost);

        $dir = $this->tempDir();
        file_put_contents($dir . '/composer.json', '{"require-dev":{"laravel/boost":"^1.0"}}');
        self::assertTrue((new ProjectDetector())->detect($dir)->hasBoost);

        $dir = $this->tempDir();
        file_put_contents($dir . '/composer.json', '{"require":{}}');
        file_put_contents($dir . '/composer.lock', json_encode(['packages' => [['name' => 'laravel/boost', 'version' => '1.0.0']]]));
        self::assertTrue((new ProjectDetector())->detect($dir)->hasBoost);

        $dir = $this->tempDir();
        file_put_contents($dir . '/composer.json', '{"require":{}}');
        file_put_contents($dir . '/composer.lock', json_encode(['packages-dev' => [['name' => 'laravel/boost', 'version' => '1.0.0']]]));
        self::assertTrue((new ProjectDetector())->detect($dir)->hasBoost);
    }

    public function test_boost_is_absent_without_a_manifest_entry_or_lock_hit(): void
    {
        self::assertFalse((new ProjectDetector())->detect($this->example('raw-php'))->hasBoost);
        self::assertFalse((new ProjectDetector())->detect($this->example('laravel-blade'))->hasBoost);

        $dir = $this->tempDir();
        file_put_contents($dir . '/composer.json', '{"require":{"laravel/framework":"^11.0"}}');
        file_put_contents($dir . '/composer.lock', json_encode(['packages' => [['name' => 'laravel/framework', 'version' => '11.0.0']]]));
        self::assertFalse((new ProjectDetector())->detect($dir)->hasBoost);

        // A broken lock cannot hide or invent Boost: the manifest still decides.
        $dir = $this->tempDir();
        file_put_contents($dir . '/composer.json', '{"require":{}}');
        file_put_contents($dir . '/composer.lock', '{broken');
        self::assertFalse((new ProjectDetector())->detect($dir)->hasBoost);

        $dir = $this->tempDir();
        file_put_contents($dir . '/composer.json', '{"require":{"laravel/boost":"^1.0"}}');
        file_put_contents($dir . '/composer.lock', '{broken');
        self::assertTrue((new ProjectDetector())->detect($dir)->hasBoost);
    }

    public function test_boost_survives_to_array(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/composer.json', '{"require":{"laravel/boost":"^1.0"}}');

        self::assertTrue((new ProjectDetector())->detect($dir)->toArray()['boost']);
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

    #[\PHPUnit\Framework\Attributes\DataProvider('laravelConstraints')]
    public function test_a_laravel_constraint_reads_as_the_major_it_floors_at(string $constraint, ?string $expected): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/composer.json', '{"require":{"laravel/framework":' . json_encode($constraint) . '}}');

        self::assertSame($expected, (new ProjectDetector())->detect($dir)->laravelMajor);
    }

    /** @return list<array{string, ?string}> */
    public static function laravelConstraints(): array
    {
        return [
            // 5.x keeps its minor: BOOSTER plans the 5.8 to 6 hop.
            ['^5.8', '5.8'],
            ['~5.8', '5.8'],
            ['^6.0', '6'],
            ['^10.0', '10'],
            ['*', null],
        ];
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
