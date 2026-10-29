<?php

declare(strict_types=1);

/*
 * index.php: the front controller for the static front page (ADR-0017).
 *
 * Same bytes in every stack repo: boot HELMET, resolve the requested path
 * into the site folder, and serve it. Every response, including the 404s,
 * carries HELMET's headers. The only solo is the nonce.
 *
 * Agent-ready (ADR-0019): a page with a Markdown twin beside it (index.md
 * for index.html, written by Squire's bin/agent-ready.php) is sent as that
 * twin to a client that asks for text/markdown, and every HTML answer
 * points at its twin with a Link header. A twin can also be fetched by its
 * own name. Any other .md in the site folder (a README) stays private.
 *
 * The site folder comes from SQUIRE_STATIC_ROOT when that variable is set and non-empty: a path relative to
 * the app directory, the parent of public/. Otherwise it is ../../site, because the app lives in deploy/
 * beside site/.
 */

require __DIR__ . '/../vendor/autoload.php';

use function Waynestate\Helmet\helmet_boot;

// HELMET's citadel preset names the platform's front proxy.
$helmet = helmet_boot([
    'preset' => 'citadel',
]);

$notFound = static function (): never {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Not found</title></head><body>'
        . '<h1>Not found</h1><p>The page you asked for is not here.</p>'
        . '</body></html>';
    exit;
};

$staticRoot = getenv('SQUIRE_STATIC_ROOT');
$siteRoot = $staticRoot === false || $staticRoot === ''
    ? realpath(__DIR__ . '/../../site')
    : realpath(__DIR__ . '/' . $staticRoot);
if ($siteRoot === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'The site folder is missing.';
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = rawurldecode($path);

// Reject what must never leave the machine: a NUL byte, any dot segment
// (.htaccess, .., ./), and the extensions that are not site assets.
if (str_contains($path, "\0")) {
    $notFound();
}
foreach (explode('/', $path) as $segment) {
    if ($segment !== '' && str_starts_with($segment, '.')) {
        $notFound();
    }
}
$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
if (in_array($extension, ['php', 'phar'], true)) {
    $notFound();
}

// Resolve inside the site folder. A directory maps to its index.html.
$resolved = realpath($siteRoot . $path);
if ($resolved !== false && is_dir($resolved)) {
    $resolved = realpath($resolved . '/index.html') ?: false;
}
if ($resolved === false
    || !str_starts_with($resolved, $siteRoot . DIRECTORY_SEPARATOR)
    || !is_file($resolved)
) {
    $notFound();
}

// A .md is served only when it is a page's twin: the .html of the same name sits beside it.
if (strtolower(pathinfo($resolved, PATHINFO_EXTENSION)) === 'md'
    && !is_file(substr($resolved, 0, -3) . '.html')
) {
    $notFound();
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Method not allowed';
    exit;
}

// MIME types the front pages ship. Text types carry a charset.
$mime = [
    'html' => 'text/html; charset=utf-8',
    'css' => 'text/css; charset=utf-8',
    'js' => 'text/javascript; charset=utf-8',
    'mjs' => 'text/javascript; charset=utf-8',
    'json' => 'application/json; charset=utf-8',
    'svg' => 'image/svg+xml; charset=utf-8',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'avif' => 'image/avif',
    'ico' => 'image/x-icon',
    'woff2' => 'font/woff2',
    'woff' => 'font/woff',
    'ttf' => 'font/ttf',
    'txt' => 'text/plain; charset=utf-8',
    'md' => 'text/markdown; charset=utf-8',
    'xml' => 'application/xml; charset=utf-8',
    'webmanifest' => 'application/manifest+json; charset=utf-8',
    'pdf' => 'application/pdf',
    'mp4' => 'video/mp4',
    'webm' => 'video/webm',
];
// The type comes from the file that is actually served: / and any directory
// resolve to their index.html, whose name the request path does not carry.
$extension = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
if (!isset($mime[$extension])) {
    $notFound();
}

// The twin of an HTML page, and whether the client would rather have it.
// Only an explicit text/markdown counts: a browser's */* never turns a page
// into Markdown. Asked for at least as strongly as text/html, it wins.
$twin = null;
$twinUrl = null;
if ($extension === 'html') {
    $candidate = substr($resolved, 0, -5) . '.md';
    if (is_file($candidate)) {
        $twin = $candidate;
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($candidate, strlen($siteRoot)));
        $twinUrl = implode('/', array_map('rawurlencode', explode('/', $relative)));
    }
}
$prefersMarkdown = static function (string $accept): bool {
    $quality = ['text/markdown' => null, 'text/html' => null];
    foreach (explode(',', $accept) as $range) {
        $parts = array_map('trim', explode(';', $range));
        $type = strtolower(array_shift($parts));
        if (!array_key_exists($type, $quality)) {
            continue;
        }
        $q = 1.0;
        foreach ($parts as $parameter) {
            if (preg_match('/^q\s*=\s*([01](?:\.\d{0,3})?)$/i', $parameter, $match)) {
                $q = (float) $match[1];
            }
        }
        $quality[$type] = $q;
    }

    return $quality['text/markdown'] !== null
        && $quality['text/markdown'] > 0
        && $quality['text/markdown'] >= ($quality['text/html'] ?? 0.0);
};

