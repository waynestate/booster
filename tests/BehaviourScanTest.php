<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Packaging\PharFiles;
use Waynestate\Booster\Scan\BehaviourScan;
use Waynestate\Booster\ToolError;

final class BehaviourScanTest extends TestCase
{
    private function dataFile(string $name = ''): string
    {
        return __DIR__ . '/fixtures/behaviour' . ($name === '' ? '' : '/' . $name);
    }

    private function scanner(): BehaviourScan
    {
        return new BehaviourScan($this->dataFile('entries.json'));
    }

    public function test_the_php_hop_finds_the_function_call_and_the_package_below_major(): void
    {
        $app = $this->dataFile('app');
        $result = $this->scanner()->scan($app, 'php-80-to-81', BehaviourScan::composerRequire($app));

        self::assertSame(['test-function', 'test-package-below'], array_map(static fn($e): string => $e->id, $result->entries));
        self::assertSame(7, $result->filesScanned);
        self::assertSame(0, $result->filesSkipped);
        self::assertSame(['src/function.php:3'], $result->entries[0]->hits);
        self::assertSame(1, $result->entries[0]->total);
        self::assertSame('legacy_call();', $result->entries[0]->keepOld);
        self::assertSame('package', $result->entries[1]->kind);
        self::assertSame(['composer.json'], $result->entries[1]->hits);
        self::assertSame(1, $result->entries[1]->total);
    }

    public function test_the_laravel_hop_finds_every_matching_kind(): void
    {
        $app = $this->dataFile('app');
        $result = $this->scanner()->scan($app, 'laravel-10-to-11', BehaviourScan::composerRequire($app));

        self::assertSame(
            ['test-method', 'test-method-static', 'test-class', 'test-property', 'test-token', 'test-blade', 'test-config-missing', 'test-package-below', 'test-always'],
            array_map(static fn($e): string => $e->id, $result->entries),
        );
        $byId = [];
        foreach ($result->entries as $entry) {
            $byId[$entry->id] = $entry;
        }
        self::assertSame(['src/method.php:3'], $byId['test-method']->hits);
        self::assertSame(['src/static.php:3'], $byId['test-method-static']->hits);
        self::assertSame(['src/class.php:3'], $byId['test-class']->hits);
        self::assertSame(['src/property.php:5'], $byId['test-property']->hits);
        self::assertSame(['src/token.php:3'], $byId['test-token']->hits);
        self::assertSame(['resources/views/pager.blade.php:2'], $byId['test-blade']->hits);
        self::assertSame(['config/app.php'], $byId['test-config-missing']->hits);
        self::assertSame([], $byId['test-always']->hits);
        self::assertSame(1, $byId['test-always']->total);
        self::assertArrayNotHasKey('test-config-present', $byId);
        self::assertArrayNotHasKey('test-package-at', $byId);
    }

    public function test_hits_are_capped_at_five_but_the_total_keeps_counting(): void
    {
        $result = $this->scanner()->scan($this->dataFile('many'), 'php-80-to-81', null);

        self::assertCount(1, $result->entries);
        self::assertSame('test-function', $result->entries[0]->id);
        self::assertSame(['function-many.php:3', 'function-many.php:4', 'function-many.php:5', 'function-many.php:6', 'function-many.php:7'], $result->entries[0]->hits);
        self::assertSame(7, $result->entries[0]->total);
    }

    public function test_package_rows_need_the_composer_require_map(): void
    {
        $result = $this->scanner()->scan($this->dataFile('app'), 'laravel-10-to-11', null);
        $ids = array_map(static fn($e): string => $e->id, $result->entries);

        self::assertNotContains('test-package-below', $ids);
        self::assertNotContains('test-package-at', $ids);
    }

    public function test_package_rows_compare_against_the_pre_run_lock_when_given(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/composer.json', (string) json_encode(['require' => ['acme/widget' => '^1.0']]));
        // The lock on disk already carries major 2: an earlier step of the run bumped it.
        file_put_contents($dir . '/composer.lock', (string) json_encode([
            'packages' => [['name' => 'acme/widget', 'version' => '2.0.0']],
            'packages-dev' => [],
        ]));
        $data = $this->tempDir() . '/entries.json';
        file_put_contents($data, (string) json_encode([
            'schema_version' => 1,
            'changes' => [$this->entry('pkg-row', 'package:acme/widget@2', ['kind' => 'package'])],
        ]));
        $scanner = new BehaviourScan($data);
        $require = BehaviourScan::composerRequire($dir);

        // The captured pre-run lock still held major 1, so the row fires even
        // though the lock on disk is past its major.
        $withLock = $scanner->scan($dir, 'php-80-to-81', $require, ['acme/widget' => '1.9.0']);
        self::assertSame(['pkg-row'], array_map(static fn($e): string => $e->id, $withLock->entries));

        // Without the captured map the scan reads the lock on disk (major 2),
        // so the row stays quiet.
        $fromDisk = $scanner->scan($dir, 'php-80-to-81', $require);
        self::assertSame([], $fromDisk->entries);
    }

