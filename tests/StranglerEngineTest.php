<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Project\AppType;
use Waynestate\Booster\Project\Project;
use Waynestate\Booster\Hop\HopKind;
use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Plan\Plan;
use Waynestate\Booster\Plan\PlanOptions;
use Waynestate\Booster\Plan\Planner;
use Waynestate\Booster\Plan\PlanStep;
use Waynestate\Booster\Replay\ReplayFile;
use Waynestate\Booster\Run\GateReport;
use Waynestate\Booster\Run\RunOptions;
use Waynestate\Booster\Run\StepResult;
use Waynestate\Booster\Run\Strangler\PartResult;
use Waynestate\Booster\Run\Strangler\StranglerFinish;
use Waynestate\Booster\Run\Strangler\StranglerParts;
use Waynestate\Booster\Run\Strangler\StranglerStep;
use Waynestate\Booster\Run\Strangler\StranglerStopSigns;
use Waynestate\Booster\Run\Strangler\UnwiredStranglerParts;
use Waynestate\Booster\Run\HopExecutor;
use Waynestate\Booster\Strangler\AddsComposerPackages;
use Waynestate\Booster\Strangler\Ledger;
use Waynestate\Booster\Strangler\MoveResult;
use Waynestate\Booster\Strangler\Mover;
use Waynestate\Booster\Strangler\Unit;
use Waynestate\Booster\Strangler\UnitSource;
use Waynestate\Booster\ToolError;

/**
 * The strangler engine (design section 6): the two hop faces, the
 * --to-laravel planner option, the ledger, and the executor branch, run
 * against doubles for the other lanes' parts (Step 0, the mount, the units,
 * the movers, the route table).
 */
final class StranglerEngineTest extends TestCase
{
    // Section 6: the two faces in the registry.

    public function test_the_two_faces_are_registered_as_strangler_hops(): void
    {
        $registry = new HopRegistry();
        foreach (['cakephp-to-laravel' => 'cakephp-5', 'slim-to-laravel' => 'slim-4'] as $id => $from) {
            $hop = $registry->get($id);
            self::assertSame(HopKind::Strangler, $hop->kind, $id);
            self::assertSame($from, $hop->from, $id);
            self::assertSame('laravel-13', $hop->to, $id);
            self::assertFalse($hop->isManual(), $id);
            self::assertTrue($hop->extraReview, $id);
        }
        self::assertSame('slim-to-laravel', HopRegistry::stranglerId('slim'));
        self::assertSame('cakephp', HopRegistry::stranglerFramework('cakephp-to-laravel'));
        self::assertNull(HopRegistry::stranglerId('laravel'));
        self::assertNull(HopRegistry::stranglerFramework('php-84-to-85'));
    }

    // Section 6: --to-laravel, placed last.

    public function test_to_laravel_puts_the_strangler_last(): void
    {
        $plan = $this->plan('slim', '4.15', '8.3', new PlanOptions(toPhp: '8.5', noPackageCheck: true, strangler: true));

        self::assertSame(['php-83-to-84', 'php-84-to-85', 'slim-to-laravel'], self::ids($plan));
        $last = $plan->steps[count($plan->steps) - 1];
        self::assertSame('8.5', $last->phpBefore);
        self::assertSame('13', $last->laravelAfter);
    }

    public function test_without_the_option_there_is_no_strangler(): void
    {
        $plan = $this->plan('cakephp', '5.4', '8.3', new PlanOptions(toPhp: '8.3', noPackageCheck: true));

        self::assertNotContains('cakephp-to-laravel', self::ids($plan));
    }

    public function test_only_names_the_strangler_without_the_option(): void
    {
        $plan = $this->plan('cakephp', '5.4', '8.4', new PlanOptions(toPhp: '8.4', noPackageCheck: true, only: 'cakephp-to-laravel'));

        self::assertSame(['cakephp-to-laravel'], self::ids($plan));
    }

    public function test_skip_leaves_it_out(): void
    {
        $plan = $this->plan('slim', '4.15', '8.4', new PlanOptions(toPhp: '8.4', noPackageCheck: true, strangler: true, skip: ['slim-to-laravel']));

        self::assertSame([], self::ids($plan));
    }

    public function test_php_under_the_floor_leaves_it_out_with_a_warning(): void
    {
        $plan = $this->plan('slim', '4.15', '8.2', new PlanOptions(toPhp: '8.2', noPackageCheck: true, strangler: true));

        self::assertNotContains('slim-to-laravel', self::ids($plan));
        self::assertStringContainsString('needs PHP 8.3 or newer', implode("\n", $plan->warnings));
    }

    public function test_an_lti_app_gets_no_strangler(): void
    {
        $plan = $this->plan('cakephp', '5.4', '8.4', new PlanOptions(toPhp: '8.4', noPackageCheck: true, strangler: true), ['packbackbooks/lti-1p3-tool' => '^6.0']);

        self::assertNotContains('cakephp-to-laravel', self::ids($plan));
        self::assertStringContainsString('No strangler yet for LTI apps (this app requires packbackbooks/lti-1p3-tool)', implode("\n", $plan->warnings));
    }

    public function test_a_raw_php_app_is_told_the_option_does_not_apply(): void
    {
        $plan = $this->plan(null, null, '8.4', new PlanOptions(toPhp: '8.4', noPackageCheck: true, strangler: true));

        self::assertSame([], array_values(array_filter(self::ids($plan), static fn(string $id): bool => str_ends_with($id, '-to-laravel'))));
        self::assertStringContainsString('not a CakePHP or Slim app', implode("\n", $plan->warnings));
    }

