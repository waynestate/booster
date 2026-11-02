<?php

declare(strict_types=1);

namespace Waynestate\Booster\Plan;

use Throwable;
use Waynestate\Booster\Run\ProcessRunner;

/**
 * Whether the repository that carries wayne-cas can be reached, so the
 * planner knows whether the app could install its dependencies at all. An
 * app that already has vendor/ needs no probe, and a package that comes from
 * Packagist is reachable by assumption: only the repositories composer.json
 * names for wayne-cas are checked. A path entry must exist on disk; a vcs,
 * git or gitlab entry is probed with git ls-remote, which never prompts and
 * gives up after ten seconds. The reason never names the URL, because a
 * repository URL may carry a token.
 */
final class WayneCasReachability
{
    private const WAYNE_CAS_PACKAGE = 'cit-web-infra/wayne-cas';

    public function __construct(private readonly ProcessRunner $processes) {}

    /**
     * Why the app cannot reach the repository that carries wayne-cas, or null
     * when the probe finds nothing wrong (or has nothing to probe).
     */
    public function unreachableReason(string $appPath): ?string
    {
        $composerFile = $appPath . '/composer.json';
        if (!is_file($composerFile)) {
            return null;
        }
        $raw = file_get_contents($composerFile);
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        $require = array_merge(
            is_array($data['require'] ?? null) ? $data['require'] : [],
            is_array($data['require-dev'] ?? null) ? $data['require-dev'] : [],
        );
        if (!array_key_exists(self::WAYNE_CAS_PACKAGE, $require)) {
            return null;
        }
        if (is_file($appPath . '/vendor/autoload.php')) {
            return null;
        }

        // repositories is a list of entries, or a map of name to entry; both
        // iterate to their entry arrays.
        $repositories = $data['repositories'] ?? [];
        if (!is_array($repositories)) {
            return null;
        }

        foreach ($repositories as $repository) {
            if (!is_array($repository)) {
                continue;
            }
            $url = $repository['url'] ?? null;
            if (!is_string($url) || !str_contains($url, 'wayne-cas')) {
                continue;
            }
            $reason = $this->entryReason($appPath, $repository, $url);
            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $repository
     */
    private function entryReason(string $appPath, array $repository, string $url): ?string
    {
        $type = $repository['type'] ?? null;
        if ($type === 'path') {
            return is_dir($this->resolvePath($appPath, $url)) ? null : sprintf('path repository "%s" does not exist', $url);
        }
        if ($type === 'vcs' || $type === 'git' || $type === 'gitlab') {
            try {
                $result = $this->processes->run(
                    ['git', 'ls-remote', '--exit-code', '--heads', $url],
                    null,
                    [
                        'GIT_TERMINAL_PROMPT' => '0',
                        'GIT_SSH_COMMAND' => 'ssh -o BatchMode=yes -o ConnectTimeout=10',
                        'GIT_ASKPASS' => 'echo',
                    ],
                    30,
                );
            } catch (Throwable $e) {
                return explode("\n", trim($e->getMessage()))[0];
            }
            if (!$result->successful()) {
                return sprintf('git ls-remote could not reach the wayne-cas repository (exit %d)', $result->exitCode);
            }

            return null;
        }

        // Any other repository type (a custom composer server, a package
        // archive) is not probed: the plan assumes it can be reached.
        return null;
    }

    /** The directory a path repository's url names, resolved against the app. */
    private function resolvePath(string $appPath, string $url): string
    {
        if ($url !== '' && ($url[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\/]/', $url) === 1)) {
            return $url;
        }

        return $appPath . '/' . $url;
    }
}
