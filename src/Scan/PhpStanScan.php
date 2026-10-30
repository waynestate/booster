<?php

declare(strict_types=1);

namespace Waynestate\Booster\Scan;

use Throwable;
use Waynestate\Booster\Packaging\PharFiles;
use Waynestate\Booster\Run\ProcessResult;
use Waynestate\Booster\Run\ProcessRunner;

/**
 * Runs PHPStan with Pinstripe's base.neon preset against an app, asking "what
 * does static analysis say at PHP X.Y?". Advisory only: every finding is a
 * hint for the human doing the upgrade, never a gate. Where the
 * PHPCompatibility scan is the OBD-II code reader, this is the compression
 * test: it finds the weak cylinders before the engine comes apart.
 *
 * The scanner is optional. Booster looks for a PHPStan binary, trying the
 * app's own vendor/bin/phpstan, then Booster's vendor/bin/phpstan, then
 * phpstan on the PATH, and for Pinstripe's preset it tries the same two
 * vendor trees. When either is missing, the scan reports a skip instead of
 * failing the plan, and the skip says how to fix it.
 *
 * A Laravel app that has Larastan gets Pinstripe's laravel.neon (which
 * includes base.neon), so Eloquent models and facades resolve; every other
 * app gets base.neon, and a Laravel app without Larastan hears why its model
 * calls will read as errors.
 */
final class PhpStanScan
{
    /** Shown when no PHPStan binary could be run at all. */
    private const MISSING_PHPSTAN = 'PHPStan scan skipped: PHPStan is not installed. Enable it with: composer require --dev phpstan/phpstan waynestate/pinstripe';

    /** Shown when a PHPStan runs but Pinstripe's preset is in neither vendor tree. */
    private const MISSING_PRESET = 'PHPStan scan skipped: the Pinstripe PHPStan preset is not installed. Enable it with: composer require --dev waynestate/pinstripe';

    /** Shown when the preset is present but one of its rule packages is not. */
    private const INCOMPLETE_PRESET = 'PHPStan scan skipped: the Pinstripe PHPStan preset is incomplete. Reinstall it with: composer require --dev waynestate/pinstripe';

    /** Hint shown when a Laravel app has no Larastan, so model and facade calls read as errors. */
    private const HINT_NO_LARASTAN = 'Laravel app without Larastan: Eloquent and facade calls show as errors. composer require --dev larastan/larastan for a cleaner scan.';

    /** Hint shown when a Laravel app has Larastan but Pinstripe's laravel.neon is in neither vendor tree. */
    private const HINT_MISSING_LARAVEL_PRESET = "Laravel app: Pinstripe's laravel.neon preset is missing, so the scan fell back to base.neon. Update Pinstripe with: composer update waynestate/pinstripe";

    /**
     * Paths that hold third-party code or IDE-generated files, not the
     * app's. PHPStan matches these fnmatch patterns against each file's full
     * path. The two IDE shapes are the same ones Rector skips in
     * config/rector.php.
     *
     * @var list<string>
     */
    private const EXCLUDE_PATHS = [
        '*/vendor/*',
        '*/node_modules/*',
        '*/storage/*',
        '*_ide_helper*.php',
        '*.phpstorm.meta.php',
    ];

    /**
     * The rule packages whose parameters base.neon relies on. They are loaded
     * before the preset because the preset's strictRules block is only valid
     * once strict-rules has registered it.
     *
     * @var list<string>
     */
    private const RULE_PACKAGES = ['phpstan/phpstan-strict-rules', 'phpstan/phpstan-deprecation-rules'];

    public function __construct(
        private readonly ProcessRunner $processes,
        private readonly ?string $phpstanBinary = null,
        private readonly ?string $phpBinary = null,
        private readonly ?string $boosterVendor = null,
    ) {}

    /**
     * Scans $appPath for what PHPStan reports at PHP $testVersion (major.minor).
     * Never throws: a broken scan becomes ScanResult::error, because an
     * advisory must not take down the plan.
     */
    public function scan(string $appPath, string $testVersion): ScanResult
    {
        try {
            return $this->doScan($appPath, $testVersion);
        } catch (Throwable $e) {
            return ScanResult::error($testVersion, 'The PHPStan scan crashed: ' . $e->getMessage());
        }
    }

