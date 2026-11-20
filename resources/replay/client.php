<?php
// Booster's replay client. It runs inside the gate container, next to the app's
// built-in PHP server, and sends the request file's requests the way a browser
// would: one sequence at a time, each with its own cookie jar, never following
// a redirect. It records each raw answer and leaves every judgement to Booster.
//
// Usage: php client.php <plan.json> <result.json> <port>
//
// It must parse and run on every PHP the gate images carry, 5.5 and up: no ??,
// no scalar or return types, no closures with fn, no [...] destructuring, and
// no extensions beyond what php:*-cli always has (no curl, no mbstring).
// Like a road crew's test pilot, it rides every hop and reports back.

error_reporting(E_ALL);
ini_set('display_errors', '1');

$plan = json_decode(file_get_contents($argv[1]), true);
$resultFile = $argv[2];
$port = (int) $argv[3];
$timeout = isset($plan['timeout']) ? (int) $plan['timeout'] : 30;

function booster_replay_wait_for_server($port, $seconds)
{
    $until = microtime(true) + $seconds;
    while (microtime(true) < $until) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
        if ($socket) {
            fclose($socket);
            return true;
        }
        usleep(100000);
    }
    return false;
}

function booster_replay_fill($text, $captured, $encoded)
{
    foreach ($captured as $name => $value) {
        $text = str_replace('{{' . $name . '}}', $value, $text);
        if ($encoded) {
            $text = str_replace('%7B%7B' . $name . '%7D%7D', rawurlencode($value), $text);
        }
    }
    return $text;
}

function booster_replay_dechunk($body)
{
    $out = '';
    while ($body !== '') {
        $pos = strpos($body, "\r\n");
        if ($pos === false) {
            break;
        }
        $size = hexdec(trim(substr($body, 0, $pos)));
        if ($size <= 0) {
            break;
        }
        $out .= substr($body, $pos + 2, $size);
        $body = (string) substr($body, $pos + 2 + $size + 2);
    }
    return $out;
}

function booster_replay_send($port, $timeout, $method, $target, $headers, $body)
{
    $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 5);
    if (!$socket) {
        return array('status' => 0, 'headers' => array(), 'body' => '', 'error' => 'could not connect: ' . $errstr);
    }
    stream_set_timeout($socket, $timeout);
    $lines = array($method . ' ' . $target . ' HTTP/1.1', 'Host: 127.0.0.1:' . $port, 'Connection: close');
    foreach ($headers as $pair) {
        $lines[] = $pair[0] . ': ' . $pair[1];
    }
    if ($body !== '' || in_array($method, array('POST', 'PUT', 'PATCH'), true)) {
        $lines[] = 'Content-Length: ' . strlen($body);
    }
    fwrite($socket, implode("\r\n", $lines) . "\r\n\r\n" . $body);
    $raw = '';
    $started = microtime(true);
    while (!feof($socket)) {
        $chunk = fread($socket, 65536);
        if ($chunk === false) {
            break;
        }
        $raw .= $chunk;
        $meta = stream_get_meta_data($socket);
        if (!empty($meta['timed_out']) || microtime(true) - $started > $timeout) {
            fclose($socket);
            return array('status' => 0, 'headers' => array(), 'body' => '', 'error' => 'no answer within ' . $timeout . ' seconds');
        }
    }
    fclose($socket);

    $split = strpos($raw, "\r\n\r\n");
    if ($split === false) {
        return array('status' => 0, 'headers' => array(), 'body' => '', 'error' => 'the server closed the connection without an answer');
    }
    $head = explode("\r\n", substr($raw, 0, $split));
    $responseBody = (string) substr($raw, $split + 4);
    $status = 0;
    if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', array_shift($head), $m)) {
        $status = (int) $m[1];
    }
    $responseHeaders = array();
    $chunked = false;
    foreach ($head as $line) {
        $parts = explode(':', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }
        $name = trim($parts[0]);
        $value = trim($parts[1]);
        $responseHeaders[] = array($name, $value);
        if (strcasecmp($name, 'Transfer-Encoding') === 0 && stripos($value, 'chunked') !== false) {
            $chunked = true;
        }
    }
    if ($chunked) {
        $responseBody = booster_replay_dechunk($responseBody);
    }
    return array('status' => $status, 'headers' => $responseHeaders, 'body' => $responseBody, 'error' => null);
}

function booster_replay_header($headers, $name)
{
    foreach ($headers as $pair) {
        if (strcasecmp($pair[0], $name) === 0) {
            return $pair[1];
        }
    }
    return null;
}

