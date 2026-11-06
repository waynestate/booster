<?php

declare(strict_types=1);

namespace Waynestate\Booster\Replay;

use Waynestate\Booster\Run\GateImage;
use Waynestate\Booster\ToolError;

/**
 * The request-replay gate: record how the app answers on the PHP it starts
 * from, then replay the same requests after each hop and fail on any answer
 * that changed. Every replay writes a transcript to the run folder, so a
 * person can diff the baseline against any hop with their own tools.
 *
 * It is the gate for apps with no tests worth the name: a passing replay says
 * "these pages answer exactly as before on fake data", which is the promise a
 * syntax check cannot make. Think of it as a tribute act that has to hit
 * every note the original band did.
 */
final class ReplayGate
{
    private ?ReplayRecording $recording = null;

    public function __construct(
        private readonly ReplayRunner $runner,
        private readonly ReplayFile $file,
        private readonly string $appPath,
        private readonly string $outDir,
        private readonly ?GateImage $image = null,
    ) {}

    /**
     * A fresh folder for this run's transcripts, outside the app so the
     * working copy stays clean: <temp>/booster-replay-<app>-<time>.
     */
    public static function defaultOutDir(string $appPath, ?\DateTimeImmutable $now = null): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename(rtrim($appPath, '/\\'))) ?: 'app';

        return sys_get_temp_dir() . '/booster-replay-' . $name . '-' . ($now ?? new \DateTimeImmutable('now'))->format('Ymd-His');
    }

    public function recording(): ?ReplayRecording
    {
        return $this->recording;
    }

    public function useRecording(ReplayRecording $recording, string $recordingPath): void
    {
        $recording->assertMatches($this->file, $recordingPath);
        $this->recording = $recording;
        $this->write('baseline-php' . $recording->php . '.txt', ReplayComparison::transcript($this->file, $recording->answers, sprintf('Booster replay: baseline on PHP %s (from %s)', $recording->php, basename($recordingPath))));
    }

    /**
     * Records the baseline: every request answered on $php. It is only
     * usable when every request got an answer and every answer has the
     * status the request file expects (when it says).
     */
    public function record(string $php, bool $install = false): ReplayOutcome
    {
        if ($this->file->todos !== []) {
            throw new ToolError(self::todoMessage($this->file));
        }
        $run = $this->runner->replay($this->appPath, $php, $this->file, $this->image, $install);
        $name = basename($this->file->path);
        if (!$run->served) {
            return new ReplayOutcome(ReplayOutcome::FAILED, $php, [
                sprintf('Replay baseline could not be recorded on PHP %s:', $php),
                ...self::indent($run->lines),
            ], $name, $this->outDir, null, count($this->file->requests));
        }
        $label = 'baseline-php' . $php;
        $transcript = $this->write($label . '.txt', ReplayComparison::transcript($this->file, $run->answers, sprintf('Booster replay: baseline on PHP %s', $php)));
        $errors = $this->writeErrors($label, $run->phpErrors);

        $problems = [];
        foreach ($this->file->requests as $i => $request) {
            $answer = $run->answers[$i] ?? null;
            if ($answer === null || $answer->status === 0) {
                $problems[] = sprintf('%s got no answer (%s).', $request->label(), $answer->error ?? 'not sent');
            } elseif ($request->expect !== null && $answer->status !== $request->expect) {
                $problems[] = sprintf('%s answered %d; the request file expects %d.', $request->label(), $answer->status, $request->expect);
            }
            foreach ($answer->notes ?? [] as $note) {
                $problems[] = sprintf('%s: %s.', $request->label(), $note);
            }
        }
        if ($problems !== []) {
            return new ReplayOutcome(ReplayOutcome::FAILED, $php, [
                sprintf('Replay baseline on PHP %s is not usable: the app does not answer the way %s says, before any change.', $php, $name),
                ...self::indent($problems),
                'Fix the request file (or the fake data) and try again. Transcript: ' . $transcript,
            ], $name, $this->outDir, $transcript, count($this->file->requests));
        }

        $this->recording = new ReplayRecording($php, $this->file->fingerprint, $run->answers);
        $lines = [sprintf('Replay: recorded %d answer%s on PHP %s from %s.', count($run->answers), count($run->answers) === 1 ? '' : 's', $php, $name)];
        if ($errors !== null) {
            $lines[] = sprintf('PHP logged %d distinct message%s while serving them (%s).', count($run->phpErrors), count($run->phpErrors) === 1 ? '' : 's', $errors);
        }
        $lines[] = 'Transcripts: ' . $this->outDir;

        return new ReplayOutcome(ReplayOutcome::RECORDED, $php, $lines, $name, $this->outDir, $transcript, count($run->answers));
    }

    /**
     * Replays every request on $php and compares the answers with the
     * recording. $label names the transcript (for example step-2-php-80-to-81).
     */
    public function check(string $php, string $label, bool $install = false): ReplayOutcome
    {
        $recording = $this->recording ?? throw new ToolError('There is no replay baseline to compare against: record one first.');
        $name = basename($this->file->path);
        $run = $this->runner->replay($this->appPath, $php, $this->file, $this->image, $install);
        if (!$run->served) {
            return new ReplayOutcome(ReplayOutcome::FAILED, $php, [
                sprintf('Replay failed on PHP %s: the app could not be served.', $php),
                ...self::indent($run->lines),
            ], $name, $this->outDir, null, count($this->file->requests));
        }
        $label = preg_replace('/[^A-Za-z0-9._-]+/', '-', $label) ?: 'replay';
        $transcript = $this->write($label . '.txt', ReplayComparison::transcript($this->file, $run->answers, sprintf('Booster replay: %s on PHP %s', $label, $php)));
        $errors = $this->writeErrors($label, $run->phpErrors);
        $differences = ReplayComparison::differences($this->file, $recording->answers, $run->answers);
        $total = count($this->file->requests);
        $errorLine = $errors === null ? [] : [sprintf('PHP logged %d distinct message%s while serving (%s).', count($run->phpErrors), count($run->phpErrors) === 1 ? '' : 's', $errors)];
        if ($differences === []) {
            return new ReplayOutcome(ReplayOutcome::PASSED, $php, [
                sprintf('Replay passed: all %d answer%s match the baseline from PHP %s.', $total, $total === 1 ? '' : 's', $recording->php),
                ...$errorLine,
            ], $name, $this->outDir, $transcript, $total);
        }

        $first = $differences[0];
        $diff = $first['diff'];
        if (count($diff) > ReplayComparison::DIFF_LINES) {
            $diff = [...array_slice($diff, 0, ReplayComparison::DIFF_LINES), sprintf('  ... (%d more diff lines in the transcript)', count($first['diff']) - ReplayComparison::DIFF_LINES)];
        }
        $baselineTranscript = $this->outDir . '/baseline-php' . $recording->php . '.txt';
        $others = array_map(static fn(array $d): string => sprintf('%02d', $d['request']->number), array_slice($differences, 1, 12));

        return new ReplayOutcome(ReplayOutcome::DIFFERS, $php, [
            sprintf('Replay failed on PHP %s: %d of %d answers differ from the baseline from PHP %s.', $php, count($differences), $total, $recording->php),
            'First difference: ' . $first['request']->label() . ' (- baseline, + now)',
            ...self::indent($diff),
            ...($others === [] ? [] : ['Also different: request' . (count($others) === 1 ? ' ' : 's ') . implode(', ', $others) . (count($differences) > 13 ? ', ...' : '') . '.']),
            sprintf('Every difference: diff %s %s', $baselineTranscript, $transcript),
            'A value that changes on every request (a token, a time) is not a real difference: hide it with a normalizer in ' . $name . '.',
            ...$errorLine,
        ], $name, $this->outDir, $transcript, $total, count($differences));
    }

    public static function todoMessage(ReplayFile $file): string
    {
        $lines = [sprintf('%s still has %d todo item%s for a person to fill in (then delete each "todo" key):', basename($file->path), count($file->todos), count($file->todos) === 1 ? '' : 's')];
        foreach ($file->todos as $todo) {
            $lines[] = '  - ' . $todo;
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function indent(array $lines): array
    {
        return array_map(static fn(string $l): string => '  ' . $l, $lines);
    }

    private function write(string $name, string $contents): string
    {
        if (!is_dir($this->outDir) && !@mkdir($this->outDir, 0777, true) && !is_dir($this->outDir)) {
            throw new ToolError(sprintf('Could not create the replay folder %s.', $this->outDir));
        }
        $path = $this->outDir . '/' . $name;
        if (file_put_contents($path, $contents) === false) {
            throw new ToolError(sprintf('Could not write %s.', $path));
        }

        return $path;
    }

    /**
     * @param list<string> $phpErrors
     */
    private function writeErrors(string $label, array $phpErrors): ?string
    {
        if ($phpErrors === []) {
            return null;
        }

        return $this->write($label . '.php-errors.txt', implode("\n", $phpErrors) . "\n");
    }
}
