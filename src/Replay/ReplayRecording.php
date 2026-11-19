<?php

declare(strict_types=1);

namespace Waynestate\Booster\Replay;

use Waynestate\Booster\ToolError;

/**
 * The baseline: every answer the app gave on the PHP it started from, raw,
 * with the request file's fingerprint. The comparison normalizes both sides
 * with the file's current normalizers, so a person can hide a volatile value
 * after recording without recording again; changing a request or how the
 * app is served needs a new recording, and the fingerprint enforces that.
 */
final readonly class ReplayRecording
{
    public const FORMAT = 'booster-replay-recording/1';

    public const DEFAULT_NAME = 'booster-replay.recording.json';

    /**
     * @param list<ReplayAnswer> $answers in request order
     */
    public function __construct(
        public string $php,
        public string $fingerprint,
        public array $answers,
        public string $requestsFingerprint = '',
        public string $serveFingerprint = '',
        public string $serveSummary = '',
    ) {}

    public function toJson(): string
    {
        return json_encode([
            'format' => self::FORMAT,
            'php' => $this->php,
            'fingerprint' => $this->fingerprint,
            'requests_fingerprint' => $this->requestsFingerprint,
            'serve_fingerprint' => $this->serveFingerprint,
            'serve_summary' => $this->serveSummary,
            'answers' => array_map(static fn(ReplayAnswer $a): array => $a->toArray(), $this->answers),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    public static function load(string $path): self
    {
        if (!is_file($path)) {
            throw new ToolError(sprintf('No recording at %s. Record one first: booster replay record', $path));
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data) || ($data['format'] ?? null) !== self::FORMAT || !is_array($data['answers'] ?? null)) {
            throw new ToolError(sprintf('%s is not a Booster replay recording.', $path));
        }
        $answers = [];
        foreach ($data['answers'] as $answer) {
            if (is_array($answer)) {
                $answers[] = ReplayAnswer::fromArray($answer);
            }
        }

        return new self(
            is_string($data['php'] ?? null) ? $data['php'] : '?',
            is_string($data['fingerprint'] ?? null) ? $data['fingerprint'] : '',
            $answers,
            is_string($data['requests_fingerprint'] ?? null) ? $data['requests_fingerprint'] : '',
            is_string($data['serve_fingerprint'] ?? null) ? $data['serve_fingerprint'] : '',
            is_string($data['serve_summary'] ?? null) ? $data['serve_summary'] : '',
        );
    }

    /**
     * Stops a comparison against a recording made from a different request
     * file: the answers would line up with the wrong requests. With
     * $serveChanged, a hop that changed how the app is served (docroot, front
     * controller, setup) may still compare the answers, as long as the
     * requests are the ones recorded.
     */
    public function assertMatches(ReplayFile $file, string $recordingPath, bool $serveChanged = false): void
    {
        if (!$serveChanged) {
            if ($this->fingerprint !== $file->fingerprint || count($this->answers) !== count($file->requests)) {
                throw new ToolError(sprintf(
                    'The recording %s was made from a different version of %s (its requests or "serve" section changed since). Record again on the old PHP: booster replay record',
                    $recordingPath,
                    basename($file->path),
                ));
            }

            return;
        }
        if ($this->requestsFingerprint === '') {
            throw new ToolError(sprintf(
                'The recording %s was made before Booster could compare across a serve change. Record it again once on the current setup (booster replay record), then run the check with --serve-changed.',
                $recordingPath,
            ));
        }
        if ($this->requestsFingerprint !== $file->requestsFingerprint || count($this->answers) !== count($file->requests)) {
            throw new ToolError(sprintf(
                'The recording %s was made from a different version of %s (its requests or "serve" section changed since). Record again on the old PHP: booster replay record',
                $recordingPath,
                basename($file->path),
            ));
        }
    }
}
