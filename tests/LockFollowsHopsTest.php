<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Composer\Semver\Semver;
use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Plan\PlanOptions;
use Waynestate\Booster\Plan\Planner;
use Waynestate\Booster\Project\ProjectDetector;
use Waynestate\Booster\Report\TextWriter;
use Waynestate\Booster\Run\BaselineResult;
use Waynestate\Booster\Run\ComposerEditor;
use Waynestate\Booster\Run\HopExecutor;
use Waynestate\Booster\Run\ProcessResult;
use Waynestate\Booster\Run\RectorRunner;
use Waynestate\Booster\Run\RunOptions;
use Waynestate\Booster\Run\RunReport;
use Waynestate\Booster\Run\StepResult;
use Waynestate\Booster\Run\TargetImageRunner;

/**
 * Found on a real 2012 app (2026-11-09): vendored-to-composer resolved the
 * app's first composer.lock on PHP 7.4, where Slim 4's psr/container 2.0
 * holds symfony/service-contracts at v1.1.2 (php ^7.1.3). That lock cannot
 * install on PHP 8.0, so the 7.4 to 8.0 hop always failed its Composer phase,
 * and a rerun with --from-php=7.4 was then refused by the baseline, because
 * the stopped hop had already raised composer.json to ^8.0.
 *
 * The fake Docker here plays Composer the way the real one answered on that
 * app: a plain install refuses a lock whose packages do not run on the
 * image's PHP, and an update of the named packages resolves them for it.
 */
final class LockFollowsHopsTest extends TestCase
{
    /** The packages the 7.4 resolve locked, with the require.php each release declares (Packagist, 2026-11-09). */
    private const LOCK_74 = [
        ['doctrine/orm', '2.20.14', '^7.1 || ^8.0'],
        ['slim/slim', '4.15.3', '~7.4.0 || ~8.0.0 || ~8.1.0 || ~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0'],
        ['smarty/smarty', 'v4.5.8', '^7.1 || ^8.0'],
        ['symfony/cache', 'v5.4.54', '>=7.2.5'],
        ['symfony/deprecation-contracts', 'v2.5.4', '>=7.1'],
        ['symfony/service-contracts', 'v1.1.2', '^7.1.3'],
    ];

    /** What `composer update -W symfony/service-contracts` moved on PHP 8.0. */
    private const UPDATED_80 = [
        'symfony/service-contracts' => ['v3.0.2', '>=8.0.2'],
        'symfony/deprecation-contracts' => ['v3.0.2', '>=8.0.2'],
    ];