    public function test_without_packages_drops_only_the_package_entries(): void
    {
        $app = $this->dataFile('app');
        $result = $this->scanner()->scan($app, 'laravel-10-to-11', BehaviourScan::composerRequire($app));
        $stripped = $result->withoutPackages();

        self::assertSame('laravel-10-to-11', $stripped->hop);
        self::assertSame(
            ['test-method', 'test-method-static', 'test-class', 'test-property', 'test-token', 'test-blade', 'test-config-missing', 'test-always'],
            array_map(static fn($e): string => $e->id, $stripped->entries),
        );
    }

    public function test_an_unreadable_tree_finds_no_php_level_changes(): void
    {
        // Config rows fire on a missing file (the key is certainly absent) and
        // always rows apply no matter what; only the lexed kinds come up empty.
        $result = $this->scanner()->scan(sys_get_temp_dir() . '/booster-behaviour-nowhere', 'laravel-10-to-11', []);

        self::assertSame(['test-config-missing', 'test-config-present', 'test-always'], array_map(static fn($e): string => $e->id, $result->entries));
        self::assertSame(0, $result->filesScanned);
    }

    public function test_vendor_and_storage_are_never_scanned(): void
    {
        $dir = $this->tempDir();
        mkdir($dir . '/vendor/lib', 0777, true);
        mkdir($dir . '/storage/logs', 0777, true);
        mkdir($dir . '/src', 0777, true);
        file_put_contents($dir . '/vendor/lib/VendorLib.php', "<?php\n\nlegacy_call();\n");
        file_put_contents($dir . '/storage/logs/App.php', "<?php\n\nlegacy_call();\n");
        file_put_contents($dir . '/src/Good.php', "<?php\n\nlegacy_call();\n");
        $result = $this->scanner()->scan($dir, 'php-80-to-81', null);

        self::assertSame(['src/Good.php:3'], $result->entries[0]->hits);
        self::assertSame(1, $result->entries[0]->total);
        self::assertSame(1, $result->filesScanned);
    }

    public function test_composer_require_reads_require_and_require_dev(): void
    {
        self::assertSame(['php' => '^8.1', 'nesbot/carbon' => '^2.0'], BehaviourScan::composerRequire($this->dataFile('app')));
        self::assertSame([], BehaviourScan::composerRequire(sys_get_temp_dir() . '/booster-behaviour-nowhere'));
    }

    public function test_the_bundled_data_file_loads(): void
    {
        $raw = json_decode((string) file_get_contents(PharFiles::path('data/behaviour-changes.json')), true);
        self::assertGreaterThanOrEqual(50, count($raw['changes']));

        $result = (new BehaviourScan())->scan($this->dataFile('carbon'), 'laravel-10-to-11', []);

        self::assertSame(['carbon-createfromtimestamp-utc'], array_map(static fn($e): string => $e->id, $result->entries));
    }

    public function test_to_array_uses_snake_case_keys(): void
    {
        $app = $this->dataFile('app');
        $result = $this->scanner()->scan($app, 'php-80-to-81', BehaviourScan::composerRequire($app));

        self::assertSame([
            'hop' => 'php-80-to-81',
            'files_scanned' => 7,
            'files_skipped' => 0,
            'entries' => [
                [
                    'id' => 'test-function',
                    'label' => 'Legacy call removed from the standard library',
                    'keep_old' => 'legacy_call();',
                    'check' => 'Confirm nothing still calls legacy_call.',
                    'source' => 'https://www.php.net/manual/en/migration81.incompatible.php',
                    'hits' => ['src/function.php:3'],
                    'total' => 1,
                ],
                [
                    'id' => 'test-package-below',
                    'label' => 'Carbon 3 changes date parsing defaults',
                    'keep_old' => "Carbon::parse(\$value, 'Europe/Paris');",
                    'check' => 'Check every parse call for an explicit timezone.',
                    'source' => 'https://carbon.nesbot.com/docs/#api-carbon-3',
                    'hits' => ['composer.json'],
                    'total' => 1,
                ],
            ],
        ], $result->toArray());
    }