    public function test_an_app_in_the_middle_of_the_move_plans_only_the_strangler(): void
    {
        $dir = $this->tempDir();
        Ledger::start('slim', '2026-11-20T17:00:00-05:00', Ledger::STEP0_DONE, [self::unit('GET /health'), self::unit('GET /users')])->save($dir);
        $ledger = Ledger::load($dir);
        self::assertNotNull($ledger);
        $ledger->mark('GET /health', Ledger::LARAVEL, '2026-11-20T18:00:00-05:00');
        $ledger->save($dir);

        $plan = $this->plan('slim', '4.15', '8.3', new PlanOptions(toPhp: '8.5', noPackageCheck: true), [], $dir);

        self::assertSame(['slim-to-laravel'], self::ids($plan));
        self::assertTrue($plan->stopsEarly);
        self::assertStringContainsString('booster-strangler.json: 1 of 2 units on Laravel', implode("\n", $plan->warnings));
        self::assertStringContainsString('--path=<app>/legacy', implode("\n", $plan->warnings));
    }

    // Section 6: the ledger.

    public function test_the_ledger_round_trips_and_keeps_moved_at_only_for_moved_units(): void
    {
        $dir = $this->tempDir();
        $ledger = Ledger::start('cakephp', '2026-11-20T17:00:00-05:00', Ledger::STEP0_DONE, [self::unit('GET /a'), self::unit('FORM /b', 'form')]);
        $ledger->mark('GET /a', Ledger::LARAVEL, '2026-11-20T18:00:00-05:00');
        $ledger->mark('FORM /b', Ledger::FAILED, '2026-11-20T18:00:00-05:00');
        $ledger->save($dir);

        $raw = (string) file_get_contents($dir . '/booster-strangler.json');
        self::assertStringEndsWith("\n", $raw);
        $data = json_decode($raw, true);
        self::assertSame(['framework', 'mounted_at', 'step0', 'units'], array_keys($data));
        self::assertSame(['id' => 'GET /a', 'state' => 'laravel', 'moved_at' => '2026-11-20T18:00:00-05:00'], $data['units'][0]);
        self::assertSame(['id' => 'FORM /b', 'state' => 'failed'], $data['units'][1]);

        $again = Ledger::load($dir);
        self::assertNotNull($again);
        self::assertSame($ledger->toArray(), $again->toArray());
        self::assertSame(['FORM /b'], $again->left());
        self::assertSame(['legacy' => 0, 'laravel' => 1, 'hand' => 0, 'failed' => 1], $again->counts());
    }

    public function test_sync_adds_new_units_and_keeps_moved_ones_the_legacy_app_dropped(): void
    {
        $ledger = Ledger::start('slim', 'now', Ledger::STEP0_DONE, [self::unit('GET /a'), self::unit('GET /b'), self::unit('GET /c')]);
        $ledger->mark('GET /b', Ledger::LARAVEL, 'then');

        $sync = $ledger->sync([self::unit('GET /a'), self::unit('GET /d')]);

        self::assertSame(['GET /d'], $sync['added']);
        self::assertSame(['GET /c'], $sync['dropped']);
        // The legacy app's order first; a moved unit it dropped stays, at the end.
        self::assertSame(['GET /a', 'GET /d', 'GET /b'], array_map(static fn(array $u): string => $u['id'], $ledger->units()));
    }

    public function test_a_broken_ledger_is_a_tool_error(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/booster-strangler.json', json_encode(['framework' => 'slim', 'mounted_at' => 'x', 'step0' => 'done', 'units' => [['id' => 'a', 'state' => 'moved']]]));

        $this->expectException(ToolError::class);
        Ledger::load($dir);
    }

    // Section 6: stop signs.

    public function test_the_stop_signs_before_the_mount(): void
    {
        $dir = $this->tempDir();
        mkdir($dir . '/legacy');
        file_put_contents($dir . '/artisan', '<?php');
        file_put_contents($dir . '/composer.json', json_encode(['require' => ['celtic/lti' => '^5.0']]));

        $signs = implode("\n", StranglerStopSigns::beforeMount($dir, 'slim', '3.12', '4', '8.2', true, 'no replay'));

        self::assertStringContainsString('finish the Slim 4 move first', $signs);
        self::assertStringContainsString('needs PHP 8.3 or newer', $signs);
        self::assertStringContainsString('uncommitted changes', $signs);
        self::assertStringContainsString('legacy/ is already there', $signs);
        self::assertStringContainsString('artisan is already at the app root', $signs);
        self::assertStringContainsString('no replay', $signs);
        self::assertStringContainsString('No strangler yet for LTI apps', $signs);
    }

    public function test_a_slim_skeleton_app_folder_is_not_a_stop_sign(): void
    {
        $dir = $this->tempDir();
        mkdir($dir . '/app');
        mkdir($dir . '/src');

        self::assertSame([], StranglerStopSigns::beforeMount($dir, 'slim', '4.15', '4', '8.3', false, null));
    }

    // Section 6: the executor branch, run 1.

    public function test_run_one_without_a_replay_is_refused(): void
    {
        [$step, $dir] = $this->strangler('slim');
        $parts = new FakeStranglerParts([self::unit('GET /a')]);

        $result = $this->step($parts)->run($step, $dir, new RunOptions(), '4.15', '4', 'booster run --only slim-to-laravel --replay');

        self::assertSame(StepResult::ERROR, $result->status);
        self::assertStringContainsString('The strangler requires the replay', implode("\n", $result->messages));
        self::assertSame([], $parts->calls);
    }

