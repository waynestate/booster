<?php

declare(strict_types=1);

namespace Waynestate\Booster\Replay;

/**
 * One request from the request file, as a browser would send it. The client
 * in the container sends it; Booster itself never opens a socket.
 */
final readonly class ReplayRequest
{
    /**
     * @param string                $sequence the name of the sequence it belongs to
     * @param array<string, string> $query    query string parameters, appended to the path
     * @param array<string, string> $headers  request headers, after the file's defaults
     * @param array<string, string> $cookies  cookies sent on top of the ones the sequence collected
     * @param array<string, mixed>|null $form form fields, sent as application/x-www-form-urlencoded
     * @param array<string, string> $capture  name to regular expression with one group, read from this answer for later requests
     * @param bool                  $csrf     take the CSRF field (name and value) from the previous answer in the sequence and send it
     *                                        with this request: as a form field when the request posts a form, else as an X-CSRF-Token header
     */
    public function __construct(
        public int $number,
        public string $sequence,
        public string $name,
        public string $method,
        public string $path,
        public array $query = [],
        public array $headers = [],
        public array $cookies = [],
        public ?array $form = null,
        public mixed $json = null,
        public bool $hasJson = false,
        public ?string $body = null,
        public array $capture = [],
        public ?int $expect = null,
        public ?string $todo = null,
        public bool $csrf = false,
    ) {}

    /** The request line as the transcript shows it: method, path and query. */
    public function target(): string
    {
        $query = $this->query === [] ? '' : '?' . http_build_query($this->query, '', '&', PHP_QUERY_RFC3986);

        return $this->path . $query;
    }

    /** "03 save then read back / save: POST /notes", or "03 pages: GET /" for a request with no name of its own. */
    public function label(): string
    {
        if ($this->name === $this->method . ' ' . $this->path) {
            return sprintf('%02d %s: %s %s', $this->number, $this->sequence, $this->method, $this->target());
        }

        return sprintf('%02d %s / %s: %s %s', $this->number, $this->sequence, $this->name, $this->method, $this->target());
    }

    /**
     * What the in-container client needs, as plain arrays. Placeholders such
     * as {{token}} are left in place: the client fills them from earlier
     * captures in the same sequence.
     *
     * @return array<string, mixed>
     */
    public function toClientArray(): array
    {
        $headers = $this->headers;
        $body = $this->body ?? '';
        // A CSRF request with no body of its own posts a form that holds only
        // the token, the way a bare button form does.
        $form = $this->form ?? ($this->csrf && !$this->hasJson && $this->body === null ? [] : null);
        if ($form !== null) {
            $body = http_build_query($form, '', '&');
            $headers = self::withDefault($headers, 'Content-Type', 'application/x-www-form-urlencoded');
        } elseif ($this->hasJson) {
            $body = (string) json_encode($this->json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers = self::withDefault($headers, 'Content-Type', 'application/json');
        }
        $headerList = [];
        foreach ($headers as $name => $value) {
            $headerList[] = [$name, $value];
        }

        $out = [
            'number' => $this->number,
            'method' => $this->method,
            'target' => $this->target(),
            'headers' => $headerList,
            'cookies' => $this->cookies === [] ? new \stdClass() : $this->cookies,
            'body' => $body,
            'capture' => $this->capture === [] ? new \stdClass() : $this->capture,
        ];
        // Only when set, so a request file without the flag keeps the
        // fingerprint its recording was made with.
        if ($this->csrf) {
            $out['csrf'] = $form !== null ? 'form' : 'header';
        }

        return $out;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    private static function withDefault(array $headers, string $name, string $value): array
    {
        foreach (array_keys($headers) as $key) {
            if (strcasecmp($key, $name) === 0) {
                return $headers;
            }
        }
        $headers[$name] = $value;

        return $headers;
    }
}
