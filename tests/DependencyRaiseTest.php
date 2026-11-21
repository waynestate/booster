<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Plan\PlanOptions;
use Waynestate\Booster\Plan\Planner;
use Waynestate\Booster\Project\ProjectDetector;
use Waynestate\Booster\Run\ComposerEditor;
use Waynestate\Booster\Run\DependencyRaise;
use Waynestate\Booster\Run\HopExecutor;
use Waynestate\Booster\Run\ProcessResult;
use Waynestate\Booster\Run\RectorRunner;
use Waynestate\Booster\Run\RunOptions;
use Waynestate\Booster\Run\RunReport;
use Waynestate\Booster\Run\StepResult;
use Waynestate\Booster\Run\TargetImageRunner;

/**
 * "Raise when proven": a PHP hop whose gate fails inside a dependency that is
 * already on the newest release its constraint allows tries that package's
 * next major once, and keeps it only when the gate passes again.
 */
final class DependencyRaiseTest extends TestCase
{
    private const URL = 'https://repo.packagist.org/p2/acme/di.json';

    /** What the gate prints when acme/di 1.x trips over PHP 8.4. */
    private const FAILURE = "PHP Deprecated:  Acme\\Di\\Container::__construct(): Implicitly marking parameter \$source as nullable is deprecated in /app/vendor/acme/di/src/Container.php on line 9\nFAILURES!\nBOOSTER_GATE_PHASE=tests\nBOOSTER_GATE_EXIT=1\n";

    public function test_it_reads_the_packages_a_failure_names_by_path(): void
    {
        $text = implode("\n", [
            '"description": "DEPRECATED: ... in file \/app\/vendor\/php-di\/php-di\/src\/Container.php."',
            'at C:\\app\\vendor\\Slim\\Slim\\Slim\\App.php:10',
            '/app/vendor/php-di/php-di/src/Definition/Resolver/ParameterResolver.php on line 38',
            '/app/vendor/autoload.php',
            '/app/myvendor/acme/x/y.php',
        ]);

        self::assertSame(['php-di/php-di', 'slim/slim'], DependencyRaise::tracedPackages($text));
    }

    public function test_it_proposes_the_next_major_only_when_the_range_is_exhausted(): void
    {
        $dir = $this->app('1.2.3');
        $raise = new DependencyRaise(new FixturePackageFetcher([self::URL => $this->document()]));

        $candidate = $raise->candidate($dir, '8.4', self::FAILURE);
        self::assertNotNull($candidate);
        self::assertSame('acme/di', $candidate['package']);
        self::assertSame('require', $candidate['section']);
        self::assertSame('^1.2 || ^2', $candidate['widened']);
        self::assertSame('1.2.3', $candidate['installed']);
        self::assertSame('1', $candidate['oldMajor']);
        self::assertSame('2', $candidate['newMajor']);
        self::assertSame('https://github.com/acme/di/tree/2.0.1', $candidate['notes']);

        // Composer can still move inside ^1.2, so that is an update, not a raise.
        self::assertNull($raise->candidate($this->app('1.2.0'), '8.4', self::FAILURE));
        // No release of 2.x runs on PHP 8.0.
        self::assertNull($raise->candidate($dir, '8.0', self::FAILURE));
        // The failure names no direct dependency.
        self::assertNull($raise->candidate($dir, '8.4', 'in /app/vendor/acme/other/src/X.php'));
        // A package a framework hop owns is never raised, whatever the path says.
        $slim = $this->app('4.15.3', 'slim/slim', '^4.10');
        $fetcher = new FixturePackageFetcher([]);
        self::assertNull((new DependencyRaise($fetcher))->candidate($slim, '8.4', 'in /app/vendor/slim/slim/Slim/App.php'));
        self::assertSame([], $fetcher->fetched);
        // Packagist does not answer: an unknown proves nothing.
        self::assertNull((new DependencyRaise(new FixturePackageFetcher([])))->candidate($dir, '8.4', self::FAILURE));
    }

