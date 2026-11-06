<?php

declare(strict_types=1);

namespace Waynestate\Booster\Replay;

use Waynestate\Booster\Packaging\PharFiles;
use Waynestate\Booster\Run\GateImage;
use Waynestate\Booster\Run\ProcessRunner;
use Waynestate\Booster\Run\TargetImageRunner;
use Waynestate\Booster\ToolError;

/**
 * Serves the app on one PHP version and sends the request file's requests to
 * it, all inside one container: the same image the gate runs the tests in,
 * the app mounted at /app, PHP's built-in server on the container's own
 * loopback, and Booster's small client next to it. The container has no
 * network at all (or, when the file names fixture services, a private Docker
 * network with no way out), so the app under replay cannot reach a real
 * database, CAS or any other service, and needs no secrets.
 */
final class ReplayRunner
{
    public const PORT = 8765;

    /** Where the scratch folder (plan, client, results, logs) is mounted inside the container. */
    public const MOUNT = '/booster-replay';

    public function __construct(
        private readonly TargetImageRunner $gate,
        private readonly ProcessRunner $processes,
        private readonly string $docker = 'docker',
        private readonly ?string $resourceDir = null,
    ) {}

    /**
     * The shell script the container runs: the file's setup command (which
     * builds the fake data), then the built-in server in the background, then
     * the client. Marker lines report which phase ended the run.
     */
    public static function containerScript(ReplayFile $file): string
    {
        $mount = self::MOUNT;
        $php = ['php', '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . $mount . '/php-errors.log', '-d', 'error_reporting=-1'];
        foreach ($file->ini as $key => $value) {
            array_push($php, '-d', $key . '=' . $value);
        }
        array_push($php, '-S', '127.0.0.1:' . self::PORT, '-t', $file->docroot);
        if ($file->frontController !== null) {
            $php[] = $mount . '/router.php';
        }
        $server = implode(' ', array_map('escapeshellarg', $php));

        return implode("\n", [
            'setup_log=' . $mount . '/setup.log',
            'if [ -n "${BOOSTER_REPLAY_SETUP:-}" ]; then',
            '  sh -c "$BOOSTER_REPLAY_SETUP" >"$setup_log" 2>&1',
            '  setup_exit=$?',
            '  if [ "$setup_exit" -ne 0 ]; then',
            '    tail -n 30 "$setup_log"',
            '    echo "BOOSTER_REPLAY_PHASE=setup"',
            '    echo "BOOSTER_REPLAY_EXIT=$setup_exit"',
            '    exit "$setup_exit"',
            '  fi',
            'fi',
            $server . ' >' . $mount . '/server.log 2>&1 &',
            'server_pid=$!',
            'php ' . $mount . '/client.php ' . $mount . '/plan.json ' . $mount . '/result.json ' . self::PORT,
            'client_exit=$?',
            'kill "$server_pid" 2>/dev/null',
            'echo "BOOSTER_REPLAY_PHASE=replay"',
            'echo "BOOSTER_REPLAY_EXIT=$client_exit"',
            'exit "$client_exit"',
        ]);
    }

    /**
     * The `docker run` argument array, exposed so tests can assert on it.
     *
     * @return list<string>
     */
    public function runCommand(string $appPath, string $scratch, string $image, ReplayFile $file, ?string $network = null, ?int $uid = null, ?int $gid = null): array
    {
        $command = [$this->docker, 'run', '--rm', '--network', $network ?? 'none', '-v', $appPath . ':/app', '-v', $scratch . ':' . self::MOUNT, '-w', '/app', '-e', 'HOME=/tmp'];
        foreach ($file->env as $key => $value) {
            array_push($command, '-e', $key . '=' . $value);
        }
        if ($file->setup !== null) {
            array_push($command, '-e', 'BOOSTER_REPLAY_SETUP=' . $file->setup);
        }
        if ($file->frontController !== null) {
            array_push($command, '-e', 'BOOSTER_REPLAY_FRONT=' . $file->frontController);
        }
        if ($uid !== null && $gid !== null) {
            array_push($command, '--user', $uid . ':' . $gid);
        }
        array_push($command, $image, 'sh', '-c', self::containerScript($file));

        return $command;
    }

    /**
     * Serves the app on $php and sends every request. With $install the
     * app's Composer dependencies are installed first, in a gate container
     * that has the network (the replay container never does); a run inside
     * `booster run` skips it, because the gate just installed them.
     */
    public function replay(string $appPath, string $php, ReplayFile $file, ?GateImage $gateImage = null, bool $install = false): ReplayRun
    {
        $image = $this->gate->prepareImage($php, $gateImage);
        if ($install && is_file($appPath . '/composer.json')) {
            $result = $this->gate->run($appPath, $php, 'true', null, $gateImage);
            $outcome = TargetImageRunner::gateOutcome($result);
            if (!$outcome->successful()) {
                return ReplayRun::failed(ReplayRun::PHASE_INSTALL, [sprintf('composer install failed (exit %d) on PHP %s, so the app could not be served.', $outcome->exitCode, $php), ...array_slice($outcome->output, -15)]);
            }
        }

        $scratch = sys_get_temp_dir() . '/booster-replay-io-' . bin2hex(random_bytes(6));
        if (!mkdir($scratch, 0777, true) && !is_dir($scratch)) {
            throw new ToolError(sprintf('Could not create the replay scratch folder %s.', $scratch));
        }
        // The container may run as another user (root on Docker Desktop, the
        // caller's uid on Linux); either way it must be able to write here.
        @chmod($scratch, 0777);
        $network = null;
        $services = [];
        try {
            $resources = $this->resourceDir ?? PharFiles::path('resources/replay');
            foreach (['client.php', 'router.php'] as $name) {
                if (!@copy($resources . '/' . $name, $scratch . '/' . $name)) {
                    throw new ToolError(sprintf('Could not copy the replay %s into %s.', $name, $scratch));
                }
            }
            file_put_contents($scratch . '/plan.json', json_encode($file->clientPlan(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            if ($file->services !== []) {
                [$network, $services] = $this->startServices($file, basename($scratch));
            }
            $uid = function_exists('posix_getuid') ? posix_getuid() : null;
            $gid = function_exists('posix_getgid') ? posix_getgid() : null;
            $timeout = 120 + 35 * count($file->requests);
            $result = $this->processes->run($this->runCommand($appPath, $scratch, $image, $file, $network, $uid, $gid), null, [], $timeout);

            return $this->readRun($scratch, $result->exitCode, $result->stdout . "\n" . $result->stderr);
        } finally {
            foreach ($services as $name) {
                $this->processes->run([$this->docker, 'rm', '-f', $name], null, [], 60);
            }
            if ($network !== null) {
                $this->processes->run([$this->docker, 'network', 'rm', $network], null, [], 60);
            }
            self::removeTree($scratch);
        }
    }

    /**
     * Starts the fixture services on a private network made for this replay:
     * `--internal`, so neither the services nor the app can reach anything
     * outside it. The app reaches each service by its name (db, for example);
     * waiting until a service accepts connections is the setup command's job.
     *
     * @return array{string, list<string>} the network name and the container names to remove
     */
    private function startServices(ReplayFile $file, string $suffix): array
    {
        $network = 'booster-replay-' . substr($suffix, -12);
        $created = $this->processes->run([$this->docker, 'network', 'create', '--internal', $network], null, [], 60);
        if (!$created->successful()) {
            throw new ToolError(sprintf('Could not create the private replay network %s: %s', $network, trim($created->stderr)));
        }
        $started = [];
        foreach ($file->services as $service) {
            $name = $network . '-' . $service['name'];
            $command = [$this->docker, 'run', '-d', '--rm', '--name', $name, '--network', $network, '--network-alias', $service['name']];
            foreach ($service['env'] as $key => $value) {
                array_push($command, '-e', $key . '=' . $value);
            }
            $command[] = $service['image'];
            $run = $this->processes->run($command, null, [], 1800);
            if (!$run->successful()) {
                foreach ($started as $done) {
                    $this->processes->run([$this->docker, 'rm', '-f', $done], null, [], 60);
                }
                $this->processes->run([$this->docker, 'network', 'rm', $network], null, [], 60);
                throw new ToolError(sprintf('Could not start the replay service %s (%s): %s', $service['name'], $service['image'], trim($run->stderr)));
            }
            $started[] = $name;
        }

        return [$network, $started];
    }

    private function readRun(string $scratch, int $exitCode, string $output): ReplayRun
    {
        $phase = ReplayRun::PHASE_REPLAY;
        $lines = [];
        foreach (explode("\n", trim($output)) as $line) {
            if (preg_match('/^BOOSTER_REPLAY_PHASE=(\S+)$/', $line, $m) === 1) {
                $phase = $m[1];
                continue;
            }
            if (preg_match('/^BOOSTER_REPLAY_EXIT=(-?\d+)$/', $line, $m) === 1) {
                $exitCode = (int) $m[1];
                continue;
            }
            if (trim($line) !== '') {
                $lines[] = $line;
            }
        }
        $serverLog = self::tail($scratch . '/server.log', 15);
        $phpErrors = self::phpErrors($scratch . '/php-errors.log');
        if ($phase === ReplayRun::PHASE_SETUP) {
            return ReplayRun::failed(ReplayRun::PHASE_SETUP, [sprintf('The setup command failed (exit %d), so there was no fake data to serve.', $exitCode), ...array_slice($lines, -15)]);
        }
        $result = is_file($scratch . '/result.json') ? json_decode((string) file_get_contents($scratch . '/result.json'), true) : null;
        if (!is_array($result) || ($result['started'] ?? false) !== true) {
            return ReplayRun::failed(ReplayRun::PHASE_SERVER, [
                sprintf('The app\'s built-in PHP server did not answer (exit %d).', $exitCode),
                ...array_slice($lines, -10),
                ...($serverLog === [] ? [] : ['Server log:', ...$serverLog]),
            ]);
        }
        $answers = [];
        foreach (is_array($result['answers'] ?? null) ? $result['answers'] : [] as $answer) {
            if (is_array($answer)) {
                $answers[] = ReplayAnswer::fromArray($answer);
            }
        }

        return new ReplayRun(true, ReplayRun::PHASE_REPLAY, $answers, [], $phpErrors, $serverLog);
    }

    /**
     * The distinct messages PHP logged while serving, with how often each
     * appeared, without the timestamps. Shown, never compared: a new
     * deprecation on the new PHP is worth reading, not worth failing a hop.
     *
     * @return list<string>
     */
    private static function phpErrors(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $counts = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim((string) preg_replace('/^\[[^\]]*\]\s*/', '', $line));
            if ($line === '') {
                continue;
            }
            $counts[$line] = ($counts[$line] ?? 0) + 1;
        }
        ksort($counts, SORT_STRING);
        $out = [];
        foreach ($counts as $line => $count) {
            $out[] = sprintf('%4dx %s', $count, $line);
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function tail(string $file, int $lines): array
    {
        if (!is_file($file)) {
            return [];
        }
        $all = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        return array_slice($all, -$lines);
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
