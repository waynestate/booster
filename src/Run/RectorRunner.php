<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

use Composer\InstalledVersions;
use Waynestate\Booster\Hop\Hop;
use Waynestate\Booster\Hop\HopKind;
use Waynestate\Booster\Packaging\PharFiles;
use Waynestate\Booster\ToolError;

/**
 * Runs rector/rector from Booster's own vendor directory against an app, with
 * config/rector.php. Rector never comes from the app's composer.json, so an app
 * on PHP 7.4 can be rewritten by a Rector that runs on PHP 8.3 or newer.
 *
 * From the phar, Rector and its config are copied out of the phar to a temp
 * directory first (PharFiles), because Rector is a child process and cannot be
 * pointed at a phar:// path.
 */
final class RectorRunner
{
    /** The memory limit Rector runs with unless the user raises it. Big apps need more than the old hard-coded gigabyte. */
    public const DEFAULT_MEMORY_LIMIT = '2G';

    public function __construct(
        private readonly ProcessRunner $processes,
        private readonly ?string $rectorBinary = null,
        private readonly ?string $phpBinary = null,
    ) {}

    public static function configPath(): string
    {
        return PharFiles::path('config/rector.php');
    }

    /**
     * Checks a --memory-limit value. Accepted: a whole number with an optional
     * K, M or G suffix (for example 2G), or -1 for no limit.
     */
    public static function assertMemoryLimit(string $value): void
    {
        if (preg_match('/^(\d+[KMG]?|-1)$/', $value) !== 1) {
            throw new ToolError(sprintf(
                'Invalid --memory-limit value "%s". Accepted: a whole number with an optional K, M or G suffix (for example 2G), or -1 for no limit.',
                $value,
            ));
        }
    }

    /**
     * @param string  $phpTarget     the PHP version the rules may assume (major.minor); Rector otherwise reads the old version from the app composer.json and skips the new rules
     * @param ?string $laravelTarget the Laravel major the version-bound rules should assume, or null for a non-Laravel app
     * @param ?string $phpunitTarget the PHPUnit major the version-bound rules should assume, or null for a non-PhpUnit hop
     * @param string  $memoryLimit   Rector's --memory-limit (for example 2G), or -1 for no limit
     */
    public function run(Hop $hop, string $appPath, bool $dryRun, string $phpTarget, ?string $laravelTarget = null, ?string $phpunitTarget = null, string $memoryLimit = self::DEFAULT_MEMORY_LIMIT): RectorResult
    {
        self::assertMemoryLimit($memoryLimit);
        $appPath = rtrim($appPath, '/\\');
        $command = [
            $this->phpBinary ?? PHP_BINARY,
            $this->binary(),
            'process',
            '--config=' . self::configPath(),
            '--output-format=json',
            '--no-progress-bar',
            '--no-ansi',
            '--clear-cache',
            '--memory-limit=' . $memoryLimit,
        ];
        if ($dryRun) {
            $command[] = '--dry-run';
        }

        $env = [
            'BOOSTER_APP_PATH' => $appPath,
            'BOOSTER_RECTOR_SETS' => json_encode($hop->rectorSets, JSON_THROW_ON_ERROR),
            'BOOSTER_CLASS_RENAMES' => json_encode((object) $hop->classRenames, JSON_THROW_ON_ERROR),
            'BOOSTER_PHP_TARGET' => $phpTarget,
            'BOOSTER_RECTOR_CACHE' => sys_get_temp_dir() . '/booster-rector-' . $hop->id,
        ];

        // Laravel rules need Laravel's classes to recognise a Model or a Controller, so a Laravel
        // hop loads the app's own autoloader when vendor/ exists. PHP hops never do: an old
        // dependency tree can fail to load on the newer PHP that runs Rector.
        $autoload = $appPath . '/vendor/autoload.php';
        if ($hop->kind === HopKind::Laravel && is_file($autoload)) {
            $env['BOOSTER_BOOTSTRAP'] = $autoload;
        }

        // Rector runs from a scratch directory that holds a synthetic
        // vendor/composer/installed.json, so it never needs the app's vendor/.
        $work = sys_get_temp_dir() . '/booster-work-' . bin2hex(random_bytes(6));
        $installed = $work . '/vendor/composer';
        if (!mkdir($installed, 0775, true) && !is_dir($installed)) {
            throw new ToolError(sprintf('Could not create "%s".', $installed));
        }

        try {
            file_put_contents(
                $installed . '/installed.json',
                json_encode((new InstalledJsonBuilder())->build($appPath, $laravelTarget, $phpunitTarget), JSON_THROW_ON_ERROR),
            );
            $result = $this->processes->run($command, $work, $env, 1800);
        } finally {
            @unlink($installed . '/installed.json');
            @rmdir($installed);
            @rmdir($work . '/vendor');
            @rmdir($work);
        }

        return $this->parse($result, $appPath, $work, $memoryLimit);
    }

