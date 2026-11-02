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

        // Every vendor/ and node_modules/ directory at any depth, so the shared
        // config skips them all: a vendored library must never be rewritten or
        // even parsed by Rector.
        $vendored = VendoredDirs::find($appPath);
        // PHP files that are not valid UTF-8 would make Rector die encoding its
        // own JSON report, so Rector runs without them and the step names each
        // one as manual work.
        $invalid = NonUtf8Files::find($appPath, $vendored);
        $env = [
            'BOOSTER_APP_PATH' => $appPath,
            'BOOSTER_RECTOR_SETS' => json_encode($hop->rectorSets, JSON_THROW_ON_ERROR),
            'BOOSTER_CLASS_RENAMES' => json_encode((object) $hop->classRenames, JSON_THROW_ON_ERROR),
            'BOOSTER_PHP_TARGET' => $phpTarget,
            'BOOSTER_RECTOR_CACHE' => sys_get_temp_dir() . '/booster-rector-' . $hop->id,
            'BOOSTER_RECTOR_SKIP_DIRS' => json_encode(array_map(static fn(string $d): string => $appPath . '/' . $d, $vendored), JSON_THROW_ON_ERROR),
            'BOOSTER_RECTOR_SKIP_FILES' => json_encode(array_map(static fn(string $f): string => $appPath . '/' . $f, $invalid), JSON_THROW_ON_ERROR),
        ];

        // Laravel rules need Laravel's classes to recognise a Model or a Controller, so a Laravel
        // hop loads the app's own autoloader when vendor/ exists. PHP hops never do: an old
        // dependency tree can fail to load on the newer PHP that runs Rector.
        $autoload = $appPath . '/vendor/autoload.php';
        if ($hop->kind === HopKind::Laravel && is_file($autoload)) {
            $env['BOOSTER_BOOTSTRAP'] = $autoload;
        }

        [$result, $work] = $this->invokeRector($command, $env, $appPath, $laravelTarget, $phpunitTarget);
        [$first, $data] = $this->parse($result, $appPath, $work, $memoryLimit, $vendored, $invalid);

        // A legacy file that extends or uses a class no autoloader knows makes Rector
        // report "Class X should be known at this point" for it, and one such error would
        // stop the whole hop. When those are the only blocking errors, rerun once without
        // the files, keep the step applied, and list them as manual work. Any other error
        // stays an error, and there is only ever one retry per call.
        $rawErrors = is_array($data['errors'] ?? null) ? $data['errors'] : [];
        $blocking = 0;
        foreach ($rawErrors as $error) {
            if (is_array($error) || is_string($error)) {
                $blocking++;
            }
        }
        $eligible = $this->unknownClassErrors($rawErrors, $appPath, $work);
        if ($eligible !== [] && count($eligible) === $blocking) {
            return $this->retryWithoutUnknownClasses($first, $eligible, $command, $env, $appPath, $laravelTarget, $phpunitTarget, $memoryLimit, $vendored, $invalid);
        }

        return $first;
    }

    /**
     * Runs Rector once from a fresh scratch directory that holds a synthetic
     * vendor/composer/installed.json, so it never needs the app's vendor/.
     * Returns the process result and the scratch directory (already removed by
     * the time this returns; the report's paths are relative to it).
     *
     * @param list<string>         $command
     * @param array<string, string> $env
     *
     * @return array{0: ProcessResult, 1: string}
     */
    private function invokeRector(array $command, array $env, string $appPath, ?string $laravelTarget, ?string $phpunitTarget): array
    {
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

        return [$result, $work];
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

    /**
     * @param list<string> $vendored every vendor/ and node_modules/ directory under the app, relative to it
     * @param list<string> $invalid  every PHP file that is not valid UTF-8, relative to the app; Rector ran without them
     *
     * @return array{0: RectorResult, 1: array<string, mixed>} the result and the decoded report, so a caller can inspect the raw errors
     */
    private function parse(ProcessResult $result, string $appPath, string $work, string $memoryLimit, array $vendored, array $invalid): array
    {
        // Rector exits 2 when a dry run finds changes, so the exit code alone says nothing.
        if (trim($result->stdout) === '') {
            // An empty report means Rector died before writing it. Malformed
            // UTF-8 in a file it could not encode into its report is one cause;
            // say that instead of pointing at memory.
            if (str_contains($result->stderr, 'Malformed UTF-8') || str_contains($result->stdout, 'Malformed UTF-8')) {
                throw new ToolError(sprintf(
                    "Rector produced no JSON report (exit %d) because it met malformed UTF-8 in a file it could not encode into its report, not because of memory.\nstderr: %s",
                    $result->exitCode,
                    substr(trim($result->stderr), 0, 500),
                ));
            }
            // Otherwise it most often died of memory on a big app.
            throw new ToolError(sprintf(
                "Rector produced no JSON report (exit %d). It ran with --memory-limit=%s; an exit code of 255 or a stderr line mentioning memory usually means Rector ran out of memory, and --memory-limit can raise the limit.\nstderr: %s",
                $result->exitCode,
                $memoryLimit,
                substr(trim($result->stderr), 0, 500),
            ));
        }

        // Rector writes file contents into its JSON report. A byte sequence that is
        // valid in the app's own encoding but not UTF-8 makes strict json_decode fail
        // with JSON_ERROR_UTF8, so retry once with invalid bytes replaced by U+FFFD.
        $repaired = false;
        $data = json_decode($result->stdout, true);
        if ($data === null && json_last_error() === JSON_ERROR_UTF8) {
            $data = json_decode($result->stdout, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
            $repaired = true;
        }
        if (!is_array($data)) {
            throw new ToolError(sprintf(
                "Rector output was not valid JSON: %s (exit %d).\nstderr: %s",
                json_last_error_msg(),
                $result->exitCode,
                substr(trim($result->stderr), 0, 500),
            ));
        }

        $files = [];
        $rules = [];
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

            // applied_rectors lists the fully qualified rule class names that touched this
            // file. Missing or malformed means nothing was recorded for it, not an error.
            $applied = is_array($entry['applied_rectors'] ?? null) ? array_values($entry['applied_rectors']) : [];
            foreach (array_unique($applied) as $class) {
                if (!is_string($class) || $class === '') {
                    continue;
                }
                $short = strrchr($class, '\\');
                $short = $short === false ? $class : substr($short, 1);
                if ($short === '') {
                    continue;
                }
                $rules[$short] = ($rules[$short] ?? 0) + 1;
            }
        }

        $errors = [];
        $raw = is_array($data['errors'] ?? null) ? $data['errors'] : [];
        foreach ($raw as $error) {
            if (is_array($error)) {
                $file = is_string($error['file'] ?? null) ? $error['file'] . ': ' : '';
                $message = is_string($error['message'] ?? null) ? $error['message'] : 'unknown error';
                // Keep Rector's own line number so a parse refusal can name it.
                if (is_int($error['line'] ?? null) && preg_match('/line \d+/i', $message) !== 1) {
                    $message .= ' (line ' . $error['line'] . ')';
                }
                $errors[] = $file . $message;
            } elseif (is_string($error)) {
                $errors[] = $error;
            }
        }

        if ($repaired) {
            // Name the files whose diff now carries U+FFFD replacement characters, so
            // the developer knows where the repair touched content.
            $damaged = [];
            foreach ($files as $f) {
                if (str_contains($f['diff'], "\u{FFFD}")) {
                    $damaged[] = $f['file'];
                }
            }
            $note = RectorResult::REPAIRED_UTF8_NOTE;
            if ($damaged !== []) {
                $note .= ' (' . implode(', ', $damaged) . ')';
            }
            array_unshift($errors, $note);
        }

        $excluded = array_map(static fn(string $f): array => ['file' => $f, 'reason' => 'not valid UTF-8'], $invalid);

        return [new RectorResult($files, $errors, $rules, VendoredDirs::nested($vendored), $excluded), $data];
    }

    /**
     * Finds the "should be known at this point" system errors that name a file:
     * legacy files that extend or use classes no autoloader knows (a vendored-by-hand
     * PHPExcel, a PEAR Services_JSON_Error). Rector cannot analyse them, but it can run
     * without them, so the hop may retry once with them excluded. Each entry carries the
     * file relative to the app and the raw message.
     *
     * @param mixed $rawErrors the report's errors value
     *
     * @return list<array{file: string, message: string}>
     */
    private function unknownClassErrors(mixed $rawErrors, string $appPath, string $work): array
    {
        $out = [];
        foreach (is_array($rawErrors) ? $rawErrors : [] as $error) {
            if (!is_array($error)) {
                continue; // a bare string names no file
            }
            $message = is_string($error['message'] ?? null) ? $error['message'] : '';
            if (preg_match('/should be known at this point/i', $message) !== 1) {
                continue;
            }
            $file = is_string($error['file'] ?? null) && $error['file'] !== '' ? $error['file'] : null;
            if ($file === null && preg_match('/"([^"]*\.php)"/', $message, $m) === 1) {
                $file = $m[1];
            }
            if ($file === null) {
                continue; // no file to run without, so the error stays
            }
            $relative = self::appRelative($file, $appPath, $work);
            if ($relative === null || $relative === '') {
                continue; // the file is outside the app; nothing to exclude
            }
            $out[] = ['file' => $relative, 'message' => $message];
        }

        return $out;
    }

    /**
     * Makes a path Rector printed app-relative the way the diffs are: resolved against
     * the scratch work dir, then the app prefix stripped. A bare relative path that does
     * not reach into the app is already app-relative. Returns null when an absolute path
     * names a file outside the app.
     */
    private static function appRelative(string $file, string $appPath, string $work): ?string
    {
        $file = str_replace('\\', '/', $file);
        $absolute = str_starts_with($file, '/') || preg_match('#^[A-Za-z]:/#', $file) === 1;
        $resolved = $absolute ? $file : self::resolve($file, str_replace('\\', '/', $work));
        $prefix = str_replace('\\', '/', $appPath) . '/';
        if (str_starts_with($resolved, $prefix)) {
            return substr($resolved, strlen($prefix));
        }
        if (!$absolute) {
            return self::resolve($file, '.');
        }

        return null;
    }

    /**
     * Reruns Rector once with the unknown-class files added to the skip list and merges
     * the two reports: files from both runs (the second wins per file), rules summed,
     * the second run's errors only (the eligible ones are gone), and the excluded files
     * named as manual work. If the rerun still reports errors, they stay errors; there
     * is no third run.
     *
     * @param list<array{file: string, message: string}> $eligible
     * @param list<string>                               $command
     * @param array<string, string>                      $env
     * @param list<string>                               $vendored
     * @param list<string>                               $invalid
     */
    private function retryWithoutUnknownClasses(RectorResult $first, array $eligible, array $command, array $env, string $appPath, ?string $laravelTarget, ?string $phpunitTarget, string $memoryLimit, array $vendored, array $invalid): RectorResult
    {
        $skipFiles = array_map(static fn(string $f): string => $appPath . '/' . $f, $invalid);
        foreach ($eligible as $entry) {
            $skipFiles[] = $appPath . '/' . $entry['file'];
        }
        $env['BOOSTER_RECTOR_SKIP_FILES'] = json_encode(array_values(array_unique($skipFiles)), JSON_THROW_ON_ERROR);

        [$result, $work] = $this->invokeRector($command, $env, $appPath, $laravelTarget, $phpunitTarget);
        $second = $this->parse($result, $appPath, $work, $memoryLimit, $vendored, $invalid)[0];

        $files = $first->files;
        $index = [];
        foreach ($files as $i => $file) {
            $index[$file['file']] = $i;
        }
        foreach ($second->files as $file) {
            if (isset($index[$file['file']])) {
                $files[$index[$file['file']]] = $file;
            } else {
                $index[$file['file']] = count($files);
                $files[] = $file;
            }
        }

        $rules = $first->rules;
        foreach ($second->rules as $name => $count) {
            $rules[$name] = ($rules[$name] ?? 0) + $count;
        }

        $excluded = $first->excluded;
        $seen = [];
        foreach ($eligible as $entry) {
            if (isset($seen[$entry['file']])) {
                continue;
            }
            $seen[$entry['file']] = true;
            if (preg_match('/Class "?([^" ]+)"? should be known/', $entry['message'], $m) === 1) {
                $known = 'Class "' . $m[1] . '" should be known at this point';
            } else {
                $known = 'a class it uses is not known';
            }
            $excluded[] = ['file' => $entry['file'], 'reason' => 'Rector could not resolve a class it uses (' . $known . ')'];
        }

        return new RectorResult($files, $second->errors, $rules, $first->skippedDirs, $excluded);
    }
}
