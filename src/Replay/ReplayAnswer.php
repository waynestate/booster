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
    /** The header a strangled app's dispatcher sets while BOOSTER_REPLAY=1, naming the half that answered. */
    public const HALF_HEADER = 'X-Booster-Half';

    public const HALF_LARAVEL = 'laravel';

    public const HALF_LEGACY = 'legacy';

    /**
     * @param list<array{string, string}> $headers name and value, in the order the app sent them
     * @param list<string>                $notes   what the client could not do (a capture that matched nothing)
     * @param list<string>                $actions what the client did on its own for this answer: the CSRF field it sent ("csrf": true)
     *                                              and the silent sign-in redirects it followed (compare.silent_sign_in); reported, never compared
     */
    public function __construct(
        public int $number,
        public int $status,
        public array $headers,
        public string $body,
        public ?string $error = null,
        public array $notes = [],
        public array $actions = [],
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
     * Which half of a strangled app answered: "laravel" or "legacy", from the
     * X-Booster-Half header the dispatcher sets while BOOSTER_REPLAY=1, or
     * null when the answer has none (an app that is not mounted). Reported,
     * never compared.
     */
    public function half(): ?string
    {
        $half = strtolower(trim((string) $this->header(self::HALF_HEADER)));

        return in_array($half, [self::HALF_LARAVEL, self::HALF_LEGACY], true) ? $half : null;
    }

    /**
     * The attributes that change what a browser does with each cookie the
     * answer sets (Path, Secure, HttpOnly, SameSite), one entry per
     * Set-Cookie header in name order, for the strangler compare preset.
     * Expires, Max-Age and Domain stay out: they move with the clock and the
     * host, not with the framework.
     *
     * @return list<array{string, string}> cookie name and its attributes, such as "path=/; httponly; samesite=lax"
     */
    public function cookieAttributes(): array
    {
        $out = [];
        foreach ($this->headers as [$key, $value]) {
            if (strcasecmp($key, 'Set-Cookie') !== 0) {
                continue;
            }
            $parts = explode(';', $value);
            $name = trim(explode('=', (string) array_shift($parts), 2)[0]);
            if ($name === '') {
                continue;
            }
            $attributes = [];
            foreach ($parts as $part) {
                $pair = explode('=', trim($part), 2);
                $attribute = strtolower(trim($pair[0]));
                if ($attribute === 'path') {
                    $attributes['path'] = 'path=' . trim($pair[1] ?? '');
                } elseif ($attribute === 'secure' || $attribute === 'httponly') {
                    $attributes[$attribute] = $attribute;
                } elseif ($attribute === 'samesite') {
                    $attributes['samesite'] = 'samesite=' . strtolower(trim($pair[1] ?? ''));
                }
            }
            ksort($attributes, SORT_STRING);
            $out[] = [$name, $attributes === [] ? '(none)' : implode('; ', $attributes)];
        }
        usort($out, static fn(array $a, array $b): int => strcmp($a[0], $b[0]));

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $utf8 = preg_match('//u', $this->body) === 1;

        $out = [
            'number' => $this->number,
            'status' => $this->status,
            'headers' => $this->headers,
            'body' => $utf8 ? $this->body : base64_encode($this->body),
            'body_encoding' => $utf8 ? 'text' : 'base64',
            'error' => $this->error,
            'notes' => $this->notes,
        ];
        if ($this->actions !== []) {
            $out['actions'] = $this->actions;
        }

        return $out;
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
        $lists = ['notes' => [], 'actions' => []];
        foreach (array_keys($lists) as $key) {
            foreach (is_array($data[$key] ?? null) ? $data[$key] : [] as $line) {
                if (is_string($line)) {
                    $lists[$key][] = $line;
                }
            }
        }

        return new self(
            is_int($data['number'] ?? null) ? $data['number'] : 0,
            is_int($data['status'] ?? null) ? $data['status'] : 0,
            $headers,
            $body,
            is_string($data['error'] ?? null) ? $data['error'] : null,
            $lists['notes'],
            $lists['actions'],
        );
    }
}
