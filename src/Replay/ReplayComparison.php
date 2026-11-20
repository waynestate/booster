<?php

declare(strict_types=1);

namespace Waynestate\Booster\Replay;

/**
 * Turns answers into the lines that are compared and written to the
 * transcript, and compares two sets of answers request by request.
 *
 * What is compared: the status, Content-Type, Location, Allow, the names of
 * the cookies the answer sets (less compare.ignore_cookies), and the body.
 * Everything else (Date, ETag, Content-Length, X-Powered-By, which names the
 * PHP version) differs between PHP versions without changing what a person
 * sees, so it stays out. The strangler compare preset adds each cookie's
 * attributes and the security headers, because moving a page to another
 * framework can change those where a PHP upgrade does not.
 */
final class ReplayComparison
{
    /** The address the built-in server listens on inside the container; the transcript shows <app> instead. */
    public const APP_ORIGIN = 'http://127.0.0.1:8765';

    /** How many diff lines the report shows for the first difference. */
    public const DIFF_LINES = 14;

    /**
     * What the strangler compare preset adds to the view when an answer
     * carries them: the headers that change what a browser allows. Compared
     * only when present on either side, so an app that sends none sees no
     * new lines.
     */
    public const SECURITY_HEADERS = [
        'content-security-policy',
        'content-security-policy-report-only',
        'strict-transport-security',
        'x-frame-options',
        'access-control-allow-origin',
        'access-control-allow-credentials',
        'access-control-allow-methods',
        'access-control-allow-headers',
        'access-control-expose-headers',
        'access-control-max-age',
    ];

    /**
     * The compared view of one answer, one line per item, the body last.
     *
     * @return list<string>
     */
    public static function view(ReplayAnswer $answer, ReplayFile $file): array
    {
        if ($answer->status === 0) {
            return ['no answer: ' . ($answer->error ?? 'the app did not answer')];
        }
        $header = static function (string $name, ?string $value) use ($file): string {
            $line = $name . ': ' . ($value === null ? '-' : self::origin($value));
            foreach ($file->normalizers as $normalizer) {
                if ($normalizer->appliesToHeaders()) {
                    $line = $normalizer->apply($line);
                }
            }

            return $line;
        };
        $contentType = $answer->header('Content-Type');
        // compare.ignore_cookies: framework session and CSRF cookies whose
        // names change when a unit moves halves (exact names, design 5.2).
        $cookies = array_values(array_filter($answer->cookieNames(), static fn(string $n): bool => !in_array($n, $file->ignoreCookies, true)));
        $lines = [
            'status: ' . $answer->status,
            $header('content-type', $contentType === null ? null : self::contentType($contentType)),
            $header('location', $answer->header('Location')),
            $header('allow', $answer->header('Allow')),
            $header('set-cookie', $cookies === [] ? null : implode(', ', $cookies)),
        ];
        if ($file->isStrangler()) {
            foreach ($answer->cookieAttributes() as [$name, $attributes]) {
                if (!in_array($name, $file->ignoreCookies, true)) {
                    $lines[] = $header('cookie ' . $name, $attributes);
                }
            }
            foreach (self::SECURITY_HEADERS as $name) {
                $value = $answer->header($name);
                if ($value !== null) {
                    $lines[] = $header($name, self::securityValue($name, $value));
                }
            }
        }
        foreach ($answer->notes as $note) {
            $lines[] = 'note: ' . $note;
        }
        $lines[] = '--- body';

        return [...$lines, ...self::body($answer->body, $contentType, $file)];
    }

    /**
     * One security header's compared value: a CSP nonce is new on every
     * request, so it becomes a marker; list headers lose case and spacing
     * differences that mean nothing to a browser.
     */
    private static function securityValue(string $name, string $value): string
    {
        if (str_starts_with($name, 'content-security-policy')) {
            return (string) preg_replace("/'nonce-[A-Za-z0-9+\\/=_\\-]+'/", "'nonce-<NONCE>'", trim($value));
        }
        if (in_array($name, ['access-control-allow-methods', 'access-control-allow-headers', 'access-control-expose-headers'], true)) {
            $items = array_filter(array_map(static fn(string $i): string => strtolower(trim($i)), explode(',', $value)), static fn(string $i): bool => $i !== '');
            sort($items, SORT_STRING);

            return implode(', ', $items);
        }
        if ($name === 'x-frame-options') {
            return strtoupper(trim($value));
        }

        return trim($value);
    }

    /**
     * The lines a transcript shows for an answer but the comparison never
     * reads: which half answered (X-Booster-Half), and what the client did on
     * its own (the CSRF field it sent, the silent sign-in redirects it
     * followed to get the answer).
     *
     * @return list<string>
     */
    public static function reported(ReplayAnswer $answer): array
    {
        $lines = [];
        if ($answer->half() !== null) {
            $lines[] = 'answered by: ' . $answer->half();
        }
        foreach ($answer->actions as $action) {
            $lines[] = 'client: ' . self::origin($action);
        }

        return $lines;
    }