    public function test_run_one_does_step_zero_then_the_mount_then_stops(): void
    {
        [$step, $dir] = $this->strangler('slim');
        $parts = new FakeStranglerParts([self::unit('GET /a'), self::unit('FORM /login', 'form', 'signin', true)]);
        $gates = [];
        $signInChanges = [];

        $result = $this->step($parts, gates: $gates, signInChanges: $signInChanges)->run($step, $dir, $this->replayOptions(), '4.15', '4', 'NEXT');

        self::assertSame(StepResult::APPLIED, $result->status, implode("\n", $result->messages));
        // SignInStep::migrate runs twice: Step 0, then the Laravel half's wiring once legacy/ is there.
        self::assertSame(['signInInspect', 'signInMigrate', 'mount', 'signInMigrate', 'unitSource'], $parts->calls);
        self::assertSame([['/auth/login']], $signInChanges, 'the Step 0 gate gets the sign-in paths Step 0 changed');
        self::assertSame(['legacy', 'mounted:serve-changed'], $gates);
        $ledger = Ledger::load($dir);
        self::assertNotNull($ledger);
        self::assertSame(Ledger::STEP0_DONE, $ledger->step0());
        self::assertSame(['legacy' => 2, 'laravel' => 0, 'hand' => 0, 'failed' => 0], $ledger->counts());
        $text = implode("\n", $result->messages);
        self::assertStringContainsString('This run stops here', $text);
        self::assertStringContainsString('MANUAL (mount): Citadel public directory', $text);
        self::assertStringContainsString('Next: NEXT --moves 1', $text);
        self::assertSame('NEXT --moves 1', $result->strangler['next'] ?? null);
        self::assertContains('booster-strangler.json', array_column($result->files, 'file'));
    }

    public function test_step_zero_already_done_skips_the_migration(): void
    {
        [$step, $dir] = $this->strangler('cakephp');
        $parts = new FakeStranglerParts([self::unit('GET /a')]);
        $parts->inspect = new PartResult(PartResult::ALREADY, lines: ['Signs in through SESAME already.']);
        $gates = [];

        $result = $this->step($parts, gates: $gates)->run($step, $dir, $this->replayOptions(), '5.4', '5', 'NEXT');

        self::assertSame(StepResult::APPLIED, $result->status);
        self::assertSame(['signInInspect', 'mount', 'signInMigrate', 'unitSource'], $parts->calls);
        self::assertSame(['mounted:serve-changed'], $gates);
    }

    public function test_a_failed_mount_gate_writes_no_ledger(): void
    {
        [$step, $dir] = $this->strangler('slim');
        $parts = new FakeStranglerParts([self::unit('GET /a')]);

        $result = $this->step($parts, mountedPasses: false)->run($step, $dir, $this->replayOptions(), '4.15', '4', 'NEXT');

        self::assertSame(StepResult::GATE_FAILED, $result->status);
        self::assertFalse(Ledger::exists($dir));
        self::assertStringContainsString('no booster-strangler.json was written', implode("\n", $result->messages));
    }

    public function test_a_failed_step_zero_gate_never_mounts(): void
    {
        [$step, $dir] = $this->strangler('slim');
        $parts = new FakeStranglerParts([self::unit('GET /a')]);

        $result = $this->step($parts, legacyPasses: false)->run($step, $dir, $this->replayOptions(), '4.15', '4', 'NEXT');

        self::assertSame(StepResult::GATE_FAILED, $result->status);
        self::assertNotContains('mount', $parts->calls);
        self::assertFalse(Ledger::exists($dir));
    }

    public function test_a_dry_run_one_changes_nothing(): void
    {
        [$step, $dir] = $this->strangler('slim');
        $parts = new FakeStranglerParts([self::unit('GET /a')]);

        $result = $this->step($parts, dirty: true)->run($step, $dir, new RunOptions(dryRun: true), '4.15', '4', 'NEXT');

        self::assertSame(StepResult::PREVIEWED, $result->status, implode("\n", $result->messages));
        self::assertSame(['signInInspect'], $parts->calls);
        self::assertStringContainsString('a real run refuses here: The strangler requires the replay', implode("\n", $result->messages));
        self::assertFalse(Ledger::exists($dir));
    }

    public function test_the_unwired_build_refuses_and_changes_nothing(): void
    {
        [$step, $dir] = $this->strangler('slim');

        $result = $this->step(new UnwiredStranglerParts())->run($step, $dir, $this->replayOptions(), '4.15', '4', 'NEXT');

        self::assertSame(StepResult::ERROR, $result->status);
        self::assertStringContainsString('does not carry the strangler', implode("\n", $result->messages));
        self::assertFalse(Ledger::exists($dir));
    }

    public function test_moves_before_the_mount_are_refused(): void
    {
        [$step, $dir] = $this->strangler('slim');

        $result = $this->step(new FakeStranglerParts([]))->run($step, $dir, $this->replayOptions(moves: 2), '4.15', '4', 'NEXT');

        self::assertSame(StepResult::ERROR, $result->status);
        self::assertStringContainsString('The app is not mounted yet', implode("\n", $result->messages));
    }

    // Section 6: later runs.

