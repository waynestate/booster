<?php

declare(strict_types=1);

namespace Waynestate\Booster\Refresh;

use Waynestate\Booster\Run\ProcessResult;
use Waynestate\Booster\Run\ProcessRunner;
use Waynestate\Booster\ToolError;
use Waynestate\Creed\Bot\GitLabClient;
use Waynestate\Creed\Bot\HttpTransport;

/**
 * The hop data robot: the scheduled job that keeps data/versions.json
 * current without a human in the loop, the way CREED's policy robot keeps
 * policy.json current.
 *
 * One run works like this:
 *
 *   1. Run bin/refresh-hop-data.php as a subprocess through the ProcessRunner.
 *   2. Decide the outcome from the refresher's exit code:
 *
 *        exit 0  the file is current: the robot does nothing
 *        exit 3  the file changed (or would change): the robot commits the
 *                data file on the refresh branch and pushes it with merge
 *                request options, so a merge request opens on Booster
 *                itself, never on an app
 *        anything else  a hard failure: the robot stops with exit 1
 *
 * Dedupe: before pushing, the robot asks the GitLab API whether an open
 * merge request already runs from the refresh branch. When one exists, the
 * push updates that branch with --force-with-lease (the expected head sha
 * comes from the API answer) instead of opening a second merge request.
 *
 * The push goes to a token URL built from the CI_SERVER_* environment
 * variables, with the token from BOOSTER_BOT_TOKEN. Every line the robot
 * prints, including runner errors and command echoes, passes through one
 * mask function that replaces the token value with [masked], so a runner
 * error that echoes the URL back never leaks it.
 *
 * With --dry-run the refresher writes nothing and the robot prints the
 * decided outcome, the planned file, the merge request title and body, and
 * touches no remote: no git command, no API call.
 */