    private function doScan(string $appPath, string $testVersion): ScanResult
    {
        if (preg_match('/^(\d+)\.(\d+)$/', $testVersion, $m) !== 1) {
            return ScanResult::error($testVersion, sprintf('PHPStan scan skipped: "%s" is not a major.minor PHP version.', $testVersion));
        }

        // Resolve to an absolute path so the reported file paths (always
        // absolute from PHPStan) can be made relative to the app again. If the
        // path does not exist, keep it as given: PHPStan will say so.
        $absolute = realpath($appPath);
        if ($absolute === false) {
            $absolute = $appPath;
        }
        $base = is_dir($absolute) ? $absolute : dirname($absolute);
        $prefix = rtrim(str_replace('\\', '/', $base), '/') . '/';

        $launch = $this->selectLauncher($absolute);
        if ($launch === null) {
            return ScanResult::error($testVersion, self::MISSING_PHPSTAN);
        }

        // A Laravel app with Larastan gets the laravel preset, so Eloquent
        // models and facades resolve. Without Larastan the same calls would be
        // a wall of errors, so the scan says what to install instead.
        $laravel = $this->isLaravelApp($absolute);
        $larastan = $laravel && is_file($absolute . '/vendor/larastan/larastan/composer.json');
        $hints = [];
        if ($laravel && !$larastan) {
            $hints[] = self::HINT_NO_LARASTAN;
        }

        $preset = $this->findPreset($absolute, $larastan);
        if ($preset === null) {
            return ScanResult::error($testVersion, self::MISSING_PRESET);
        }
        if ($larastan && $preset['name'] !== 'laravel.neon') {
            $hints[] = self::HINT_MISSING_LARAVEL_PRESET;
        }

        $rules = $this->ruleNeons($absolute, $preset);
        if ($rules === null) {
            return ScanResult::error($testVersion, self::INCOMPLETE_PRESET);
        }

        $neon = $this->writeConfiguration($absolute, $preset, $rules, ((int) $m[1]) * 10000 + ((int) $m[2]) * 100);
        register_shutdown_function(static function () use ($neon): void {
            if (is_file($neon)) {
                unlink($neon);
            }
        });

        // Run from inside the app so its autoloader and extension-installer
        // are what PHPStan picks up. When the app path does not exist there is
        // no directory to run from; inherit the caller's instead, so the
        // failure message comes from PHP or PHPStan itself.
        $cwd = is_dir($absolute) ? $absolute : null;

        try {
            $result = $this->processes->run(array_merge($launch, [
                'analyse',
                '--error-format=json',
                '--no-progress',
                '--memory-limit=2G',
                '--configuration=' . $neon,
                $absolute,
            ]), $cwd, [], 900);
        } finally {
            if (is_file($neon)) {
                unlink($neon);
            }
        }

        return $this->parse($result, $testVersion, $prefix, $hints);
    }

