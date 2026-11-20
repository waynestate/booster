<?php

declare(strict_types=1);

namespace Waynestate\Booster\Update;

use Phar;

/**
 * How the running tool was installed, and the command that updates it.
 *
 * Detection looks at where the running code lives: the package root is the
 * folder holding bin/ and src/, which for this file is two levels up.
 */
final class InstallKind
{
    public const PHAR = 'phar';
    public const SOURCE = 'source';
    public const COMPOSER_GLOBAL = 'composer-global';
    public const PROJECT = 'project';
    public const UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $kind,
        public readonly string $tool = 'booster',
        public readonly ?string $packageRoot = null,
        public readonly ?string $projectRoot = null,
    ) {}

    /**
     * Detect the install from where the running code lives, in the order PHAR,
     * source checkout, Composer global, project dependency, unknown.
     */
    public static function detected(?string $packageRoot = null, string $tool = 'booster'): self
    {
        $phar = class_exists(Phar::class, false) ? Phar::running(false) : '';
        if ($phar !== '') {
            return new self(self::PHAR, $tool, $phar);
        }

        $packageRoot ??= \dirname(\dirname(__DIR__));
        if (self::isSourceCheckout($packageRoot)) {
            return new self(self::SOURCE, $tool, $packageRoot);
        }

        if (str_ends_with(self::flat($packageRoot), '/vendor/waynestate/'.$tool)) {
            $root = \dirname(\dirname(\dirname($packageRoot)));
            if (self::isComposerGlobalHome($root)) {
                return new self(self::COMPOSER_GLOBAL, $tool, $packageRoot);
            }

            return new self(self::PROJECT, $tool, $packageRoot, $root);
        }

        return new self(self::UNKNOWN, $tool, $packageRoot);
    }

    /** The command that brings this install to the newest release. */
    public function updateCommand(string $newest): string
    {
        return match ($this->kind) {
            self::PHAR => 'curl -L -o booster.phar https://github.com/waynestate/booster/releases/latest/download/booster.phar',
            self::SOURCE => 'git pull',
            self::COMPOSER_GLOBAL, self::UNKNOWN => 'composer global update '.$this->package(),
            self::PROJECT => $this->projectCommand($newest),
        };
    }

    /**
     * A project dependency updates in place, unless composer.json pins a
     * ^ or ~ constraint that the newest release falls outside: then the
     * constraint itself must move (composer require), with --dev when the
     * package sits in require-dev.
     */
    private function projectCommand(string $newest): string
    {
        [$constraint, $dev] = $this->pinnedConstraint();
        if ($constraint !== null && self::constraintExcludes($constraint, $newest)) {
            return sprintf('composer require %s:^%s%s', $this->package(), self::majorMinor($newest), $dev ? ' --dev' : '');
        }

        return 'composer update '.$this->package();
    }

    /**
     * The constraint pinning the package in the project's composer.json, and
     * whether it sits in require-dev.
     *
     * @return array{0: string|null, 1: bool}
     */
    private function pinnedConstraint(): array
    {
        if ($this->projectRoot === null) {
            return [null, false];
        }

        $path = $this->projectRoot.'/composer.json';
        if (! is_file($path)) {
            return [null, false];
        }

        $decoded = json_decode((string) @file_get_contents($path), true);
        if (! is_array($decoded)) {
            return [null, false];
        }

        $require = is_array($decoded['require'] ?? null) ? $decoded['require'] : [];
        if (isset($require[$this->package()]) && is_string($require[$this->package()])) {
            return [$require[$this->package()], false];
        }

        $requireDev = is_array($decoded['require-dev'] ?? null) ? $decoded['require-dev'] : [];
        if (isset($requireDev[$this->package()]) && is_string($requireDev[$this->package()])) {
            return [$requireDev[$this->package()], true];
        }

        return [null, false];
    }

    /**
     * Whether a ^ or ~ constraint leaves $newest out: the major must match,
     * and for 0.x the minor as well. Other constraints (exact, *, >=) never
     * exclude, because composer update resolves them.
     */
    private static function constraintExcludes(string $constraint, string $newest): bool
    {
        $trimmed = trim($constraint);
        if (! str_starts_with($trimmed, '^') && ! str_starts_with($trimmed, '~')) {
            return false;
        }

        $parts = explode('.', substr($trimmed, 1));
        $newestParts = explode('.', ltrim($newest, 'vV'));
        $major = (int) ($parts[0] ?? 0);
        $minor = isset($parts[1]) ? (int) $parts[1] : 0;
        $newestMajor = (int) ($newestParts[0] ?? 0);
        $newestMinor = isset($newestParts[1]) ? (int) $newestParts[1] : 0;

        if ($major === 0) {
            return $newestMajor !== 0 || $newestMinor !== $minor;
        }

        return $newestMajor !== $major;
    }

    /** X.Y of a version, for the ^X.Y a pinned project must move to. */
    private static function majorMinor(string $version): string
    {
        $parts = explode('.', ltrim($version, 'vV'));

        return ((int) ($parts[0] ?? 0)).'.'.((int) ($parts[1] ?? 0));
    }

    /** The Composer package name of the running tool. */
    private function package(): string
    {
        return 'waynestate/'.$this->tool;
    }

    /**
     * A source checkout: the package root carries a .git (folder or file, so
     * worktrees count) and is not itself inside a vendor/ folder.
     */
    private static function isSourceCheckout(string $root): bool
    {
        if (! is_dir($root.DIRECTORY_SEPARATOR.'.git') && ! is_file($root.DIRECTORY_SEPARATOR.'.git')) {
            return false;
        }

        foreach (explode('/', self::flat($root)) as $segment) {
            if ($segment === 'vendor') {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether $dir is the Composer global home: $COMPOSER_HOME when set, else
     * ~/.config/composer or ~/.composer, or %APPDATA%\Composer on Windows.
     */
    private static function isComposerGlobalHome(string $dir): bool
    {
        $dir = self::flat($dir);
        $home = getenv('COMPOSER_HOME');
        if ($home !== false && trim($home) !== '') {
            return self::flat($home) === $dir;
        }

        $candidates = [];
        if (\PHP_OS_FAMILY === 'Windows') {
            $appData = getenv('APPDATA');
            if ($appData !== false && trim($appData) !== '') {
                $candidates[] = self::flat($appData).'/composer';
            }
        } else {
            $homeDir = getenv('HOME');
            if ($homeDir !== false && trim($homeDir) !== '') {
                $candidates[] = self::flat($homeDir).'/.config/composer';
                $candidates[] = self::flat($homeDir).'/.composer';
            }
        }

        return in_array($dir, $candidates, true);
    }

    /** Forward slashes, trimmed, and lower-cased on case-insensitive filesystems. */
    private static function flat(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', trim($path)), '/');

        return \PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }
}
