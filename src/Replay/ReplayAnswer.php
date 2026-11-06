<?php

declare(strict_types=1);

namespace Waynestate\Booster\Replay;

/**
 * How the app answered one request, as the client in the container saw it:
 * the raw status, headers and body, before any normalizing. Status 0 means
 * the app never answered (no connection, a timeout); $error says why.
 */
final readonly class ReplayAnswer
{
    /**
     * @param list<array{string, string}> $headers name and value, in the order the app sent them
     * @param list<string>                $notes   what the client could not do (a capture that matched nothing)
     */
    public function __construct(
        public int $number,
        public int $status,
        public array $headers,
        public string $body,
        public ?string $error = null,
        public array $notes = [],
    ) {}

    /** The first value of a header, case-insensitive, or null. */
    public function header(string $name): ?string
    {
        foreach ($this->headers as [$key, $value]) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * The cookie names the answer sets, sorted and unique. Values never take
     * part: they are session ids and tokens by nature.
     *
     * @return list<string>
     */
    public function cookieNames(): array
    {
        $names = [];
        foreach ($this->headers as [$key, $value]) {
            if (strcasecmp($key, 'Set-Cookie') === 0) {
                $name = trim(explode('=', $value, 2)[0]);
                if ($name !== '') {
                    $names[$name] = true;
                }
            }
        }
        $names = array_keys($names);
        sort($names, SORT_STRING);

        return array_map('strval', $names);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $utf8 = preg_match('//u', $this->body) === 1;

        return [
            'number' => $this->number,
            'status' => $this->status,
            'headers' => $this->headers,
            'body' => $utf8 ? $this->body : base64_encode($this->body),
            'body_encoding' => $utf8 ? 'text' : 'base64',
            'error' => $this->error,
            'notes' => $this->notes,
        ];
    }

    /**
     * Reads one answer from a recording or the client's result file. The
     * client always sends the body base64-encoded; a recording keeps text as
     * text so a person can read it.
     *
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $headers = [];
        foreach (is_array($data['headers'] ?? null) ? $data['headers'] : [] as $pair) {
            if (is_array($pair) && isset($pair[0], $pair[1]) && is_string($pair[0]) && is_scalar($pair[1])) {
                $headers[] = [$pair[0], (string) $pair[1]];
            }
        }
        $body = is_string($data['body'] ?? null) ? $data['body'] : '';
        if (($data['body_encoding'] ?? 'text') === 'base64') {
            $decoded = base64_decode($body, true);
            $body = $decoded === false ? '' : $decoded;
        }
        $notes = [];
        foreach (is_array($data['notes'] ?? null) ? $data['notes'] : [] as $note) {
            if (is_string($note)) {
                $notes[] = $note;
            }
        }

        return new self(
            is_int($data['number'] ?? null) ? $data['number'] : 0,
            is_int($data['status'] ?? null) ? $data['status'] : 0,
            $headers,
            $body,
            is_string($data['error'] ?? null) ? $data['error'] : null,
            $notes,
        );
    }
}
