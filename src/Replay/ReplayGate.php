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
    /** The replay guide's section on pages behind sign-in. */
    public const SIGN_IN_GUIDE = 'https://booster.apps.wayne.edu/docs/guides/replay-gate.html#pages-behind-sign-in';

    private ?ReplayRecording $recording = null;

    public function __construct(
        private readonly ReplayRunner $runner,
        private ReplayFile $file,
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

    public function useRecording(ReplayRecording $recording, string $recordingPath, bool $serveChanged = false): void
    {
        $recording->assertMatches($this->file, $recordingPath, $serveChanged);
        $this->recording = $recording;
        $this->write('baseline-php' . $recording->php . '.txt', ReplayComparison::transcript($this->file, $recording->answers, sprintf('Booster replay: baseline on PHP %s (from %s)', $recording->php, basename($recordingPath))));
    }

    /**
     * Records the baseline: every request answered on $php. It is only
     * usable when every request got an answer, every answer has the status
     * the request file expects (when it says), and the answers are not all
     * errors: a baseline of fatals replays the same fatals after every hop
     * and "passes" while proving nothing (the proof run's bug 14, a Slim 3
     * app whose five pages were one missing-folder fatal). $allowErrors
     * (--allow-failing-baseline) records such a baseline anyway, with a
     * warning.
     */
    public function record(string $php, bool $install = false, bool $allowErrors = false): ReplayOutcome
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

        $failing = self::errorBaseline($this->file->requests, $run->answers);
        if ($failing !== null && !$allowErrors) {
            return new ReplayOutcome(ReplayOutcome::FAILED, $php, [
                sprintf('Replay baseline on PHP %s is not usable: %s, before any change. A replay of it would only prove the app fails the same way after each hop.', $php, $failing['why']),
                ...self::indent($failing['lines']),
                ...($errors === null ? [] : [sprintf('PHP logged %d distinct message%s while serving them: %s', count($run->phpErrors), count($run->phpErrors) === 1 ? '' : 's', $errors)]),
                'Make the app answer on fake data first (a missing folder, config file or setup step is the usual cause), or pass --allow-failing-baseline to replay against the errors anyway. Transcript: ' . $transcript,
            ], $name, $this->outDir, $transcript, count($this->file->requests));
        }

        $this->recording = new ReplayRecording($php, $this->file->fingerprint, $run->answers, $this->file->requestsFingerprint, $this->file->serveFingerprint, $this->file->serveSummary());
        $lines = [sprintf('Replay: recorded %d answer%s on PHP %s from %s.', count($run->answers), count($run->answers) === 1 ? '' : 's', $php, $name)];
        if ($failing !== null) {
            $lines[] = sprintf('Warning: %s. Recorded anyway because of --allow-failing-baseline, so the replay proves only that the app fails the same way after each hop.', ucfirst($failing['why']));
            array_push($lines, ...self::indent($failing['lines']));
        } else {
            $some = self::errorAnswers($this->file->requests, $run->answers);
            if ($some !== []) {
                $lines[] = sprintf('Warning: %d of %d recorded answers are errors, so those requests replay the error, not the page:', count($some), count($run->answers));
                array_push($lines, ...self::indent($some));
            }
        }
        $redirect = self::sameRedirect($run->answers);
        if ($redirect !== null) {
            $lines[] = self::sameRedirectWarning(count($run->answers), $redirect);
        }
        if ($errors !== null) {
            $fatal = self::fatalLogLines($run->phpErrors);
            $lines[] = $fatal === []
                ? sprintf('PHP logged %d distinct message%s while serving them (%s).', count($run->phpErrors), count($run->phpErrors) === 1 ? '' : 's', $errors)
                : sprintf('Warning: PHP logged %d fatal error%s while serving the baseline (%d distinct message%s in all; %s). First: %s', count($fatal), count($fatal) === 1 ? '' : 's', count($run->phpErrors), count($run->phpErrors) === 1 ? '' : 's', $errors, self::shorten($fatal[0]));
        }
        $lines[] = 'Transcripts: ' . $this->outDir;

        return new ReplayOutcome(ReplayOutcome::RECORDED, $php, $lines, $name, $this->outDir, $transcript, count($run->answers));
    }

    /**
     * Adds a framework preset to every later comparison, for a hop that
     * moves a framework whose own markup then changes (the CakePHP 5 hop
     * adds "cakephp"). The baseline answers are kept; both sides are
     * normalized at compare time, so this hides only what the preset names.
     * Returns the line for the report, or null when the file already lists it.
     */
    public function addPreset(string $preset): ?string
    {
        foreach ($this->file->normalizers as $normalizer) {
            if ($normalizer->name === $preset) {
                return null;
            }
        }
        $f = $this->file;
        $this->file = new ReplayFile($f->path, $f->requests, $f->sequences, [...$f->normalizers, ReplayNormalizer::preset($preset)], $f->docroot, $f->frontController, $f->setup, $f->env, $f->ini, $f->services, $f->compareJson, $f->compareWhitespace, $f->todos, $f->fingerprint, $f->requestsFingerprint, $f->serveFingerprint);

        return sprintf('Replay: the "%s" normalizer is on from this step (%s).', $preset, ReplayNormalizer::PRESETS[$preset][0]);
    }

    /**
     * Replays every request on $php and compares the answers with the
     * recording. $label names the transcript (for example step-2-php-80-to-81).
     * With $serveChanged, a hop that changed how the app is served may still
     * pass when the answers match; the change is named in the transcript and
     * the report.
     */
    public function check(string $php, string $label, bool $install = false, bool $serveChanged = false): ReplayOutcome
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
        $serveLine = $serveChanged ? $this->serveChangeLine($recording) : null;
        $label = preg_replace('/[^A-Za-z0-9._-]+/', '-', $label) ?: 'replay';
        $transcriptText = ReplayComparison::transcript($this->file, $run->answers, sprintf('Booster replay: %s on PHP %s', $label, $php));
        if ($serveLine !== null) {
            $lines = explode("\n", $transcriptText);
            array_splice($lines, 2, 0, [$serveLine]);
            $transcriptText = implode("\n", $lines);
        }
        $transcript = $this->write($label . '.txt', $transcriptText);
        $errors = $this->writeErrors($label, $run->phpErrors);
        $differences = ReplayComparison::differences($this->file, $recording->answers, $run->answers);
        $total = count($this->file->requests);
        $errorLine = $errors === null ? [] : [sprintf('PHP logged %d distinct message%s while serving (%s).', count($run->phpErrors), count($run->phpErrors) === 1 ? '' : 's', $errors)];
        if ($differences === []) {
            return new ReplayOutcome(ReplayOutcome::PASSED, $php, [
                sprintf('Replay passed: all %d answer%s match the baseline from PHP %s.', $total, $total === 1 ? '' : 's', $recording->php),
                ...($serveLine === null ? [] : [$serveLine]),
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
            ...($serveLine === null ? [] : [$serveLine]),
            'First difference: ' . $first['request']->label() . ' (- baseline, + now)',
            ...self::indent($diff),
            ...($others === [] ? [] : ['Also different: request' . (count($others) === 1 ? ' ' : 's ') . implode(', ', $others) . (count($differences) > 13 ? ', ...' : '') . '.']),
            sprintf('Every difference: diff %s %s', $baselineTranscript, $transcript),
            'A value that changes on every request (a token, a time) is not a real difference: hide it with a normalizer in ' . $name . '.',
            ...$errorLine,
        ], $name, $this->outDir, $transcript, $total, count($differences));
    }

    /**
     * The one line that names how the app is served now versus when the
     * recording was made, or null when the serve section did not change. A
     * recording without a serve summary predates the split fingerprints, so
     * its side of the arrow is just "changed".
     */
    private function serveChangeLine(ReplayRecording $recording): ?string
    {
        if ($recording->serveFingerprint !== '' && $recording->serveFingerprint === $this->file->serveFingerprint) {
            return null;
        }

        return sprintf('serve: %s -> %s', $recording->serveSummary === '' ? 'changed' : $recording->serveSummary, $this->file->serveSummary());
    }

    /**
     * The one Location every answer redirects to, or null when any answer
     * is not a 3xx or two of them point at different places. A real app
     * answered every request with the redirect to sign-in before it loaded a
     * single library, so "all 5 answers match" on every hop proved only that
     * index.php still reaches its auth check: a launch rehearsal on an empty
     * pad.
     *
     * @param list<ReplayAnswer> $answers
     */
    public static function sameRedirect(array $answers): ?string
    {
        $location = null;
        foreach ($answers as $answer) {
            $to = $answer->header('Location');
            if ($answer->status < 300 || $answer->status > 399 || $to === null || ($location !== null && $to !== $location)) {
                return null;
            }
            $location = $to;
        }

        return $location;
    }

    /**
     * Why a baseline is all errors, with one line per failing request, or
     * null when at least one answer is a real page. An answer is an error
     * when its body shows a PHP fatal or parse error (an app that turns
     * display_errors on answers its fatals with a 200), or when it is a 5xx
     * the request file does not expect. Two or more answers that are the same
     * 4xx page byte for byte count too (every path hit one "Not Found" or one
     * error template), unless the file expects each of those statuses.
     *
     * @param list<ReplayRequest> $requests
     * @param list<ReplayAnswer>  $answers
     *
     * @return array{why: string, lines: list<string>}|null
     */
    public static function errorBaseline(array $requests, array $answers): ?array
    {
        $count = count($answers);
        if ($count === 0) {
            return null;
        }
        $lines = [];
        foreach ($answers as $i => $answer) {
            $line = self::errorLine($requests[$i] ?? null, $answer);
            if ($line === null) {
                $lines = null;
                break;
            }
            $lines[] = $line;
        }
        if ($lines !== null) {
            return [
                'why' => $count === 1 ? 'the one recorded answer is an error' : sprintf('all %d recorded answers are errors', $count),
                'lines' => self::capped($lines),
            ];
        }
        if ($count < 2) {
            return null;
        }
        $first = $answers[0];
        $expected = true;
        foreach ($answers as $i => $answer) {
            if ($answer->status < 400 || $answer->status !== $first->status || $answer->body !== $first->body) {
                return null;
            }
            $expected = $expected && ($requests[$i] ?? null)?->expect === $answer->status;
        }
        if ($expected) {
            return null;
        }
        $text = self::firstText($first->body);

        return [
            'why' => sprintf('all %d recorded answers are the same %d page', $count, $first->status),
            'lines' => [sprintf('%s and every other request answered %d%s', ($requests[0] ?? null)?->label() ?? 'Request 01', $first->status, $text === '' ? ', with the same body.' : ': ' . $text)],
        ];
    }

    /**
     * One line per answer that is an error in a baseline that also has real
     * pages, so the warning names the requests that replay an error.
     *
     * @param list<ReplayRequest> $requests
     * @param list<ReplayAnswer>  $answers
     *
     * @return list<string>
     */
    public static function errorAnswers(array $requests, array $answers): array
    {
        $lines = [];
        foreach ($answers as $i => $answer) {
            $line = self::errorLine($requests[$i] ?? null, $answer);
            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return self::capped($lines);
    }

    /**
     * The PHP fatal or parse error an answer's body shows ("Fatal error:
     * Uncaught Exception: ..."), tags stripped and shortened, or null.
     */
    public static function phpErrorIn(string $body): ?string
    {
        if ($body === '' || stripos($body, 'error') === false) {
            return null;
        }
        $text = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('/\b((?:fatal|parse) error)\s*:\s*([^\r\n]*)/i', $text, $m) !== 1) {
            return null;
        }

        return self::shorten(ucfirst(strtolower($m[1])) . ': ' . $m[2]);
    }

    /**
     * The lines of PHP's own log that are fatal or parse errors.
     *
     * @param list<string> $phpErrors
     *
     * @return list<string>
     */
    public static function fatalLogLines(array $phpErrors): array
    {
        return array_values(array_filter($phpErrors, static fn(string $line): bool => preg_match('/\b(?:fatal|parse) error\s*:/i', $line) === 1));
    }

    private static function errorLine(?ReplayRequest $request, ReplayAnswer $answer): ?string
    {
        $label = $request?->label() ?? sprintf('Request %02d', $answer->number);
        $php = self::phpErrorIn($answer->body);
        if ($php !== null) {
            return sprintf('%s answered %d with a PHP error: %s', $label, $answer->status, $php);
        }
        if ($answer->status >= 500 && $request?->expect !== $answer->status) {
            $text = self::firstText($answer->body);

            return sprintf('%s answered %d%s', $label, $answer->status, $text === '' ? '.' : ': ' . $text);
        }

        return null;
    }

    /**
     * What an error page calls itself: its <title>, else its first line of
     * readable text, shortened; '' for none.
     */
    private static function firstText(string $body): string
    {
        if (preg_match('//u', $body) !== 1) {
            return '';
        }
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $body, $m) === 1 && trim(strip_tags($m[1])) !== '') {
            return self::shorten(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        $body = (string) preg_replace('#<(script|style)\b.*?</\1>#is', '', $body);
        $body = (string) preg_replace('#<(?:br|/?(?:p|div|h[1-6]|li|tr|pre|body|head|html))\b[^>]*>#i', "\n", $body);
        $text = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (trim($line) !== '') {
                return self::shorten($line);
            }
        }

        return '';
    }

    private static function shorten(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $text)));

        if (strlen($text) <= 160) {
            return $text;
        }

        return (preg_match('/^.{0,157}/su', $text, $m) === 1 ? $m[0] : substr($text, 0, 157)) . '...';
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function capped(array $lines): array
    {
        if (count($lines) <= 5) {
            return $lines;
        }

        return [...array_slice($lines, 0, 5), sprintf('... and %d more.', count($lines) - 5)];
    }

    /** What to say when every recorded answer is the same redirect. */
    public static function sameRedirectWarning(int $count, string $location): string
    {
        return sprintf(
            'Warning: %s the same redirect (Location: %s). If that is the sign-in page, the replay proves only that the app reaches sign-in, not that the pages behind it still work. Give the replay a test login: %s',
            $count === 1 ? 'the one recorded answer is' : sprintf('all %d recorded answers are', $count),
            preg_replace('/[\x00-\x1F\x7F]/', '', $location),
            self::SIGN_IN_GUIDE,
        );
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