    public function test_a_strangler_update_raises_the_root_package_that_holds_a_shared_one(): void
    {
        $dir = $this->app('1.2.3');
        $raise = new DependencyRaise(new FixturePackageFetcher([self::URL => $this->document()]));
        $failure = implode("\n", [
            '  Problem 1',
            '    - Root composer.json requires acme/di ^1.2 -> satisfiable by acme/di[1.2.0, 1.2.3].',
            '    - Root composer.json requires waynestate/sesame ^0.10 -> satisfiable by waynestate/sesame[0.10.0].',
            '    - acme/di[1.2.0, 1.2.3] require psr/log ~1.0 -> satisfiable by psr/log[1.0.0, ..., 1.1.4].',
        ]);

        $candidate = $raise->rootHolder($dir, '8.4', $failure, ['waynestate/sesame']);
        self::assertNotNull($candidate);
        self::assertSame('acme/di', $candidate['package']);
        self::assertSame('^1.2 || ^2', $candidate['widened']);
        // A package the update already names is not raised.
        self::assertNull($raise->rootHolder($dir, '8.4', $failure, ['waynestate/sesame', 'acme/di']));
        // A failure that names no root requirement raises nothing.
        self::assertNull($raise->rootHolder($dir, '8.4', 'found psr/log[3.0.0] but these were not loaded', []));
    }

    public function test_a_php_hop_keeps_the_raise_when_the_gate_passes_again(): void
    {
        $dir = $this->app('1.2.3');
        $runs = 0;
        $report = $this->runHop($dir, static function () use ($dir, &$runs): ProcessResult {
            $runs++;
            if ($runs === 2) {
                return new ProcessResult(1, self::FAILURE, '');
            }
            if ($runs === 3) {
                // composer update -W acme/di moved the lock to the next major.
                file_put_contents($dir . '/composer.lock', (string) json_encode(['packages' => [['name' => 'acme/di', 'version' => 'v2.0.1']]]));
            }

            return new ProcessResult(0, '', '');
        });

        self::assertSame([StepResult::APPLIED], array_map(static fn(StepResult $r): string => $r->status, $report->results));
        self::assertSame(3, $runs);
        $composer = json_decode((string) file_get_contents($dir . '/composer.json'), true);
        self::assertSame('^1.2 || ^2', $composer['require']['acme/di']);
        $all = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString('The gate failed inside acme/di on PHP 8.4, and 1.2.3 is the newest release its constraint ^1.2 allows, so Booster raised it: composer.json asks for ^1.2 || ^2, then composer update -W acme/di.', $all);
        self::assertStringContainsString('MANUAL: acme/di moved from 1.x to 2.x (locked 2.0.1) and the gate passed again. A new major can change behavior the tests and replay do not cover: read its upgrade notes before release (https://github.com/acme/di/tree/2.0.1).', $all);
        self::assertStringNotContainsString('Gate failed', $all);
    }

    public function test_a_raise_that_does_not_pass_is_backed_out(): void
    {
        $dir = $this->app('1.2.3');
        $composerAfterGate = null;
        $lockBefore = (string) file_get_contents($dir . '/composer.lock');
        $runs = 0;
        $report = $this->runHop($dir, static function () use ($dir, &$runs, &$composerAfterGate): ProcessResult {
            $runs++;
            if ($runs === 2) {
                // What the hop's own edit (the PHP constraint) left for the gate.
                $composerAfterGate = (string) file_get_contents($dir . '/composer.json');
            }
            if ($runs === 3) {
                file_put_contents($dir . '/composer.lock', '{"packages": [{"name": "acme/di", "version": "2.0.1"}]}');
            }

            return $runs === 1 ? new ProcessResult(0, '', '') : new ProcessResult(1, self::FAILURE, '');
        });

        // One raise per hop: baseline, the gate, the raised gate, and no more.
        self::assertSame(3, $runs);
        self::assertSame([StepResult::GATE_FAILED], array_map(static fn(StepResult $r): string => $r->status, $report->results));
        // Back to what the first gate saw: the hop's PHP raise stays, the package raise goes.
        self::assertSame($composerAfterGate, (string) file_get_contents($dir . '/composer.json'));
        self::assertSame('^1.2', json_decode((string) $composerAfterGate, true)['require']['acme/di']);
        self::assertSame($lockBefore, (string) file_get_contents($dir . '/composer.lock'));
        $all = implode("\n", $report->results[0]->messages);
        self::assertStringContainsString('so Booster raised it: composer.json asks for ^1.2 || ^2, then composer update -W acme/di. The gate still failed with acme/di 2.x, so composer.json and composer.lock were put back (run composer install before the next try).', $all);
        self::assertStringNotContainsString('MANUAL: acme/di', $all);
    }

