<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Hop\EngineId;
use Waynestate\Booster\Hop\Hop;
use Waynestate\Booster\Hop\HopKind;
use Waynestate\Booster\Run\Cake\CakeUpgradeMigration;
use Waynestate\Booster\Run\CakeUpgradeRunner;
use Waynestate\Booster\Run\ComposerEditor;
use Waynestate\Booster\Run\ProcessResult;

/**
 * The CakeUpgrade engine on small CakePHP apps in temp folders. A fake
 * process runner stands in for the tool's container: it reads the copy the
 * engine mounted (`-v <copy>:/app`) and edits it the way a rule set would,
 * so the copy, the diffs, the copy-back and the refusals are all real.
 */
final class CakeUpgradeMigrationTest extends TestCase
{
    private const APP_CONTROLLER = <<<'PHP'
        <?php
        declare(strict_types=1);

        namespace App\Controller;

        use Cake\Controller\Controller;
        use Cake\I18n\FrozenTime;

        class AppController extends Controller
        {
            public function initialize(): void
            {
                parent::initialize();
                $this->loadComponent('Flash');
            }

            public function now(): string
            {
                return (string) FrozenTime::now();
            }
        }

        PHP;

    private const E_ALL_HINT = 'MANUAL: run the tests with E_ALL on CakePHP 4.6 and fix every deprecation before the 5 step.';

    /** @var list<string> the copies the fake container was handed */
    private array $mounts = [];

    private static function hop46(): Hop
    {
        return new Hop(
            'cakephp-4-to-4.6',
            HopKind::Framework,
            '4',
            '4.6',
            'CakePHP 4 to 4.6',
            rectorSets: ['cakephp41', 'cakephp42', 'cakephp43', 'cakephp44', 'cakephp45', 'migrations45'],
            composerSet: ['cakephp/cakephp' => '^4.6'],
            engine: EngineId::CakeUpgrade,
        );
    }

    private static function hop5(): Hop
    {
        return new Hop(
            'cakephp-4.6-to-5',
            HopKind::Framework,
            '4.6',
            '5',
            'CakePHP 4.6 to 5',
            rectorSets: ['cakephp50', 'cakephp51', 'cakephp52', 'cakephp53', 'cakephp54', 'chronos3', 'migrations50'],
            composerSet: ['cakephp/cakephp' => '^5.4'],
            composerSetIfPresent: ['cakephp/migrations' => '^4.0', 'cakephp/debug_kit' => '^5.2'],
            engine: EngineId::CakeUpgrade,
        );
    }

