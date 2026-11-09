<?php

declare(strict_types=1);

namespace Waynestate\Booster\Scan;

/**
 * Finds the old Wayne Auth sign-in in an app's own code: the WayneAuth class
 * apps copied into lib/, the validUser cookie it reads, and the sso_host
 * setting that points it at the service. Wayne Auth is deprecated, and its
 * check only keeps working on the PHP 5.6 the apps run today: PHP 7 and later
 * verify the service's TLS certificate, which has expired, so the first PHP
 * hop off 5.6 can lock everyone out of the app. That makes it a stop sign
 * for the whole upgrade, not a finding for one hop: the move to SESAME's
 * Wayne Auth bridge has to ship with the PHP hops.
 *
 * Read-only and never throws. Composer's vendor/, node_modules/ and .git are
 * skipped. An app that already boots SESAME's bridge keeps its old
 * `new WayneAuth(...)` calls and config keys on purpose, so those count only
 * while the bridge is missing; the old class itself, the cookie and the
 * password mode always count.
 */
final class WayneAuthScan
{
    /** The SESAME guide every Wayne Auth line points at. */
    public const GUIDE = 'https://sesame.apps.wayne.edu/docs/guides/moving-off-wayne-auth.html';

    /** How many hits the stop sign names before it says how many more. */
    public const SHOWN = 3;

    /** How much of one file the scan reads; Wayne Auth clients are small. */
    private const MAX_BYTES = 262144;

    /** Directories that never hold the app's own sign-in code. */
    private const SKIPPED = ['vendor', 'node_modules', '.git', 'storage'];

    /** File types that carry the class, its callers or its config. */
    private const EXTENSIONS = ['php', 'inc', 'phtml', 'ini', 'conf'];

    /**
     * Pattern => what the line does. Checked in order; one hit per line.
     * WayneAuthenticate (the wayne-cas Laravel middleware) never matches.
     *
     * @var array<string, string>
     */
    private const ALWAYS = [
        '/\bclass\s+WayneAuth(?:Client)?\b(?!\\\\)/' => 'declares the Wayne Auth class',
        '/_parseSSOCookie\s*\(\s*[\'"]d[\'"]/' => 'asks Wayne Auth for the password (no bridge can)',
        '/[\'"](?:validUser|mobileUser)[\'"]/' => 'reads the Wayne Auth validUser cookie',
        '/\bwayneauth_http_gateway\b/i' => 'calls the Wayne Auth HTTP gateway',
    ];

    /** @var array<string, string> the lines the bridge keeps working on purpose */
    private const UNLESS_BRIDGED = [
        '/\bnew\s+\\\\?WayneAuth(?:Client)?\s*\(/' => 'signs people in through Wayne Auth',
        '/\bsso_host\b/' => 'points at the Wayne Auth server (sso_host)',
    ];

    /** A line that boots SESAME's Wayne Auth bridge. */
    private const BRIDGE = '/WayneAuth::boot\s*\(|Waynestate\\\\Sesame\\\\WayneAuth\\\\/';

    /**
     * Every Wayne Auth line in the app, as "file:line what", sorted by file.
     * Empty when the app has none, or when SESAME's bridge already took over.
     *
     * @return list<string>
     */
    public static function find(string $appPath): array
    {
        $appPath = rtrim(str_replace('\\', '/', $appPath), '/');
        if (!is_dir($appPath)) {
            return [];
        }

        $sources = [];
        foreach (self::files($appPath) as $relative) {
            $handle = @fopen($appPath . '/' . $relative, 'rb');
            if ($handle === false) {
                continue;
            }
            $chunk = fread($handle, self::MAX_BYTES);
            fclose($handle);
            if (is_string($chunk) && $chunk !== '') {
                $sources[$relative] = $chunk;
            }
        }

        $bridged = false;
        foreach ($sources as $source) {
            if (preg_match(self::BRIDGE, $source) === 1) {
                $bridged = true;
                break;
            }
        }
        $patterns = $bridged ? self::ALWAYS : self::ALWAYS + self::UNLESS_BRIDGED;

        $hits = [];
        foreach ($sources as $relative => $source) {
            if (stripos($source, 'wayneauth') === false && stripos($source, 'validuser') === false
                && stripos($source, 'mobileuser') === false && !str_contains($source, 'sso_host')) {
                continue;
            }
            foreach (preg_split('/\r\n|\n|\r/', $source) ?: [] as $index => $line) {
                foreach ($patterns as $pattern => $what) {
                    if (preg_match($pattern, $line) === 1) {
                        $hits[] = sprintf('%s:%d %s', $relative, $index + 1, $what);
                        break;
                    }
                }
            }
        }

        return $hits;
    }

    /**
     * The stop sign lines for detect and plan: what was found, why it stops
     * the upgrade, and the way off. Empty when there are no hits.
     *
     * @param list<string> $hits from find()
     *
     * @return list<string>
     */
    public static function stopSign(array $hits): array
    {
        if ($hits === []) {
            return [];
        }
        $shown = array_slice($hits, 0, self::SHOWN);
        $more = count($hits) - count($shown);

        return [
            sprintf(
                'Stop sign: the app signs people in through Wayne Auth (%s%s).',
                implode('; ', $shown),
                $more > 0 ? sprintf('; and %d more', $more) : '',
            ),
            'Wayne Auth is deprecated, and its check survives only on PHP 5.6, which never verified the service\'s TLS certificate.'
            . ' PHP 7 and later verify it by default, and that certificate has expired, so once the app leaves PHP 5.6 nobody can sign in.'
            . ' Ship the move to SESAME\'s Wayne Auth bridge (a one-line swap; SESAME needs PHP 8.3) in the same release as the PHP hops,'
            . ' and deploy no hop off PHP 5.6 before it: ' . self::GUIDE,
        ];
    }

    /**
     * The app's own files worth reading, relative with forward slashes,
     * sorted. Symlinks are not followed.
     *
     * @return list<string>
     */
    private static function files(string $appPath): array
    {
        $found = [];
        $stack = [''];
        while (($dir = array_pop($stack)) !== null) {
            $handle = @opendir($appPath . ($dir === '' ? '' : '/' . $dir));
            if ($handle === false) {
                continue;
            }
            while (($entry = readdir($handle)) !== false) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $relative = $dir === '' ? $entry : $dir . '/' . $entry;
                $full = $appPath . '/' . $relative;
                if (is_link($full)) {
                    continue;
                }
                if (is_dir($full)) {
                    if (!in_array($entry, self::SKIPPED, true)) {
                        $stack[] = $relative;
                    }
                    continue;
                }
                $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                if (in_array($extension, self::EXTENSIONS, true) || str_ends_with(strtolower($entry), '.ini.example')) {
                    $found[] = $relative;
                }
            }
            closedir($handle);
        }
        sort($found);

        return $found;
    }
}