    private function app(): string
    {
        $dir = $this->tempDir();
        $from = __DIR__ . '/fixtures/vendored-2012';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            self::assertInstanceOf(\SplFileInfo::class, $file);
            $target = $dir . substr($file->getPathname(), strlen($from));
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0775, true);
            }
            copy($file->getPathname(), $target);
        }

        return $dir;
    }

    /**
     * @param list<array{0: string, 1: string, 2: string}> $packages
     */
    private static function writeLock(string $dir, array $packages): void
    {
        $locked = array_map(static fn(array $p): array => ['name' => $p[0], 'version' => $p[1], 'require' => ['php' => $p[2]]], $packages);
        file_put_contents($dir . '/composer.lock', (string) json_encode(['content-hash' => 'x', 'packages' => $locked, 'packages-dev' => []], JSON_PRETTY_PRINT));
    }

    /**
     * Git and Rector answer as in the other run tests; each gate container
     * runs the Composer phase against the mounted app on the image's PHP.
     */
    private function fake(): FakeProcessRunner
    {
        return new FakeProcessRunner(static function (array $command): ProcessResult {
            if ($command[0] === 'git') {
                return new ProcessResult(0, '', '');
            }
            if ($command[0] !== 'docker') {
                return new ProcessResult(2, (string) json_encode(['file_diffs' => [['file' => '/tmp/x/lib/helpers.php', 'diff' => "--- a\n+++ b"]], 'errors' => []]), '');
            }
            if (($command[1] ?? '') !== 'run') {
                return new ProcessResult(0, '', '');
            }

            return self::composer($command);
        });
    }

    /**
     * Composer inside one gate container.
     *
     * @param list<string> $command
     */
    private static function composer(array $command): ProcessResult
    {
        $app = null;
        $php = null;
        foreach ($command as $i => $arg) {
            if ($arg === '-v' && str_ends_with($command[$i + 1] ?? '', ':/app')) {
                $app = substr($command[$i + 1], 0, -strlen(':/app'));
            }
            if (preg_match('/^booster-target:(\d+\.\d+)-/', $arg, $m) === 1) {
                $php = $m[1];
            }
        }
        self::assertNotNull($app);
        self::assertNotNull($php);
        $script = (string) end($command);
        $pass = new ProcessResult(0, "BOOSTER_GATE_PHASE=tests\n", '');
        if (!is_file($app . '/composer.json')) {
            return $pass;
        }
        $running = $php . '.30';
        $fail = static fn(string $why): ProcessResult => new ProcessResult(2, $why . "\nBOOSTER_GATE_PHASE=install\nBOOSTER_GATE_EXIT=2\n", '');
        $root = json_decode((string) file_get_contents($app . '/composer.json'), true);
        self::assertIsArray($root);
        $rootPhp = $root['require']['php'] ?? null;
        if (is_string($rootPhp) && !Semver::satisfies($running, $rootPhp)) {
            return $fail(sprintf('  - Root composer.json requires php %s but your php version (%s) does not satisfy that requirement.', $rootPhp, $running));
        }
        if (!is_file($app . '/composer.lock')) {
            // No lock: the install resolves one for this PHP.
            self::writeLock($app, self::LOCK_74);

            return $pass;
        }
        $lock = json_decode((string) file_get_contents($app . '/composer.lock'), true);
        self::assertIsArray($lock);
        if (preg_match('/composer update -W --no-interaction --no-progress (.+)$/m', $script, $m) === 1) {
            $named = explode(' ', trim($m[1]));
            foreach ($lock['packages'] as $n => $package) {
                if ((in_array($package['name'], $named, true) || $package['name'] === 'symfony/deprecation-contracts') && isset(self::UPDATED_80[$package['name']])) {
                    [$version, $needs] = self::UPDATED_80[$package['name']];
                    $lock['packages'][$n]['version'] = $version;
                    $lock['packages'][$n]['require']['php'] = $needs;
                }
            }
            file_put_contents($app . '/composer.lock', (string) json_encode($lock, JSON_PRETTY_PRINT));
        }
        $problems = [];
        foreach ($lock['packages'] as $package) {
            if (!Semver::satisfies($running, $package['require']['php'])) {
                $problems[] = sprintf('    - %s %s requires php %s -> your php version (%s) does not satisfy that requirement.', $package['name'], $package['version'], $package['require']['php'], $running);
            }
        }
        if ($problems !== []) {
            return $fail("Your lock file does not contain a compatible set of packages. Please run composer update.\n  Problem 1\n" . implode("\n", $problems));
        }

        return $pass;
    }

    private function execute(string $dir, FakeProcessRunner $fake, PlanOptions $plan): RunReport
    {
        $plan = (new Planner($this->policy(), new HopRegistry()))->plan((new ProjectDetector())->detect($dir), $plan, $this->today());

        return (new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake, dirname(__DIR__) . '/docker'), $fake))->execute($plan, new RunOptions());
    }

    /**
     * @return array<string, string> the gate script of each gate container, keyed by the image's PHP, baseline first
     */
    private static function gateScripts(FakeProcessRunner $fake): array
    {
        $scripts = [];
        foreach ($fake->calls as $n => $call) {
            $command = $call['command'];
            if ($command[0] !== 'docker' || ($command[1] ?? '') !== 'run') {
                continue;
            }
            foreach ($command as $arg) {
                if (preg_match('/^booster-target:(\d+\.\d+)-/', $arg, $m) === 1) {
                    $scripts[$m[1] . '#' . $n] = (string) end($command);
                }
            }
        }

        return $scripts;
    }

    public function test_the_lock_the_vendored_hop_resolves_on_74_follows_every_php_hop_to_85(): void
    {
        $dir = $this->app();
        $fake = $this->fake();

        $report = $this->execute($dir, $fake, new PlanOptions(fromPhp: '7.4', toPhp: '8.5', vendored: true));

        $all = implode("\n", array_merge(...array_map(static fn(StepResult $r): array => $r->messages, $report->results)));
        self::assertSame(
            ['vendored-to-composer', 'php-74-to-80', 'php-80-to-81', 'php-81-to-82', 'php-82-to-83', 'php-83-to-84', 'php-84-to-85'],
            array_map(static fn(StepResult $r): string => $r->hopId, $report->results),
        );
        self::assertSame(array_fill(0, 7, StepResult::APPLIED), array_map(static fn(StepResult $r): string => $r->status, $report->results), $all);
        self::assertFalse($report->failed());

        // The 8.0 hop updates only the package that refuses 8.0, and says why.
        self::assertContains(
            'The gate refreshed composer.lock (composer update -W symfony/service-contracts): the lock held symfony/service-contracts v1.1.2, which needs php ^7.1.3, not PHP 8.0.',
            $report->results[1]->messages,
        );
        $scripts = array_values(self::gateScripts($fake));
        // Baseline on 7.4, the vendored hop's gate, then one gate per PHP hop.
        self::assertCount(8, $scripts);
        self::assertStringContainsString('composer update -W --no-interaction --no-progress symfony/service-contracts', $scripts[2]);
        foreach ([0, 1, 3, 4, 5, 6, 7] as $plain) {
            self::assertStringContainsString('composer install --no-interaction', $scripts[$plain], 'gate ' . $plain . ' keeps the plain install');
        }
        $lock = json_decode((string) file_get_contents($dir . '/composer.lock'), true);
        self::assertIsArray($lock);
        $versions = array_column($lock['packages'], 'version', 'name');
        self::assertSame('v3.0.2', $versions['symfony/service-contracts']);
        // Nothing else moved: each hop's lock diff stays reviewable.
        self::assertSame('v5.4.54', $versions['symfony/cache']);
        self::assertSame('^8.5', json_decode((string) file_get_contents($dir . '/composer.json'), true)['require']['php']);
    }

    public function test_a_dry_run_names_the_lock_update_a_php_hop_will_run(): void
    {
        $dir = $this->copyExample('raw-php');
        file_put_contents($dir . '/composer.json', (string) json_encode(['require' => ['php' => '^7.4']]));
        self::writeLock($dir, self::LOCK_74);
        $fake = $this->fake();

        $plan = (new Planner($this->policy(), new HopRegistry()))->plan((new ProjectDetector())->detect($dir), new PlanOptions(fromPhp: '7.4', toPhp: '8.0'), $this->today());
        $report = (new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake, dirname(__DIR__) . '/docker'), $fake))->execute($plan, new RunOptions(dryRun: true));

        self::assertContains('would run: composer update -W symfony/service-contracts', $report->results[0]->messages);
    }

    public function test_a_rerun_from_below_composer_json_says_what_to_run(): void
    {
        // Where the real run stood after the 8.0 hop stopped and the lock was
        // updated by hand: composer.json says ^8.0 and the lock is 8.0's.
        $dir = $this->copyExample('raw-php');
        file_put_contents($dir . '/composer.json', (string) json_encode(['require' => ['php' => '^8.0']]));
        self::writeLock($dir, [['symfony/service-contracts', 'v3.0.2', '>=8.0.2']]);
        $fake = $this->fake();

        $report = $this->execute($dir, $fake, new PlanOptions(fromPhp: '7.4', toPhp: '8.5'));

        self::assertSame(BaselineResult::INSTALL_FAILED, $report->baseline->status);
        $advice = 'composer.json already requires php ^8.0, which PHP 7.4 does not meet, so the app is past 7.4 (a run that stopped at a hop leaves that hop\'s composer.json edit in place). Run again without --from-php=7.4: Booster then starts from PHP 8.0, as composer.json says, and the baseline on 8.0 checks the stopped hop\'s work.';
        self::assertSame($advice, $report->baseline->advice);
        self::assertSame('Not run because the baseline failed: ' . $advice, $report->results[0]->messages[0]);
        $text = (new TextWriter())->run($report, $dir, 'booster');
        self::assertStringContainsString($advice, $text);
        self::assertStringNotContainsString('or pass --allow-failing-baseline', $text);
        self::assertSame($advice, $report->toArray()['baseline']['advice']);
    }

    public function test_a_baseline_whose_lock_refuses_its_php_names_the_update_to_run(): void
    {
        // The stopped 8.0 hop raised composer.json, but its lock update did not land.
        $dir = $this->copyExample('raw-php');
        file_put_contents($dir . '/composer.json', (string) json_encode(['require' => ['php' => '^8.0']]));
        self::writeLock($dir, self::LOCK_74);
        $fake = $this->fake();

        $report = $this->execute($dir, $fake, new PlanOptions(toPhp: '8.5'));

        self::assertSame(BaselineResult::INSTALL_FAILED, $report->baseline->status);
        self::assertSame(
            'The lock holds packages that do not install on PHP 8.0: symfony/service-contracts v1.1.2 needs php ^7.1.3. Run composer update -W symfony/service-contracts on PHP 8.0, commit composer.lock, then run again.',
            $report->baseline->advice,
        );
    }

    public function test_any_other_baseline_install_failure_keeps_the_general_advice(): void
    {
        $dir = $this->copyExample('raw-php');
        file_put_contents($dir . '/composer.json', (string) json_encode(['require' => ['php' => '^8.0']]));
        self::writeLock($dir, [['symfony/service-contracts', 'v3.0.2', '>=8.0.2']]);
        $fake = new FakeProcessRunner(static function (array $command): ProcessResult {
            if ($command[0] === 'docker' && ($command[1] ?? '') === 'run') {
                return new ProcessResult(2, "Could not authenticate against git.example.edu\nBOOSTER_GATE_PHASE=install\nBOOSTER_GATE_EXIT=2\n", '');
            }

            return new ProcessResult(0, '', '');
        });

        $report = $this->execute($dir, $fake, new PlanOptions(toPhp: '8.5'));

        self::assertSame(BaselineResult::INSTALL_FAILED, $report->baseline->status);
        self::assertNull($report->baseline->advice);
        self::assertStringContainsString("fix the app's composer.json and composer.lock, or pass --allow-failing-baseline.", (new TextWriter())->run($report, $dir, 'booster'));
    }
}