    public function test_moves_takes_units_in_order_and_records_them(): void
    {
        [$step, $dir] = $this->mounted('slim', ['GET /a', 'GET /b', 'GET /c']);
        $parts = new FakeStranglerParts([self::unit('GET /a'), self::unit('GET /b'), self::unit('GET /c')]);
        $gates = [];

        $result = $this->step($parts, gates: $gates)->run($step, $dir, $this->replayOptions(moves: 2), '4.15', '4', 'NEXT');

        self::assertSame(StepResult::APPLIED, $result->status, implode("\n", $result->messages));
        self::assertSame(['GET /a', 'GET /b'], $parts->moved);
        self::assertSame(['GET /a', 'GET /b'], $parts->routes);
        self::assertSame(['mounted'], $gates);
        $ledger = Ledger::load($dir);
        self::assertNotNull($ledger);
        self::assertSame(Ledger::LARAVEL, $ledger->state('GET /a'));
        self::assertSame(Ledger::LEGACY, $ledger->state('GET /c'));
        self::assertSame(['GET /a', 'GET /b'], array_column($result->strangler['moved'] ?? [], 'id'));
        self::assertSame('Replay passed: 12 answers match.', $result->strangler['moved'][0]['replay'] ?? null);
        self::assertSame(1, $result->strangler['left']['count'] ?? null);
        self::assertStringContainsString('Next: NEXT --moves 1', implode("\n", $result->messages));
    }

    public function test_a_refused_unit_is_reported_with_file_and_line_and_the_next_one_moves(): void
    {
        [$step, $dir] = $this->mounted('slim', ['GET /a', 'GET /b']);
        $parts = new FakeStranglerParts([self::unit('GET /a'), self::unit('GET /b')]);
        $parts->refuse['GET /a'] = ['file' => 'legacy/src/Action/A.php', 'line' => 14, 'reason' => 'calls $app->getContainer() in a shape the mover does not read'];

        $result = $this->step($parts)->run($step, $dir, $this->replayOptions(), '4.15', '4', 'NEXT');

        self::assertSame(StepResult::APPLIED, $result->status);
        self::assertSame(['GET /b'], $parts->moved);
        self::assertStringContainsString('Refused GET /a: legacy/src/Action/A.php:14: calls', implode("\n", $result->messages));
        self::assertSame(14, $result->strangler['refusals'][0]['line'] ?? null);
        $ledger = Ledger::load($dir);
        self::assertNotNull($ledger);
        self::assertSame(Ledger::LEGACY, $ledger->state('GET /a'));
    }

    public function test_a_failed_gate_marks_the_moved_units_failed(): void
    {
        [$step, $dir] = $this->mounted('slim', ['GET /a', 'GET /b']);
        $parts = new FakeStranglerParts([self::unit('GET /a'), self::unit('GET /b')]);

        $result = $this->step($parts, mountedPasses: false)->run($step, $dir, $this->replayOptions(), '4.15', '4', 'NEXT');

        self::assertSame(StepResult::GATE_FAILED, $result->status);
        $ledger = Ledger::load($dir);
        self::assertNotNull($ledger);
        self::assertSame(Ledger::FAILED, $ledger->state('GET /a'));

        // A failed unit waits for a person: the next plain run moves the next legacy unit.
        $parts->moved = [];
        $this->step($parts)->run($step, $dir, $this->replayOptions(), '4.15', '4', 'NEXT');
        self::assertSame(['GET /b'], $parts->moved);

        // --route retries it.
        $parts->moved = [];
        $retry = $this->step($parts)->run($step, $dir, $this->replayOptions(route: 'GET /a'), '4.15', '4', 'NEXT');
        self::assertSame(StepResult::APPLIED, $retry->status);
        self::assertSame(['GET /a'], $parts->moved);
        self::assertStringContainsString('Next: NEXT --finish', implode("\n", $retry->messages));
    }

    public function test_an_unknown_route_lists_the_units(): void
    {
        [$step, $dir] = $this->mounted('slim', ['GET /a']);
        $parts = new FakeStranglerParts([self::unit('GET /a')]);

        $result = $this->step($parts)->run($step, $dir, $this->replayOptions(route: 'GET /nope'), '4.15', '4', 'NEXT');

        self::assertSame(StepResult::ERROR, $result->status);
        self::assertStringContainsString('--route "GET /nope" is not a unit in booster-strangler.json. The units are: GET /a.', implode("\n", $result->messages));
    }

    public function test_a_unit_that_needs_sign_in_waits_for_step_zero(): void
    {
        [$step, $dir] = $this->mounted('slim', ['FORM /account'], Ledger::STEP0_PENDING);
        $parts = new FakeStranglerParts([self::unit('FORM /account', 'form', 'signin', true)]);

        $result = $this->step($parts)->run($step, $dir, $this->replayOptions(), '4.15', '4', 'NEXT');

        self::assertSame(StepResult::ERROR, $result->status);
        self::assertSame([], $parts->moved);
        self::assertStringContainsString('Step 0 (sign-in on SESAME) is not done', implode("\n", $result->messages));
    }

    public function test_nothing_left_points_at_finish(): void
    {
        [$step, $dir] = $this->mounted('slim', ['GET /a'], Ledger::STEP0_DONE, ['GET /a' => Ledger::LARAVEL]);
        $parts = new FakeStranglerParts([self::unit('GET /a')]);

        $result = $this->step($parts)->run($step, $dir, $this->replayOptions(), '4.15', '4', 'NEXT');

        self::assertSame(StepResult::NOTHING_TO_DO, $result->status);
        self::assertStringContainsString('Next: NEXT --finish', implode("\n", $result->messages));
    }