    private function binary(): string
    {
        if ($this->rectorBinary !== null) {
            return $this->rectorBinary;
        }

        if (PharFiles::running()) {
            // Rector's bin requires the root vendor autoloader, so the whole
            // runtime vendor tree is copied out of the phar, not just Rector.
            $binary = PharFiles::path('vendor') . '/rector/rector/bin/rector';
            if (!is_file($binary)) {
                throw new ToolError('Rector is not installed in the BOOSTER phar.');
            }

            return $binary;
        }

        $root = InstalledVersions::isInstalled('rector/rector')
            ? InstalledVersions::getInstallPath('rector/rector')
            : null;
        $binary = ($root ?? dirname(__DIR__, 2) . '/vendor/rector/rector') . '/bin/rector';
        if (!is_file($binary)) {
            throw new ToolError('Rector is not installed. Run `composer install` in BOOSTER first.');
        }

        return $binary;
    }

    /**
     * Joins a path to a base and collapses "." and ".." without touching the
     * disk (the scratch directory is already gone when the report is read).
     */
    private static function resolve(string $file, string $base): string
    {
        $isAbsolute = str_starts_with($file, '/') || preg_match('#^[A-Za-z]:/#', $file) === 1;
        $joined = $isAbsolute ? $file : rtrim($base, '/') . '/' . $file;

        $parts = [];
        foreach (explode('/', $joined) as $i => $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '.' && ($part !== '' || $i === 0)) {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }

    private function parse(ProcessResult $result, string $appPath, string $work, string $memoryLimit): RectorResult
    {
        // Rector exits 2 when a dry run finds changes, so the exit code alone says nothing.
        if (trim($result->stdout) === '') {
            // An empty report means Rector died before writing it, most often of
            // memory on a big app.
            throw new ToolError(sprintf(
                "Rector produced no JSON report (exit %d). It ran with --memory-limit=%s; an exit code of 255 or a stderr line mentioning memory usually means Rector ran out of memory, and --memory-limit can raise the limit.\nstderr: %s",
                $result->exitCode,
                $memoryLimit,
                substr(trim($result->stderr), 0, 500),
            ));
        }

        $data = json_decode($result->stdout, true);
        if (!is_array($data)) {
            throw new ToolError(sprintf(
                "Rector output was not valid JSON: %s (exit %d).\nstderr: %s",
                json_last_error_msg(),
                $result->exitCode,
                substr(trim($result->stderr), 0, 500),
            ));
        }

        $files = [];
        $diffs = is_array($data['file_diffs'] ?? null) ? $data['file_diffs'] : [];
        foreach ($diffs as $entry) {
            if (!is_array($entry) || !is_string($entry['file'] ?? null)) {
                continue;
            }
            // Rector reports paths relative to the directory it ran from (the scratch directory).
            $file = self::resolve(str_replace('\\', '/', $entry['file']), str_replace('\\', '/', $work));
            $prefix = str_replace('\\', '/', $appPath) . '/';
            if (str_starts_with($file, $prefix)) {
                $file = substr($file, strlen($prefix));
            }
            $files[] = ['file' => $file, 'diff' => is_string($entry['diff'] ?? null) ? $entry['diff'] : ''];
        }

        $errors = [];
        $raw = is_array($data['errors'] ?? null) ? $data['errors'] : [];
        foreach ($raw as $error) {
            if (is_array($error)) {
                $file = is_string($error['file'] ?? null) ? $error['file'] . ': ' : '';
                $message = is_string($error['message'] ?? null) ? $error['message'] : 'unknown error';
                $errors[] = $file . $message;
            } elseif (is_string($error)) {
                $errors[] = $error;
            }
        }

        return new RectorResult($files, $errors);
    }
}
