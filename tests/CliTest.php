<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Symfony\Component\Console\Tester\ApplicationTester;
use Waynestate\Booster\Console\BoosterApplication;
use Waynestate\Booster\Run\ProcessResult;

final class CliTest extends TestCase
{
    /**
     * @param array<string, mixed> $input
     *
     * @return array{int, string}
     */
    private function booster(array $input, ?FakeProcessRunner $fake = null): array
    {
        $app = new BoosterApplication($fake);
        $app->setAutoExit(false);
        $tester = new ApplicationTester($app);
        $exit = $tester->run($input, ['capture_stderr_separately' => true]);

        return [$exit, $tester->getDisplay() . $tester->getErrorOutput()];
    }

    public function test_plan_prints_the_hops_in_order(): void
    {
        [$exit, $out] = $this->booster(['command' => 'plan', '--path' => $this->example('raw-php'), '--today' => self::TODAY, '--goal' => 'php-74-to-84']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('1. php-74-to-80', $out);
        self::assertStringContainsString('5. php-83-to-84', $out);
        self::assertStringNotContainsString('php-84-to-85', $out);
    }

    public function test_plan_json_is_machine_readable(): void
    {
        [$exit, $out] = $this->booster(['command' => 'plan', '--path' => $this->example('laravel-blade'), '--today' => self::TODAY, '--format' => 'json']);
        $data = json_decode($out, true);

        self::assertSame(0, $exit);
        self::assertSame('laravel-blade', $data['project']['app_type']);
        self::assertSame('laravel-10-to-11', $data['steps'][0]['hop']['id']);
        self::assertSame('bundled snapshot', $data['policy']);
    }

    public function test_the_policy_option_replaces_the_bundled_copy(): void
    {
        $policy = $this->tempDir() . '/policy.json';
        $json = json_decode((string) file_get_contents(dirname(__DIR__) . '/src/Creed/policy.json'), true);
        $json['components']['php']['versions']['8.5']['security_until'] = '2020-01-01';
        $json['components']['php']['versions']['8.5']['active_until'] = '2020-01-01';
        file_put_contents($policy, json_encode($json));

        [$exit, $out] = $this->booster(['command' => 'plan', '--path' => $this->example('raw-php'), '--today' => self::TODAY, '--policy' => $policy, '--format' => 'json']);
        $data = json_decode($out, true);

        self::assertSame(0, $exit);
        self::assertSame('8.4', $data['target_php']);
        self::assertSame($policy, $data['policy']);
    }

    public function test_a_missing_policy_file_is_exit_2(): void
    {
        [$exit, $out] = $this->booster(['command' => 'plan', '--path' => $this->example('raw-php'), '--policy' => '/nonexistent/policy.json']);

        self::assertSame(2, $exit);
        self::assertStringContainsString('does not exist', $out);
    }

    public function test_bad_options_are_exit_2(): void
    {
        foreach ([
            ['--format' => 'xml'],
            ['--today' => 'yesterday'],
            ['--goal' => 'nonsense'],
            ['--to-php' => '8.1'],
        ] as $bad) {
            [$exit] = $this->booster(['command' => 'plan', '--path' => $this->example('raw-php'), '--today' => self::TODAY, ...$bad]);
            self::assertSame(2, $exit, json_encode($bad));
        }
    }

    public function test_an_unknown_command_is_exit_2(): void
    {
        [$exit, $out] = $this->booster(['command' => 'upgrade-everything']);

        self::assertSame(2, $exit);
        self::assertStringContainsString('not defined', $out);
    }

    public function test_hops_lists_every_hop_and_marks_playbooks(): void
    {
        [$exit, $out] = $this->booster(['command' => 'hops']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('php-74-to-80', $out);
        self::assertStringContainsString('node-pin-24', $out);
        self::assertStringContainsString('vue-2-to-3', $out);
        self::assertStringContainsString('[playbook]', $out);

        [, $json] = $this->booster(['command' => 'hops', '--format' => 'json']);
        $hops = json_decode($json, true)['hops'];
        self::assertCount(24, $hops);
        $pin = null;
        foreach ($hops as $hop) {
            if ($hop['id'] === 'node-pin-24') {
                $pin = $hop;
            }
        }
        self::assertNotNull($pin);
        self::assertFalse($pin['manual']);
    }

    public function test_detect_names_the_permutation(): void
    {
        [$exit, $out] = $this->booster(['command' => 'detect', '--path' => $this->example('laravel-inertia-vue')]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('laravel-inertia-vue', $out);
    }

    public function test_run_dry_run_prints_the_rector_diff_and_exits_0(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(2, (string) json_encode([
            'file_diffs' => [['file' => '/x/src/Legacy.php', 'diff' => "-old\n+new"]],
        ]), ''));

        [$exit, $out] = $this->booster([
            'command' => 'run', '--path' => $this->example('raw-php'), '--today' => self::TODAY, '--dry-run' => true, '--goal' => 'php-74-to-84',
        ], $fake);

        self::assertSame(0, $exit);
        self::assertStringContainsString('dry run (nothing was changed)', $out);
        self::assertStringContainsString('+new', $out);
    }

    public function test_run_exits_1_when_a_hop_is_capped(): void
    {
        $files = array_fill(0, 3, ['file' => '/x/a.php', 'diff' => 'd']);
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(2, (string) json_encode(['file_diffs' => $files]), ''));

        [$exit, $out] = $this->booster([
            'command' => 'run', '--path' => $this->example('raw-php'), '--today' => self::TODAY, '--dry-run' => true, '--max-changes' => '2', '--goal' => 'php-74-to-84',
        ], $fake);

        self::assertSame(1, $exit);
        self::assertStringContainsString('CAPPED', $out);
    }

    public function test_dry_run_reports_unique_files_and_a_closing_line(): void
    {
        $files = [['file' => '/x/src/A.php', 'diff' => 'd'], ['file' => '/x/src/B.php', 'diff' => 'd']];
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(2, (string) json_encode(['file_diffs' => $files]), ''));

        [$exit, $out] = $this->booster([
            'command' => 'run', '--path' => $this->example('raw-php'), '--today' => self::TODAY, '--dry-run' => true, '--goal' => 'php-74-to-84',
        ], $fake);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Unique files across hops: 2', $out);
        self::assertStringContainsString('Dry run: these changes were previewed, none were applied.', $out);
    }

