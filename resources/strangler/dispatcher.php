<?php

/*
 * public/index.php: the strangler's dispatcher, written by Booster's mount
 * step. It is yours (app-owned), and the finish step puts Laravel's stock
 * front controller back once nothing is left in legacy/.
 *
 * One framework per request, decided before any autoloader loads:
 *
 *   1. A real file under public/ never gets here (Apache and php -S serve it).
 *   2. The app's base path is stripped (a sub-path deploy such as /portal/),
 *      and the path is decoded with %2F kept encoded.
 *   3. Laravel-owned prefixes (/_laravel/) go to Laravel, and so does /build/
 *      when legacy has no file there.
 *   4. Moved routes: bootstrap/booster-moved.php (Booster writes it) lists
 *      method and regex per moved unit; HEAD counts as GET; first match wins. The regex sees
 *      the fully decoded path (%2F too), as Laravel's own matcher does.
 *      (Skipped, with 3, when a legacy rule moved into public/.htaccess named
 *      a legacy file: that request stays legacy's, as before.)
 *   5. A real file under the legacy docroot is served as it was: static files
 *      streamed with the type the server in front gave (php -S or Apache), ETag,
 *      Last-Modified and 304; a .php script runs as its own script, as Apache
 *      ran it before.
 *   6. Everything else goes to the legacy front controller, with SCRIPT_NAME,
 *      SCRIPT_FILENAME, PHP_SELF and DOCUMENT_ROOT set to what it saw before.
 *
 * Laravel's autoloader never loads on a legacy request and legacy's never on
 * a Laravel one, so the two App\ namespaces and the two __() helpers never
 * meet. Must parse on PHP 8.0 and later. Two kitchens, one door, and the
 * maitre d' never lets the cooks swap aprons.
 */