// Applies an answer's Set-Cookie headers to the sequence's cookie jar, the way
// a browser would: a cookie with an empty value, "deleted", Max-Age 0 or an
// Expires in the past is removed.
function booster_replay_update_jar($jar, $headers)
{
    foreach ($headers as $pair) {
        if (strcasecmp($pair[0], 'Set-Cookie') !== 0) {
            continue;
        }
        $attributes = explode(';', $pair[1]);
        $nameValue = explode('=', trim(array_shift($attributes)), 2);
        if (count($nameValue) !== 2 || $nameValue[0] === '') {
            continue;
        }
        $deleted = ($nameValue[1] === '' || $nameValue[1] === 'deleted');
        foreach ($attributes as $attribute) {
            $attribute = trim($attribute);
            if (stripos($attribute, 'max-age=') === 0 && (int) substr($attribute, 8) <= 0) {
                $deleted = true;
            }
            if (stripos($attribute, 'expires=') === 0) {
                $when = strtotime(substr($attribute, 8));
                if ($when !== false && $when < time()) {
                    $deleted = true;
                }
            }
        }
        if ($deleted) {
            unset($jar[$nameValue[0]]);
        } else {
            $jar[$nameValue[0]] = $nameValue[1];
        }
    }
    return $jar;
}

function booster_replay_with_cookies($headers, $cookies)
{
    $out = array();
    foreach ($headers as $pair) {
        if (strcasecmp($pair[0], 'Cookie') !== 0) {
            $out[] = $pair;
        }
    }
    if (count($cookies) > 0) {
        $cookieParts = array();
        foreach ($cookies as $name => $value) {
            $cookieParts[] = $name . '=' . $value;
        }
        $out[] = array('Cookie', implode('; ', $cookieParts));
    }
    return $out;
}

function booster_replay_attribute($tag, $attribute)
{
    if (preg_match('/\s' . $attribute . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $tag, $m)) {
        $value = isset($m[2]) && $m[1] === '' ? $m[2] : $m[1];
        return html_entity_decode($value, ENT_QUOTES, 'UTF-8');
    }
    return null;
}

// The CSRF fields a page carries: every hidden input named like a CSRF token
// (Laravel's _token, CakePHP's _csrfToken and its _Token[...] form protection
// fields, Slim-Csrf's csrf_name and csrf_value, anything with csrf or xsrf in
// its name), and the first CSRF meta tag (csrf-token, csrfToken). Returns null
// when the page has neither.
function booster_replay_csrf_find($body)
{
    $fields = array();
    $meta = null;
    if (preg_match_all('/<input\b[^>]*>/i', $body, $inputs)) {
        foreach ($inputs[0] as $tag) {
            $name = booster_replay_attribute($tag, 'name');
            $value = booster_replay_attribute($tag, 'value');
            if ($name === null || $value === null || isset($fields[$name])) {
                continue;
            }
            if (preg_match('/^(?:_token|_csrfToken|authenticity_token|__RequestVerificationToken|[\w.\-]*(?:csrf|xsrf)[\w.\-]*)$/i', $name) || strpos($name, '_Token[') === 0) {
                $fields[$name] = $value;
            }
        }
    }
    if (preg_match_all('/<meta\b[^>]*>/i', $body, $metas)) {
        foreach ($metas[0] as $tag) {
            $name = booster_replay_attribute($tag, 'name');
            $content = booster_replay_attribute($tag, 'content');
            if ($name !== null && $content !== null && preg_match('/^(?:csrf-token|csrfToken|_csrf|csrf)$/i', $name)) {
                $meta = $content;
                break;
            }
        }
    }
    if (count($fields) === 0 && $meta === null) {
        return null;
    }
    $token = $meta;
    if ($token === null) {
        foreach ($fields as $name => $value) {
            if (strpos($name, '_Token[') !== 0) {
                $token = $value;
                break;
            }
        }
    }
    return array('fields' => $fields, 'token' => $token);
}

// Sets one urlencoded form field, replacing any value the form already had.
function booster_replay_with_field($body, $name, $value)
{
    $kept = array();
    foreach ($body === '' ? array() : explode('&', $body) as $pair) {
        $parts = explode('=', $pair, 2);
        if (urldecode($parts[0]) === $name) {
            continue;
        }
        $kept[] = $pair;
    }
    $kept[] = rawurlencode($name) . '=' . rawurlencode($value);
    return implode('&', $kept);
}

// The path and query of a Location on this app (relative, or absolute on
// 127.0.0.1 or localhost), or null for anywhere else.
function booster_replay_local_target($location)
{
    if ($location === null || $location === '') {
        return null;
    }
    if (preg_match('#^https?://(?:127\.0\.0\.1|localhost)(?::\d+)?(/[^\#]*)?#i', $location, $m)) {
        return isset($m[1]) && $m[1] !== '' ? $m[1] : '/';
    }
    if ($location[0] === '/' && (strlen($location) === 1 || $location[1] !== '/')) {
        $hash = strpos($location, '#');
        return $hash === false ? $location : substr($location, 0, $hash);
    }
    return null;
}

function booster_replay_is_silent($target, $prefixes)
{
    $path = parse_url($target, PHP_URL_PATH);
    if (!is_string($path)) {
        return false;
    }
    foreach ($prefixes as $prefix) {
        if ($path === $prefix || strpos($path, rtrim($prefix, '/') . '/') === 0) {
            return true;
        }
    }
    return false;
}