    public function test_without_packagist_the_gate_fails_as_before(): void
    {
        $dir = $this->app('1.2.3');
        $runs = 0;
        $report = $this->runHop($dir, static function () use (&$runs): ProcessResult {
            $runs++;

            return $runs === 1 ? new ProcessResult(0, '', '') : new ProcessResult(1, self::FAILURE, '');
        }, false);

        self::assertSame(2, $runs);
        self::assertSame([StepResult::GATE_FAILED], array_map(static fn(StepResult $r): string => $r->status, $report->results));
        self::assertStringNotContainsString('raised', implode("\n", $report->results[0]->messages));
    }

    /**
     * Runs a PHP 8.3 to 8.4 plan on $dir, answering every docker run with
     * $gate (the first is the baseline) and every Rector call with one change.
     */
    private function runHop(string $dir, \Closure $gate, bool $packagist = true): RunReport
    {
        $fake = new FakeProcessRunner(static function (array $command) use ($gate): ProcessResult {
            if ($command[0] === 'git') {
                return new ProcessResult(0, '', '');
            }
            if ($command[0] === 'docker') {
                return ($command[1] ?? '') === 'run' ? $gate() : new ProcessResult(0, '', '');
            }

            return new ProcessResult(2, (string) json_encode(['file_diffs' => [['file' => '/tmp/x/src/F.php', 'diff' => "--- a\n+++ b"]], 'errors' => []]), '');
        });
        $project = (new ProjectDetector())->detect($dir);
        $plan = (new Planner($this->policy(), new HopRegistry()))->plan($project, new PlanOptions(fromPhp: '8.3', toPhp: '8.4'), $this->today());
        $executor = new HopExecutor(
            new RectorRunner($fake, '/fake/rector'),
            new ComposerEditor(),
            new TargetImageRunner($fake),
            $fake,
            packages: $packagist ? new FixturePackageFetcher([self::URL => $this->document()]) : null,
        );

        return $executor->execute($plan, new RunOptions());
    }

    /** A raw PHP app that requires $package $constraint, locked at $locked. */
    private function app(string $locked, string $package = 'acme/di', string $constraint = '^1.2'): string
    {
        $dir = $this->copyExample('raw-php');
        file_put_contents($dir . '/composer.json', (string) json_encode([
            'name' => 'example/raw-php',
            'require' => ['php' => '^8.2', $package => $constraint],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        file_put_contents($dir . '/composer.lock', (string) json_encode([
            'packages' => [['name' => $package, 'version' => $locked]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        return $dir;
    }

    /** @return array<string, mixed> a p2 document for acme/di, newest first */
    private function document(): array
    {
        return ['packages' => ['acme/di' => [
            ['name' => 'acme/di', 'version' => '2.0.1', 'require' => ['php' => '>=8.1'], 'support' => ['source' => 'https://github.com/acme/di/tree/2.0.1']],
            ['name' => 'acme/di', 'version' => '2.0.0'],
            ['name' => 'acme/di', 'version' => '2.0.0-beta1'],
            ['name' => 'acme/di', 'version' => '1.2.3', 'require' => ['php' => '>=7.4'], 'support' => ['source' => 'https://github.com/acme/di/tree/1.2.3']],
            ['name' => 'acme/di', 'version' => '1.2.0'],
        ]]];
    }
}