final readonly class HopDataBot
{
    /**
     * The branch the refresh commit lands on, pushed with merge request
     * options. A run that finds an open merge request from this branch
     * updates it instead of opening a second one.
     */
    public const BRANCH = 'hop-data/refresh';

    /**
     * The only path a hop data refresh may change; a commit stages exactly
     * this, nothing else.
     *
     * @var list<string>
     */
    public const DATA_ALLOWLIST = [
        'data/versions.json',
    ];

    /**
     * The project access token variable a person sets in the CI settings
     * (write_repository scope). Values never leave the robot unmasked.
     */
    public const TOKEN_ENV = 'BOOSTER_BOT_TOKEN';

    /**
     * The committer identity for the refresh commit, passed with -c flags
     * so the repository config is never touched.
     */
    public const COMMITTER_NAME = 'Booster hop data bot';

    /**
     * @see self::COMMITTER_NAME
     */
    public const COMMITTER_EMAIL = 'hop-data-bot@noreply.invalid';

    /**
     * The last line of every merge request body, exactly.
     */
    public const CLOSING_LINE = 'Opened by the scheduled hop-data job. Review the diff, then merge.';

    /**
     * The environment variables a real run needs, in fixed order.
     *
     * @var list<string>
     */
    private const ENVIRONMENT = [
        'CI_SERVER_PROTOCOL',
        'CI_SERVER_HOST',
        'CI_SERVER_PORT',
        'CI_PROJECT_PATH',
        'CI_DEFAULT_BRANCH',
        self::TOKEN_ENV,
        'CI_API_V4_URL',
        'CI_PROJECT_ID',
    ];

    /**
     * @param string $repoRoot the Booster checkout the robot works in
     * @param ProcessRunner $runner the command seam; a fake in tests
     * @param HttpTransport|null $http the GitLab API seam; CREED's stream
     *        transport when null
     * @param bool $dryRun print what a run would do; touch no remote
     * @param string|null $today the date in the merge request title, YYYY-MM-DD; the real today when null
     * @param resource|null $errorStream stream for error lines; STDERR by default
     */
    public function __construct(
        private string $repoRoot,
        private ProcessRunner $runner,
        private ?HttpTransport $http = null,
        private bool $dryRun = false,
        private ?string $today = null,
        private mixed $errorStream = null,
    ) {}

    /**
     * One scheduled run.
     *
     * @return int 0 on every handled outcome (nothing to do, merge request
     *         opened or updated), 1 on a hard failure
     */
    public function run(): int
    {
        // A real run owns the remote, so it needs its environment before it
        // refreshes, commits or pushes. A dry run needs none of it.
        if (!$this->dryRun) {
            $missing = $this->missingEnvironment();
            if ($missing !== []) {
                $this->say('missing environment variables: ' . implode(', ', $missing) . "\n");

                return 1;
            }
        }

        $argv = [PHP_BINARY, $this->repoRoot . '/bin/refresh-hop-data.php'];
        if ($this->dryRun) {
            $argv[] = '--dry-run';
        }

        $result = $this->runner->run($argv, $this->repoRoot);
        if ($result->stdout !== '') {
            $this->say($result->stdout);
        }
        if ($result->stderr !== '') {
            $this->err($result->stderr);
        }

        if ($result->exitCode === 0) {
            $this->say($this->dryRun ? "dry run: nothing to do\n" : "nothing to do\n");

            return 0;
        }
        if ($result->exitCode !== 3) {
            $this->say(sprintf("the refresher exited with an unknown code (%d); the robot stops here\n", $result->exitCode));

            return 1;
        }

        $changes = self::changeLinesFromReport($result->stdout);

        if ($this->dryRun) {
            return $this->dryRunOutcome($changes);
        }

        return $this->commitAndPush($changes);
    }

    /**
     * The merge request title, exactly.
     */
    public function mrTitle(): string
    {
        return sprintf('Hop data: refresh versions.json (%s)', $this->todayString());
    }

    /**
     * The merge request body: the changed keys old to new, taken from the
     * refresher's report, then the closing line.
     *
     * @param list<string> $changes
     */
    public function mrBody(array $changes): string
    {
        $lines = [];
        foreach ($changes as $line) {
            $lines[] = $line;
        }
        if ($changes !== []) {
            $lines[] = '';
        }
        $lines[] = self::CLOSING_LINE;

        return implode("\n", $lines);
    }

    /**
     * The "key: old -> new" lines the refresher prints after its update
     * header ("data/versions.json updated:" or "... would change:"). Empty
     * when the report carries no such header.
     *
     * @return list<string>
     */
    public static function changeLinesFromReport(string $stdout): array
    {
        $lines = [];
        $collecting = false;
        foreach (explode("\n", $stdout) as $line) {
            if (preg_match('/^data\/versions\.json (?:updated|would change):$/', trim($line)) === 1) {
                $collecting = true;
                continue;
            }
            if (!$collecting) {
                continue;
            }
            $line = trim($line);
            if ($line === '') {
                break;
            }
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * What a dry run would have done, printed without any git command or
     * API call.
     *
     * @param list<string> $changes
     */
    private function dryRunOutcome(array $changes): int
    {
        $this->say("dry run: would commit these files:\n");
        foreach (self::DATA_ALLOWLIST as $file) {
            $this->say("  {$file}\n");
        }
        $this->say(sprintf("dry run: would push %s and open a merge request against the default branch\n", self::BRANCH));
        $this->printMergeRequest($changes);

        return 0;
    }

    /**
     * Changes were written (exit 3): commit the data allowlist on the
     * refresh branch and push it with merge request options, updating an
     * open merge request from that branch instead of opening a second one.
     *
     * @param list<string> $changes
     */
    private function commitAndPush(array $changes): int
    {
        try {
            $openHead = $this->openRefreshMergeRequestHead();
        } catch (ToolError $e) {
            $this->err($e->getMessage() . "\n");

            return 1;
        }

        if ($openHead !== null) {
            $this->say(sprintf("an open merge request already runs from %s; updating that branch instead of opening a second one\n", self::BRANCH));
        } else {
            $this->say(sprintf("no open merge request on %s; the push opens one\n", self::BRANCH));
        }

        $this->say("the commit plan:\n");
        foreach (self::DATA_ALLOWLIST as $file) {
            $this->say("  {$file}\n");
        }

        $result = $this->runGit(['checkout', '-B', self::BRANCH]);
        if ($result->exitCode !== 0) {
            $this->say("git checkout failed (exit {$result->exitCode}); the robot stops here\n");

            return 1;
        }

        $result = $this->runGit(['add', ...self::DATA_ALLOWLIST]);
        if ($result->exitCode !== 0) {
            $this->say("git add failed (exit {$result->exitCode}); the robot stops here\n");

            return 1;
        }

        $result = $this->runGit(['diff', '--cached', '--name-only']);
        if ($result->exitCode !== 0) {
            $this->say("git diff failed (exit {$result->exitCode}); the robot stops here\n");

            return 1;
        }
        if (trim($result->stdout) === '') {
            $this->say('nothing staged; skipping the commit and push\n');

            return 0;
        }

        $result = $this->runGit([
            '-c',
            'user.name=' . self::COMMITTER_NAME,
            '-c',
            'user.email=' . self::COMMITTER_EMAIL,
            'commit',
            '-m',
            $this->mrTitle(),
        ]);
        if ($result->exitCode !== 0) {
            $this->say("git commit failed (exit {$result->exitCode}); the robot stops here\n");

            return 1;
        }

        $args = ['push'];
        if ($openHead !== null) {
            // Only the bot branch is ever force-pushed, and only while its
            // remote head still matches the sha the API reported.
            $args[] = '--force-with-lease=' . self::BRANCH . ':' . $openHead;
        } else {
            foreach ($this->pushOptions($changes) as $option) {
                $args[] = '-o';
                $args[] = $option;
            }
        }
        $args[] = $this->pushUrl();
        $args[] = 'HEAD:refs/heads/' . self::BRANCH;

        $result = $this->runGit($args);
        if ($result->exitCode !== 0) {
            $this->say("git push failed (exit {$result->exitCode}); the robot stops here\n");

            return 1;
        }

        if ($openHead !== null) {
            $this->say(sprintf("%s now carries the refreshed data; the open merge request shows the new commit\n", self::BRANCH));
        }
        $this->printMergeRequest($changes);

        return 0;
    }

    /**
     * Run one git command through the runner. The command line is echoed
     * first (masked) and its streams are replayed (masked), so a push URL
     * never reaches the terminal raw.
     *
     * @param list<string> $args the git arguments, without the leading "git"
     */
    private function runGit(array $args): ProcessResult
    {
        $argv = ['git', ...$args];
        $this->say('$ ' . implode(' ', $argv) . "\n");

        $result = $this->runner->run($argv, $this->repoRoot);
        if ($result->stdout !== '') {
            $this->say($result->stdout);
        }
        if ($result->stderr !== '') {
            $this->err($result->stderr);
        }

        return $result;
    }

    /**
     * The head sha of the open merge request that runs from the refresh
     * branch, or null when none is open. The lease for the update push
     * rests on this sha, so an answer without a usable one is an error.
     *
     * @throws ToolError when the API call fails or answers without a usable sha
     */
    private function openRefreshMergeRequestHead(): ?string
    {
        $apiUrl = rtrim((string) getenv('CI_API_V4_URL'), '/');
        $projectId = (string) getenv('CI_PROJECT_ID');
        $url = $apiUrl . '/projects/' . rawurlencode($projectId) . '/merge_requests?state=opened&source_branch=' . rawurlencode(self::BRANCH);

        $result = $this->http()->request('GET', $url, [
            'PRIVATE-TOKEN' => $this->token(),
            'Accept' => 'application/json',
        ], null);

        $status = (int) $result['status'];
        if ($status < 200 || $status > 299) {
            throw new ToolError($this->mask(sprintf('GitLab API error %d looking up open merge requests: %s', $status, self::excerpt($result['body'] ?? null))));
        }
        $mergeRequests = [];
        if (is_array($result['body'])) {
            foreach (array_values($result['body']) as $candidate) {
                if (is_array($candidate)) {
                    $mergeRequests[] = $candidate;
                }
            }
        }
        if ($mergeRequests === []) {
            return null;
        }
        foreach ($mergeRequests as $mergeRequest) {
            $sha = $mergeRequest['sha'] ?? null;
            if (is_string($sha) && $sha !== '') {
                return $sha;
            }
        }

        throw new ToolError('The GitLab API lists an open merge request on ' . self::BRANCH . ' without a usable head sha; refusing to guess.');
    }

    /**
     * The GitLab API seam: the injected one, or CREED's stream transport.
     */
    private function http(): HttpTransport
    {
        if ($this->http !== null) {
            return $this->http;
        }

        return GitLabClient::streamTransport();
    }

    /**
     * The token push URL, built from the CI environment. Only called after
     * missingEnvironment() came back empty.
     */
    private function pushUrl(): string
    {
        return sprintf(
            '%s://oauth2:%s@%s:%s/%s.git',
            (string) getenv('CI_SERVER_PROTOCOL'),
            $this->token(),
            (string) getenv('CI_SERVER_HOST'),
            (string) getenv('CI_SERVER_PORT'),
            (string) getenv('CI_PROJECT_PATH'),
        );
    }

    /**
     * The push options that open the merge request, one per -o flag.
     *
     * @param list<string> $changes
     *
     * @return list<string>
     */
    private function pushOptions(array $changes): array
    {
        return [
            'merge_request.create',
            'merge_request.target=' . (string) getenv('CI_DEFAULT_BRANCH'),
            'merge_request.remove_source_branch',
            'merge_request.title=' . $this->mrTitle(),
            'merge_request.description=' . $this->mrBody($changes),
        ];
    }

    /**
     * The required environment variable names that are missing or empty,
     * in fixed order. Names only; values never leave this method.
     *
     * @return list<string>
     */
    private function missingEnvironment(): array
    {
        $missing = [];
        foreach (self::ENVIRONMENT as $name) {
            $value = getenv($name);
            if ($value === false || $value === '') {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    /**
     * The push token from BOOSTER_BOT_TOKEN, or '' when unset.
     */
    private function token(): string
    {
        $token = getenv(self::TOKEN_ENV);

        return $token === false ? '' : (string) $token;
    }

    /**
     * The one mask every printed line passes through: the token value
     * becomes [masked]. An empty token masks nothing.
     */
    private function mask(string $line): string
    {
        $token = $this->token();

        return $token === '' ? $line : str_replace($token, '[masked]', $line);
    }

    /**
     * Print one line (or chunk) to stdout, masked.
     */
    private function say(string $line): void
    {
        echo $this->mask($line);
    }

    /**
     * Print one line (or chunk) to the error stream (STDERR by default),
     * masked.
     */
    private function err(string $line): void
    {
        if (is_resource($this->errorStream)) {
            fwrite($this->errorStream, $this->mask($line));

            return;
        }

        fwrite(STDERR, $this->mask($line));
    }

    /**
     * The injected today (tests) or the real today.
     */
    private function todayString(): string
    {
        if ($this->today !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->today) === 1) {
            return $this->today;
        }

        return date('Y-m-d');
    }

    /**
     * The merge request title and body.
     *
     * @param list<string> $changes
     */
    private function printMergeRequest(array $changes): void
    {
        $this->say('merge request title: ' . $this->mrTitle() . "\n");
        $this->say("merge request body:\n");
        $this->say($this->mrBody($changes) . "\n");
    }

    /**
     * A short, printable view of a decoded API body for error messages.
     */
    private static function excerpt(mixed $body): string
    {
        if ($body === null) {
            return '(empty body)';
        }
        $text = is_string($body) ? $body : (string) json_encode($body);
        $text = preg_replace('/\s+/', ' ', trim($text)) ?? $text;

        return strlen($text) > 200 ? substr($text, 0, 200) . '...' : $text;
    }
}
