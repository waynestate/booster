<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Waynestate\Booster\Refresh\HopDataBot;
use Waynestate\Booster\Run\ProcessResult;
use Waynestate\Creed\Bot\HttpTransport;

/**
 * Exercises the hop data robot offline: the command seam is a
 * FakeProcessRunner and the GitLab API seam is a fake HttpTransport, so no
 * test touches git, the network or a remote.
 */
final class HopDataBotTest extends TestCase
{
    private const TOKEN = 's3cr3t-token-value';

    /**
     * The CI environment every real run needs, with an invented host and
     * project.
     *
     * @var array<string, string>
     */
    private const CI_ENVIRONMENT = [
        'CI_SERVER_PROTOCOL' => 'https',
        'CI_SERVER_HOST' => 'git.example.com',
        'CI_SERVER_PORT' => '443',
        'CI_PROJECT_PATH' => 'acme/booster',
        'CI_DEFAULT_BRANCH' => 'main',
        'CI_API_V4_URL' => 'https://git.example.com/api/v4',
        'CI_PROJECT_ID' => '42',
        HopDataBot::TOKEN_ENV => self::TOKEN,
    ];

    /**
     * @var array<string, string|false>
     */
    private array $savedEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::CI_ENVIRONMENT as $name => $value) {
            $this->savedEnvironment[$name] = getenv($name);
            putenv("{$name}={$value}");
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnvironment as $name => $value) {
            $value === false ? putenv($name) : putenv("{$name}={$value}");
        }
        $this->savedEnvironment = [];
        parent::tearDown();
    }

    public function test_unchanged_data_does_nothing_and_touches_no_remote(): void
    {
        $runner = $this->commandFake(new ProcessResult(0, "data/versions.json is current (checked against the live feeds as of 2026-10-28).\n", ''));
        $http = $this->httpFake([]);
        [$bot, $err] = $this->makeBot($runner, $http);
        [$code, $out] = $this->runBot($bot, $err);

        self::assertSame(0, $code);
        self::assertStringContainsString('nothing to do', $out);
        self::assertCount(1, $runner->calls);
        self::assertSame([], $http->calls);
    }

    public function test_changed_data_opens_a_merge_request_on_booster_itself(): void
    {
        $report = "data/versions.json updated:\n  node_pin_target: 24 -> 26\n  laravel_phpunit[10]: 9 -> 10\n";
        $runner = $this->commandFake(new ProcessResult(3, $report, ''));
        $http = $this->httpFake([]);
        [$bot, $err] = $this->makeBot($runner, $http);
        [$code, $out] = $this->runBot($bot, $err);

        self::assertSame(0, $code);

        $git = array_values(array_filter($runner->calls, static fn(array $call): bool => ($call['command'][0] ?? '') === 'git'));
        self::assertCount(5, $git);
        self::assertSame(['git', 'checkout', '-B', HopDataBot::BRANCH], $git[0]['command']);
        self::assertSame(['git', 'add', 'data/versions.json'], $git[1]['command']);
        self::assertSame(['git', 'diff', '--cached', '--name-only'], $git[2]['command']);
        self::assertSame([
            'git',
            '-c',
            'user.name=' . HopDataBot::COMMITTER_NAME,
            '-c',
            'user.email=' . HopDataBot::COMMITTER_EMAIL,
            'commit',
            '-m',
            'Hop data: refresh versions.json (' . self::TODAY . ')',
        ], $git[3]['command']);

        $push = $git[4]['command'];
        self::assertSame('git', $push[0]);
        self::assertSame('push', $push[1]);
        self::assertContains('-o', $push);
        self::assertContains('merge_request.create', $push);
        self::assertContains('merge_request.target=main', $push);
        self::assertContains('merge_request.remove_source_branch', $push);
        self::assertContains('merge_request.title=Hop data: refresh versions.json (' . self::TODAY . ')', $push);
        self::assertContains(
            'merge_request.description=node_pin_target: 24 -> 26' . "\n" . 'laravel_phpunit[10]: 9 -> 10' . "\n\n" . HopDataBot::CLOSING_LINE,
            $push,
        );
        self::assertContains('https://oauth2:' . self::TOKEN . '@git.example.com:443/acme/booster.git', $push);
        self::assertContains('HEAD:refs/heads/' . HopDataBot::BRANCH, $push);
        foreach ($push as $arg) {
            self::assertStringStartsNotWith('--force-with-lease', $arg);
        }

        // The dedupe lookup went to the right place with the token header.
        self::assertCount(1, $http->calls);
        self::assertSame('GET', $http->calls[0]['method']);
        self::assertSame('https://git.example.com/api/v4/projects/42/merge_requests?state=opened&source_branch=hop-data%2Frefresh', $http->calls[0]['url']);
        self::assertSame(self::TOKEN, $http->calls[0]['headers']['PRIVATE-TOKEN']);

        // The merge request is printed for the job log.
        self::assertStringContainsString('merge request title: Hop data: refresh versions.json (' . self::TODAY . ')', $out);
        self::assertStringContainsString('node_pin_target: 24 -> 26', $out);
        self::assertStringContainsString('laravel_phpunit[10]: 9 -> 10', $out);
        self::assertStringContainsString(HopDataBot::CLOSING_LINE, $out);
    }

    public function test_the_token_never_reaches_the_output(): void
    {
        $report = "data/versions.json updated:\n  vite_version: ^8.3 -> ^8.4\n";
        $runner = $this->commandFake(new ProcessResult(3, $report, ''));
        [$bot, $err] = $this->makeBot($runner, $this->httpFake([]));
        [$code, $out, $errOut] = $this->runBot($bot, $err);

        self::assertSame(0, $code);
        self::assertStringNotContainsString(self::TOKEN, $out);
        self::assertStringNotContainsString(self::TOKEN, $errOut);
        self::assertStringContainsString('https://oauth2:[masked]@git.example.com:443/acme/booster.git', $out);
    }

    public function test_an_open_merge_request_is_updated_not_reopened(): void
    {
        $report = "data/versions.json updated:\n  node_pin_target: 24 -> 26\n";
        $runner = $this->commandFake(new ProcessResult(3, $report, ''));
        $http = $this->httpFake([['iid' => 7, 'sha' => 'abc123def456']]);
        [$bot, $err] = $this->makeBot($runner, $http);
        [$code, $out] = $this->runBot($bot, $err);

        self::assertSame(0, $code);
        self::assertStringContainsString('updating that branch instead of opening a second one', $out);

        $push = $this->pushCommand($runner);
        self::assertContains('--force-with-lease=' . HopDataBot::BRANCH . ':abc123def456', $push);
        foreach ($push as $arg) {
            self::assertStringStartsNotWith('merge_request.', $arg);
        }
    }

    public function test_a_missing_token_fails_clearly_before_touching_anything(): void
    {
        putenv(HopDataBot::TOKEN_ENV);
        $runner = $this->commandFake(new ProcessResult(3, "data/versions.json updated:\n  node_pin_target: 24 -> 26\n", ''));
        $http = $this->httpFake([]);
        [$bot, $err] = $this->makeBot($runner, $http);
        [$code, $out] = $this->runBot($bot, $err);

        self::assertSame(1, $code);
        self::assertStringContainsString('missing environment variables: ' . HopDataBot::TOKEN_ENV, $out);
        self::assertCount(0, $runner->calls);
        self::assertSame([], $http->calls);
    }

    public function test_dry_run_prints_the_plan_and_touches_no_remote(): void
    {
        $report = "data/versions.json would change:\n  node_pin_target: 24 -> 26\n";
        $runner = $this->commandFake(new ProcessResult(3, $report, ''));
        $http = $this->httpFake([]);
        [$bot, $err] = $this->makeBot($runner, $http, dryRun: true);
        [$code, $out] = $this->runBot($bot, $err);

        self::assertSame(0, $code);
        self::assertContains('--dry-run', $runner->calls[0]['command']);
        self::assertCount(1, $runner->calls);
        self::assertSame([], $http->calls);
        self::assertStringContainsString('dry run: would commit these files:', $out);
        self::assertStringContainsString('data/versions.json', $out);
        self::assertStringContainsString('dry run: would push ' . HopDataBot::BRANCH . ' and open a merge request against the default branch', $out);
        self::assertStringContainsString('merge request title: Hop data: refresh versions.json (' . self::TODAY . ')', $out);
        self::assertStringContainsString(HopDataBot::CLOSING_LINE, $out);
    }

    public function test_dry_run_with_current_data_does_nothing(): void
    {
        $runner = $this->commandFake(new ProcessResult(0, "data/versions.json is current (checked against the live feeds as of 2026-10-28).\n", ''));
        $http = $this->httpFake([]);
        [$bot, $err] = $this->makeBot($runner, $http, dryRun: true);
        [$code, $out] = $this->runBot($bot, $err);

        self::assertSame(0, $code);
        self::assertStringContainsString('dry run: nothing to do', $out);
        self::assertCount(1, $runner->calls);
        self::assertSame([], $http->calls);
    }

    public function test_an_api_error_fails_the_run_before_any_git_command(): void
    {
        $report = "data/versions.json updated:\n  node_pin_target: 24 -> 26\n";
        $runner = $this->commandFake(new ProcessResult(3, $report, ''));
        $http = $this->httpFake('service unavailable', status: 503);
        [$bot, $err] = $this->makeBot($runner, $http);
        [$code, , $errOut] = $this->runBot($bot, $err);

        self::assertSame(1, $code);
        self::assertStringContainsString('GitLab API error 503', $errOut);
        self::assertCount(1, $runner->calls);
    }

    public function test_an_unknown_refresher_exit_code_fails_the_run(): void
    {
        $runner = $this->commandFake(new ProcessResult(2, '', 'the feed at https://example.invalid/feed.xml could not be read'));
        [$bot, $err] = $this->makeBot($runner, $this->httpFake([]));
        [$code, $out] = $this->runBot($bot, $err);

        self::assertSame(1, $code);
        self::assertStringContainsString('unknown code (2)', $out);
        self::assertCount(1, $runner->calls);
    }

    #[DataProvider('changeLinesProvider')]
    public function test_change_lines_are_read_from_the_report_header(string $stdout, array $expected): void
    {
        self::assertSame($expected, HopDataBot::changeLinesFromReport($stdout));
    }

    /**
     * @return list<array{string, list<string>}>
     */
    public static function changeLinesProvider(): array
    {
        return [
            [
                "data/versions.json updated:\n  node_pin_target: 24 -> 26\n  laravel_phpunit[10]: 9 -> 10\n",
                ['node_pin_target: 24 -> 26', 'laravel_phpunit[10]: 9 -> 10'],
            ],
            [
                "data/versions.json would change:\n  vite_version: ^8.3 -> ^8.4\n",
                ['vite_version: ^8.3 -> ^8.4'],
            ],
            ["data/versions.json is current (checked against the live feeds as of 2026-10-28).\n", []],
            ['', []],
        ];
    }

    public function test_the_merge_request_title_carries_the_run_date(): void
    {
        self::assertSame('Hop data: refresh versions.json (' . self::TODAY . ')', $this->bot()->mrTitle());
    }

    public function test_the_merge_request_body_lists_the_changes_then_the_closing_line(): void
    {
        $body = $this->bot()->mrBody(['node_pin_target: 24 -> 26', 'laravel_phpunit[10]: 9 -> 10']);

        self::assertSame("node_pin_target: 24 -> 26\nlaravel_phpunit[10]: 9 -> 10\n\n" . HopDataBot::CLOSING_LINE, $body);
    }

    public function test_an_empty_change_list_yields_only_the_closing_line(): void
    {
        self::assertSame(HopDataBot::CLOSING_LINE, $this->bot()->mrBody([]));
    }

    /**
     * A bot wired to a quiet fake runner, for the pure methods.
     */
    private function bot(bool $dryRun = false): HopDataBot
    {
        $runner = $this->commandFake(new ProcessResult(0, '', ''));

        return new HopDataBot($this->tempDir(), $runner, null, dryRun: $dryRun, today: self::TODAY);
    }

    /**
     * One bot run with captured stdout and stderr.
     *
     * @param resource $stderr the memory stream the bot writes errors to
     *
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function runBot(HopDataBot $bot, $stderr): array
    {
        ob_start();
        try {
            $code = $bot->run();
        } finally {
            $out = (string) ob_get_clean();
        }
        rewind($stderr);
        $err = (string) stream_get_contents($stderr);

        return [$code, $out, $err];
    }

    /**
     * A bot plus the memory stream it writes errors to.
     *
     * @return array{0: HopDataBot, 1: resource}
     */
    private function makeBot(FakeProcessRunner $runner, ?HttpTransport $http, bool $dryRun = false): array
    {
        $stderr = fopen('php://memory', 'w+');
        $bot = new HopDataBot($this->tempDir(), $runner, $http, dryRun: $dryRun, today: self::TODAY, errorStream: $stderr);

        return [$bot, $stderr];
    }

    /**
     * A FakeProcessRunner whose refresher answer is $refresher and whose
     * git commands all succeed (the staged diff lists the data file).
     */
    private function commandFake(ProcessResult $refresher): FakeProcessRunner
    {
        return new FakeProcessRunner(static function (array $command) use ($refresher): ProcessResult {
            if (str_ends_with((string) ($command[1] ?? ''), 'refresh-hop-data.php')) {
                return $refresher;
            }
            if (($command[0] ?? '') !== 'git') {
                return new ProcessResult(127, '', 'unexpected command: ' . implode(' ', $command) . "\n");
            }
            if (in_array('diff', $command, true)) {
                return new ProcessResult(0, "data/versions.json\n", '');
            }

            return new ProcessResult(0, '', '');
        });
    }

    /**
     * A fake GitLab API seam that answers the merge request lookup with
     * $body and records every call.
     */
    private function httpFake(mixed $body, int $status = 200): object
    {
        return new class ($body, $status) implements HttpTransport {
            /**
             * @var list<array{method: string, url: string, headers: array<string, string>}>
             */
            public array $calls = [];

            public function __construct(
                private mixed $body,
                private int $status,
            ) {}

            public function request(string $method, string $url, array $headers, ?array $form): array
            {
                $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers];

                return ['status' => $this->status, 'body' => $this->body];
            }
        };
    }

    /**
     * The git push command from a recorded runner.
     *
     * @return list<string>
     */
    private function pushCommand(FakeProcessRunner $runner): array
    {
        foreach ($runner->calls as $call) {
            $command = $call['command'];
            if (($command[0] ?? '') === 'git' && in_array('push', $command, true)) {
                return $command;
            }
        }

        self::fail('no git push was recorded');
    }
}