    /**
     * A CakePHP 4 app with src/, tests/ and config/ (no plugins/). A null
     * value leaves that file out; other entries add or replace files.
     *
     * @param array<string, string|null> $files
     */
    private function cakeApp(array $files = []): string
    {
        $dir = $this->tempDir();
        $defaults = [
            'composer.json' => json_encode([
                'name' => 'acme/app',
                'require' => ['php' => '>=8.1', 'cakephp/cakephp' => '^4.5', 'cakephp/migrations' => '^3.2'],
                'require-dev' => ['cakephp/debug_kit' => '^4.5', 'phpunit/phpunit' => '^9.5'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            'src/Controller/AppController.php' => self::APP_CONTROLLER,
            'tests/TestCase/ApplicationTest.php' => "<?php\nnamespace App\\Test\\TestCase;\n\nclass ApplicationTest {}\n",
            'config/app.php' => "<?php\nreturn [];\n",
            'vendor/cakephp/cakephp/VERSION.txt' => "4.5.0\n",
            'vendor/autoload.php' => "<?php\n// the installed app's autoloader\n",
            'tmp/cache/models/x' => 'cached',
        ];
        foreach (array_replace($defaults, $files) as $file => $contents) {
            if ($contents !== null) {
                self::put($dir . '/' . $file, $contents);
            }
        }

        return $dir;
    }

    private static function put(string $file, string $contents): void
    {
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        file_put_contents($file, $contents);
    }

    /**
     * The fake container: exit 0 for every docker call; a rector call hands
     * the mounted copy, the set and the folder to $onRector, a file_rename
     * call the copy and its target to $onRename. Either may return a
     * ProcessResult to answer with instead.
     */
    private function docker(?callable $onRector = null, ?callable $onRename = null): FakeProcessRunner
    {
        return new FakeProcessRunner(function (array $command) use ($onRector, $onRename): ProcessResult {
            $flag = array_search('-v', $command, true);
            if ($flag !== false) {
                $copy = substr((string) $command[$flag + 1], 0, -strlen(':/app'));
                $this->mounts[] = $copy;
                $last = (string) end($command);
                if (in_array('rector', $command, true) && $onRector !== null) {
                    $set = (string) $command[(int) array_search('--rules', $command, true) + 1];

                    return $onRector($copy, $set, substr($last, strlen('/app/'))) ?? new ProcessResult(0, '', '');
                }
                if (in_array('file_rename', $command, true) && $onRename !== null) {
                    return $onRename($copy, (string) $command[count($command) - 2]) ?? new ProcessResult(0, '', '');
                }
            }

            return new ProcessResult(0, '', '');
        });
    }

    private function engine(FakeProcessRunner $fake): CakeUpgradeMigration
    {
        return new CakeUpgradeMigration(new CakeUpgradeRunner($fake), new ComposerEditor());
    }

    /**
     * @return list<array{0: string, 1: string}> [set, folder] per rector call, in order
     */
    private static function rectorCalls(FakeProcessRunner $fake): array
    {
        $calls = [];
        foreach ($fake->commandsContaining('rector') as $command) {
            $calls[] = [(string) $command[(int) array_search('--rules', $command, true) + 1], substr((string) end($command), strlen('/app/'))];
        }

        return $calls;
    }

    /**
     * @return array<string, string> every file under $dir, relative => contents
     */
    private static function snapshot(string $dir, string $prefix = ''): array
    {
        $files = [];
        foreach (scandir($dir . ($prefix === '' ? '' : '/' . $prefix)) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $relative = $prefix === '' ? $entry : $prefix . '/' . $entry;
            if (is_dir($dir . '/' . $relative)) {
                $files += self::snapshot($dir, $relative);
            } else {
                $files[$relative] = (string) file_get_contents($dir . '/' . $relative);
            }
        }

        return $files;
    }

    /**
     * @param list<string> $lines
     */
    private static function lineStartingWith(array $lines, string $start): ?string
    {
        foreach ($lines as $line) {
            if (str_starts_with($line, $start)) {
                return $line;
            }
        }

        return null;
    }

    public function test_missing_composer_json_is_refused(): void
    {
        $app = $this->cakeApp(['composer.json' => null]);
        $fake = $this->docker();

        $result = $this->engine($fake)->migrate($app, false, '8.3', self::hop46());

        self::assertTrue($result->refused());
        self::assertStringContainsString('composer.json', implode("\n", $result->refusals));
        self::assertSame([], $fake->calls, 'a refusal runs nothing');
        self::assertSame([], $result->changes);
    }

    public function test_app_without_cakephp_is_refused(): void
    {
        $app = $this->cakeApp(['composer.json' => json_encode(['require' => ['slim/slim' => '^3.12']]) . "\n"]);
        $before = self::snapshot($app);
        $fake = $this->docker();

        $result = $this->engine($fake)->migrate($app, false, '8.3', self::hop5());

        self::assertTrue($result->refused());
        self::assertSame(['Neither composer.lock nor composer.json requires cakephp/cakephp, so this is not a CakePHP app.'], $result->refusals);
        self::assertSame([], $fake->calls);
        self::assertSame($before, self::snapshot($app));
    }

    public function test_hop_without_rule_sets_is_refused(): void
    {
        $app = $this->cakeApp();
        $fake = $this->docker();
        $hop = new Hop('cakephp-empty', HopKind::Framework, '4', '4.6', 'No sets', composerSet: ['cakephp/cakephp' => '^4.6'], engine: EngineId::CakeUpgrade);

        $result = $this->engine($fake)->migrate($app, false, '8.3', $hop);
        $none = $this->engine($fake)->migrate($app, false, '8.3', null);

        self::assertSame(['The cakephp-empty hop names no cakephp/upgrade rule sets, so there is nothing to run.'], $result->refusals);
        self::assertTrue($none->refused());
        self::assertSame([], $fake->calls);
        self::assertStringContainsString('"cakephp/cakephp": "^4.5"', (string) file_get_contents($app . '/composer.json'));
    }

    public function test_each_rule_set_runs_on_each_existing_subdir_in_order(): void
    {
        $app = $this->cakeApp();
        $fake = $this->docker();

        $result = $this->engine($fake)->migrate($app, false, '8.3', self::hop46());

        self::assertFalse($result->refused(), implode("\n", $result->refusals));
        $expected = [];
        foreach (self::hop46()->rectorSets as $set) {
            foreach (['src', 'tests', 'config'] as $subdir) {
                $expected[] = [$set, $subdir];
            }
        }
        self::assertSame($expected, self::rectorCalls($fake));
        self::assertSame(['docker', 'image', 'inspect', CakeUpgradeRunner::imageTag()], $fake->calls[0]['command'], 'the image is checked first');
        self::assertCount(1, $fake->commandsContaining('inspect'), 'and only once');
        self::assertContains('Ran cakephp41 on src, tests, config.', $result->changes);
        self::assertContains('Ran migrations45 on src, tests, config.', $result->changes);
        foreach (array_unique($this->mounts) as $mount) {
            self::assertNotSame(rtrim($app, '/\\'), rtrim($mount, '/\\'), 'the rules run on a copy, never the app');
        }

        // plugins/ joins the list when the app has one, after config/.
        $withPlugins = $this->cakeApp(['plugins/Reports/src/Plugin.php' => "<?php\n"]);
        $second = $this->docker();
        $this->engine($second)->migrate($withPlugins, true, '8.3', self::hop46());
        self::assertSame([['cakephp41', 'src'], ['cakephp41', 'tests'], ['cakephp41', 'config'], ['cakephp41', 'plugins']], array_slice(self::rectorCalls($second), 0, 4));
    }

    public function test_file_renames_run_only_when_cakephp50_is_in_the_hop(): void
    {
        $fourSix = $this->docker();
        $this->engine($fourSix)->migrate($this->cakeApp(), true, '8.3', self::hop46());
        self::assertSame([], $fourSix->commandsContaining('file_rename'));

        $onRename = static function (string $copy, string $what): ?ProcessResult {
            if ($what === 'templates' && is_file($copy . '/src/Template/Pages/home.ctp')) {
                mkdir($copy . '/templates/Pages', 0775, true);
                rename($copy . '/src/Template/Pages/home.ctp', $copy . '/templates/Pages/home.php');
            }

            return null;
        };
        $five = $this->docker(null, $onRename);
        $result = $this->engine($five)->migrate($this->cakeApp(['src/Template/Pages/home.ctp' => "<h1>Home</h1>\n"]), true, '8.3', self::hop5());

        $renames = $five->commandsContaining('file_rename');
        self::assertCount(2, $renames);
        self::assertSame(['templates', '/app'], array_slice($renames[0], -2));
        self::assertSame(['locales', '/app'], array_slice($renames[1], -2));
        $order = array_map(static fn(array $call): string => in_array('file_rename', $call['command'], true) ? 'rename' : (in_array('rector', $call['command'], true) ? 'rector' : 'other'), $five->calls);
        self::assertSame(['rename', 'rename'], array_slice($order, -2), 'the renames run once, after every rule set');
        self::assertContains('Dry run: would rename 1 .ctp template to .php.', $result->changes);

        // The set decides, never the hop's name.
        $named = new Hop('cakephp-4.6-to-5', HopKind::Framework, '4.6', '5', 'Named like the 5 hop', rectorSets: ['cakephp45'], engine: EngineId::CakeUpgrade);
        $byName = $this->docker();
        $this->engine($byName)->migrate($this->cakeApp(), true, '8.3', $named);
        self::assertSame([], $byName->commandsContaining('file_rename'));

        $unnamed = new Hop('some-later-hop', HopKind::Framework, '4.6', '5', 'Carries the 5 set', rectorSets: ['cakephp50'], engine: EngineId::CakeUpgrade);
        $bySet = $this->docker();
        $this->engine($bySet)->migrate($this->cakeApp(), true, '8.3', $unnamed);
        self::assertCount(2, $bySet->commandsContaining('file_rename'));
    }

    public function test_dry_run_leaves_the_app_untouched_and_reports_diffs(): void
    {
        $app = $this->cakeApp();
        $before = self::snapshot($app);
        $fake = $this->docker(static function (string $copy, string $set, string $subdir): ?ProcessResult {
            if ($set === 'cakephp50' && $subdir === 'src') {
                $file = $copy . '/src/Controller/AppController.php';
                file_put_contents($file, str_replace('FrozenTime', 'DateTime', (string) file_get_contents($file)));
            }

            return null;
        });

        $result = $this->engine($fake)->migrate($app, true, '8.3', self::hop5());

        self::assertFalse($result->refused(), implode("\n", $result->refusals));
        self::assertSame($before, self::snapshot($app), 'a dry run writes nothing in the app');
        $diffs = array_column($result->files, 'diff', 'file');
        self::assertArrayHasKey('src/Controller/AppController.php', $diffs);
        $diff = $diffs['src/Controller/AppController.php'];
        self::assertStringStartsWith("--- a/src/Controller/AppController.php\n+++ b/src/Controller/AppController.php\n@@ -", $diff);
        self::assertStringContainsString("\n-use Cake\\I18n\\FrozenTime;\n+use Cake\\I18n\\DateTime;\n", $diff);
        self::assertStringContainsString("\n-        return (string) FrozenTime::now();\n+        return (string) DateTime::now();\n", $diff);
        self::assertArrayHasKey('composer.json', $diffs, 'the pins are previewed too');
        self::assertStringContainsString('+        "cakephp/cakephp": "^5.4",', $diffs['composer.json']);
        self::assertContains('Dry run: would run cakephp50 on src, tests, config.', $result->changes);
        self::assertContains('Dry run: would set cakephp/cakephp to ^5.4 in require.', $result->changes);
        foreach ($result->changes as $line) {
            self::assertStringStartsWith('Dry run: ', $line);
        }
        self::assertArrayNotHasKey('vendor/cakephp/cakephp/VERSION.txt', $diffs);
    }

    public function test_real_run_copies_changed_files_back(): void
    {
        $app = $this->cakeApp([
            'src/Template/Pages/home.ctp' => "<h1>Home</h1>\n",
            'tests/TestCase/Old/LegacyTest.php' => "<?php\n",
        ]);
        $fake = $this->docker(
            static function (string $copy, string $set, string $subdir): ?ProcessResult {
                if ($set === 'cakephp50' && $subdir === 'src') {
                    $file = $copy . '/src/Controller/AppController.php';
                    file_put_contents($file, str_replace('FrozenTime', 'DateTime', (string) file_get_contents($file)));
                }
                if ($set === 'cakephp50' && $subdir === 'tests') {
                    unlink($copy . '/tests/TestCase/Old/LegacyTest.php');
                }

                return null;
            },
            static function (string $copy, string $what): ?ProcessResult {
                if ($what === 'templates') {
                    mkdir($copy . '/templates/Pages', 0775, true);
                    rename($copy . '/src/Template/Pages/home.ctp', $copy . '/templates/Pages/home.php');
                }

                return null;
            },
        );

        $result = $this->engine($fake)->migrate($app, false, '8.3', self::hop5());

        self::assertFalse($result->refused(), implode("\n", $result->refusals));
        self::assertStringContainsString('use Cake\I18n\DateTime;', (string) file_get_contents($app . '/src/Controller/AppController.php'));
        self::assertSame("<h1>Home</h1>\n", file_get_contents($app . '/templates/Pages/home.php'));
        self::assertFileDoesNotExist($app . '/src/Template/Pages/home.ctp');
        self::assertDirectoryDoesNotExist($app . '/src/Template', 'a folder the rules emptied goes too');
        self::assertFileDoesNotExist($app . '/tests/TestCase/Old/LegacyTest.php');
        self::assertFileExists($app . '/tmp/cache/models/x', 'runtime folders are never copied or touched');
        self::assertFileExists($app . '/vendor/cakephp/cakephp/VERSION.txt');
        self::assertContains('Renamed 1 .ctp template to .php.', $result->changes);
        $files = array_column($result->files, 'file');
        self::assertContains('templates/Pages/home.php', $files);
        self::assertContains('src/Template/Pages/home.ctp', $files);
        self::assertContains('tests/TestCase/Old/LegacyTest.php', $files);
    }

    public function test_failed_rule_set_refuses_and_changes_nothing(): void
    {
        $app = $this->cakeApp();
        $before = self::snapshot($app);
        $output = implode("\n", array_map(static fn(int $n): string => 'output line ' . $n, range(1, 20)));
        $fake = $this->docker(static function (string $copy, string $set, string $subdir) use ($output): ?ProcessResult {
            if ($set === 'cakephp41') {
                $file = $copy . '/src/Controller/AppController.php';
                file_put_contents($file, "<?php\n// edited by the first set\n");
            }

            return $set === 'cakephp43' && $subdir === 'tests' ? new ProcessResult(1, $output, '') : null;
        });

        $result = $this->engine($fake)->migrate($app, false, '8.3', self::hop46());

        self::assertTrue($result->refused());
        self::assertCount(1, $result->refusals);
        $refusal = $result->refusals[0];
        self::assertStringStartsWith('The cakephp/upgrade rule set cakephp43 failed on tests/ (exit 1), so nothing in the app changed.', $refusal);
        self::assertStringContainsString("output line 6\n", $refusal);
        self::assertStringEndsWith('output line 20', $refusal);
        self::assertStringNotContainsString('output line 5', $refusal, 'only the last 15 lines are quoted');
        self::assertSame($before, self::snapshot($app), 'the app is as it was, composer.json included');
        self::assertSame([], self::callsFor(self::rectorCalls($fake), 'cakephp44'), 'nothing runs after the failure');
    }

    public function test_vendor_is_mounted_read_only_when_present(): void
    {
        $app = $this->cakeApp();
        $fake = $this->docker();

        $this->engine($fake)->migrate($app, true, '8.3', self::hop46());

        $rector = array_values(array_filter($fake->calls, static fn(array $call): bool => in_array('rector', $call['command'], true)));
        self::assertNotSame([], $rector);
        foreach ($rector as $call) {
            $command = $call['command'];
            // The copy at /app, then the app's own vendor/ over /app/vendor, read-only, read through the image's class-map autoloader.
            self::assertSame($app . '/vendor:/app/vendor:ro', $command[(int) array_search('-v', $command, true) + 3]);
            self::assertSame('/booster/app-autoload.php', $command[(int) array_search('--autoload', $command, true) + 1]);
            self::assertStringStartsNotWith($app . ':', $command[(int) array_search('-v', $command, true) + 1], 'Rector writes the copy, never the app');
        }
    }

    public function test_missing_vendor_is_a_stop_sign(): void
    {
        $app = $this->cakeApp(['vendor/autoload.php' => null]);
        $before = self::snapshot($app);
        $fake = $this->docker();

        $result = $this->engine($fake)->migrate($app, true, '8.3', self::hop46());

        self::assertSame([CakeUpgradeMigration::NO_VENDOR], $result->refusals);
        self::assertSame("The CakePHP rule sets need the app's vendor/ folder; run composer install in the app first.", CakeUpgradeMigration::NO_VENDOR);
        self::assertSame([], $fake->calls, 'no container runs');
        self::assertSame($before, self::snapshot($app), 'nothing changed');
    }

    public function test_a_rule_set_that_prints_an_error_with_exit_0_refuses(): void
    {
        // What cakephp/upgrade really prints when Rector cannot load a set: the error, then a success line, exit 0.
        $app = $this->cakeApp();
        $before = self::snapshot($app);
        $output = " [ERROR] The path \"/upgrade/config/rector/default-rector.php\" does not exist.\n\nRector applied successfully";
        $fake = $this->docker(static fn(string $copy, string $set): ?ProcessResult => $set === 'cakephp41' ? new ProcessResult(0, $output, '') : null);

        $result = $this->engine($fake)->migrate($app, false, '8.3', self::hop46());

        self::assertTrue($result->refused());
        self::assertStringStartsWith('The cakephp/upgrade rule set cakephp41 printed an error on src/ (exit 0), so nothing in the app changed.', $result->refusals[0]);
        self::assertStringContainsString('default-rector.php', $result->refusals[0]);
        self::assertSame($before, self::snapshot($app));
        self::assertSame([], self::callsFor(self::rectorCalls($fake), 'cakephp42'), 'nothing runs after the error');
    }

    public function test_a_clean_rector_run_that_mentions_errors_in_a_diff_is_not_a_failure(): void
    {
        // A diff line that happens to say "[ERROR]" is indented code, not Rector's error block at the line start.
        $output = "1 file with changes\n-    \$log = '[ERROR] disk full';\n+    \$log = '[ERROR] disk full!';\n [OK] Rector is done!\nRector applied successfully";
        $fake = $this->docker(static fn(): ProcessResult => new ProcessResult(0, $output, ''));

        $result = $this->engine($fake)->migrate($this->cakeApp(), true, '8.3', self::hop46());

        self::assertFalse($result->refused());
    }

    /**
     * @param list<array{0: string, 1: string}> $calls
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function callsFor(array $calls, string $set): array
    {
        return array_values(array_filter($calls, static fn(array $call): bool => $call[0] === $set));
    }

    public function test_composer_edits_are_applied_and_packages_listed(): void
    {
        $app = $this->cakeApp();

        $result = $this->engine($this->docker())->migrate($app, false, '8.3', self::hop5());

        self::assertFalse($result->refused(), implode("\n", $result->refusals));
        $json = json_decode((string) file_get_contents($app . '/composer.json'), true);
        self::assertIsArray($json);
        self::assertSame('^5.4', $json['require']['cakephp/cakephp']);
        self::assertSame('^4.0', $json['require']['cakephp/migrations']);
        self::assertSame('^5.2', $json['require-dev']['cakephp/debug_kit']);
        self::assertSame('^9.5', $json['require-dev']['phpunit/phpunit'], 'a package the hop does not pin stays');
        self::assertSame(['cakephp/cakephp', 'cakephp/migrations', 'cakephp/debug_kit'], $result->packages);
        self::assertContains('Set cakephp/cakephp to ^5.4 in require.', $result->changes);
        self::assertContains('Set cakephp/debug_kit to ^5.2 in require-dev.', $result->changes);
        self::assertContains('composer.json', array_column($result->files, 'file'));
    }

    public function test_manual_report_lists_auth_component_calls_for_the_5_hop(): void
    {
        $files = [
            'src/Controller/AppController.php' => "<?php\nnamespace App\\Controller;\n\n/**\n * @property \\Cake\\Controller\\Component\\AuthComponent \$Auth\n */\nclass AppController extends \\Cake\\Controller\\Controller\n{\n    public function isAuthorized(\$user = null): bool\n    {\n        return true;\n    }\n}\n",
            'src/Controller/UsersController.php' => "<?php\nnamespace App\\Controller;\n\nclass UsersController extends AppController\n{\n    public function login()\n    {\n        \$user = \$this->Auth->identify();\n        \$url = \$this->Auth->redirectUrl();\n    }\n}\n",
            'src/Controller/ApiController.php' => "<?php\nnamespace App\\Controller;\n\nuse Cake\\I18n\\Time;\n\nclass ApiController extends AppController\n{\n    public function initialize(): void\n    {\n        \$this->loadComponent('RequestHandler');\n    }\n\n    public function csv()\n    {\n        return \$this->response->withType('csv');\n    }\n}\n",
        ];

        $app = $this->cakeApp($files);

        $result = $this->engine($this->docker())->migrate($app, false, '8.3', self::hop5());

        self::assertFalse($result->refused(), implode("\n", $result->refusals));
        $auth = self::lineStartingWith($result->manual, 'MANUAL: AuthComponent is removed in CakePHP 5; port it to the Authentication plugin');
        self::assertNotNull($auth, implode("\n", $result->manual));
        self::assertStringContainsString('src/Controller/AppController.php (1)', $auth);
        $calls = self::lineStartingWith($result->manual, 'MANUAL: the $this->Auth calls');
        self::assertNotNull($calls);
        self::assertStringContainsString('src/Controller/UsersController.php (2)', $calls);
        self::assertNotNull(self::lineStartingWith($result->manual, 'MANUAL: isAuthorized()'));
        // Nothing reads $this->RequestHandler, so the load goes (CAKE-4) and no MANUAL line is left for it.
        self::assertNull(self::lineStartingWith($result->manual, 'MANUAL: RequestHandlerComponent'));
        self::assertContains("Removed loadComponent('RequestHandler') from src/Controller/ApiController.php (CakePHP 5 has no RequestHandlerComponent).", $result->changes);
        self::assertStringNotContainsString('RequestHandler', (string) file_get_contents($app . '/src/Controller/ApiController.php'));
        self::assertNotNull(self::lineStartingWith($result->manual, 'MANUAL: no upgrade rule checks ->withType()'));
        self::assertStringContainsString('src/Controller/ApiController.php (1)', (string) self::lineStartingWith($result->manual, 'MANUAL: Cake\I18n\Time'));
        foreach ($result->manual as $line) {
            self::assertStringStartsWith('MANUAL: ', $line);
        }
        self::assertNotContains(self::E_ALL_HINT, $result->manual);
        self::assertSame($files['src/Controller/UsersController.php'], file_get_contents($app . '/src/Controller/UsersController.php'), 'the report never edits, even in a real run');
    }

    public function test_manual_report_has_the_e_all_hint_for_the_4_6_hop(): void
    {
        $app = $this->cakeApp([
            'src/Controller/AppController.php' => "<?php\n/** @property \\Cake\\Controller\\Component\\AuthComponent \$Auth */\nclass AppController {}\n",
            'src/View/AppView.php' => "<?php\nclass AppView\n{\n    public function initialize(): void\n    {\n        \$this->loadHelper('Html');\n    }\n}\n",
        ]);

        $result = $this->engine($this->docker())->migrate($app, true, '8.3', self::hop46());

        self::assertContains(self::E_ALL_HINT, $result->manual);
        self::assertSame(self::E_ALL_HINT, $result->manual[count($result->manual) - 1]);
        self::assertContains(CakeUpgradeMigration::SOCKET_HINT, $result->manual, 'the Socket rules the image drops are a person\'s job');
        self::assertNull(self::lineStartingWith($result->manual, 'MANUAL: AuthComponent'), 'the CakePHP 5 removals wait for the 5 hop');
        self::assertStringContainsString('src/View/AppView.php (1)', (string) self::lineStartingWith($result->manual, 'MANUAL: View::loadHelper()'));
    }

    public function test_temp_copy_is_removed(): void
    {
        $app = $this->cakeApp();
        $this->engine($this->docker())->migrate($app, false, '8.3', self::hop46());
        $failed = $this->engine($this->docker(static fn(): ProcessResult => new ProcessResult(2, '', 'boom')))->migrate($app, false, '8.3', self::hop46());

        self::assertTrue($failed->refused());
        $copies = array_values(array_unique($this->mounts));
        self::assertCount(2, $copies, 'one copy per run');
        foreach ($copies as $copy) {
            self::assertNotSame(rtrim($app, '/\\'), rtrim($copy, '/\\'));
            self::assertDirectoryDoesNotExist($copy);
        }
        self::assertDirectoryExists($app . '/src');
    }

    private const AUTH_CONTROLLER = <<<'PHP'
        <?php
        declare(strict_types=1);

        namespace App\Controller;

        use Cake\Controller\Controller;

        class AppController extends Controller
        {
            public function initialize(): void
            {
                parent::initialize();
                $this->loadComponent('Flash');
                $this->loadComponent('Auth', [
                    'loginAction' => ['controller' => 'Auth', 'action' => 'login'],
                    'authError' => '',
                ]);
                $this->Auth->allow(['display']);
            }

            protected function role(): ?string
            {
                return $this->Auth->User('ROLE');
            }

            protected function signIn(array $profile): void
            {
                $this->Auth->setUser($profile);
            }
        }

        PHP;

    private const APPLICATION = <<<'PHP'
        <?php
        declare(strict_types=1);

        namespace App;

        use Cake\Core\Configure;
        use Cake\Http\BaseApplication;
        use Cake\Http\Middleware\BodyParserMiddleware;
        use Cake\Http\MiddlewareQueue;
        use Cake\Routing\Middleware\RoutingMiddleware;

        class Application extends BaseApplication
        {
            public function bootstrap(): void
            {
                parent::bootstrap();
                $this->addPlugin(\CakeDC\OracleDriver\Plugin::class, ['bootstrap' => true]);
            }

            public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
            {
                $middlewareQueue
                    ->add(new RoutingMiddleware($this))
                    ->add(new BodyParserMiddleware());

                return $middlewareQueue;
            }
        }

        PHP;

    private const ORACLE_MANUAL = 'MANUAL: check that the Oracle driver for CakePHP 5 covers the Oracle calls the app makes.';

    /**
     * An app that signs people in through AuthComponent, with an
     * Application class, optionally on the cakedc Oracle driver.
     *
     * @param array<string, string|null> $files
     */
    private function authApp(bool $oracle = false, array $files = []): string
    {
        $require = ['php' => '>=8.1', 'cakephp/cakephp' => '^4.5'];
        if ($oracle) {
            $require['cakedc/cakephp-oracle-driver'] = '^5.0';
        }

        return $this->cakeApp(array_replace([
            'composer.json' => json_encode(['name' => 'acme/app', 'require' => $require], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            'src/Controller/AppController.php' => self::AUTH_CONTROLLER,
            'src/Application.php' => self::APPLICATION,
            'config/app.php' => "<?php\nreturn ['Datasources' => ['default' => ['className' => 'CakeDC\\OracleDriver\\Database\\OracleConnection', 'driver' => \\CakeDC\\OracleDriver\\Database\\Driver\\OracleOCI::class]]];\n",
        ], $files));
    }

    /**
     * @return array<string, mixed>
     */
    private static function composerJson(string $app): array
    {
        $json = json_decode((string) file_get_contents($app . '/composer.json'), true);
        self::assertIsArray($json);

        return $json;
    }

    public function test_auth_component_calls_are_moved_for_the_5_hop(): void
    {
        $app = $this->authApp();

        $result = $this->engine($this->docker())->migrate($app, false, '8.3', self::hop5());

        self::assertFalse($result->refused(), implode("\n", $result->refusals));
        $controller = (string) file_get_contents($app . '/src/Controller/AppController.php');
        self::assertStringNotContainsString('$this->Auth->', $controller);
        self::assertStringContainsString("\$this->loadComponent('Authentication.Authentication');", $controller);
        self::assertStringContainsString("\$this->Authentication->addUnauthenticatedActions(['display']);", $controller);
        self::assertStringContainsString("return \$this->Authentication->getIdentity()?->get('ROLE');", $controller);
        self::assertStringContainsString('$this->Authentication->setIdentity($profile);', $controller);
        self::assertContains('Moved 3 AuthComponent calls to the Authentication plugin.', $result->changes);
        self::assertContains("Replaced loadComponent('Auth') with loadComponent('Authentication.Authentication') in src/Controller/AppController.php.", $result->changes);
        // CAKE-12: the authError moves to the guest redirect in src/Application.php.
        self::assertNull(self::lineStartingWith($result->manual, "MANUAL: Auth config 'authError'"));
        self::assertStringContainsString("\$request->getFlash()->set('', [", (string) file_get_contents($app . '/src/Application.php'));
        self::assertSame(array_values(array_unique($result->manual)), $result->manual, 'each MANUAL line once');
        self::assertNull(self::lineStartingWith($result->manual, 'MANUAL: AuthComponent is removed'), 'nothing left for a person once the calls moved');
        self::assertContains('src/Controller/AppController.php', array_column($result->files, 'file'));
    }

    public function test_application_php_is_scaffolded_when_auth_was_used(): void
    {
        $app = $this->authApp();

        $result = $this->engine($this->docker())->migrate($app, false, '8.3', self::hop5());

        $application = (string) file_get_contents($app . '/src/Application.php');
        self::assertStringContainsString('class Application extends BaseApplication implements AuthenticationServiceProviderInterface', $application);
        self::assertStringContainsString('->add(new AuthenticationMiddleware($this))', $application);
        self::assertStringContainsString('getAuthenticationService(ServerRequestInterface $request)', $application);
        self::assertStringContainsString("['controller' => 'Auth', 'action' => 'login']", $application, 'guests go to the old loginAction');
        self::assertContains('Wired src/Application.php for the Authentication plugin.', $result->changes);
        self::assertSame('^3.0', self::composerJson($app)['require']['cakephp/authentication']);
        self::assertArrayNotHasKey('cakephp/authorization', self::composerJson($app)['require']);
        self::assertContains('cakephp/authentication', $result->packages);
        self::assertContains('Required cakephp/authentication ^3.0 for the Authentication plugin.', $result->changes);
    }

    // CAKE-11: AuthComponent let guests reach the loginAction; on 5 a fleet app's sign-in page redirected to itself.
    public function test_the_login_action_stays_open_to_guests(): void
    {
        $authController = "<?php\nnamespace App\\Controller;\n\nclass AuthController extends AppController\n{\n    public function initialize(): void\n    {\n        parent::initialize();\n    }\n\n    public function login()\n    {\n        return \$this->redirect('https://sso.example.invalid/');\n    }\n}\n";
        $app = $this->authApp(false, ['src/Controller/AuthController.php' => $authController]);

        $result = $this->engine($this->docker())->migrate($app, false, '8.3', self::hop5());

        $code = (string) file_get_contents($app . '/src/Controller/AuthController.php');
        self::assertStringContainsString("        parent::initialize();\n        // AuthComponent let guests reach its loginAction; the Authentication plugin needs it named.\n        \$this->Authentication->addUnauthenticatedActions(['login']);\n    }", $code);
        self::assertContains('Opened login() to guests in src/Controller/AuthController.php, as AuthComponent did for its loginAction.', $result->changes);
        self::assertNull(self::lineStartingWith($result->manual, "MANUAL: the loginAction's controller"));

        $missing = $this->engine($this->docker())->migrate($this->authApp(), false, '8.3', self::hop5());
        self::assertNotNull(self::lineStartingWith($missing->manual, "MANUAL: the loginAction's controller (src/Controller/AuthController.php) was not found"));
    }

    public function test_no_auth_means_no_authentication_plugin_or_scaffold(): void
    {
        $component = "<?php\nnamespace App\\Controller\\Component;\n\nuse Cake\\Controller\\Component;\n\nclass DataComponent extends Component\n{\n    public \$components = ['Flash'];\n}\n";
        $app = $this->cakeApp([
            'src/Application.php' => self::APPLICATION,
            'src/Controller/Component/DataComponent.php' => $component,
        ]);

        $result = $this->engine($this->docker())->migrate($app, false, '8.3', self::hop5());

        self::assertFalse($result->refused(), implode("\n", $result->refusals));
        self::assertSame(self::APPLICATION, file_get_contents($app . '/src/Application.php'));
        self::assertSame($component, file_get_contents($app . '/src/Controller/Component/DataComponent.php'), 'step 1 is skipped whole');
        self::assertArrayNotHasKey('cakephp/authentication', self::composerJson($app)['require']);
        self::assertNotContains('cakephp/authentication', $result->packages);
        self::assertNull(self::lineStartingWith($result->changes, 'Wired '));
        self::assertNull(self::lineStartingWith($result->changes, 'Moved '));
        self::assertNull(self::lineStartingWith($result->changes, 'Required '));
    }

    public function test_oracle_driver_is_swapped_and_namespaces_renamed(): void
    {
        $app = $this->authApp(true);

        $result = $this->engine($this->docker())->migrate($app, false, '8.3', self::hop5());

        self::assertFalse($result->refused(), implode("\n", $result->refusals));
        $require = self::composerJson($app)['require'];
        self::assertArrayNotHasKey('cakedc/cakephp-oracle-driver', $require);
        self::assertSame('^1.0', $require['ioigoume/cakephp5-oracle-driver']);
        $config = (string) file_get_contents($app . '/config/app.php');
        self::assertStringNotContainsString('CakeDC', $config);
        self::assertStringContainsString("'Ioigoume\\OracleDriver\\Database\\OracleConnection'", $config);
        self::assertStringContainsString('\\Ioigoume\\OracleDriver\\Database\\Driver\\OracleOCI::class', $config);
        self::assertStringContainsString('$this->addPlugin(\\Ioigoume\\OracleDriver\\Plugin::class', (string) file_get_contents($app . '/src/Application.php'));
        self::assertContains('Swapped the Oracle driver for the CakePHP 5 one.', $result->changes);
        self::assertContains('ioigoume/cakephp5-oracle-driver', $result->packages);
    }

    public function test_oracle_manual_line_present(): void
    {
        $withDriver = $this->engine($this->docker())->migrate($this->authApp(true), true, '8.3', self::hop5());
        $without = $this->engine($this->docker())->migrate($this->authApp(false), true, '8.3', self::hop5());

        self::assertSame(1, count(array_keys($withDriver->manual, self::ORACLE_MANUAL, true)));
        self::assertNotContains(self::ORACLE_MANUAL, $without->manual);
        self::assertNull(self::lineStartingWith($without->changes, 'Dry run: would swap'));
    }

    public function test_4_6_hop_does_not_run_the_auth_rewrite(): void
    {
        $app = $this->authApp(true);

        $result = $this->engine($this->docker())->migrate($app, false, '8.3', self::hop46());

        self::assertFalse($result->refused(), implode("\n", $result->refusals));
        self::assertSame(self::AUTH_CONTROLLER, file_get_contents($app . '/src/Controller/AppController.php'));
        self::assertSame(self::APPLICATION, file_get_contents($app . '/src/Application.php'));
        $require = self::composerJson($app)['require'];
        self::assertArrayNotHasKey('cakephp/authentication', $require);
        self::assertSame('^5.0', $require['cakedc/cakephp-oracle-driver']);
        self::assertArrayNotHasKey('ioigoume/cakephp5-oracle-driver', $require);
        self::assertNotContains(self::ORACLE_MANUAL, $result->manual);
        self::assertSame(['cakephp/cakephp'], $result->packages);
        self::assertContains(self::E_ALL_HINT, $result->manual);
    }

    public function test_dry_run_reports_the_auth_steps(): void
    {
        $app = $this->authApp(true);
        $before = self::snapshot($app);

        $result = $this->engine($this->docker())->migrate($app, true, '8.3', self::hop5());

        self::assertFalse($result->refused(), implode("\n", $result->refusals));
        self::assertSame($before, self::snapshot($app), 'a dry run writes nothing');
        self::assertContains('Dry run: would move 3 AuthComponent calls to the Authentication plugin.', $result->changes);
        self::assertContains('Dry run: would wire src/Application.php for the Authentication plugin.', $result->changes);
        self::assertContains('Dry run: would require cakephp/authentication ^3.0 for the Authentication plugin.', $result->changes);
        self::assertContains('Dry run: would swap the Oracle driver for the CakePHP 5 one.', $result->changes);
        $diffs = array_column($result->files, 'diff', 'file');
        self::assertStringContainsString('+use Authentication\\Middleware\\AuthenticationMiddleware;', $diffs['src/Application.php']);
        self::assertStringContainsString('+        "cakephp/authentication": "^3.0"', $diffs['composer.json']);
        self::assertStringContainsString('-        "cakedc/cakephp-oracle-driver": "^5.0"', $diffs['composer.json']);
        self::assertArrayHasKey('config/app.php', $diffs);
        self::assertContains('cakephp/authentication', $result->packages);
        self::assertContains('ioigoume/cakephp5-oracle-driver', $result->packages);
        self::assertContains(self::ORACLE_MANUAL, $result->manual);
    }
}
