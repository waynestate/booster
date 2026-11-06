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

$answers = array();
if (!booster_replay_wait_for_server($port, 20)) {
    file_put_contents($resultFile, json_encode(array('started' => false, 'answers' => array())));
    fwrite(STDERR, "The app's built-in PHP server did not start within 20 seconds.\n");
    exit(3);
}

foreach ($plan['sequences'] as $sequence) {
    $jar = array();
    $captured = array();
    foreach ($sequence['requests'] as $request) {
        $notes = array();
        $cookies = $jar;
        foreach ($request['cookies'] as $name => $value) {
            $cookies[$name] = booster_replay_fill($value, $captured, false);
        }
        $headers = array();
        foreach ($request['headers'] as $pair) {
            $headers[] = array($pair[0], booster_replay_fill($pair[1], $captured, false));
        }
        if (count($cookies) > 0) {
            $cookieParts = array();
            foreach ($cookies as $name => $value) {
                $cookieParts[] = $name . '=' . $value;
            }
            $headers[] = array('Cookie', implode('; ', $cookieParts));
        }
        $target = booster_replay_fill($request['target'], $captured, true);
        $body = booster_replay_fill($request['body'], $captured, true);
        $answer = booster_replay_send($port, $timeout, $request['method'], $target, $headers, $body);

        foreach ($answer['headers'] as $pair) {
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
        $answers[] = array(
            'number' => $request['number'],
            'status' => $answer['status'],
            'headers' => $answer['headers'],
            'body' => base64_encode($answer['body']),
            'body_encoding' => 'base64',
            'error' => $answer['error'],
            'notes' => $notes,
        );
    }
}

file_put_contents($resultFile, json_encode(array('started' => true, 'answers' => $answers)));
exit(0);
