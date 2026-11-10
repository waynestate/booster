<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

/**
 * Runs Node commands against the app inside a node:<major>-bookworm-slim
 * container with the app mounted at /app, so front-end hops work identically
 * on Windows, macOS and Linux without Node on the host. The image major is a
 * constructor argument (the major comes from the hop data, 26 today) and the command is always
 * an argument array, never a shell string.
 */
final class NodeRunner
{
    /** Where the opt-in npm cache (BOOSTER_NPM_CACHE) is mounted: npm's own cache folder for root, the container's user. */
    public const NPM_CACHE_MOUNT = '/root/.npm';

    public function __construct(
        private readonly ProcessRunner $processes,
        private readonly int $major = 24,
        private readonly string $docker = 'docker',
        private readonly ?CacheMount $npmCache = null,
    ) {}

    public static function imageTag(int $major): string
    {
        return 'node:' . $major . '-bookworm-slim';
    }

    /**
     * @param list<string> $argv what runs in the container, for example ['npm', 'ci']
     */
    public function run(string $appPath, array $argv): ProcessResult
    {
        return $this->processes->run($this->runCommand($appPath, $argv), null, [], 3600);
    }

    /**
     * The argument array for `docker run`, exposed so tests can assert on it.
     *
     * @param list<string> $argv
     *
     * @return list<string>
     */
    public function runCommand(string $appPath, array $argv): array
    {
        // Opt-in (BOOSTER_NPM_CACHE): npm ci then reads packages it already
        // fetched instead of downloading the whole tree for every hop.
        $cache = $this->npmCache === null ? [] : $this->npmCache->mountArgs(self::NPM_CACHE_MOUNT);

        return [$this->docker, 'run', '--rm', '-v', rtrim($appPath, '/\\') . ':/app', ...$cache, '-w', '/app', self::imageTag($this->major), ...$argv];
    }
}