$booster_route = (static function (): array {
    // The mount writes these three lines; keep their shape.
    $legacyDocroot = 'legacy/public'; // booster:legacy-docroot
    $legacyFront = 'index.php'; // booster:legacy-front
    $legacyRouter = true; // booster:legacy-router
    $laravelPrefixes = ['/_laravel'];

    $root = dirname(__DIR__);
    $half = static function (string $name): void {
        $flag = getenv('BOOSTER_REPLAY');
        if ($flag === false && isset($_SERVER['BOOSTER_REPLAY'])) {
            $flag = (string) $_SERVER['BOOSTER_REPLAY'];
        }
        if ($flag === '1' && !headers_sent()) {
            header('X-Booster-Half: ' . $name);
        }
    };

    // The request path, without the query, in origin form.
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $query = strpos($uri, '?');
    $rawPath = $query === false ? $uri : substr($uri, 0, $query);
    if (preg_match('#^[A-Za-z][A-Za-z0-9+.-]*://[^/]*#', $rawPath, $m) === 1) {
        $rawPath = substr($rawPath, strlen($m[0]));
    }
    if ($rawPath === '' || $rawPath[0] !== '/') {
        $rawPath = '/' . $rawPath;
    }
    // Decoded, with %2F kept encoded, so an encoded slash never splits a segment.
    $path = implode('%2F', array_map('rawurldecode', preg_split('/%2F/i', $rawPath) ?: [$rawPath]));

    // The base path: where this front controller sits under the host.
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $base = rtrim(str_replace('\\', '/', dirname($scriptName)), '/.');
    if ($base !== '' && ($path === $base || strncmp($path, $base . '/', strlen($base) + 1) === 0)) {
        $path = substr($path, strlen($base));
        if ($path === '' || $path === false) {
            $path = '/';
        }
    }
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    $legacyRoot = realpath($root . '/' . $legacyDocroot);
    // A real file or folder under the legacy docroot, never a dotfile (but
    // .well-known/), confined to that docroot. Null when there is none.
    $legacyPath = static function (string $path, bool $isRoot = false) use ($legacyRoot): ?string {
        if ($legacyRoot === false || strpos($path, "\0") !== false) {
            return null;
        }
        if ($path === '/') {
            return $isRoot ? $legacyRoot : null;
        }
        foreach (explode('/', trim($path, '/')) as $i => $segment) {
            if ($segment === '' || ($segment[0] === '.' && !($i === 0 && $segment === '.well-known'))) {
                return null;
            }
        }
        $real = realpath($legacyRoot . $path);
        if ($real === false || strncmp($real, $legacyRoot . DIRECTORY_SEPARATOR, strlen($legacyRoot) + 1) !== 0) {
            return null;
        }

        return $real;
    };
    // A real file under the legacy docroot; with $index, a folder gives its
    // index.php or index.html (php -S looks in that order, and so does
    // Apache's DirectoryIndex on Citadel). Null when there is none.
    $legacyFile = static function (string $path, bool $index) use ($legacyPath, $legacyRoot): ?string {
        $real = $legacyPath($path, $index);
        if ($real !== null && $index && is_dir($real)) {
            foreach (['index.php', 'index.html'] as $name) {
                $found = realpath($real . DIRECTORY_SEPARATOR . $name);
                if ($found !== false && is_file($found) && strncmp($found, $legacyRoot . DIRECTORY_SEPARATOR, strlen((string) $legacyRoot) + 1) === 0) {
                    return $found;
                }
            }

            return null;
        }

        return $real !== null && is_file($real) ? $real : null;
    };
    // Folders answer as they did before the mount. Under Apache the folder
    // rules in public/.htaccess answer them (a marked request gets its index
    // here); any other folder request went to the legacy front controller and
    // still does. Under php -S (the replay) the legacy run either had REPLAY's
    // router (serve.front_controller: a folder went to the front controller,
    // as here) or plain php -S, which serves a folder's index with or without
    // the slash and answers its own 404 for a folder with none.
    $plainPhpServer = PHP_SAPI === 'cli-server' && !$legacyRouter;

    // A legacy rewrite rule the mount moved into public/.htaccess sent this
    // request to a legacy file (BOOSTER_LEGACY_FILE: the path itself, a
    // pass-through rule; BOOSTER_LEGACY_TARGET: the rule's target). Apache
    // adds REDIRECT_ after its internal redirect. Clients cannot set these
    // (headers arrive as HTTP_*), and legacy never sees them.
    $target = null;
    foreach ($_SERVER as $key => $value) {
        if (is_string($key) && preg_match('/^(?:REDIRECT_)*BOOSTER_LEGACY_(TARGET|FILE)$/', $key, $m) === 1) {
            if ($target === null && is_string($value) && $value !== '') {
                $target = $m[1] === 'FILE' ? $path : '/' . ltrim($value, '/');
            }
            unset($_SERVER[$key]);
        }
    }

    // 3. Laravel-owned prefixes.
    foreach ($target === null ? $laravelPrefixes : [] as $prefix) {
        if ($path === $prefix || strncmp($path, $prefix . '/', strlen($prefix) + 1) === 0) {
            $half('laravel');

            return ['laravel'];
        }
    }
    if ($target === null && strncmp($path, '/build/', 7) === 0 && $legacyPath($path) === null) {
        $half('laravel');

        return ['laravel'];
    }

    // 4. Moved routes.
    $table = $root . '/bootstrap/booster-moved.php';
    $rows = $target === null && is_file($table) ? require $table : [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        $methods = (array) ($row['methods'] ?? []);
        if (!in_array($method, $methods, true) && !($method === 'HEAD' && in_array('GET', $methods, true))) {
            continue;
        }
        // Matched on the fully decoded path, as Laravel's UriValidator and Slim's
        // router match: /users/1%2F2 is /users/1/2 to both, so it is never sent
        // to a moved users/{id} that Laravel would answer with its own 404.
        if (preg_match((string) $row['regex'], rawurldecode($path)) === 1) {
            $half('laravel');
            // bootstrap/app.php reads this: moved code gets its input as legacy did.
            $_SERVER['BOOSTER_MOVED_UNIT'] = (string) ($row['unit'] ?? '');

            return ['laravel'];
        }
    }

    $half('legacy');
    $legacyDir = $legacyRoot === false ? $root . '/' . $legacyDocroot : $legacyRoot;
    $script = $legacyDir . '/' . $legacyFront;
    $name = $base . '/' . $legacyFront;

    // 5. A real file under the legacy docroot, or the moved rule's target
    // (a missing target is a 404, as Apache answered it before).
    $file = $legacyFile($target ?? $path, $target !== null ? substr($target, -1) === '/' : $plainPhpServer && $path !== '/');
    if ($target !== null && $file === null) {
        http_response_code(404);

        return ['done'];
    }
    $folder = $file === null && $plainPhpServer ? $legacyPath($path) : null;
    if ($folder !== null && is_dir($folder)) {
        // A legacy folder with no index under plain php -S: its own 404 page
        // (PHP 8.5's, the request URI as sent), not the front controller.
        http_response_code(404);
        header('Content-Type: text/html; charset=UTF-8');
        if ($method !== 'HEAD') {
            echo '<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><title>404 Not Found</title><style>' . "\n"
                . 'body { background-color: #fcfcfc; color: #333333; margin: 0; padding:0; }' . "\n"
                . 'h1 { font-size: 1.5em; font-weight: normal; background-color: #9999cc; min-height:2em; line-height:2em; border-bottom: 1px inset black; margin: 0; }' . "\n"
                . 'h1, p { padding-left: 10px; }' . "\n"
                . 'code.url { background-color: #eeeeee; font-family:monospace; padding:0 2px;}' . "\n"
                . '</style>' . "\n"
                . '</head><body><h1>Not Found</h1><p>The requested resource <code class="url">'
                . htmlspecialchars((string) ($_SERVER['REQUEST_URI'] ?? $path), ENT_QUOTES, 'UTF-8', false)
                . '</code> was not found on this server.</p></body></html>';
        }

        return ['done'];
    }
    if ($file !== null && preg_match('/\.(php[0-9s]?|phtml|phar)$/i', $file) === 1) {
        $script = $file;
        $name = $base . '/' . ltrim(str_replace('\\', '/', substr($file, strlen($legacyDir))), '/');
    } elseif ($file !== null) {
        // Answered as the server in front answered it before the mount:
        // php -S (the replay) with its own type table and a charset on text/*,
        // Apache with the system mime.types and no charset. Any method gets
        // the file, as both servers do; HEAD gets no body. The table is the
        // web-facing part of php -S's own (PHP 8.5 knows about 930
        // extensions): one outside it answers with no type here.
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $phpTypes = [
            'css' => 'text/css', 'js' => 'application/javascript', 'mjs' => 'application/javascript',
            'json' => 'application/json', 'map' => 'application/json', 'html' => 'text/html', 'htm' => 'text/html',
            'txt' => 'text/plain', 'csv' => 'text/csv', 'xml' => 'application/xml', 'md' => 'text/markdown',
            'yml' => 'text/yaml', 'yaml' => 'text/yaml', 'svg' => 'image/svg+xml', 'png' => 'image/png',
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'ico' => 'image/vnd.microsoft.icon',
            'webp' => 'image/webp', 'avif' => 'image/avif', 'bmp' => 'image/bmp', 'tif' => 'image/tiff', 'tiff' => 'image/tiff',
            'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf',
            'eot' => 'application/vnd.ms-fontobject', 'pdf' => 'application/pdf', 'zip' => 'application/zip',
            'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wave', 'ogg' => 'audio/ogg',
            'wasm' => 'application/wasm', 'swf' => 'application/x-shockwave-flash', 'rss' => 'application/rss+xml',
            'atom' => 'application/atom+xml', 'webmanifest' => 'application/manifest+json', 'jsonld' => 'application/ld+json',
            'ini' => 'text/plain', 'log' => 'text/plain', 'conf' => 'text/plain', 'text' => 'text/plain', 'tsv' => 'text/tab-separated-values',
            'ics' => 'text/calendar', 'vcf' => 'text/x-vcard', 'sql' => 'application/x-sql', 'rtf' => 'application/rtf',
            'doc' => 'application/msword', 'xls' => 'application/vnd.ms-excel', 'ppt' => 'application/vnd.ms-powerpoint',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'odt' => 'application/vnd.oasis.opendocument.text', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
            'odp' => 'application/vnd.oasis.opendocument.presentation', 'epub' => 'application/epub+zip',
            'tar' => 'application/x-tar', 'gz' => 'application/gzip', '7z' => 'application/x-7z-compressed', 'rar' => 'application/vnd.rar',
            'jar' => 'application/java-archive', 'bin' => 'application/octet-stream', 'xhtml' => 'application/xhtml+xml',
            'xsl' => 'application/xml', 'xslt' => 'application/xslt+xml', 'dtd' => 'application/xml-dtd', 'ps' => 'application/postscript',
            'eps' => 'application/postscript', 'kml' => 'application/vnd.google-earth.kml+xml', 'gpx' => 'application/gpx+xml',
            'geojson' => 'application/geo+json', 'apng' => 'image/apng', 'heic' => 'image/heic', 'svgz' => 'image/svg+xml',
            'mov' => 'video/quicktime', 'avi' => 'video/x-msvideo', 'mpeg' => 'video/mpeg', 'mpg' => 'video/mpeg', 'm4v' => 'video/x-m4v',
            'ogv' => 'video/ogg', 'm4a' => 'audio/mp4', 'aac' => 'audio/x-aac', 'flac' => 'audio/x-flac', 'oga' => 'audio/ogg', 'opus' => 'audio/ogg',
        ];
        $type = null;
        if (PHP_SAPI === 'cli-server') {
            $type = $phpTypes[$ext] ?? null;
            if ($type !== null && strncmp($type, 'text/', 5) === 0) {
                $type .= '; charset=UTF-8';
            }
        } else {
            // PHP would append default_charset to a text/ type; Apache sends none.
            ini_set('default_charset', '');
            $mimeTypes = @file_get_contents('/etc/mime.types');
            if (is_string($mimeTypes) && $ext !== '') {
                // "type ext ext ...", one type per line; the extension is a whole token.
                if (preg_match('/^([^#\s]+)(?:[ \t]+\S+)*?[ \t]+' . preg_quote($ext, '/') . '(?=[ \t]*$|[ \t])/mi', $mimeTypes, $m) === 1) {
                    $type = $m[1];
                }
            } elseif ($ext !== '') {
                $type = $phpTypes[$ext] ?? null;
                if ($type === 'application/javascript') {
                    $type = 'text/javascript';
                }
            }
        }
        $size = (int) filesize($file);
        $mtime = (int) filemtime($file);
        $etag = '"' . dechex($size) . '-' . dechex($mtime) . '"';
        if ($type === null) {
            // No type, as the server sent none (PHP would add text/html).
            ini_set('default_mimetype', '');
        } else {
            header('Content-Type: ' . $type);
        }
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        header('ETag: ' . $etag);
        $match = (string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
        $since = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? strtotime((string) $_SERVER['HTTP_IF_MODIFIED_SINCE']) : false;
        $fresh = ($method === 'GET' || $method === 'HEAD') && ($match !== ''
            ? in_array($etag, array_map('trim', explode(',', $match)), true) || trim($match) === '*'
            : $since !== false && $since >= $mtime);
        if ($fresh) {
            http_response_code(304);

            return ['done'];
        }
        header('Content-Length: ' . $size);
        if ($method !== 'HEAD') {
            readfile($file);
        }

        return ['done'];
    }

    // 6. The legacy front controller (or the legacy script named), with the
    // server variables it saw before the mount.
    $publicDir = realpath(__DIR__);
    $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string) $_SERVER['DOCUMENT_ROOT']) : false;
    if ($documentRoot === false || $documentRoot === $publicDir) {
        $_SERVER['DOCUMENT_ROOT'] = $legacyDir;
    }
    $_SERVER['SCRIPT_FILENAME'] = $script;
    $_SERVER['SCRIPT_NAME'] = $name;
    $_SERVER['PHP_SELF'] = $name;
    chdir(dirname($script));

    return ['legacy'];
})();

if ($booster_route[0] === 'legacy') {
    unset($booster_route);
    require $_SERVER['SCRIPT_FILENAME'];
} elseif ($booster_route[0] === 'laravel') {
    unset($booster_route);

    // Laravel's stock front controller.
    define('LARAVEL_START', microtime(true));

    // Determine if the application is in maintenance mode...
    if (file_exists($maintenance = __DIR__ . '/../storage/framework/maintenance.php')) {
        require $maintenance;
    }

    // Register the Composer autoloader...
    require __DIR__ . '/../vendor/autoload.php';

    // Bootstrap Laravel and handle the request...
    /** @var Illuminate\Foundation\Application $app */
    $app = require_once __DIR__ . '/../bootstrap/app.php';

    $app->handleRequest(Illuminate\Http\Request::capture());
} else {
    unset($booster_route);
}