$answers = array();
if (!booster_replay_wait_for_server($port, 20)) {
    file_put_contents($resultFile, json_encode(array('started' => false, 'answers' => array())));
    fwrite(STDERR, "The app's built-in PHP server did not start within 20 seconds.\n");
    exit(3);
}

$silent = isset($plan['silent_sign_in']) ? $plan['silent_sign_in'] : array();

foreach ($plan['sequences'] as $sequence) {
    $jar = array();
    $captured = array();
    $previous = null;
    foreach ($sequence['requests'] as $request) {
        $notes = array();
        $actions = array();
        $cookies = $jar;
        foreach ($request['cookies'] as $name => $value) {
            $cookies[$name] = booster_replay_fill($value, $captured, false);
        }
        $baseHeaders = array();
        foreach ($request['headers'] as $pair) {
            $baseHeaders[] = array($pair[0], booster_replay_fill($pair[1], $captured, false));
        }
        $target = booster_replay_fill($request['target'], $captured, true);
        $body = booster_replay_fill($request['body'], $captured, true);

        // "csrf": true takes the CSRF field from the previous answer in the
        // sequence, so a sequence keeps working when the form page moves from
        // one framework's token to the other's. A page with no CSRF field (a
        // raw PHP form that checks none) sends the request as it is: whether
        // that still answers the same is the comparison's job, not a note's.
        if (isset($request['csrf'])) {
            $csrfFound = $previous === null ? null : booster_replay_csrf_find($previous['body']);
            if ($csrfFound === null) {
                $actions[] = 'csrf: the previous answer has no CSRF field, sent without one';
            } elseif ($request['csrf'] === 'form' && count($csrfFound['fields']) > 0) {
                foreach ($csrfFound['fields'] as $name => $value) {
                    $body = booster_replay_with_field($body, $name, $value);
                }
                $actions[] = 'csrf: sent ' . implode(', ', array_keys($csrfFound['fields'])) . ' from the previous answer';
            } elseif ($csrfFound['token'] !== null) {
                $baseHeaders[] = array('X-CSRF-Token', $csrfFound['token']);
                $actions[] = 'csrf: sent the X-CSRF-Token header from the previous answer';
            } else {
                $actions[] = 'csrf: the previous answer has form protection fields but no CSRF token, sent without one';
            }
        }

        $answer = booster_replay_send($port, $timeout, $request['method'], $target, booster_replay_with_cookies($baseHeaders, $cookies), $body);
        $jar = booster_replay_update_jar($jar, $answer['headers']);

        // compare.silent_sign_in: a redirect to one of these paths is a
        // browser's silent sign-in round trip (the Laravel half signing in on
        // its own session, the fake driver in a replay). Follow it, and the
        // redirect back to the page asked for, and record that page's answer.
        if (count($silent) > 0 && ($request['method'] === 'GET' || $request['method'] === 'HEAD')) {
            $getHeaders = array();
            foreach ($baseHeaders as $pair) {
                if (strcasecmp($pair[0], 'Content-Type') !== 0) {
                    $getHeaders[] = $pair;
                }
            }
            for ($hop = 0; $hop < 6 && $answer['status'] >= 300 && $answer['status'] < 400; $hop++) {
                $next = booster_replay_local_target(booster_replay_header($answer['headers'], 'Location'));
                if ($next === null) {
                    break;
                }
                $isSilent = booster_replay_is_silent($next, $silent);
                if (!$isSilent && ($hop === 0 || $next !== $target)) {
                    break;
                }
                $actions[] = 'sign-in: followed ' . $answer['status'] . ' to ' . $request['method'] . ' ' . $next;
                $answer = booster_replay_send($port, $timeout, $request['method'], $next, booster_replay_with_cookies($getHeaders, $jar), '');
                $jar = booster_replay_update_jar($jar, $answer['headers']);
                if (!$isSilent) {
                    break;
                }
            }
        }

        foreach ($request['capture'] as $name => $pattern) {
            $haystack = $answer['body'];
            foreach ($answer['headers'] as $pair) {
                $haystack .= "\n" . $pair[0] . ': ' . $pair[1];
            }
            if (preg_match('~' . str_replace('~', '\~', $pattern) . '~', $haystack, $found) && isset($found[1])) {
                $captured[$name] = $found[1];
            } else {
                $captured[$name] = '';
                $notes[] = 'capture ' . $name . ' matched nothing';
            }
        }
        $previous = $answer;
        $answers[] = array(
            'number' => $request['number'],
            'status' => $answer['status'],
            'headers' => $answer['headers'],
            'body' => base64_encode($answer['body']),
            'body_encoding' => 'base64',
            'error' => $answer['error'],
            'notes' => $notes,
            'actions' => $actions,
        );
    }
}

file_put_contents($resultFile, json_encode(array('started' => true, 'answers' => $answers)));
exit(0);