if ($twin !== null && $prefersMarkdown($_SERVER['HTTP_ACCEPT'] ?? '')) {
    $body = file_get_contents($twin);
    if ($body === false) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'The page could not be read.';
        exit;
    }
    header('Content-Type: ' . $mime['md']);
    header('Content-Location: ' . $twinUrl);
    header('Vary: Accept');
    header('Cache-Control: no-cache');
    header('Content-Length: ' . strlen($body));
    if ($method === 'GET') {
        echo $body;
    }
    exit;
}

// HTML: the CSP nonce changes per request, so the page is rewritten on the
// fly and never cached. Every <script start tag without a nonce gets one.
if ($extension === 'html') {
    $body = file_get_contents($resolved);
    if ($body === false) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'The page could not be read.';
        exit;
    }
    $nonce = $helmet['nonce'];
    $body = preg_replace_callback(
        '/<script(?=[\s>])([^>]*)>/i',
        static function (array $match) use ($nonce): string {
            if (preg_match('/\bnonce\s*=/i', $match[1])) {
                return $match[0];
            }
            return '<script nonce="' . $nonce . '"' . $match[1] . '>';
        },
        $body,
    );
    header('Content-Type: ' . $mime['html']);
    if ($twinUrl !== null) {
        header('Link: <' . $twinUrl . '>; rel="alternate"; type="text/markdown"');
        header('Vary: Accept');
    }
    header('Cache-Control: no-cache');
    header('Content-Length: ' . strlen($body));
    if ($method === 'GET') {
        echo $body;
    }
    exit;
}

// Everything else: a stable identity, a cache life of an hour, and a 304
// when the client already holds this copy.
$stat = stat($resolved);
$etag = '"' . sha1($stat['mtime'] . '-' . $stat['size']) . '"';
$lastModified = gmdate('D, d M Y H:i:s', $stat['mtime']) . ' GMT';

$ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
$matches = $ifNoneMatch !== ''
    && ($ifNoneMatch === '*' || in_array($etag, array_map('trim', explode(',', $ifNoneMatch)), true));
if ($matches) {
    http_response_code(304);
    header('ETag: ' . $etag);
    header('Last-Modified: ' . $lastModified);
    exit;
}

header('Content-Type: ' . $mime[$extension]);
header('ETag: ' . $etag);
header('Last-Modified: ' . $lastModified);
header('Cache-Control: public, max-age=3600');
header('Content-Length: ' . $stat['size']);
if ($method === 'GET') {
    readfile($resolved);
}