    public function test_hand_checks_route_list_before_it_flips_the_unit(): void
    {
        [$step, $dir] = $this->mounted('slim', ['FORM /articles/add']);
        $parts = new FakeStranglerParts([self::unit('FORM /articles/add', 'form', 'write')]);
        $routes = [['methods' => ['GET', 'HEAD'], 'uri' => 'articles/add']];

        $missing = $this->step($parts, routes: $routes)->run($step, $dir, $this->replayOptions(route: 'FORM /articles/add', hand: true), '4.15', '4', 'NEXT');
        self::assertSame(StepResult::ERROR, $missing->status);
        self::assertStringContainsString('route:list has no POST articles/add yet', implode("\n", $missing->messages));
        self::assertSame([], $parts->routes);

        $routes[] = ['methods' => ['POST'], 'uri' => '/articles/add'];
        $flipped = $this->step($parts, routes: $routes)->run($step, $dir, $this->replayOptions(route: 'FORM /articles/add', hand: true), '4.15', '4', 'NEXT');
        self::assertSame(StepResult::APPLIED, $flipped->status, implode("\n", $flipped->messages));
        self::assertSame(['FORM /articles/add'], $parts->routes);
        self::assertSame([], $parts->moved, 'a hand move never calls the mover');
        $ledger = Ledger::load($dir);
        self::assertNotNull($ledger);
        self::assertSame(Ledger::HAND, $ledger->state('FORM /articles/add'));
    }

    public function test_units_move_in_the_unit_source_order_never_re_sorted(): void
    {
        [$step, $dir] = $this->mounted('slim', ['GET /b', 'GET /a']);
        // The unit source already put this write first; the engine must not re-sort by kind, order or id.
        $parts = new FakeStranglerParts([self::unit('GET /b', 'route', 'write'), self::unit('GET /a', 'route', 'stateless')]);

        $this->step($parts)->run($step, $dir, $this->replayOptions(), '4.15', '4', 'NEXT');

        self::assertSame(['GET /b'], $parts->moved);
    }

    public function test_a_move_that_adds_packages_updates_them_on_the_laravel_root_before_the_gate(): void
    {
        [$step, $dir] = $this->mounted('slim', ['GET /a']);
        $parts = new FakeStranglerParts([self::unit('GET /a')]);
        $parts->adds = ['slim/psr7' => '^1.7', 'php-di/php-di' => '^7.0'];
        $gates = [];

        $result = $this->step($parts, gates: $gates)->run($step, $dir, $this->replayOptions(), '4.15', '4', 'NEXT');

        self::assertSame(StepResult::APPLIED, $result->status, implode("\n", $result->messages));
        self::assertSame(['composer:slim/psr7 php-di/php-di', 'mounted'], $gates);
        self::assertContains('composer.lock', array_column($result->files, 'file'));
        self::assertStringContainsString('composer update -W slim/psr7 php-di/php-di on the Laravel root for GET /a.', implode("\n", $result->messages));
    }

    public function test_a_failed_composer_update_marks_the_unit_failed_and_never_gates(): void
    {
        [$step, $dir] = $this->mounted('slim', ['GET /a']);
        $parts = new FakeStranglerParts([self::unit('GET /a')]);
        $parts->adds = ['slim/psr7' => '^9.0'];
        $gates = [];
        $error = 'Problem 1: Root composer.json requires slim/psr7 ^9.0, it is satisfiable by nothing.';

        $result = $this->step($parts, gates: $gates, composerError: $error)->run($step, $dir, $this->replayOptions(), '4.15', '4', 'NEXT');

        self::assertSame(StepResult::GATE_FAILED, $result->status);
        self::assertSame(['composer:slim/psr7'], $gates, 'no gate after a failed update');
        self::assertSame([], $parts->routes, 'the route table never sends the unit to Laravel');
        $ledger = Ledger::load($dir);
        self::assertNotNull($ledger);
        self::assertSame(Ledger::FAILED, $ledger->state('GET /a'));
        self::assertSame('GET /a', $result->strangler['failed'][0]['unit'] ?? null);
        self::assertStringContainsString($error, $result->strangler['failed'][0]['reason'] ?? '');
        self::assertStringContainsString('composer update -W slim/psr7 failed on the Laravel root: Problem 1:', implode("\n", $result->messages));
    }

    public function test_the_composer_error_line_is_the_reason_under_problem_one(): void
    {
        $output = ['Loading composer repositories with package information', 'Your requirements could not be resolved to an installable set of packages.', '', '  Problem 1', '    - Root composer.json requires slim/psr7 ^9.0, it is satisfiable by nothing.'];

        self::assertSame('Problem 1: Root composer.json requires slim/psr7 ^9.0, it is satisfiable by nothing.', HopExecutor::composerErrorLine($output));
        self::assertSame('Your requirements could not be resolved to an installable set of packages.', HopExecutor::composerErrorLine(array_slice($output, 0, 3)));
        self::assertSame('[RuntimeException] Failed to execute git clone', HopExecutor::composerErrorLine(['Updating dependencies', '[RuntimeException] Failed to execute git clone']));
        self::assertSame('Killed', HopExecutor::composerErrorLine(['', 'Killed']));
        self::assertNull(HopExecutor::composerErrorLine([]));
    }

    // Section 6: finish.

    public function test_finish_is_refused_while_units_are_left(): void
    {
        [$step, $dir] = $this->mounted('cakephp', ['GET /a', 'GET /b'], Ledger::STEP0_DONE, ['GET /a' => Ledger::LARAVEL]);
        mkdir($dir . '/legacy');

        $result = $this->step(new FakeStranglerParts([]))->run($step, $dir, $this->replayOptions(finish: true), '5.4', '5', 'NEXT');

        self::assertSame(StepResult::ERROR, $result->status);
        self::assertStringContainsString('1 unit still answers from legacy (GET /b)', implode("\n", $result->messages));
        self::assertDirectoryExists($dir . '/legacy');
    }