    public function test_dry_run_with_no_changes_reports_no_unique_files(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(2, (string) json_encode(['file_diffs' => []]), ''));

        [$exit, $out] = $this->booster([
            'command' => 'run', '--path' => $this->example('raw-php'), '--today' => self::TODAY, '--dry-run' => true, '--goal' => 'php-74-to-84',
        ], $fake);

        self::assertSame(0, $exit);
        self::assertStringNotContainsString('Unique files across hops', $out);
        self::assertStringNotContainsString('these changes were previewed', $out);
    }

    public function test_run_rejects_a_bad_cap(): void
    {
        [$exit] = $this->booster(['command' => 'run', '--path' => $this->example('raw-php'), '--max-changes' => 'lots']);

        self::assertSame(2, $exit);
    }

    public function test_run_json_contains_the_baseline_object(): void
    {
        $fake = new FakeProcessRunner(static function (array $command): ProcessResult {
            if ($command[0] === 'git') {
                return new ProcessResult(0, '', '');
            }
            if ($command[0] === 'docker') {
                return new ProcessResult(0, '', '');
            }

            return new ProcessResult(2, (string) json_encode(['file_diffs' => [], 'errors' => []]), '');
        });

        [$exit, $out] = $this->booster([
            'command' => 'run', '--path' => $this->copyExample('raw-php'), '--today' => self::TODAY, '--format' => 'json', '--goal' => 'php-74-to-84',
        ], $fake);
        $data = json_decode($out, true);

        self::assertSame(0, $exit);
        self::assertArrayHasKey('baseline', $data);
        self::assertSame('passed', $data['baseline']['status']);
        self::assertSame('7.4', $data['baseline']['php']);
        self::assertSame(0, $data['baseline']['exit_code']);
    }

    public function test_run_exits_1_when_the_baseline_fails(): void
    {
        $dockerRuns = 0;
        $fake = new FakeProcessRunner(static function (array $command) use (&$dockerRuns): ProcessResult {
            if ($command[0] === 'git') {
                return new ProcessResult(0, '', '');
            }
            if ($command[0] === 'docker' && ($command[1] ?? '') === 'run') {
                // The first docker run is the baseline; make it fail.
                $exit = $dockerRuns === 0 ? 1 : 0;
                $dockerRuns++;

                return new ProcessResult($exit, $exit !== 0 ? 'FAILURES!' : '', '');
            }
            if ($command[0] === 'docker') {
                return new ProcessResult(0, '', '');
            }

            return new ProcessResult(2, (string) json_encode(['file_diffs' => [], 'errors' => []]), '');
        });

        [$exit, $out] = $this->booster([
            'command' => 'run', '--path' => $this->copyExample('raw-php'), '--today' => self::TODAY, '--goal' => 'php-74-to-84',
        ], $fake);

        self::assertSame(1, $exit);
        self::assertStringContainsString('Baseline: FAIL', $out);
        self::assertStringContainsString('--allow-failing-baseline', $out);
    }

    public function test_hostile_file_names_cannot_inject_terminal_escapes(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(2, (string) json_encode([
            'file_diffs' => [['file' => "/x/src/\e[2Jevil.php", 'diff' => "+\e]0;owned\x07 ok"]],
        ]), ''));

        [, $out] = $this->booster([
            'command' => 'run', '--path' => $this->example('raw-php'), '--today' => self::TODAY, '--dry-run' => true, '--goal' => 'php-74-to-84',
        ], $fake);

        self::assertStringNotContainsString("\e", $out);
        self::assertStringNotContainsString("\x07", $out);
    }

    public function test_init_writes_playbooks_and_check_passes_then_fails_when_stale(): void
    {
        $dir = $this->tempDir();

        [$exit] = $this->booster(['command' => 'init', '--path' => $dir]);
        self::assertSame(0, $exit);
        self::assertFileExists($dir . '/.agents/skills/booster/SKILL.md');
        self::assertFileExists($dir . '/.agents/skills/booster-php-74-to-80/SKILL.md');

        [$exit, $out] = $this->booster(['command' => 'init', '--path' => $dir, '--check' => true]);
        self::assertSame(0, $exit);
        self::assertStringContainsString('current', $out);

        file_put_contents($dir . '/.agents/skills/booster-php-74-to-80/SKILL.md', 'edited by hand');
        [$exit, $out] = $this->booster(['command' => 'init', '--path' => $dir, '--check' => true]);
        self::assertSame(1, $exit);
        self::assertStringContainsString('stale: booster-php-74-to-80/SKILL.md', $out);
    }

    public function test_init_check_changes_nothing(): void
    {
        $dir = $this->tempDir();
        [$exit] = $this->booster(['command' => 'init', '--path' => $dir, '--check' => true]);

        self::assertSame(1, $exit);
        self::assertDirectoryDoesNotExist($dir . '/.agents');
    }

    public function test_the_committed_playbooks_are_current(): void
    {
        [$exit, $out] = $this->booster(['command' => 'init', '--path' => dirname(__DIR__), '--check' => true]);

        self::assertSame(0, $exit, $out);
    }
}
