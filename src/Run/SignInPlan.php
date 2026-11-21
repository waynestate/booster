<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

use Waynestate\Booster\Strangler\SignInSource;

/**
 * The edits one sign-in migration wants, held in memory until all of them are
 * known (strangler Step 0). Nothing touches the disk until write(), and
 * write() refuses when anything failed or any edited PHP file stops parsing,
 * so a half-done Step 0 can never land.
 */
final class SignInPlan
{
    /** @var array<string, string> app-relative path => new content */
    public array $files = [];

    /** @var list<string> */
    public array $changes = [];

    /** @var list<string> */
    public array $manual = [];

    /** @var list<string> */
    public array $failures = [];

    /** @var list<string> */
    public array $replayChanges = [];

    public function __construct(
        public readonly string $root,
    ) {}

    /** The current content of an app-relative file: the planned edit, else the disk, else null. */
    public function read(string $relative): ?string
    {
        if (array_key_exists($relative, $this->files)) {
            return $this->files[$relative];
        }

        $path = $this->root . '/' . $relative;
        if (! is_file($path)) {
            return null;
        }

        $content = file_get_contents($path);

        return $content === false ? null : $content;
    }

    public function put(string $relative, string $content, string $change): void
    {
        if ($this->read($relative) === $content) {
            return;
        }

        $this->files[$relative] = $content;
        $this->changes[] = $change;
    }

    /** Adds a requirement to an app-relative composer.json unless one is already there and new enough. */
    public function requirePackage(string $composerJson, string $package, string $constraint, string $atLeast): void
    {
        $content = $this->read($composerJson);
        if ($content === null) {
            $this->failures[] = sprintf('%s is missing, so %s cannot be required.', $composerJson, $package);

            return;
        }

        $data = json_decode($content, true);
        if (! is_array($data)) {
            $this->failures[] = sprintf('%s is not valid JSON.', $composerJson);

            return;
        }

        $current = $data['require'][$package] ?? null;
        // The patch counts too: `^0.10` is older than a floor of 0.10.1 (SESAME's JSON-session fix).
        if (is_string($current) && preg_match('/(\d+\.\d+(?:\.\d+)?)/', $current, $m) === 1 && version_compare($m[1], $atLeast, '>=')) {
            return;
        }

        $require = is_array($data['require'] ?? null) ? $data['require'] : [];
        $require[$package] = $constraint;
        $data['require'] = $require;

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->put($composerJson, $json . "\n", sprintf('%s: require %s %s', $composerJson, $package, $constraint));
    }

    /**
     * Writes every planned file. Refuses, writing nothing, when the plan has
     * failures or an edited PHP file would not parse.
     *
     * @return list<string> the app-relative files written
     */
    public function write(): array
    {
        foreach ($this->files as $relative => $content) {
            if (str_ends_with($relative, '.php') && ! SignInSource::parses($content)) {
                $this->failures[] = sprintf('%s would not parse after the edit, so Step 0 wrote nothing. Please report this to Booster.', $relative);
            }
        }

        if ($this->failures !== []) {
            return [];
        }

        foreach ($this->files as $relative => $content) {
            $path = $this->root . '/' . $relative;
            if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0o775, true) && ! is_dir(dirname($path))) {
                throw new \Waynestate\Booster\ToolError(sprintf('Could not create the folder for "%s".', $relative));
            }
            if (file_put_contents($path, $content) === false) {
                throw new \Waynestate\Booster\ToolError(sprintf('Could not write "%s".', $relative));
            }
        }

        return array_keys($this->files);
    }
}