    /**
     * Pick the argument prefix that starts a PHPStan, or null when none of the
     * candidates runs. A bare --version probe is enough: unlike phpcs there is
     * no standard registration to check, a PHPStan that starts is a PHPStan
     * that can analyse.
     *
     * @return list<string>|null
     */
    private function selectLauncher(string $appPath): ?array
    {
        if ($this->phpstanBinary !== null) {
            // Test seam: trust the injected binary and skip discovery, so tests
            // can exercise the scan without a real PHPStan installed.
            return $this->launcher($this->phpstanBinary);
        }

        foreach ($this->candidates($appPath) as $candidate) {
            if ($this->runs($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The candidate PHPStan launchers, in priority order: the scanned app's
     * own vendor copy, Booster's vendor copy, then phpstan on the PATH.
     *
     * @return list<list<string>>
     */
    private function candidates(string $appPath): array
    {
        $candidates = [];

        $appPhpstan = $appPath . '/vendor/bin/phpstan';
        if (is_file($appPhpstan)) {
            $candidates[] = $this->launcher($appPhpstan);
        }

        $boosterPhpstan = $this->boosterVendorDir() . '/bin/phpstan';
        if (is_file($boosterPhpstan)) {
            $candidates[] = $this->launcher($boosterPhpstan);
        }

        $candidates[] = ['phpstan'];

        return $candidates;
    }

    private function boosterVendorDir(): string
    {
        return $this->boosterVendor ?? PharFiles::path('vendor');
    }

    /**
     * Argument prefix that runs a phpstan launcher file through the PHP
     * interpreter, so it behaves the same on Windows, macOS and Linux.
     *
     * @return list<string>
     */
    private function launcher(string $phpstan): array
    {
        return [($this->phpBinary ?? PHP_BINARY), $phpstan];
    }

    /**
     * Whether a launcher starts at all, as shown by a --version probe.
     *
     * @param list<string> $launch
     */
    private function runs(array $launch): bool
    {
        try {
            $result = $this->processes->run(array_merge($launch, ['--version']), null, [], 60);
        } catch (Throwable) {
            return false;
        }

        return $result->successful();
    }

    /**
     * Pinstripe's preset for this app: laravel.neon when $laravel (a Laravel
     * app with Larastan, whose extension-installer registers the rules), else
     * base.neon. Each name is looked up in the app's vendor tree first and
     * Booster's second, so the scan works for apps that have not installed
     * Pinstripe themselves. When laravel.neon is nowhere, base.neon stands in
     * and the caller says why.
     *
     * @return array{path: string, name: string, fromBooster: bool}|null
     */
    private function findPreset(string $appPath, bool $laravel): ?array
    {
        foreach (($laravel ? ['laravel.neon', 'base.neon'] : ['base.neon']) as $name) {
            $appPreset = $appPath . '/vendor/waynestate/pinstripe/phpstan/' . $name;
            if (is_file($appPreset)) {
                return ['path' => $appPreset, 'name' => $name, 'fromBooster' => false];
            }

            $boosterPreset = $this->boosterVendorDir() . '/waynestate/pinstripe/phpstan/' . $name;
            if (is_file($boosterPreset)) {
                return ['path' => $boosterPreset, 'name' => $name, 'fromBooster' => true];
            }
        }

        return null;
    }

    /**
     * Whether the app's composer.json requires laravel/framework in require or
     * require-dev, the same test ProjectDetector uses to pick an AppType. A
     * missing or broken composer.json is simply not Laravel: detection must
     * never turn a scan into a crash.
     */
    private function isLaravelApp(string $appPath): bool
    {
        $file = $appPath . '/composer.json';
        if (!is_file($file)) {
            return false;
        }
        $raw = file_get_contents($file);
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data)) {
            return false;
        }
        foreach (['require', 'require-dev'] as $section) {
            if (isset($data[$section]) && is_array($data[$section]) && array_key_exists('laravel/framework', $data[$section])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The rule neons the preset expects to be loaded before it, or null when
     * one of them is in neither vendor tree. When the app's own
     * extension-installer already loads a package (its GeneratedConfig lists
     * it), including the neon again would make PHPStan refuse to start, so
     * that one is left to the app.
     *
     * @param array{path: string, name: string, fromBooster: bool} $preset
     *
     * @return list<string>|null
     */
    private function ruleNeons(string $appPath, array $preset): ?array
    {
        $presetVendor = dirname(dirname(dirname(dirname($preset['path']))));
        $installer = $appPath . '/vendor/phpstan/extension-installer/src/GeneratedConfig.php';
        $installed = is_file($installer) ? (string) file_get_contents($installer) : '';

        $neons = [];
        foreach (self::RULE_PACKAGES as $package) {
            if ($installed !== '' && str_contains($installed, $package)) {
                continue;
            }
            $found = null;
            foreach ([$presetVendor, $this->boosterVendorDir()] as $vendor) {
                $neon = $vendor . '/' . $package . '/rules.neon';
                if (is_file($neon)) {
                    $found = $neon;
                    break;
                }
            }
            if ($found === null) {
                return null;
            }
            $neons[] = $found;
        }

        return $neons;
    }

    /**
     * Write the temporary configuration the scan runs with: the rule neons,
     * then the preset, then the parameters the preset does not carry. The
     * scanned app goes on the command line, not here, so the CLI stays the
     * shape users expect.
     *
     * @param array{path: string, name: string, fromBooster: bool} $preset
     * @param list<string> $rules
     */
    private function writeConfiguration(string $appPath, array $preset, array $rules, int $phpVersionId): string
    {
        $lines = ['includes:'];
        foreach (array_merge($rules, [$preset['path']]) as $included) {
            $lines[] = '    - ' . $this->neonQuote($included);
        }
        $lines[] = 'parameters:';
        $lines[] = '    phpVersion: ' . $phpVersionId;
        $lines[] = '    excludePaths:';
        foreach (self::EXCLUDE_PATHS as $pattern) {
            $lines[] = '        - ' . $pattern;
        }
        foreach ($this->bootstrapFiles($appPath, $preset) as $file) {
            $lines[] = '        - ' . $this->neonQuote($file);
        }
        // A preset without a level would silently analyse at level 0; level 5
        // is the floor the scan promises.
        if (preg_match('/^[ \t]+level:[ \t]*\d+/m', (string) file_get_contents($preset['path'])) !== 1) {
            $lines[] = '    level: 5';
        }

        $neon = sys_get_temp_dir() . '/booster-phpstan-' . bin2hex(random_bytes(6)) . '.neon';
        file_put_contents($neon, implode("\n", $lines) . "\n");

        return $neon;
    }

    /**
     * The autoloaders PHPStan should load before analysing, so the app's own
     * classes resolve. The app's autoloader comes first; Booster's is added
     * only when the preset lives in Booster's vendor, because then the rule
     * classes the included neons register live there too.
     *
     * @param array{path: string, name: string, fromBooster: bool} $preset
     *
     * @return list<string>
     */
    private function bootstrapFiles(string $appPath, array $preset): array
    {
        $files = [];
        $appAutoload = $appPath . '/vendor/autoload.php';
        if (is_file($appAutoload)) {
            $files[] = $appAutoload;
        }
        if ($preset['fromBooster']) {
            $boosterAutoload = $this->boosterVendorDir() . '/autoload.php';
            if (is_file($boosterAutoload)) {
                $files[] = $boosterAutoload;
            }
        }

        return $files;
    }

    /**
     * Quote a path for NEON: forward slashes throughout (a backslash is an
     * escape character in NEON strings) and single quotes around it.
     */
    private function neonQuote(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return "'" . str_replace("'", "''", $path) . "'";
    }

    /**
     * Turn the JSON report into findings. PHPStan exits 1 both when it found
     * something and when it failed to start (invalid configuration, missing
     * paths), so the exit code cannot tell the two apart: a parseable report
     * is the truth, and anything else is a skip with the reason on stderr.
     *
     * @param list<string> $hints
     */
    private function parse(ProcessResult $result, string $testVersion, string $prefix, array $hints): ScanResult
    {
        $data = json_decode($result->stdout, true);
        if (!is_array($data) || !isset($data['files']) || !is_array($data['files'])) {
            return $this->noReport($result, $testVersion);
        }

        $findings = [];
        foreach ($data['files'] as $file => $info) {
            if (!is_string($file) || !is_array($info) || !isset($info['messages']) || !is_array($info['messages'])) {
                continue;
            }
            $file = str_replace('\\', '/', $file);
            if (str_starts_with($file, $prefix)) {
                $file = substr($file, strlen($prefix));
            }
            foreach ($info['messages'] as $message) {
                if (!is_array($message) || !is_string($message['message'] ?? null) || !is_int($message['line'] ?? null)) {
                    continue;
                }
                $findings[] = new Finding(
                    $file,
                    $message['line'],
                    $message['message'],
                    'error',
                    is_string($message['identifier'] ?? null) ? $message['identifier'] : '',
                );
            }
        }

        return ScanResult::ok($testVersion, $findings, $hints);
    }

    private function noReport(ProcessResult $result, string $testVersion): ScanResult
    {
        $detail = trim($result->stderr !== '' ? $result->stderr : $result->stdout);
        $suffix = $detail !== '' ? "\n" . $detail : '';

        return ScanResult::error($testVersion, sprintf('phpstan exited %d without a JSON report.', $result->exitCode) . $suffix);
    }
}