    public function test_finish_deletes_legacy_puts_back_the_stock_front_controller_and_drops_the_ledger(): void
    {
        [$step, $dir] = $this->mounted('cakephp', ['GET /a'], Ledger::STEP0_DONE, ['GET /a' => Ledger::HAND]);
        mkdir($dir . '/legacy/src', 0777, true);
        file_put_contents($dir . '/legacy/src/A.php', '<?php');
        mkdir($dir . '/bootstrap');
        file_put_contents($dir . '/bootstrap/booster-moved.php', '<?php return [];');
        mkdir($dir . '/public');
        file_put_contents($dir . '/public/index.php', '<?php // the dispatcher');
        mkdir($dir . '/bin');
        file_put_contents($dir . '/bin/install-legacy.php', '<?php');
        file_put_contents($dir . '/composer.json', json_encode(['require' => ['laravel/framework' => '^13.0'], 'scripts' => ['post-install-cmd' => ['@php bin/install-legacy.php'], 'test' => 'phpunit']], JSON_PRETTY_PRINT));
        file_put_contents($dir . '/booster-replay.json', json_encode(['version' => 1, 'serve' => ['docroot' => 'public', 'setup' => 'composer install && composer install -d legacy'], 'requests' => []], JSON_PRETTY_PRINT));
        $parts = new FakeStranglerParts([]);
        $gates = [];

        $result = $this->step($parts, gates: $gates)->run($step, $dir, $this->replayOptions(finish: true), '5.4', '5', 'NEXT');

        self::assertSame(StepResult::APPLIED, $result->status, implode("\n", $result->messages));
        self::assertSame(['signInFinish'], $parts->calls);
        self::assertSame(['mounted:serve-changed'], $gates);
        self::assertDirectoryDoesNotExist($dir . '/legacy');
        self::assertFileDoesNotExist($dir . '/bootstrap/booster-moved.php');
        self::assertFileDoesNotExist($dir . '/bin/install-legacy.php');
        self::assertSame(StranglerFinish::STOCK_FRONT_CONTROLLER, file_get_contents($dir . '/public/index.php'));
        self::assertStringStartsWith("<?php\n\nuse Illuminate\\Foundation\\Application;", StranglerFinish::STOCK_FRONT_CONTROLLER);
        $composer = json_decode((string) file_get_contents($dir . '/composer.json'), true);
        self::assertArrayNotHasKey('post-install-cmd', $composer['scripts']);
        self::assertSame('phpunit', $composer['scripts']['test']);
        $replay = json_decode((string) file_get_contents($dir . '/booster-replay.json'), true);
        self::assertSame('composer install', $replay['serve']['setup']);
        self::assertFalse(Ledger::exists($dir));
        self::assertStringContainsString('MANUAL (finish):', implode("\n", $result->messages));
    }

    public function test_finish_is_refused_while_slim_hosted_units_remain_and_names_them(): void
    {
        [$step, $dir] = $this->mounted('slim', ['GET /login', 'GET /a'], Ledger::STEP0_DONE, ['GET /login' => Ledger::LARAVEL, 'GET /a' => Ledger::LARAVEL]);
        mkdir($dir . '/legacy');
        mkdir($dir . '/routes');
        file_put_contents($dir . '/routes/web.php', "<?php\n\n// booster:strangler GET /login from legacy/app/routes.php:9\n\Illuminate\Support\Facades\Route::match(['GET'], 'login', [\App\Http\Controllers\Legacy\Support\SlimAdapter::class, 'handle'])\n    ->defaults('booster_slim', 'bootstrap');\n\n// booster:strangler GET /a from legacy/app/routes.php:12\n\Illuminate\Support\Facades\Route::match(['GET'], 'a', [\App\Http\Controllers\Legacy\Support\SlimAdapter::class, 'handle'])\n    ->defaults('booster_slim', 'class:A');\n");

        $result = $this->step(new FakeStranglerParts([]))->run($step, $dir, $this->replayOptions(finish: true), '4.15', '4', 'NEXT');

        $text = implode("\n", $result->messages);
        self::assertSame(StepResult::ERROR, $result->status);
        self::assertStringContainsString('1 unit on Laravel still runs through Slim inside the Laravel route (GET /login)', $text);
        self::assertStringContainsString("Slim-hosted: 1 unit on Laravel still runs through Slim", $text);
        self::assertSame(['count' => 1, 'units' => ['GET /login'], 'notes' => []], $result->toArray()['strangler']['slim_hosted']);
        self::assertDirectoryExists($dir . '/legacy');
    }

    public function test_slim_hosted_manual_lines_group_under_their_own_section(): void
    {
        $report = new StranglerReport('moves', 'slim');
        $report->addManual('route moves', [SlimMover::HOSTED_PREFIX . ' FORM /login runs through Slim inside Laravel because of a role check (app/routes.php:9)', 'legacy/app/settings.php:21 __DIR__ moved']);
        $report->slimHosted = ['FORM /login'];

        self::assertSame(['legacy/app/settings.php:21 __DIR__ moved'], $report->manual['route moves']);
        self::assertSame([
            "Slim-hosted: 1 unit on Laravel still runs through Slim inside the Laravel route; port each one's check to Laravel middleware before --finish.",
            '  FORM /login',
            'SLIM-HOSTED: FORM /login runs through Slim inside Laravel because of a role check (app/routes.php:9)',
        ], $report->hostedLines());
        self::assertSame(['MANUAL (route moves): legacy/app/settings.php:21 __DIR__ moved'], $report->manualLines());
        self::assertSame([], (new StranglerReport('moves', 'cakephp'))->hostedLines());
    }