    public function test_a_missing_data_file_is_a_tool_error(): void
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('is missing');

        new BehaviourScan($this->tempDir() . '/absent.json');
    }

    /**
     * @param array<string, mixed>|string $content
     */
    private function expectLoadError(array|string $content, string $needle): void
    {
        $path = $this->tempDir() . '/behaviour-changes.json';
        file_put_contents($path, is_string($content) ? $content : (string) json_encode($content));
        $this->expectException(ToolError::class);
        $this->expectExceptionMessage($needle);

        new BehaviourScan($path);
    }

    /**
     * @param array<string, mixed> $detect
     *
     * @return array<string, mixed>
     */
    private function entry(string $id, string $hop, array $detect, ?string $keepOld = null): array
    {
        return [
            'id' => $id,
            'hop' => $hop,
            'detect' => $detect,
            'label' => 'Label for ' . $id,
            'keep_old' => $keepOld,
            'check' => 'Check it.',
            'source' => 'https://example.com/',
        ];
    }

    public function test_a_wrong_schema_version_is_rejected(): void
    {
        $this->expectLoadError(['schema_version' => 2, 'changes' => []], 'schema_version 1');
    }

    public function test_non_json_is_rejected(): void
    {
        $this->expectLoadError('not json at all', 'schema_version 1');
    }

    public function test_a_duplicate_id_is_rejected(): void
    {
        $this->expectLoadError([
            'schema_version' => 1,
            'changes' => [
                $this->entry('dup', 'php-80-to-81', ['kind' => 'function', 'match' => 'a']),
                $this->entry('dup', 'php-80-to-81', ['kind' => 'function', 'match' => 'b']),
            ],
        ], 'repeats id "dup"');
    }

    public function test_more_than_three_always_rows_are_rejected(): void
    {
        $this->expectLoadError([
            'schema_version' => 1,
            'changes' => [
                $this->entry('always-1', 'laravel-10-to-11', ['kind' => 'always']),
                $this->entry('always-2', 'laravel-10-to-11', ['kind' => 'always']),
                $this->entry('always-3', 'laravel-10-to-11', ['kind' => 'always']),
                $this->entry('always-4', 'laravel-10-to-11', ['kind' => 'always']),
            ],
        ], 'more than 3 always rows');
    }

    public function test_a_malformed_hop_is_rejected(): void
    {
        $this->expectLoadError([
            'schema_version' => 1,
            'changes' => [$this->entry('bad-hop', 'laravel-10', ['kind' => 'function', 'match' => 'a'])],
        ], 'expected php-74-to-80');
    }

    public function test_a_non_https_source_is_rejected(): void
    {
        $entry = $this->entry('http-source', 'php-80-to-81', ['kind' => 'function', 'match' => 'a']);
        $entry['source'] = 'http://example.com/';
        $this->expectLoadError(['schema_version' => 1, 'changes' => [$entry]], 'expected an https URL');
    }

    public function test_an_unknown_detect_kind_is_rejected(): void
    {
        $this->expectLoadError([
            'schema_version' => 1,
            'changes' => [$this->entry('widget', 'php-80-to-81', ['kind' => 'widget', 'match' => 'a'])],
        ], 'unknown or missing detect kind');
    }

    public function test_a_config_row_without_key_is_rejected(): void
    {
        $this->expectLoadError([
            'schema_version' => 1,
            'changes' => [$this->entry('no-key', 'laravel-10-to-11', ['kind' => 'config', 'file' => 'config/app.php'])],
        ], 'needs detect.file and detect.key');
    }

    public function test_a_numeric_keep_old_is_rejected(): void
    {
        $entry = $this->entry('numeric-keep', 'php-80-to-81', ['kind' => 'function', 'match' => 'a']);
        $entry['keep_old'] = 5;
        $this->expectLoadError(['schema_version' => 1, 'changes' => [$entry]], 'needs a keep_old string or null');
    }

    public function test_an_empty_match_is_rejected(): void
    {
        $this->expectLoadError([
            'schema_version' => 1,
            'changes' => [$this->entry('empty-match', 'php-80-to-81', ['kind' => 'function', 'match' => ''])],
        ], 'needs detect.match');
    }
}
