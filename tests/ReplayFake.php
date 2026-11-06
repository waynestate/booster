<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Run\ProcessResult;

/**
 * Stands in for Docker in the replay tests: every replay container (a
 * `docker run --network ...`) "answers" by writing the next canned result into
 * the scratch folder Booster mounted, exactly where the real client would.
 * Gate containers (the test gate, composer install) pass.
 */
final class ReplayFake
{
    /** A replay whose setup command fails. */
    public const SETUP_FAILS = 'setup-fails';

    /** A replay whose server never starts. */
    public const NO_SERVER = 'no-server';

    /**
     * @param list<list<array{0: int, 1: string, 2?: list<array{string, string}>}>|string> $replays one entry per replay container: answers as [status, body, headers], or SETUP_FAILS / NO_SERVER
     * @param int  $gateExit exit code of each gate (non-replay) docker run
     */
    public static function runner(array $replays, int $gateExit = 0): FakeProcessRunner
    {
        return new FakeProcessRunner(static function (array $command) use (&$replays, $gateExit): ProcessResult {
            if (($command[0] ?? '') === 'git') {
                return new ProcessResult(0, '', '');
            }
            if (($command[0] ?? '') !== 'docker' || ($command[1] ?? '') !== 'run') {
                return new ProcessResult(0, '', '');
            }
            if (in_array('-d', $command, true)) {
                // A fixture service started in the background.
                return new ProcessResult(0, "0123456789ab
", '');
            }
            if (!in_array('--network', $command, true)) {
                return new ProcessResult($gateExit, $gateExit === 0 ? "BOOSTER_GATE_PHASE=tests\n" : "FAILURES!\nBOOSTER_GATE_PHASE=tests\nBOOSTER_GATE_EXIT=1\n", '');
            }
            $scratch = null;
            foreach ($command as $i => $arg) {
                if ($arg === '-v' && str_ends_with($command[$i + 1] ?? '', ':/booster-replay')) {
                    $scratch = substr($command[$i + 1], 0, -strlen(':/booster-replay'));
                }
            }
            $next = array_shift($replays);
            if ($scratch === null || $next === null) {
                return new ProcessResult(1, '', 'unexpected replay container');
            }
            if ($next === self::SETUP_FAILS) {
                return new ProcessResult(1, "SQLSTATE[HY000]: unable to open database file\nBOOSTER_REPLAY_PHASE=setup\nBOOSTER_REPLAY_EXIT=1\n", '');
            }
            if ($next === self::NO_SERVER) {
                file_put_contents($scratch . '/result.json', json_encode(['started' => false, 'answers' => []]));
                file_put_contents($scratch . '/server.log', "PHP Fatal error: Uncaught Error in router\n");

                return new ProcessResult(3, "BOOSTER_REPLAY_PHASE=replay\nBOOSTER_REPLAY_EXIT=3\n", '');
            }
            $answers = [];
            foreach ($next as $n => $answer) {
                $answers[] = [
                    'number' => $n + 1,
                    'status' => $answer[0],
                    'headers' => $answer[2] ?? [['Content-Type', 'text/html; charset=UTF-8']],
                    'body' => base64_encode($answer[1]),
                    'body_encoding' => 'base64',
                    'error' => $answer[0] === 0 ? 'could not connect' : null,
                    'notes' => [],
                ];
            }
            file_put_contents($scratch . '/result.json', json_encode(['started' => true, 'answers' => $answers]));
            file_put_contents($scratch . '/php-errors.log', "[05-Nov-2026 20:00:00 UTC] PHP Deprecated:  Old thing in /app/src/app.php on line 3\n[05-Nov-2026 20:00:01 UTC] PHP Deprecated:  Old thing in /app/src/app.php on line 3\n");

            return new ProcessResult(0, "BOOSTER_REPLAY_PHASE=replay\nBOOSTER_REPLAY_EXIT=0\n", '');
        });
    }
}
