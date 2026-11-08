<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Rector\Config\RectorConfig;
use Rector\Configuration\Option;
use Rector\Configuration\Parameter\SimpleParameterProvider;
use Rector\Php80\Rector\Switch_\ChangeSwitchToMatchRector;
use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Packaging\PharFiles;
use Waynestate\Booster\Packaging\RunScratch;
use Waynestate\Booster\Run\ProcessResult;
use Waynestate\Booster\Run\RectorResult;
use Waynestate\Booster\Run\RectorRunner;
use Waynestate\Booster\ToolError;

final class RectorRunnerTest extends TestCase
{
    public function test_the_default_memory_limit_is_two_gigabytes(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(2, '{"file_diffs": [], "errors": []}', ''));
        (new RectorRunner($fake, '/fake/rector'))->run((new HopRegistry())->get('php-74-to-80'), '/srv/acme-app', true, '8.0');

        self::assertSame('2G', RectorRunner::DEFAULT_MEMORY_LIMIT);
        self::assertCount(1, $fake->commandsContaining('--memory-limit=2G'));
    }

    public function test_run_names_the_vendored_directories_at_any_depth_for_the_config(): void
    {
        $app = $this->tempDir();
        mkdir($app . '/src/vendor/x', 0775, true);
        file_put_contents($app . '/src/vendor/x/y.php', "<?php\n");
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(2, '{"file_diffs": [], "errors": []}', ''));
        $result = (new RectorRunner($fake, '/fake/rector'))->run((new HopRegistry())->get('php-74-to-80'), $app, true, '8.0');

        $process = $fake->callsWith('process');
        self::assertCount(1, $process);
        $skipDirs = json_decode($process[0]['env']['BOOSTER_RECTOR_SKIP_DIRS'], true);
        self::assertIsArray($skipDirs);
        self::assertContains($app . '/src/vendor', $skipDirs);
        self::assertSame(['src/vendor'], $result->skippedDirs);
    }

    public function test_run_excludes_php_files_that_are_not_valid_utf8_and_names_them(): void
    {
        $app = $this->tempDir();
        file_put_contents($app . '/a.php', "<?php // cafe\n");
        file_put_contents($app . '/b.php', "<?php // caf\xE9\n");
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(2, '{"file_diffs": [], "errors": []}', ''));
        $result = (new RectorRunner($fake, '/fake/rector'))->run((new HopRegistry())->get('php-74-to-80'), $app, true, '8.0');

        $process = $fake->callsWith('process');
        self::assertCount(1, $process);
        $skipFiles = json_decode($process[0]['env']['BOOSTER_RECTOR_SKIP_FILES'], true);
        self::assertIsArray($skipFiles);
        self::assertContains($app . '/b.php', $skipFiles);
        self::assertSame([['file' => 'b.php', 'reason' => 'not valid UTF-8']], $result->excluded);
    }

    public function test_an_unknown_class_error_is_retried_once_without_the_file(): void
    {
        $app = $this->tempDir();
        $first = (string) json_encode([
            'file_diffs' => [['file' => $app . '/src/B.php', 'diff' => '+b']],
            'errors' => [['file' => 'legacy/Excel.php', 'message' => 'System error: Class "PHPExcel" should be known at this point']],
        ]);
        $second = (string) json_encode([
            'file_diffs' => [['file' => $app . '/src/A.php', 'diff' => '+a']],
            'errors' => [],
        ]);
        $fake = new FakeProcessRunner(static function (array $command, array $env) use ($app, $first, $second): ProcessResult {
            $skip = json_decode($env['BOOSTER_RECTOR_SKIP_FILES'] ?? '[]', true);
            $skipped = is_array($skip) && in_array($app . '/legacy/Excel.php', $skip, true);

            return new ProcessResult(2, $skipped ? $second : $first, '');
        });
        $result = (new RectorRunner($fake, '/fake/rector'))->run((new HopRegistry())->get('php-74-to-80'), $app, true, '8.0');

        $process = $fake->callsWith('process');
        self::assertCount(2, $process);
        $skip = json_decode($process[1]['env']['BOOSTER_RECTOR_SKIP_FILES'], true);
        self::assertIsArray($skip);
        self::assertContains($app . '/legacy/Excel.php', $skip);
        self::assertSame([], $result->errors);
        self::assertSame([
            ['file' => 'legacy/Excel.php', 'reason' => 'Rector could not resolve a class it uses (Class "PHPExcel" should be known at this point)'],
        ], $result->excluded);
        self::assertSame(['src/B.php', 'src/A.php'], array_column($result->files, 'file'));
    }

    public function test_a_non_unknown_class_error_stops_the_retry(): void
    {
        $app = $this->tempDir();
        $json = (string) json_encode([
            'file_diffs' => [],
            'errors' => [
                ['file' => 'legacy/Excel.php', 'message' => 'System error: Class "PHPExcel" should be known at this point'],
                ['file' => 'src/Bad.php', 'message' => 'Syntax error, unexpected token "endif"'],
            ],
        ]);
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(2, $json, ''));
        $result = (new RectorRunner($fake, '/fake/rector'))->run((new HopRegistry())->get('php-74-to-80'), $app, true, '8.0');

        self::assertCount(1, $fake->callsWith('process'));
        self::assertSame([
            'legacy/Excel.php: System error: Class "PHPExcel" should be known at this point',
            'src/Bad.php: Syntax error, unexpected token "endif"',
        ], $result->errors);
        self::assertSame([], $result->excluded);
    }

    public function test_a_failed_retry_keeps_the_second_errors(): void
    {
        $app = $this->tempDir();
        $first = (string) json_encode([
            'file_diffs' => [],
            'errors' => [['file' => 'legacy/Excel.php', 'message' => 'System error: Class "PHPExcel" should be known at this point']],
        ]);
        $second = (string) json_encode([
            'file_diffs' => [],
            'errors' => [['file' => 'src/C.php', 'message' => 'Something else broke']],
        ]);
        $fake = new FakeProcessRunner(static function (array $command, array $env) use ($app, $first, $second): ProcessResult {
            $skip = json_decode($env['BOOSTER_RECTOR_SKIP_FILES'] ?? '[]', true);
            $skipped = is_array($skip) && in_array($app . '/legacy/Excel.php', $skip, true);

            return new ProcessResult(2, $skipped ? $second : $first, '');
        });
        $result = (new RectorRunner($fake, '/fake/rector'))->run((new HopRegistry())->get('php-74-to-80'), $app, true, '8.0');

        self::assertCount(2, $fake->callsWith('process'));
        self::assertSame(['src/C.php: Something else broke'], $result->errors);
        self::assertSame([
            ['file' => 'legacy/Excel.php', 'reason' => 'Rector could not resolve a class it uses (Class "PHPExcel" should be known at this point)'],
        ], $result->excluded);
    }

    public function test_an_unknown_class_error_without_a_file_is_kept_as_an_error(): void
    {
        $app = $this->tempDir();
        $json = (string) json_encode([
            'file_diffs' => [],
            'errors' => [['message' => 'System error: Class "Mystery" should be known at this point']],
        ]);
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(2, $json, ''));
        $result = (new RectorRunner($fake, '/fake/rector'))->run((new HopRegistry())->get('php-74-to-80'), $app, true, '8.0');

        self::assertCount(1, $fake->callsWith('process'));
        self::assertSame(['System error: Class "Mystery" should be known at this point'], $result->errors);
    }

    public function test_empty_stdout_on_malformed_utf8_says_utf8_not_memory(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(5, '', 'Json.php line 38: Malformed UTF-8 characters'));
        try {
            (new RectorRunner($fake, '/fake/rector'))->run((new HopRegistry())->get('php-74-to-80'), '/srv/acme-app', true, '8.0');
            self::fail('expected a ToolError');
        } catch (ToolError $e) {
            self::assertStringContainsString('Rector produced no JSON report (exit 5)', $e->getMessage());
            self::assertStringContainsString('malformed UTF-8 in a file it could not encode into its report, not because of memory', $e->getMessage());
            self::assertStringNotContainsString('--memory-limit can raise the limit', $e->getMessage());
            self::assertStringContainsString('Json.php line 38: Malformed UTF-8 characters', $e->getMessage());
        }
    }

    public function test_a_custom_memory_limit_is_passed_to_rector(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(2, '{"file_diffs": [], "errors": []}', ''));
        (new RectorRunner($fake, '/fake/rector'))->run((new HopRegistry())->get('php-74-to-80'), '/srv/acme-app', true, '8.0', null, null, '4G');

        self::assertCount(1, $fake->commandsContaining('--memory-limit=4G'));
    }

    public function test_unlimited_memory_is_allowed(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(2, '{"file_diffs": [], "errors": []}', ''));
        (new RectorRunner($fake, '/fake/rector'))->run((new HopRegistry())->get('php-74-to-80'), '/srv/acme-app', true, '8.0', null, null, '-1');

        self::assertCount(1, $fake->commandsContaining('--memory-limit=-1'));
    }

    public function test_an_invalid_memory_limit_is_refused_naming_the_option(): void
    {
        foreach (['banana', '1.5G', '2T', '2 g', '1G1'] as $bad) {
            try {
                RectorRunner::assertMemoryLimit($bad);
                self::fail($bad . ' should have been refused');
            } catch (ToolError $e) {
                self::assertStringContainsString('--memory-limit', $e->getMessage());
                self::assertStringContainsString($bad, $e->getMessage());
            }
        }
    }

    public function test_run_refuses_an_invalid_memory_limit_before_running_anything(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => self::fail('Rector should not run'));
        $this->expectException(ToolError::class);
        $this->expectExceptionMessageMatches('/--memory-limit/');
        (new RectorRunner($fake, '/fake/rector'))->run((new HopRegistry())->get('php-74-to-80'), '/srv/acme-app', true, '8.0', null, null, 'lots');
    }

    public function test_empty_stdout_points_at_the_memory_limit(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(255, '', 'Fatal error: Allowed memory size of 2147483648 bytes exhausted'));
        try {
            (new RectorRunner($fake, '/fake/rector'))->run((new HopRegistry())->get('php-74-to-80'), '/srv/acme-app', true, '8.0');
            self::fail('expected a ToolError');
        } catch (ToolError $e) {
            self::assertStringContainsString('Rector produced no JSON report (exit 255)', $e->getMessage());
            self::assertStringContainsString('--memory-limit=2G', $e->getMessage());
            self::assertStringContainsString('--memory-limit can raise the limit', $e->getMessage());
            self::assertStringContainsString('Allowed memory size', $e->getMessage());
        }
    }

    public function test_empty_stdout_names_a_custom_memory_limit(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(255, '', ''));
        try {
            (new RectorRunner($fake, '/fake/rector'))->run((new HopRegistry())->get('php-74-to-80'), '/srv/acme-app', true, '8.0', null, null, '4G');
            self::fail('expected a ToolError');
        } catch (ToolError $e) {
            self::assertStringContainsString('--memory-limit=4G', $e->getMessage());
        }
    }

    public function test_hopeless_stdout_throws_a_clear_tool_error(): void
    {
        $stderr = "first-line-of-stderr\n" . str_repeat('padding line\n', 100) . 'last-line-of-stderr';
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(255, 'this is not json at all', $stderr));
        try {
            (new RectorRunner($fake, '/fake/rector'))->run((new HopRegistry())->get('php-74-to-80'), '/srv/acme-app', true, '8.0');
            self::fail('expected a ToolError');
        } catch (ToolError $e) {
            $message = $e->getMessage();
            self::assertStringContainsString('Rector output was not valid JSON', $message);
            self::assertStringContainsString('Syntax error', $message);
            self::assertStringContainsString('exit 255', $message);
            self::assertStringContainsString('first-line-of-stderr', $message);
            // The stderr excerpt is capped at 500 characters.
            self::assertStringNotContainsString('last-line-of-stderr', $message);
        }
    }

    public function test_invalid_utf8_in_the_report_is_repaired_and_named(): void
    {
        // Lone 0xFF and 0xFE bytes are not valid UTF-8, so strict json_decode fails
        // with JSON_ERROR_UTF8. chr() keeps the raw bytes out of this source file.
        $stdout = '{"file_diffs": [{"file": "/srv/acme-app/src/Bad.php", "diff": "+@@@"}], "errors": []}';
        $stdout = str_replace('@@@', chr(255) . chr(254), $stdout);
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(2, $stdout, ''));
        $result = (new RectorRunner($fake, '/fake/rector'))->run((new HopRegistry())->get('php-74-to-80'), '/srv/acme-app', true, '8.0');

        self::assertCount(1, $result->files);
        self::assertSame('src/Bad.php', $result->files[0]['file']);
        self::assertStringContainsString("\u{FFFD}", $result->files[0]['diff']);
        self::assertSame([RectorResult::REPAIRED_UTF8_NOTE . ' (src/Bad.php)'], $result->errors);
    }

    public function test_repaired_utf8_outside_the_diffs_gets_a_bare_note(): void
    {
        $stdout = '{"file_diffs": [], "errors": ["bad @@@ byte"]}';
        $stdout = str_replace('@@@', chr(255), $stdout);
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(2, $stdout, ''));
        $result = (new RectorRunner($fake, '/fake/rector'))->run((new HopRegistry())->get('php-74-to-80'), '/srv/acme-app', true, '8.0');

        self::assertSame([], $result->files);
        self::assertSame(RectorResult::REPAIRED_UTF8_NOTE, $result->errors[0]);
        self::assertCount(2, $result->errors);
        self::assertStringContainsString("\u{FFFD}", $result->errors[1]);
    }

    public function test_parse_keeps_line_number(): void
    {
        $json = '{"file_diffs": [], "errors": [{"file":"src/A.php","message":"Syntax error, unexpected token","line":12},{"file":"src/B.php","message":"Something went wrong on line 5","line":9}]}';
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(2, $json, ''));
        $result = (new RectorRunner($fake, '/fake/rector'))->run((new HopRegistry())->get('php-74-to-80'), '/srv/acme-app', true, '8.0');
        self::assertSame([
            'src/A.php: Syntax error, unexpected token (line 12)',
            'src/B.php: Something went wrong on line 5',
        ], $result->errors);
    }

    public function test_parse_counts_files_per_rule(): void
    {
        // Two files touched by three rules in total; one rule touches both files.
        $json = json_encode([
            'file_diffs' => [
                [
                    'file' => '/srv/acme-app/src/A.php',
                    'diff' => '+a',
                    'applied_rectors' => [
                        'Rector\Php\CodeQuality\Rector\Assign\NoTrailingCommaInSinglelineListRector',
                        'Rector\Php\TypeDeclaration\Rector\FunctionLike\AddReturnTypeDeclarationRector',
                    ],
                ],
                [
                    'file' => '/srv/acme-app/src/B.php',
                    'diff' => '+b',
                    'applied_rectors' => [
                        'Rector\Php\TypeDeclaration\Rector\FunctionLike\AddReturnTypeDeclarationRector',
                        'Rector\Renaming\Rector\Name\RenameClassRector',
                    ],
                ],
            ],
            'errors' => [],
        ]);
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(2, $json, ''));
        $result = (new RectorRunner($fake, '/fake/rector'))->run((new HopRegistry())->get('php-74-to-80'), '/srv/acme-app', true, '8.0');

        self::assertSame(2, $result->changedCount());
        self::assertSame([
            'NoTrailingCommaInSinglelineListRector' => 1,
            'AddReturnTypeDeclarationRector' => 2,
            'RenameClassRector' => 1,
        ], $result->rules());
    }

    public function test_parse_without_applied_rectors_records_no_rules(): void
    {
        $json = '{"file_diffs": [{"file": "/srv/acme-app/src/A.php", "diff": "+a"}], "errors": []}';
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(2, $json, ''));
        $result = (new RectorRunner($fake, '/fake/rector'))->run((new HopRegistry())->get('php-74-to-80'), '/srv/acme-app', true, '8.0');

        self::assertSame(1, $result->changedCount());
        self::assertSame([], $result->rules());
        self::assertSame([], $result->errors);
    }

    public function test_the_shared_config_skips_ide_helper_files(): void
    {
        // The real Rector binary is not available in the test suite, so load the
        // config Booster ships and read back the skip list it registers.
        $saved = [];
        foreach (['BOOSTER_APP_PATH', 'BOOSTER_RECTOR_SETS', 'BOOSTER_CLASS_RENAMES', 'BOOSTER_PHP_TARGET', 'BOOSTER_BOOTSTRAP'] as $name) {
            $saved[$name] = getenv($name);
        }
        putenv('BOOSTER_APP_PATH=' . $this->tempDir());
        putenv('BOOSTER_RECTOR_SETS=');
        putenv('BOOSTER_CLASS_RENAMES=');
        putenv('BOOSTER_PHP_TARGET=');
        putenv('BOOSTER_BOOTSTRAP=');

        try {
            $configure = require PharFiles::path('config/rector.php');
            $configure(new RectorConfig());

            $skip = SimpleParameterProvider::provideArrayParameter(Option::SKIP);
            self::assertContains('*_ide_helper*.php', $skip);
            self::assertContains('*.phpstorm.meta.php', $skip);
        } finally {
            foreach ($saved as $name => $value) {
                $value === false ? putenv($name) : putenv("{$name}={$value}");
            }
        }
    }

    public function test_the_shared_config_skips_only_the_vendored_directories_booster_names(): void
    {
        // The real Rector binary is not available in the test suite, so load the
        // config Booster ships and read back the skip list it registers.
        $saved = [];
        foreach (['BOOSTER_APP_PATH', 'BOOSTER_RECTOR_SETS', 'BOOSTER_CLASS_RENAMES', 'BOOSTER_PHP_TARGET', 'BOOSTER_BOOTSTRAP', 'BOOSTER_RECTOR_SKIP_DIRS'] as $name) {
            $saved[$name] = getenv($name);
        }
        $app = $this->tempDir();
        putenv('BOOSTER_APP_PATH=' . $app);
        putenv('BOOSTER_RECTOR_SETS=');
        putenv('BOOSTER_CLASS_RENAMES=');
        putenv('BOOSTER_PHP_TARGET=');
        putenv('BOOSTER_BOOTSTRAP=');
        putenv('BOOSTER_RECTOR_SKIP_DIRS=' . json_encode([$app . '/src/vendor']));

        try {
            $configure = require PharFiles::path('config/rector.php');
            $configure(new RectorConfig());

            $skip = SimpleParameterProvider::provideArrayParameter(Option::SKIP);
            self::assertContains($app . '/src/vendor', $skip);
            // The app-root vendor/ is no longer hard-coded; it is skipped only
            // when Booster names it.
            self::assertNotContains($app . '/vendor', $skip);
            self::assertNotContains($app . '/node_modules', $skip);
        } finally {
            foreach ($saved as $name => $value) {
                $value === false ? putenv($name) : putenv("{$name}={$value}");
            }
        }
    }

    public function test_every_rector_cache_and_temp_dir_is_inside_this_runs_scratch_directory(): void
    {
        // 1.8.2: Rector's PHPStan cached its container in sys_get_temp_dir(), with
        // absolute paths into the phar copy that was deleted at exit, so the second
        // run on any machine died on hop 1. Nothing may be shared between runs.
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(2, '{"file_diffs": [], "errors": []}', ''));
        (new RectorRunner($fake, '/fake/rector'))->run((new HopRegistry())->get('php-74-to-80'), $this->tempDir(), true, '8.0');

        $env = $fake->callsWith('process')[0]['env'];
        $root = RunScratch::root();
        self::assertNotSame(rtrim(sys_get_temp_dir(), '/'), $root);
        self::assertStringStartsWith(sys_get_temp_dir() . '/booster-run-', $root);
        foreach (['BOOSTER_RECTOR_CACHE', 'BOOSTER_RECTOR_CONTAINER_CACHE', 'TMPDIR', 'TMP', 'TEMP'] as $key) {
            self::assertArrayHasKey($key, $env);
            self::assertStringStartsWith($root . '/', $env[$key], $key . ' must live in the run scratch directory');
            self::assertDirectoryExists($env[$key]);
        }
    }

    public function test_the_shared_config_points_phpstans_container_cache_at_the_directory_booster_names(): void
    {
        $saved = [];
        foreach (['BOOSTER_APP_PATH', 'BOOSTER_RECTOR_SETS', 'BOOSTER_CLASS_RENAMES', 'BOOSTER_PHP_TARGET', 'BOOSTER_BOOTSTRAP', 'BOOSTER_RECTOR_CONTAINER_CACHE'] as $name) {
            $saved[$name] = getenv($name);
        }
        $container = $this->tempDir();
        putenv('BOOSTER_APP_PATH=' . $this->tempDir());
        putenv('BOOSTER_RECTOR_SETS=');
        putenv('BOOSTER_CLASS_RENAMES=');
        putenv('BOOSTER_PHP_TARGET=');
        putenv('BOOSTER_BOOTSTRAP=');
        putenv('BOOSTER_RECTOR_CONTAINER_CACHE=' . $container);

        try {
            $configure = require PharFiles::path('config/rector.php');
            $configure(new RectorConfig());

            self::assertSame($container, SimpleParameterProvider::provideStringParameter(Option::CONTAINER_CACHE_DIRECTORY));
        } finally {
            foreach ($saved as $name => $value) {
                $value === false ? putenv($name) : putenv("{$name}={$value}");
            }
        }
    }

    public function test_the_shared_config_never_turns_a_loose_switch_into_a_strict_match(): void
    {
        // switch ($_GET['column']) { case 1: } matches the string "1"; the match
        // Rector would write does not, so every request falls to default.
        $saved = [];
        foreach (['BOOSTER_APP_PATH', 'BOOSTER_RECTOR_SETS', 'BOOSTER_CLASS_RENAMES', 'BOOSTER_PHP_TARGET', 'BOOSTER_BOOTSTRAP'] as $name) {
            $saved[$name] = getenv($name);
        }
        putenv('BOOSTER_APP_PATH=' . $this->tempDir());
        // Load the PHP 8.0 set: ChangeSwitchToMatchRector is registered only by it,
        // and the skip under test travels with that set.
        putenv('BOOSTER_RECTOR_SETS=' . json_encode(['Rector\Set\ValueObject\SetList::PHP_80']));
        putenv('BOOSTER_CLASS_RENAMES=');
        putenv('BOOSTER_PHP_TARGET=');
        putenv('BOOSTER_BOOTSTRAP=');

        try {
            $configure = require PharFiles::path('config/rector.php');
            $configure(new RectorConfig());

            self::assertContains(ChangeSwitchToMatchRector::class, SimpleParameterProvider::provideArrayParameter(Option::SKIP));
        } finally {
            foreach ($saved as $name => $value) {
                $value === false ? putenv($name) : putenv("{$name}={$value}");
            }
        }
    }
}
