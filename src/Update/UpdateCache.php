<?php

declare(strict_types=1);

namespace Waynestate\Booster\Update;

/**
 * The last update-check result, so the network is consulted at most once a
 * day. The file holds {"checked_at": <unix seconds>, "latest": "<X.Y.Z or null>"}.
 */
final class UpdateCache
{
    public function __construct(private readonly string $path) {}

    /**
     * The user cache file for one tool: $XDG_CACHE_HOME/waynestate/<tool>/update-check.json,
     * else ~/.cache/...; %LOCALAPPDATA%\waynestate\<tool>\update-check.json on Windows.
     */
    public static function defaultPath(string $tool): string
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $local = getenv('LOCALAPPDATA');
            $base = $local !== false && trim($local) !== '' ? $local : sys_get_temp_dir();

            return $base.DIRECTORY_SEPARATOR.'waynestate'.DIRECTORY_SEPARATOR.$tool.DIRECTORY_SEPARATOR.'update-check.json';
        }

        $xdg = getenv('XDG_CACHE_HOME');
        if ($xdg !== false && trim($xdg) !== '') {
            $base = rtrim(str_replace('\\', '/', $xdg), '/');
        } else {
            $home = getenv('HOME');
            $base = ($home !== false && trim($home) !== '' ? $home : sys_get_temp_dir()).'/.cache';
        }

        return $base.'/waynestate/'.$tool.'/update-check.json';
    }

    /**
     * @return array<string, mixed>|null the stored result, or null when absent or unreadable
     */
    public function read(): ?array
    {
        if (! is_file($this->path)) {
            return null;
        }

        $body = @file_get_contents($this->path);
        if ($body === false) {
            return null;
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Store the result. A cache dir that cannot be created or written is
     * silent: the check simply loses its memory.
     */
    public function write(int $checkedAt, ?string $latest): void
    {
        $dir = \dirname($this->path);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return;
        }

        $payload = json_encode(['checked_at' => $checkedAt, 'latest' => $latest]);
        if ($payload === false) {
            return;
        }

        @file_put_contents($this->path, $payload, LOCK_EX);
    }
}