    public function test_a_failed_finish_gate_keeps_the_ledger(): void
    {
        [$step, $dir] = $this->mounted('cakephp', ['GET /a'], Ledger::STEP0_DONE, ['GET /a' => Ledger::LARAVEL]);

        $result = $this->step(new FakeStranglerParts([]), mountedPasses: false)->run($step, $dir, $this->replayOptions(finish: true), '5.4', '5', 'NEXT');

        self::assertSame(StepResult::GATE_FAILED, $result->status);
        self::assertTrue(Ledger::exists($dir));
    }

    // Section 6: the report.

    public function test_the_json_report_carries_the_strangler_section_only_on_strangler_steps(): void
    {
        [$step, $dir] = $this->mounted('slim', ['GET /a']);
        $result = $this->step(new FakeStranglerParts([self::unit('GET /a')]))->run($step, $dir, $this->replayOptions(), '4.15', '4', 'NEXT');

        $data = $result->toArray();
        self::assertSame(['phase', 'framework', 'status', 'step0', 'moved', 'left', 'counts', 'refusals', 'failed', 'stop_signs', 'manual', 'slim_hosted', 'next'], array_keys($data['strangler']));
        self::assertArrayNotHasKey('strangler', (new StepResult(1, 'php-84-to-85', StepResult::APPLIED, [], []))->toArray());
    }

    // Section 6: the command line.

    public function test_a_bare_to_laravel_is_the_opt_in_and_a_major_is_still_a_target(): void
    {
        $dir = $this->copyExample('raw-php');

        [, $bare] = self::cli(['command' => 'plan', '--path' => $dir, '--to-laravel' => null, '--no-package-check' => true, '--policy' => self::POLICY_FIXTURE, '--today' => self::TODAY]);
        self::assertStringContainsString('not a CakePHP or Slim app', $bare);

        [, $major] = self::cli(['command' => 'plan', '--path' => $dir, '--to-laravel' => '13', '--no-package-check' => true, '--policy' => self::POLICY_FIXTURE, '--today' => self::TODAY]);
        self::assertStringNotContainsString('not a CakePHP or Slim app', $major);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function badStranglerOptions(): iterable
    {
        yield 'hand without a route' => [['--hand' => true], '--hand needs --route'];
        yield 'zero moves' => [['--moves' => '0'], 'Invalid --moves value "0"'];
        yield 'finish with moves' => [['--finish' => true, '--moves' => '2'], '--finish is a run of its own'];
        yield 'moves and a route' => [['--moves' => '2', '--route' => 'GET /a'], 'use one'];
    }

    /** @param array<string, mixed> $options */
    #[\PHPUnit\Framework\Attributes\DataProvider('badStranglerOptions')]
    public function test_run_refuses_strangler_options_that_do_not_go_together(array $options, string $message): void
    {
        [$exit, $out] = self::cli(['command' => 'run', '--path' => $this->copyExample('raw-php'), '--dry-run' => true, ...$options]);

        self::assertSame(2, $exit);
        self::assertStringContainsString($message, $out);
    }

    // Helpers.

    /**
     * @param array<string, mixed> $input
     *
     * @return array{int, string}
     */
    private static function cli(array $input): array
    {
        $app = new \Waynestate\Booster\Console\BoosterApplication();
        $app->setAutoExit(false);
        $tester = new \Symfony\Component\Console\Tester\ApplicationTester($app);
        $exit = $tester->run($input, ['capture_stderr_separately' => true]);

        return [$exit, $tester->getDisplay() . $tester->getErrorOutput()];
    }

    /**
     * @param array<string, string> $require
     */
    private function plan(?string $framework, ?string $line, string $php, PlanOptions $options, array $require = [], ?string $dir = null): Plan
    {
        $dir ??= $this->tempDir();
        file_put_contents($dir . '/composer.json', json_encode(['require' => ['php' => '^' . $php, ...$require]]));
        $project = new Project(
            path: $dir,
            hasComposer: true,
            phpVersion: $php,
            laravelMajor: null,
            phpunitMajor: null,
            vueMajor: null,
            type: AppType::RawPhp,
            usesWayneCas: false,
            hasTestScript: false,
            framework: $framework,
            frameworkVersion: $line,
        );

        return (new Planner($this->policy(), new HopRegistry()))->plan($project, $options, $this->today());
    }

    /** @return list<string> */
    private static function ids(Plan $plan): array
    {
        return array_map(static fn(PlanStep $step): string => $step->hop->id, $plan->steps);
    }

    /** @return array{0: PlanStep, 1: string} the strangler step and its app folder */
    private function strangler(string $framework): array
    {
        $dir = $this->tempDir();
        $plan = $this->plan($framework, $framework === 'slim' ? '4.15' : '5.4', '8.4', new PlanOptions(toPhp: '8.4', noPackageCheck: true, strangler: true), [], $dir);
        $step = $plan->steps[count($plan->steps) - 1];
        self::assertSame(HopKind::Strangler, $step->hop->kind);

        return [$step, $dir];
    }

    /**
     * A mounted app: the strangler step and a ledger with these units.
     *
     * @param list<string>          $ids
     * @param array<string, string> $states
     *
     * @return array{0: PlanStep, 1: string}
     */
    private function mounted(string $framework, array $ids, string $step0 = Ledger::STEP0_DONE, array $states = []): array
    {
        [$step, $dir] = $this->strangler($framework);
        $ledger = Ledger::start($framework, '2026-11-20T17:00:00-05:00', $step0, array_map(static fn(string $id): Unit => self::unit($id), $ids));
        foreach ($states as $id => $state) {
            $ledger->mark($id, $state, '2026-11-20T18:00:00-05:00');
        }
        $ledger->save($dir);

        return [$step, $dir];
    }

    private function replayOptions(?int $moves = null, ?string $route = null, bool $hand = false, bool $finish = false): RunOptions
    {
        return new RunOptions(replay: ReplayFile::load(__DIR__ . '/fixtures/replay-app/booster-replay-compare.json'), moves: $moves, route: $route, hand: $hand, finish: $finish);
    }

    /**
     * @param list<string>                                         $gates  the gates the step ran, in order
     * @param list<array{methods: list<string>, uri: string}>|string $routes what route:list prints
     */
    private function step(StranglerParts $parts, bool $legacyPasses = true, bool $mountedPasses = true, bool $dirty = false, ?array &$gates = null, array|string $routes = [], ?array &$signInChanges = null, ?string $composerError = null): StranglerStep
    {
        $gates = [];
        $log = &$gates;
        $signInChanges = [];
        $changes = &$signInChanges;

        return new StranglerStep(
            $parts,
            static function (array $paths) use ($legacyPasses, &$log, &$changes): GateReport {
                $log[] = 'legacy';
                $changes[] = $paths;

                return new GateReport($legacyPasses, [$legacyPasses ? 'Replay passed: 12 answers match.' : 'Replay failed: GET / changed.']);
            },
            static function (bool $serveChanged) use ($mountedPasses, &$log): GateReport {
                $log[] = $serveChanged ? 'mounted:serve-changed' : 'mounted';

                return new GateReport($mountedPasses, [$mountedPasses ? 'Replay passed: 12 answers match.' : 'Replay failed: GET /a changed.']);
            },
            static fn(): bool => $dirty,
            static fn(): array|string => $routes,
            static fn(): string => '2026-11-20T19:00:00-05:00',
            null,
            static function (array $packages) use ($composerError, &$log): ?string {
                $log[] = 'composer:' . implode(' ', $packages);

                return $composerError;
            },
        );
    }

    private static function unit(string $id, string $kind = 'route', string $order = 'stateless', bool $needsSignIn = false): Unit
    {
        [$method, $uri] = explode(' ', $id, 2);
        $methods = $method === 'FORM' ? ['GET', 'POST'] : [$method];

        return new Unit($id, $kind, $methods, trim($uri, '/'), null, $order, $needsSignIn, 'legacy/app/routes.php', 10);
    }
}

/** The other lanes' parts, as a double: records its calls, moves what it is told to. */
final class FakeStranglerParts implements StranglerParts, UnitSource, Mover, AddsComposerPackages
{
    /** @var array<string, string> what the next move adds to the Laravel root's composer.json */
    public array $adds = [];