    /**
     * The transcript of a whole replay: every request, its compared view.
     *
     * @param list<ReplayAnswer> $answers
     */
    public static function transcript(ReplayFile $file, array $answers, string $title): string
    {
        $lines = [$title, sprintf('Request file: %s', basename($file->path)), ''];
        foreach ($file->requests as $i => $request) {
            $lines[] = '=== ' . $request->label();
            $answer = $answers[$i] ?? null;
            foreach ($answer === null ? ['(not sent)'] : [...self::reported($answer), ...self::view($answer, $file)] as $line) {
                $lines[] = $line;
            }
            $lines[] = '';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Compares the replay with the baseline. Returns one entry per request
     * whose view differs, in request order.
     *
     * @param list<ReplayAnswer> $baseline
     * @param list<ReplayAnswer> $replay
     *
     * @return list<array{request: ReplayRequest, diff: list<string>}>
     */
    public static function differences(ReplayFile $file, array $baseline, array $replay): array
    {
        $out = [];
        foreach ($file->requests as $i => $request) {
            $before = isset($baseline[$i]) ? self::view($baseline[$i], $file) : ['(not sent)'];
            $after = isset($replay[$i]) ? self::view($replay[$i], $file) : ['(not sent)'];
            if ($before !== $after) {
                $out[] = ['request' => $request, 'diff' => self::diff($before, $after)];
            }
        }

        return $out;
    }

    /**
     * A unified-style line diff ("-" baseline, "+" replay, " " context),
     * trimmed to the changed lines and one line of context around each run.
     * Small views get a real longest-common-subsequence diff; huge ones fall
     * back to the first line that differs, so a 5 MB page never stalls a run.
     *
     * @param list<string> $before
     * @param list<string> $after
     *
     * @return list<string>
     */
    public static function diff(array $before, array $after): array
    {
        $n = count($before);
        $m = count($after);
        if ($n * $m > 4_000_000) {
            for ($i = 0; $i < min($n, $m) && $before[$i] === $after[$i]; $i++) {
                // Walk to the first difference.
            }

            return ['  (large answer: showing the first line that differs)', '- ' . ($before[$i] ?? '(end)'), '+ ' . ($after[$i] ?? '(end)')];
        }
        // Longest common subsequence, filled from the end.
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $before[$i] === $after[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }
        $ops = [];
        $i = 0;
        $j = 0;
        while ($i < $n || $j < $m) {
            if ($i < $n && $j < $m && $before[$i] === $after[$j]) {
                $ops[] = ['  ', $before[$i]];
                $i++;
                $j++;
            } elseif ($i < $n && ($j >= $m || $lcs[$i + 1][$j] >= $lcs[$i][$j + 1])) {
                // The baseline's line first, then the replay's, like diff -u.
                $ops[] = ['- ', $before[$i]];
                $i++;
            } else {
                $ops[] = ['+ ', $after[$j]];
                $j++;
            }
        }
        $keep = [];
        foreach ($ops as $k => [$mark]) {
            if ($mark !== '  ') {
                $keep[$k - 1] = true;
                $keep[$k] = true;
                $keep[$k + 1] = true;
            }
        }
        $lines = [];
        $last = -2;
        foreach ($ops as $k => [$mark, $text]) {
            if (!isset($keep[$k])) {
                continue;
            }
            if ($last >= 0 && $k > $last + 1) {
                $lines[] = '  ...';
            }
            $lines[] = $mark . $text;
            $last = $k;
        }

        return $lines;
    }

    /**
     * "text/html;charset=UTF-8" and "text/html; charset=utf-8" are the same
     * answer to a browser; "text/html" without a charset is not, so the
     * charset stays.
     */
    private static function contentType(string $value): string
    {
        $parts = array_map('trim', explode(';', strtolower($value)));

        return implode('; ', array_filter($parts, static fn(string $p): bool => $p !== ''));
    }

    private static function origin(string $text): string
    {
        return str_replace([self::APP_ORIGIN, 'http://localhost:8765'], '<app>', $text);
    }

    /**
     * @return list<string>
     */
    private static function body(string $body, ?string $contentType, ReplayFile $file): array
    {
        if ($body === '') {
            return ['(empty)'];
        }
        if (preg_match('//u', $body) !== 1) {
            return [sprintf('(binary, %d bytes, sha256 %s)', strlen($body), hash('sha256', $body))];
        }
        $body = self::origin($body);
        foreach ($file->normalizers as $normalizer) {
            if ($normalizer->appliesToBody()) {
                $body = $normalizer->apply($body);
            }
        }
        if ($file->isStrangler()) {
            $body = (new ReplayNormalizer('strangler', [...ReplayNormalizer::STRANGLER_CSRF, ...ReplayNormalizer::STRANGLER_PATHS]))->apply($body);
        }
        if ($file->compareJson === ReplayFile::COMPARE_JSON_VALUE) {
            $trimmed = trim($body);
            $looksJson = ($contentType !== null && str_contains(strtolower($contentType), 'json'))
                || ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '['));
            if ($looksJson) {
                try {
                    $decoded = json_decode($trimmed, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
                    $pretty = json_encode(self::canonical($decoded), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    if (is_string($pretty)) {
                        return explode("\n", $pretty);
                    }
                } catch (\JsonException) {
                    // Not JSON after all: compare it as text.
                }
            }
        }
        $body = str_replace("\r\n", "\n", $body);
        if ($file->compareWhitespace === ReplayFile::COMPARE_EXACT) {
            return explode("\n", rtrim($body, "\n"));
        }
        $lines = [];
        foreach (explode("\n", $body) as $line) {
            $line = trim((string) preg_replace('/\s+/u', ' ', $line));
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines === [] ? ['(whitespace only)'] : $lines;
    }

    /**
     * JSON compared by value: object keys sorted, and a whole float written as
     * an integer (PHP 7.1 and later print 3.0 where 5.6 printed 3; JavaScript
     * reads both as the same number).
     */
    private static function canonical(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $out = [];
            foreach (get_object_vars($value) as $key => $item) {
                $out[(string) $key] = self::canonical($item);
            }
            ksort($out, SORT_STRING);

            return (object) $out;
        }
        if (is_array($value)) {
            return array_map(self::canonical(...), $value);
        }
        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            return (int) $value;
        }

        return $value;
    }
}
