<?php

declare(strict_types=1);

namespace Waynestate\Booster\Update;

use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * The update notice: after any command finishes, one stderr line when a newer
 * stable release of the running tool exists, naming the command that updates
 * it. It writes nothing to stdout, never throws, and never changes the exit
 * code of the command that just ran.
 */
final class UpdateNotice
{
    /** The Packagist p2 endpoint base for the tool's own package. */
    private const PACKAGIST_BASE = 'https://repo.packagist.org/p2/';

    /** How long a check result stays fresh, in seconds (one day). */
    public const CACHE_TTL = 86_400;

    /** Where a major bump points for the upgrade steps. */
    public const UPGRADE_URL = 'https://booster.apps.wayne.edu/docs/upgrading/';

    public function __construct(
        private readonly UpdateFetcher $fetcher,
        private readonly UpdateCache $cache,
        private readonly InstallKind $install,
        private readonly string $tool,
        private readonly string $version,
        /** @var callable(): int */
        private readonly callable $clock,
        private readonly string $upgradeUrl = self::UPGRADE_URL,
    ) {}

    /**
     * Print the line, when there is one, to $output (the caller passes the
     * stderr stream). All failures are swallowed: the notice must never break
     * or slow down the command that just ran.
     */
    public function run(OutputInterface $output): void
    {
        try {
            $line = $this->line($output->isQuiet());
            if ($line !== null) {
                $output->writeln($line);
            }
        } catch (Throwable) {
            // The notice is advisory; the command's own result wins.
        }
    }

    /**
     * The line to print, or null when the notice must stay silent: quiet
     * output, WAYNE_NO_UPDATE_CHECK=1, a CI system, a running version that is
     * not a plain release number, or no newer stable release.
     */
    public function line(bool $quiet = false): ?string
    {
        if ($quiet || self::optedOut() || self::inCi()) {
            return null;
        }

        $have = self::plainVersion($this->version);
        if ($have === null) {
            return null;
        }

        $newest = $this->newestStableRelease();
        if ($newest === null || version_compare($newest, $have, '<=')) {
            return null;
        }

        $line = sprintf(
            '%s %s is out of date: %s is the newest release. Update: %s',
            $this->tool,
            $have,
            $newest,
            $this->install->updateCommand($newest),
        );
        if ((int) explode('.', $newest)[0] > (int) explode('.', $have)[0]) {
            $line .= ' Upgrading: '.$this->upgradeUrl;
        }

        return $line;
    }

    /**
     * The newest stable release: from the cache while it is fresh, otherwise
     * from the network. A failed fetch keeps the old value and refreshes the
     * timestamp, so an offline machine checks at most once a day.
     */
    private function newestStableRelease(): ?string
    {
        $now = (int) ($this->clock)();
        $cached = $this->cache->read();
        $previous = $cached !== null && is_string($cached['latest'] ?? null)
            ? self::plainVersion($cached['latest'])
            : null;

        if ($this->cacheFresh($cached, $now)) {
            return $previous;
        }

        $document = $this->fetcher->fetch(self::PACKAGIST_BASE.$this->package().'.json');
        $latest = $document === null ? null : self::newestStable($document, $this->package());

        $this->cache->write($now, $document === null ? $previous : $latest);

        return $latest;
    }

    /** Whether the stored result is at most CACHE_TTL old. */
    private function cacheFresh(?array $cached, int $now): bool
    {
        return $cached !== null
            && is_int($cached['checked_at'] ?? null)
            && $now - $cached['checked_at'] <= self::CACHE_TTL;
    }

    /** The Composer package name of the running tool. */
    private function package(): string
    {
        return 'waynestate/'.$this->tool;
    }

    /**
     * The highest stable release in a p2 document: every version carrying dev,
     * alpha, beta or rc (any case) is dropped, the rest compared with
     * version_compare.
     *
     * @param array<string, mixed> $document
     */
    private static function newestStable(array $document, string $package): ?string
    {
        $packages = $document['packages'] ?? null;
        if (! is_array($packages)) {
            return null;
        }

        $releases = $packages[$package] ?? null;
        if (! is_array($releases) || ! array_is_list($releases)) {
            return null;
        }

        $best = null;
        foreach ($releases as $release) {
            if (! is_array($release) || ! isset($release['version']) || ! is_string($release['version'])) {
                continue;
            }

            $version = self::plainVersion($release['version']);
            if ($version === null) {
                continue;
            }

            if ($best === null || version_compare($version, $best, '>')) {
                $best = $version;
            }
        }

        return $best;
    }

    /**
     * A plain release number (1.20.0, v1.20.0) without the leading v; anything
     * else (dev builds, prereleases, dirty strings) is null.
     */
    private static function plainVersion(string $version): ?string
    {
        $version = ltrim(trim($version), 'vV');
        if (preg_match('/^\d+(\.\d+){1,2}$/', $version) !== 1) {
            return null;
        }

        return $version;
    }

    /** Whether the run asked for no update check (WAYNE_NO_UPDATE_CHECK=1). */
    private static function optedOut(): bool
    {
        $value = getenv('WAYNE_NO_UPDATE_CHECK');

        return $value !== false && trim($value) === '1';
    }

    /** Whether a CI system set CI to anything other than empty, 0 or false. */
    private static function inCi(): bool
    {
        $value = getenv('CI');
        if ($value === false) {
            return false;
        }

        $value = trim($value);

        return $value !== '' && $value !== '0' && strtolower($value) !== 'false';
    }
}