    public function composerAdds(): array
    {
        return $this->adds;
    }

    /** @var list<string> */
    public array $calls = [];

    /** @var list<string> */
    public array $moved = [];

    /** @var list<string> */
    public array $routes = [];

    /** @var array<string, array{file: string, line: int, reason: string}> */
    public array $refuse = [];

    public PartResult $inspect;

    /** @param list<Unit> $units */
    public function __construct(private readonly array $units)
    {
        $this->inspect = new PartResult(PartResult::OK, lines: ['The legacy half signs in with wayne-cas.']);
    }

    public function replayUnusable(string $appRoot, ?array $answers, ?string $file): ?string
    {
        return null;
    }

    public function signInInspect(string $appRoot, string $framework): PartResult
    {
        $this->calls[] = 'signInInspect';

        return $this->inspect;
    }

    public function signInMigrate(string $appRoot, string $framework): PartResult
    {
        $this->calls[] = 'signInMigrate';

        return new PartResult(PartResult::OK, ['legacy/config/app.php'], [], ['MANUAL: register the SESAME service URL.'], ['Swapped wayne-cas for SESAME.'], ['/auth/login']);
    }

    public function mount(string $appRoot, string $framework): PartResult
    {
        $this->calls[] = 'mount';

        return new PartResult(PartResult::OK, ['public/index.php', 'bootstrap/booster-moved.php'], [], ['MANUAL: Citadel public directory becomes public.'], ['Mounted Laravel at the root.']);
    }

    public function signInFinish(string $appRoot, string $framework): PartResult
    {
        $this->calls[] = 'signInFinish';

        return new PartResult(PartResult::OK, ['routes/web.php'], [], [], ['/login moved to Laravel.']);
    }

    public function unitSource(string $framework): UnitSource
    {
        $this->calls[] = 'unitSource';

        return $this;
    }

    public function mover(string $framework): Mover
    {
        return $this;
    }

    public function routeAdd(string $appRoot, Unit $unit): void
    {
        $this->routes[] = $unit->id;
    }

    public function routeRemove(string $appRoot, string $unitId): void {}

    public function routeHas(string $appRoot, string $unitId): bool
    {
        return in_array($unitId, $this->routes, true);
    }

    public function framework(): string
    {
        return 'slim';
    }

    public function units(string $appRoot): array
    {
        return $this->units;
    }

    public function move(Unit $unit, string $appRoot): MoveResult
    {
        if (isset($this->refuse[$unit->id])) {
            return new MoveResult(false, [], [$this->refuse[$unit->id]], []);
        }
        $this->moved[] = $unit->id;

        $files = ['app/Http/Controllers/Moved.php', 'routes/web.php', ...($this->adds === [] ? [] : ['composer.json'])];

        return new MoveResult(true, $files, [], ['MANUAL: check the moved view.']);
    }
}
