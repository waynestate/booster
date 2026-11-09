<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Plan\Plan;
use Waynestate\Booster\Plan\PlanOptions;
use Waynestate\Booster\Plan\Planner;
use Waynestate\Booster\Project\ProjectDetector;
use Waynestate\Booster\Report\TextWriter;
use Waynestate\Booster\Run\ComposerEditor;
use Waynestate\Booster\Run\HopExecutor;
use Waynestate\Booster\Run\ProcessResult;
use Waynestate\Booster\Run\RectorRunner;
use Waynestate\Booster\Run\RunOptions;
use Waynestate\Booster\Run\RunReport;
use Waynestate\Booster\Run\StepResult;
use Waynestate\Booster\Run\StrictSession;
use Waynestate\Booster\Run\TargetImageRunner;

/**
 * `booster plan` and `booster run` with --strict-session on the replay
 * fixture, a raw PHP 7.4 notes app with one session_start(): the hop is
 * opt-in, goes first on the PHP the app runs today, has its own gate, and
 * plans nothing once the app is covered.
 */
final class StrictSessionHopTest extends TestCase
{
    private const ID = HopRegistry::SESSION_STRICT_MODE_ID;

    private function app(?callable $edit = null): string
    {
        $dir = $this->tempDir();
        $from = __DIR__ . '/fixtures/replay-app';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS)) as $file) {
            self::assertInstanceOf(SplFileInfo::class, $file);
            $target = $dir . substr($file->getPathname(), strlen($from));
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0775, true);
            }
            copy($file->getPathname(), $target);
        }
        if ($edit !== null) {
            $edit($dir);
        }

        return $dir;
    }

    private function plan(string $dir, PlanOptions $options): Plan
    {
        return (new Planner($this->policy(), new HopRegistry()))->plan((new ProjectDetector())->detect($dir), $options, $this->today());
    }

    /** @return list<string> */
    private static function ids(Plan $plan): array
    {
        return array_map(static fn($step): string => $step->hop->id, $plan->steps);
    }

    private function fake(): FakeProcessRunner
    {
        $docker = ReplayFake::runner([]);

        return new FakeProcessRunner(static function (array $command) use ($docker): ProcessResult {
            if (in_array($command[0], ['git', 'docker'], true)) {
                return $docker->run($command);
            }

            return new ProcessResult(2, (string) json_encode(['file_diffs' => [['file' => '/tmp/x/src/app.php', 'diff' => "--- a\n+++ b"]], 'errors' => []]), '');
        });
    }

    private function execute(Plan $plan, FakeProcessRunner $fake, RunOptions $options): RunReport
    {
        return (new HopExecutor(new RectorRunner($fake, '/fake/rector'), new ComposerEditor(), new TargetImageRunner($fake, dirname(__DIR__) . '/docker'), $fake))->execute($plan, $options);
    }

    public function test_the_flag_puts_the_hop_first_on_the_current_php(): void
    {
        $plan = $this->plan($this->app(), new PlanOptions(fromPhp: '7.4', toPhp: '8.0', strictSession: true, noPackageCheck: true));

        self::assertSame([self::ID, 'php-74-to-80'], self::ids($plan));
        self::assertSame(['7.4', '7.4'], [$plan->steps[0]->phpBefore, $plan->steps[0]->phpAfter]);
    }

    public function test_without_the_flag_the_plan_only_suggests_it(): void
    {
        $plan = $this->plan($this->app(), new PlanOptions(fromPhp: '7.4', toPhp: '8.0', noPackageCheck: true));

        self::assertSame(['php-74-to-80'], self::ids($plan));
        self::assertContains('The app starts PHP sessions without strict session IDs (1 session_start() call in 1 file). Add --strict-session to plan the hop that turns them on.', $plan->warnings);
    }

    public function test_skip_leaves_it_out_without_a_suggestion(): void
    {
        $plan = $this->plan($this->app(), new PlanOptions(fromPhp: '7.4', toPhp: '8.0', strictSession: true, skip: [self::ID], noPackageCheck: true));

        self::assertSame(['php-74-to-80'], self::ids($plan));
        self::assertSame([], array_filter($plan->warnings, static fn(string $w): bool => str_contains($w, 'strict session')));
    }

    public function test_only_runs_just_the_session_hop(): void
    {
        $plan = $this->plan($this->app(), new PlanOptions(fromPhp: '7.4', toPhp: '8.0', strictSession: true, noPackageCheck: true, only: self::ID));

        self::assertSame([self::ID], self::ids($plan));
    }

    public function test_a_laravel_app_is_skipped_with_a_reason(): void
    {
        $plan = $this->plan($this->copyExample('laravel-blade'), new PlanOptions(strictSession: true, noPackageCheck: true));

        self::assertNotContains(self::ID, self::ids($plan));
        self::assertContains("Asked for strict sessions, but this is a Laravel app: Laravel runs its own session store and issues its own IDs, so PHP's session settings do not apply; skipped.", $plan->warnings);
    }

    public function test_a_laravel_app_gets_no_suggestion_without_the_flag(): void
    {
        $plan = $this->plan($this->copyExample('laravel-blade'), new PlanOptions(noPackageCheck: true));

        self::assertSame([], array_filter($plan->warnings, static fn(string $w): bool => str_contains($w, 'strict session')));
    }

    public function test_a_stop_sign_leaves_the_hop_out_and_names_it(): void
    {
        $dir = $this->app(static function (string $dir): void {
            file_put_contents($dir . '/public/link.php', "<?php\necho '<a href=\"index.php?' . SID . '\">Notes</a>';\n");
        });

        $plan = $this->plan($dir, new PlanOptions(fromPhp: '7.4', toPhp: '7.4', strictSession: true, noPackageCheck: true));

        self::assertSame([], self::ids($plan));
        self::assertContains('The session-strict-mode hop was left out: strict mode would change how this app hands out session IDs. Check this stop sign, then set the two options by hand where it is safe:', $plan->warnings);
        self::assertContains('Stop sign: public/link.php:2 uses the SID constant, which carries the session ID in URLs; strict mode with cookie-only IDs would drop those sessions.', $plan->warnings);
    }

    public function test_an_app_that_boots_helmet_is_skipped(): void
    {
        $dir = $this->app(static function (string $dir): void {
            file_put_contents($dir . '/public/boot.php', "<?php\nhelmet_boot();\n");
        });

        $plan = $this->plan($dir, new PlanOptions(fromPhp: '7.4', toPhp: '7.4', strictSession: true, noPackageCheck: true));

        self::assertSame([], self::ids($plan));
        self::assertContains('Asked for strict sessions, but the app calls helmet_boot() (public/boot.php:2), which turns strict session IDs on. Check that every page that starts a session boots HELMET first; skipped.', $plan->warnings);
    }

    public function test_a_dry_run_previews_and_changes_nothing(): void
    {
        $dir = $this->app();
        $before = file_get_contents($dir . '/src/app.php');
        $plan = $this->plan($dir, new PlanOptions(fromPhp: '7.4', toPhp: '7.4', strictSession: true, noPackageCheck: true));

        $report = $this->execute($plan, $this->fake(), new RunOptions(dryRun: true));

        $result = $report->results[0];
        self::assertSame([self::ID, StepResult::PREVIEWED], [$result->hopId, $result->status]);
        self::assertStringStartsWith('Dry run: would edit src/app.php: strict session IDs set before its session_start() call (line ', $result->messages[0]);
        self::assertSame($before, file_get_contents($dir . '/src/app.php'));
        self::assertStringContainsString(self::ID, (new TextWriter())->run($report, $dir, 'booster'));
    }

    public function test_a_real_run_edits_the_app_and_gates_it_on_the_current_php(): void
    {
        $dir = $this->app();
        $plan = $this->plan($dir, new PlanOptions(fromPhp: '7.4', toPhp: '7.4', strictSession: true, noPackageCheck: true));

        $report = $this->execute($plan, $this->fake(), new RunOptions());

        $result = $report->results[0];
        self::assertSame(StepResult::APPLIED, $result->status, implode("\n", $result->messages));
        self::assertContains('Gate passed: syntax check is clean on PHP 7.4.', $result->messages);
        self::assertStringContainsString('MANUAL: Sign in, use a page that keeps session data, and sign out on pre-production.', implode("\n", $result->messages));
        self::assertSame(1, substr_count((string) file_get_contents($dir . '/src/app.php'), StrictSession::GUARD));

        // Run it again: the app is covered, so the plan carries nothing.
        $again = $this->plan($dir, new PlanOptions(fromPhp: '7.4', toPhp: '7.4', strictSession: true, noPackageCheck: true));
        self::assertSame([], self::ids($again));
        self::assertContains('Asked for strict sessions, but every session_start() call already runs with them; skipped.', $again->warnings);
    }

    public function test_an_app_covered_between_plan_and_run_has_nothing_to_do(): void
    {
        $dir = $this->app();
        $plan = $this->plan($dir, new PlanOptions(fromPhp: '7.4', toPhp: '7.4', strictSession: true, noPackageCheck: true));
        (new StrictSession())->migrate($dir, false);
        $fake = $this->fake();

        $report = $this->execute($plan, $fake, new RunOptions());

        self::assertSame(StepResult::NOTHING_TO_DO, $report->results[0]->status);
        self::assertContains('The gate was skipped: nothing changed.', $report->results[0]->messages);
    }

    public function test_a_stop_sign_found_at_run_time_refuses_the_step(): void
    {
        $dir = $this->app();
        $plan = $this->plan($dir, new PlanOptions(fromPhp: '7.4', toPhp: '7.4', strictSession: true, noPackageCheck: true));
        file_put_contents($dir . '/public/resume.php', "<?php\nsession_id(\$_GET['sid']);\n");
        $before = file_get_contents($dir . '/src/app.php');

        $report = $this->execute($plan, $this->fake(), new RunOptions());

        self::assertSame(StepResult::ERROR, $report->results[0]->status);
        self::assertSame('Refused: strict mode would change how this app hands out session IDs, so nothing changed.', $report->results[0]->messages[0]);
        self::assertSame($before, file_get_contents($dir . '/src/app.php'));
    }
}
