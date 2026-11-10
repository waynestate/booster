<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

use Waynestate\Booster\ToolError;

/**
 * An opt-in download cache for the gate containers: a Docker volume or a
 * folder on the host, named by an environment variable. Without one, every
 * gate container starts with an empty cache and downloads every package
 * again (five gates on a real Laravel app, five full downloads). With one,
 * the baseline fills it and every later gate reads from it.
 *
 * Off unless the variable is set, so a run without it behaves exactly as
 * before. A plain name ("stack-composer-cache") is a Docker volume; anything
 * that looks like a path (a slash, a backslash, a leading dot or tilde) is a
 * folder, created when it is missing. Rockets refuel faster from the tank
 * already on the pad.
 */
final readonly class CacheMount
{
    /** Composer's download cache, mounted as COMPOSER_CACHE_DIR in the baseline and every gate. */
    public const COMPOSER = 'BOOSTER_COMPOSER_CACHE';

    /** npm's download cache, mounted at /root/.npm in every Node container. */
    public const NPM = 'BOOSTER_NPM_CACHE';

    /** What Docker accepts as a volume name. */
    private const VOLUME_NAME = '/^[A-Za-z0-9][A-Za-z0-9_.-]*$/';

    /**
     * @param string $source   the volume name, or the folder's absolute path
     * @param bool   $volume   true for a Docker volume, false for a host folder
     * @param string $variable the environment variable that named it, for messages
     */
    public function __construct(
        public string $source,
        public bool $volume,
        public string $variable,
    ) {}

    /** The cache the environment variable names, or null when it is unset or empty. */
    public static function fromEnvironment(string $variable): ?self
    {
        $value = getenv($variable);

        return self::parse($variable, $value === false ? null : $value);
    }

    /**
     * Reads one cache setting. A volume name is taken as it is; a folder is
     * made absolute, created when it is missing, and must be writable.
     */
    public static function parse(string $variable, ?string $value): ?self
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match(self::VOLUME_NAME, $value) === 1) {
            return new self($value, true, $variable);
        }

        return new self(self::folder($variable, $value), false, $variable);
    }

    /**
     * The `-v` pair that mounts this cache at $target in a container.
     *
     * @return list<string>
     */
    public function mountArgs(string $target): array
    {
        return ['-v', $this->source . ':' . $target];
    }

    /** How a report names this cache: the volume or the folder, and the variable that set it. */
    public function describe(): string
    {
        return sprintf('%s %s (%s)', $this->volume ? 'the Docker volume' : 'the folder', $this->source, $this->variable);
    }

    private static function folder(string $variable, string $value): string
    {
        $path = $value;
        if ($path === '~' || str_starts_with($path, '~/') || str_starts_with($path, '~\\')) {
            $home = getenv('HOME');
            if ($home === false || $home === '') {
                $home = getenv('USERPROFILE');
            }
            if ($home === false || $home === '') {
                throw new ToolError(sprintf('%s starts with ~, but neither HOME nor USERPROFILE is set. Give the full path.', $variable));
            }
            $path = rtrim($home, '/\\') . substr($path, 1);
        }
        $absolute = str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('#^[A-Za-z]:[/\\\\]#', $path) === 1;
        if (!$absolute) {
            $cwd = getcwd();
            $path = ($cwd === false ? '.' : $cwd) . '/' . $path;
        }
        // Docker splits -v on colons, so only a Windows drive letter may carry one.
        if (str_contains(preg_replace('#^[A-Za-z]:#', '', $path) ?? $path, ':')) {
            throw new ToolError(sprintf('%s=%s: a cache folder path cannot contain a colon, because docker -v splits on it. Pick another folder, or name a Docker volume instead.', $variable, $value));
        }
        if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
            throw new ToolError(sprintf('%s=%s: the folder does not exist and could not be created. Create it, or name a Docker volume instead (a plain name such as stack-composer-cache).', $variable, $value));
        }
        $real = realpath($path);
        if ($real === false || !is_writable($real)) {
            throw new ToolError(sprintf('%s=%s: the folder is not writable, so the gate could not fill the cache. Fix its permissions, or name a Docker volume instead.', $variable, $value));
        }

        return $real;
    }
}
