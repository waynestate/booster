<?php

declare(strict_types=1);

namespace Waynestate\Booster\Strangler;

use Waynestate\Booster\Packaging\PharFiles;
use Waynestate\Booster\Refresh\HopDataRefresher;
use Waynestate\Booster\Replay\ReplayFile;
use Waynestate\Booster\ToolError;

/**
 * The strangler's mount step (design sections 1 and 2, amended by 9.3 and
 * 10.6): Laravel at the root, the old app's Composer root moved unchanged to
 * legacy/, and a framework-free public/index.php that sends each request to
 * exactly one of them.
 *
 * In order, and nothing is changed until the bake has worked:
 *
 * 1. Sourdough bakes the Laravel half into a scratch folder (MountBaker).
 * 2. The old Composer root moves to legacy/: a src/ root is renamed; a root
 *    that is the repository root has its entries moved, except the files
 *    that belong to the repository (git and CI files, Booster's own files,
 *    deploy scripts, README and licence).
 * 3. The baked tree is laid over the root where paths are free; a
 *    collision keeps the app's file and becomes a MANUAL line (.gitignore is
 *    merged instead).
 * 4. public/index.php becomes the dispatcher (and public/.htaccess Laravel's
 *    without the trailing-slash 301, with the legacy docroot's rules moved
 *    in by LegacyHtaccess), bootstrap/booster-moved.php
 *    the empty route table, bin/install-legacy.php the install hook that the
 *    root composer.json runs after every install and update.
 * 5. Laravel's own routes move under /_laravel (health, readiness, the demo
 *    pages), so the Laravel half answers nothing legacy answered; and
 *    TrimStrings and ConvertEmptyStringsToNull skip moved legacy routes.
 * 6. booster-replay.json is served from public/ with legacy's setup run in
 *    legacy/ and Laravel's replay environment (a fixed fake APP_KEY).
 * 7. .env.example gets the keys the legacy half reads; the report lists the
 *    Citadel public directory, the moved config paths and the .htaccess
 *    lines that could not move.
 *
 * Booster never commits, so the mount is one reviewable diff. Moving house
 * with the furniture still in the boxes: every box keeps its label.
 */
final class Mount
{
    /** Where the old Composer root lives after the mount. */
    public const LEGACY = 'legacy';

    /** The fixed fake APP_KEY the replay serves the Laravel half with (32 bytes, never a real key). */
    public const REPLAY_APP_KEY = 'base64:Ym9vc3Rlci1yZXBsYXktZmFrZS1hcHAta2V5LTMyYiE=';

    /** The Laravel half's session cookie in the replay (pinned through SESSION_COOKIE). */
    public const LARAVEL_SESSION_COOKIE = 'laravel_session';

    /** Where the Laravel half signs in (SESAME_ROUTE_PREFIX=_laravel): the replay's silent sign-in follows it. */
    public const LARAVEL_LOGIN = '/_laravel/login';

    /**
     * Repository-level entries that stay at the root when the Composer root
     * is the repository root (matched case-insensitively; a trailing * is a
     * prefix).
     */
    private const REPO_LEVEL = [
        '.git', '.github', '.gitlab', '.gitlab-ci.yml', '.booster', '.idea', '.vscode',
        'booster-replay.json', 'booster-replay.recording.json', 'booster-hop.json', 'booster-strangler.json',
        'parity.json', 'deployments', 'build.sh', 'dockerfile', 'docker-compose.yml', 'docker-compose.yaml',
        'compose.yml', 'compose.yaml', '.dockerignore', 'docs', 'readme*', 'license*', 'licence*',
        'changelog*', 'contributing*', 'security*', 'code_of_conduct*', '.editorconfig',
    ];

    /** The baked files whose collision is expected and silent: the mount writes its own. */
    private const MOUNT_OWNED = ['public/index.php', 'public/.htaccess', 'bootstrap/booster-moved.php', 'bin/install-legacy.php'];

    /** Config files a Citadel dashboard may write into the source tree, relative to the Composer root. */
    private const CONFIG_FILES = [
        'cakephp' => ['config/app_local.php', 'config/.env', '.env'],
        'slim' => ['.env', 'etc/config.ini', 'config/settings.local.php', 'app/settings.local.php', 'config/local.php'],
    ];

    /** @var list<string> */
    private array $changed = [];

    /** @var list<string> */
    private array $manual = [];

    /** The lines movedInputAsLegacy() puts at the top of bootstrap/app.php's withMiddleware(). */
    private const MOVED_INPUT = <<<'APP'
                // Booster's strangler mount: code moved from legacy/ gets its input as
                // legacy gave it (no trimming, "" stays ""). public/index.php marks
                // those requests with BOOSTER_MOVED_UNIT; the finish step removes this.
                $movedFromLegacy = fn (Request $request): bool => $request->server->has('BOOSTER_MOVED_UNIT');
                $middleware->trimStrings(except: [$movedFromLegacy]);
                $middleware->convertEmptyStringsToNull(except: [$movedFromLegacy]);

        APP;

    /** @var list<string> */
    private array $notes = [];

    /** @var list<string> */
    private array $htaccessManual = [];

    public function __construct(
        private readonly MountBaker $baker,
        private readonly ?string $resourceDir = null,
    ) {}

    public function run(string $appRoot, string $framework): MountResult
    {
        if (!in_array($framework, ['slim', 'cakephp'], true)) {
            throw new ToolError(sprintf('The strangler mounts "slim" or "cakephp" apps, not "%s".', $framework));
        }
        $this->changed = [];
        $this->manual = [];
        $this->notes = [];
        $this->htaccessManual = [];
        $root = rtrim(str_replace('\\', '/', $appRoot), '/');
        if (!is_dir($root)) {
            throw new ToolError(sprintf('No app at %s.', $appRoot));
        }

        // Stop signs the mount checks itself, whatever called it.
        foreach ([self::LEGACY, 'bootstrap/booster-moved.php'] as $taken) {
            if (file_exists($root . '/' . $taken)) {
                return MountResult::refused([['file' => $taken, 'line' => 0, 'reason' => sprintf('%s is already there, so this app is mounted already (or holds a folder the mount needs). Booster changed nothing.', $taken)]]);
            }
        }
        $composerRoot = $this->composerRoot($root, $framework);
        if (is_array($composerRoot)) {
            return MountResult::refused([$composerRoot]);
        }
        $legacySource = $composerRoot === '.' ? $root : $root . '/' . $composerRoot;
        if ($composerRoot !== '.') {
            foreach (['app', 'bootstrap', 'routes', 'artisan'] as $laravel) {
                if (file_exists($root . '/' . $laravel)) {
                    return MountResult::refused([['file' => $laravel, 'line' => 0, 'reason' => sprintf('The repository root already has %s, where the Laravel half goes. Booster changed nothing.', $laravel)]]);
                }
            }
        }

        // The replay file, read before anything moves.
        [$replayPath, $replayData] = $this->readReplay($root, $composerRoot);
        $oldDocroot = is_array($replayData['serve'] ?? null) && is_string($replayData['serve']['docroot'] ?? null) ? trim($replayData['serve']['docroot'], '/') : '';
        $legacyDocroot = self::legacyDocroot($framework, $oldDocroot, $legacySource);
        $legacyFront = $framework === 'cakephp'
            ? 'index.php'
            : (is_array($replayData['serve'] ?? null) && is_string($replayData['serve']['front_controller'] ?? null) ? ltrim($replayData['serve']['front_controller'], '/') : 'index.php');
        if (!is_file($legacySource . '/' . $legacyDocroot . '/' . $legacyFront)) {
            return MountResult::refused([['file' => self::join($composerRoot, $legacyDocroot . '/' . $legacyFront), 'line' => 0, 'reason' => sprintf('The legacy front controller %s is missing, so the dispatcher would have nothing to send legacy requests to. Booster changed nothing.', self::join($composerRoot, $legacyDocroot . '/' . $legacyFront))]]);
        }
        $legacyCookie = self::legacySessionCookie($framework, $legacySource);
        $htaccess = $this->htaccessLines($legacySource, $composerRoot, $legacyDocroot);
        $docrootHtaccess = $legacySource . '/' . $legacyDocroot . '/.htaccess';
        $moved = LegacyHtaccess::move(is_file($docrootHtaccess) ? (string) file_get_contents($docrootHtaccess) : '', self::LEGACY . '/' . $legacyDocroot, $legacyFront);

        // 1. Bake first: a failed bake changes nothing.
        $scratch = sys_get_temp_dir() . '/booster-mount-' . bin2hex(random_bytes(6));
        if (!mkdir($scratch, 0777, true) && !is_dir($scratch)) {
            throw new ToolError(sprintf('Could not create the scratch folder %s.', $scratch));
        }
        @chmod($scratch, 0777);
        try {
            $this->baker->bake($scratch, self::appName($root));
            $this->notes[] = 'The Laravel half was baked by ' . $this->baker->describe() . '.';

            // 2. The old Composer root moves to legacy/.
            $this->moveLegacy($root, $composerRoot);

            // 3. The baked tree goes over the root where paths are free.
            $this->overlay($scratch, $root, '', self::LEGACY . '/' . $legacyDocroot);
        } finally {
            self::removeTree($scratch);
        }

        // 4. Dispatcher, route table, install hook, composer scripts.
        $this->writeDispatcher($root, self::LEGACY . '/' . $legacyDocroot, $legacyFront);
        RouteTable::load($root)->save();
        $this->changed[] = RouteTable::FILE;
        $this->writeFile($root, 'bin/install-legacy.php', $this->resource('install-legacy.php'));
        // Laravel's .htaccess without its trailing-slash 301, which would answer
        // legacy paths first, with the legacy docroot's rules moved in.
        $this->writeFile($root, 'public/.htaccess', $moved->apply($this->resource('public.htaccess'), self::join($composerRoot, $legacyDocroot . '/.htaccess')));
        $this->htaccessReport($moved, self::join($composerRoot, $legacyDocroot . '/.htaccess'), $legacyDocroot . '/.htaccess');
        $this->movedInputAsLegacy($root);
        $this->addInstallHook($root);

        // 5. Laravel's own routes under /_laravel.
        $this->prefixLaravelRoutes($root);
        $this->excludeLegacyFromRootTools($root);
        if ($composerRoot !== '.') {
            $this->rewriteRootGitignore($root, $composerRoot);
        }

        // 6. The replay file.
        $ignoreCookies = array_values(array_unique(array_filter([
            $legacyCookie,
            $framework === 'cakephp' ? 'csrfToken' : null,
            self::LARAVEL_SESSION_COOKIE,
            'XSRF-TOKEN',
        ])));
        $written = null;
        if ($replayData !== null) {
            $written = $this->rewriteReplay($root, $replayPath, $replayData, $composerRoot, $ignoreCookies);
        } else {
            $this->notes[] = 'No booster-replay.json, so the mount could not be replayed; every later move needs one (booster replay init).';
        }

        // 7. .env.example, parity.json and the MANUAL lines.
        $this->mapEnvExample($root, $composerRoot);
        $oldPublic = self::join($composerRoot, $legacyDocroot);
        $this->parity($root);
        array_unshift(
            $this->manual,
            sprintf('Citadel: set the app\'s public directory to public (it was %s) in the dashboard, which triggers an Update. Deploy the mount and this change together: %s no longer exists after the mount.', $oldPublic, $oldPublic),
        );
        foreach (self::CONFIG_FILES[$framework] as $config) {
            if (file_exists($root . '/' . self::LEGACY . '/' . $config) || file_exists($root . '/' . self::LEGACY . '/' . $config . '.example') || file_exists($root . '/' . self::LEGACY . '/' . preg_replace('/\.php$/', '.example.php', $config))) {
                $this->manual[] = sprintf('Citadel: a dashboard config file written to %s now belongs at %s; change its path in the dashboard.', self::join($composerRoot, $config), self::LEGACY . '/' . $config);
            }
        }
        foreach ([...$htaccess, ...$this->htaccessManual] as $line) {
            $this->manual[] = $line;
        }
        $this->manual[] = 'Sourdough feed will skip public/index.php until the finish step: the dispatcher is app-owned until then.';
        if (str_contains($this->baker->describe(), '--no-playbook')) {
            $this->manual[] = 'Playbook: the Laravel half was baked without waynestate/playbook (the bake container cannot read git.wayne.edu). Add it as Sourdough does: composer require waynestate/playbook in the root.';
        }

        return new MountResult(
            mounted: true,
            changedFiles: array_values(array_unique($this->changed)),
            refusals: [],
            manual: $this->manual,
            notes: $this->notes,
            composerRoot: $composerRoot,
            legacyDocroot: self::LEGACY . '/' . $legacyDocroot,
            oldPublicDir: $oldPublic,
            ignoreCookies: $ignoreCookies,
            replayFile: $written,
        );
    }

    /**
     * The folder (relative to the root) whose composer.json is the legacy
     * app: the root itself, or the one subfolder whose composer.json requires
     * the framework (the fleet's src/). A refusal row when there is none.
     *
     * @return string|array{file: string, line: int, reason: string}
     */
    private function composerRoot(string $root, string $framework): string|array
    {
        $package = $framework === 'slim' ? 'slim/slim' : 'cakephp/cakephp';
        if (is_file($root . '/composer.json')) {
            if (!self::requires($root . '/composer.json', $package)) {
                return ['file' => 'composer.json', 'line' => 0, 'reason' => sprintf('composer.json does not require %s, so this is not a %s app the strangler can mount.', $package, $framework === 'slim' ? 'Slim' : 'CakePHP')];
            }

            return '.';
        }
        $found = [];
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry[0] !== '.' && is_dir($root . '/' . $entry) && is_file($root . '/' . $entry . '/composer.json') && self::requires($root . '/' . $entry . '/composer.json', $package)) {
                $found[] = $entry;
            }
        }
        if (count($found) !== 1) {
            return ['file' => 'composer.json', 'line' => 0, 'reason' => $found === []
                ? sprintf('No composer.json requiring %s at the root or one folder down, so Booster cannot tell which folder is the app.', $package)
                : sprintf('More than one folder holds a %s app (%s); the strangler mounts one Composer root.', $package, implode(', ', $found))];
        }

        return $found[0];
    }

    private static function requires(string $composerJson, string $package): bool
    {
        $data = json_decode((string) file_get_contents($composerJson), true);

        return is_array($data) && is_array($data['require'] ?? null) && isset($data['require'][$package]);
    }

    /**
     * The legacy docroot relative to the Composer root: CakePHP's front
     * controller is always webroot/index.php (a root index.php only requires
     * it); Slim's is the replay file's docroot, or public.
     */
    private static function legacyDocroot(string $framework, string $oldDocroot, string $legacySource): string
    {
        if ($oldDocroot !== '' && $oldDocroot !== '.' && is_dir($legacySource . '/' . $oldDocroot)) {
            return $oldDocroot;
        }

        return $framework === 'cakephp' ? 'webroot' : 'public';
    }

    /**
     * @return array{0: ?string, 1: ?array<string, mixed>}
     */
    private function readReplay(string $root, string $composerRoot): array
    {
        $candidates = [$root . '/' . ReplayFile::DEFAULT_NAME];
        if ($composerRoot !== '.') {
            $candidates[] = $root . '/' . $composerRoot . '/' . ReplayFile::DEFAULT_NAME;
        }
        foreach ($candidates as $path) {
            if (!is_file($path)) {
                continue;
            }
            $data = json_decode((string) file_get_contents($path), true);
            if (!is_array($data)) {
                throw new ToolError(sprintf('%s is not valid JSON, so the mount cannot rewrite its serve block.', $path));
            }
            ReplayFile::fromArray($data, $path);
            $data['$booster_from'] = $path === $candidates[0] ? '.' : $composerRoot;

            return [$path, $data];
        }

        return [null, null];
    }

    private function moveLegacy(string $root, string $composerRoot): void
    {
        $legacy = $root . '/' . self::LEGACY;
        if ($composerRoot !== '.') {
            if (!rename($root . '/' . $composerRoot, $legacy)) {
                throw new ToolError(sprintf('Could not move %s to %s.', $composerRoot, self::LEGACY));
            }
            $this->changed[] = self::LEGACY . '/';
            $this->notes[] = sprintf('The legacy app\'s Composer root moved unchanged from %s/ to legacy/.', $composerRoot);

            return;
        }
        if (!mkdir($legacy, 0775) && !is_dir($legacy)) {
            throw new ToolError('Could not create legacy/.');
        }
        $stayed = [];
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === self::LEGACY) {
                continue;
            }
            if (self::repoLevel($entry)) {
                $stayed[] = $entry;
                continue;
            }
            if (!rename($root . '/' . $entry, $legacy . '/' . $entry)) {
                throw new ToolError(sprintf('Could not move %s into legacy/.', $entry));
            }
        }
        $this->changed[] = self::LEGACY . '/';
        $this->notes[] = 'The legacy app moved unchanged into legacy/' . ($stayed === [] ? '.' : sprintf('; these stayed at the root because they belong to the repository: %s.', implode(', ', $stayed)));
    }

    private static function repoLevel(string $entry): bool
    {
        $lower = strtolower($entry);
        foreach (self::REPO_LEVEL as $pattern) {
            if (str_ends_with($pattern, '*') ? str_starts_with($lower, substr($pattern, 0, -1)) : $lower === $pattern) {
                return true;
            }
        }

        return false;
    }

    /**
     * Copies the baked tree over the root where paths are free. A baked file
     * under public/ whose URL the legacy docroot also answers is skipped: the
     * web server serves public/ before the dispatcher runs, so Sourdough's
     * robots.txt or favicon.ico would shadow the app's and change its answer.
     */
    private function overlay(string $from, string $root, string $relative, string $legacyDocroot): void
    {
        foreach (scandir($from . ($relative === '' ? '' : '/' . $relative)) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || ($relative === '' && in_array($entry, ['.git', 'vendor', 'node_modules', self::LEGACY], true))) {
                continue;
            }
            $rel = $relative === '' ? $entry : $relative . '/' . $entry;
            $source = $from . '/' . $rel;
            $target = $root . '/' . $rel;
            if (is_dir($source)) {
                if (file_exists($target) && !is_dir($target)) {
                    $this->manual[] = sprintf('Sourdough\'s %s/ was not written: the app has a file of that name. Compare them by hand.', $rel);
                    continue;
                }
                if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
                    throw new ToolError(sprintf('Could not create %s.', $rel));
                }
                $this->overlay($from, $root, $rel, $legacyDocroot);
                continue;
            }
            if (in_array($rel, self::MOUNT_OWNED, true)) {
                continue;
            }
            if (str_starts_with($rel, 'public/') && $rel !== 'public/.htaccess' && file_exists($root . '/' . $legacyDocroot . '/' . substr($rel, 7))) {
                $this->notes[] = sprintf('Sourdough\'s %s was not written: %s/%s answers that URL, and a file in public/ would be served before the dispatcher runs.', $rel, $legacyDocroot, substr($rel, 7));
                continue;
            }
            if (!file_exists($target)) {
                if (!copy($source, $target)) {
                    throw new ToolError(sprintf('Could not write %s.', $rel));
                }
                @chmod($target, fileperms($source) & 0777);
                $this->changed[] = $rel;
                continue;
            }
            if (is_file($target) && hash_file('sha256', $target) === hash_file('sha256', $source)) {
                continue;
            }
            if ($rel === '.gitignore') {
                $this->mergeGitignore($target, (string) file_get_contents($source));
                continue;
            }
            $this->manual[] = sprintf('Sourdough\'s %s was not written: the app already has one, and the app\'s copy was kept. Compare them by hand.', $rel);
        }
    }

    private function mergeGitignore(string $target, string $baked): void
    {
        $have = array_map('trim', file($target, FILE_IGNORE_NEW_LINES) ?: []);
        $add = [];
        foreach (preg_split('/\R/', $baked) ?: [] as $line) {
            $line = rtrim($line);
            if ($line !== '' && !str_starts_with($line, '#') && !in_array($line, $have, true) && !in_array($line, $add, true)) {
                $add[] = $line;
            }
        }
        if ($add === []) {
            return;
        }
        $text = rtrim((string) file_get_contents($target)) . "\n\n# The Laravel half (added by Booster's strangler mount)\n" . implode("\n", $add) . "\n";
        file_put_contents($target, $text);
        $this->changed[] = '.gitignore';
    }

    /** Root .gitignore lines that named the old Composer root now name legacy/. */
    private function rewriteRootGitignore(string $root, string $composerRoot): void
    {
        $path = $root . '/.gitignore';
        if (!is_file($path)) {
            return;
        }
        $text = (string) file_get_contents($path);
        $quoted = preg_quote($composerRoot, '/');
        $new = (string) preg_replace('/^(!?\/?)' . $quoted . '\//m', '${1}' . self::LEGACY . '/', $text);
        if ($new !== $text) {
            file_put_contents($path, $new);
            $this->changed[] = '.gitignore';
            $this->notes[] = sprintf('.gitignore lines that named %s/ now name legacy/.', $composerRoot);
        }
    }

    private function writeDispatcher(string $root, string $legacyDocroot, string $legacyFront): void
    {
        $source = $this->resource('dispatcher.php');
        $source = (string) preg_replace_callback(
            '/^(\s*\$legacyDocroot = )\'[^\']*\';( \/\/ booster:legacy-docroot)$/m',
            static fn(array $m): string => $m[1] . var_export($legacyDocroot, true) . ';' . $m[2],
            $source,
            1,
            $docroot,
        );
        $source = (string) preg_replace_callback(
            '/^(\s*\$legacyFront = )\'[^\']*\';( \/\/ booster:legacy-front)$/m',
            static fn(array $m): string => $m[1] . var_export($legacyFront, true) . ';' . $m[2],
            $source,
            1,
            $front,
        );
        if ($docroot !== 1 || $front !== 1) {
            throw new ToolError('The dispatcher template lost its booster:legacy-docroot or booster:legacy-front line.');
        }
        $this->writeFile($root, 'public/index.php', $source);
    }

    private function writeFile(string $root, string $relative, string $contents): void
    {
        $path = $root . '/' . $relative;
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
            throw new ToolError(sprintf('Could not create the folder for %s.', $relative));
        }
        if (file_put_contents($path, $contents) === false) {
            throw new ToolError(sprintf('Could not write %s.', $relative));
        }
        $this->changed[] = $relative;
    }

    /** The root composer.json runs bin/install-legacy.php after every install and update. */
    private function addInstallHook(string $root): void
    {
        $path = $root . '/composer.json';
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            throw new ToolError('The baked composer.json is not valid JSON.');
        }
        $scripts = is_array($data['scripts'] ?? null) ? $data['scripts'] : [];
        foreach (['post-install-cmd', 'post-update-cmd'] as $event) {
            $list = $scripts[$event] ?? [];
            $list = is_array($list) ? array_values($list) : [$list];
            if (!in_array('@php bin/install-legacy.php', $list, true)) {
                $list[] = '@php bin/install-legacy.php';
            }
            $scripts[$event] = $list;
        }
        $data['scripts'] = $scripts;
        // One PHP runs both halves: the root never floors below the legacy app (its lock was resolved on that PHP).
        $legacy = json_decode((string) @file_get_contents($root . '/' . self::LEGACY . '/composer.json'), true);
        $legacyPhp = is_array($legacy) && is_string($legacy['require']['php'] ?? null) ? $legacy['require']['php'] : null;
        $rootPhp = is_array($data['require'] ?? null) && is_string($data['require']['php'] ?? null) ? $data['require']['php'] : null;
        $legacyFloor = $legacyPhp === null ? null : HopDataRefresher::phpFloor($legacyPhp);
        $rootFloor = $rootPhp === null ? null : HopDataRefresher::phpFloor($rootPhp);
        if ($legacyPhp !== null && $legacyFloor !== null && ($rootFloor === null || version_compare($legacyFloor, $rootFloor, '>'))) {
            $data['require'] = ['php' => $legacyPhp, ...(is_array($data['require'] ?? null) ? $data['require'] : [])];
            $data['require']['php'] = $legacyPhp;
            $this->notes[] = sprintf('composer.json requires PHP %s, as legacy/ does (the Laravel half asked for %s): one PHP runs both halves.', $legacyPhp, $rootPhp ?? 'none');
            // Sourdough pins config.platform.php to its own floor so the lock resolves the same everywhere; move the pin up with it.
            $platform = $data['config']['platform']['php'] ?? null;
            if (is_string($platform) && version_compare($platform, $legacyFloor . '.0', '<')) {
                $data['config']['platform']['php'] = $legacyFloor . '.0';
                $this->notes[] = sprintf('composer.json config.platform.php is %s.0 (it was %s), so the lock resolves for that PHP.', $legacyFloor, $platform);
            }
        }
        file_put_contents($path, self::json($data));
        $this->changed[] = 'composer.json';
        $this->notes[] = 'composer.json runs bin/install-legacy.php after every install and update, so one composer install installs both roots (--no-dev carries over).';
    }

    /**
     * Laravel's own routes (health, readiness and Sourdough's demo pages)
     * move under /_laravel, the prefix the dispatcher always sends to
     * Laravel, so the Laravel half answers nothing legacy answered.
     */
    private function prefixLaravelRoutes(string $root): void
    {
        $app = $root . '/bootstrap/app.php';
        if (is_file($app)) {
            $text = (string) file_get_contents($app);
            $new = str_replace(["health: '/up'", "Route::helmetReady('/ready'"], ["health: '/_laravel/up'", "Route::helmetReady('/_laravel/ready'"], $text);
            if ($new !== $text) {
                file_put_contents($app, $new);
                $this->changed[] = 'bootstrap/app.php';
            }
            if (str_contains($new, "health: '/up'") || !str_contains($new, "'/_laravel/up'")) {
                $this->manual[] = 'bootstrap/app.php: move Laravel\'s health route under /_laravel (health: \'/_laravel/up\'); the mount could not find the stock line.';
            }
        }
        $web = $root . '/routes/web.php';
        if (is_file($web)) {
            $text = (string) file_get_contents($web);
            $new = str_replace(["Route::get('/', ", "Route::get('/dashboard', "], ["Route::get('/_laravel', ", "Route::get('/_laravel/dashboard', "], $text);
            if ($new !== $text) {
                $new = (string) preg_replace('/^(\/\/ \/ready, HELMET)/m', "// The strangler mount moved Sourdough's pages under /_laravel, the prefix\n// public/index.php always sends to Laravel; moved legacy routes go below.\n\n$1", $new, 1);
                file_put_contents($web, $new);
                $this->changed[] = 'routes/web.php';
            }
        }
        if (is_dir($root . '/tests/Feature')) {
            $this->manual[] = 'tests/Feature: Sourdough\'s own tests still ask for its pages at /, /dashboard, /login and /up, which now live under /_laravel (sign-in at /_laravel/login), so the root suite fails until you point them there or delete the demo tests. While legacy/ exists, Booster gates with the syntax check and the legacy suite, not the root suite.';
        }
    }

    /** Pint and PHPStan in the root leave legacy/ alone. */
    private function excludeLegacyFromRootTools(string $root): void
    {
        $pint = $root . '/pint.json';
        if (is_file($pint)) {
            $data = json_decode((string) file_get_contents($pint), true);
            if (is_array($data)) {
                $exclude = is_array($data['exclude'] ?? null) ? $data['exclude'] : [];
                if (!in_array(self::LEGACY, $exclude, true)) {
                    $exclude[] = self::LEGACY;
                    $data['exclude'] = $exclude;
                    file_put_contents($pint, self::json($data));
                    $this->changed[] = 'pint.json';
                }
            }
        }
        // Sourdough's phpstan.neon names its paths (app, bootstrap, routes,
        // database), so legacy/ is outside it already.
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $ignoreCookies
     */
    private function rewriteReplay(string $root, ?string $oldPath, array $data, string $composerRoot, array $ignoreCookies): string
    {
        $from = (string) ($data['$booster_from'] ?? '.');
        unset($data['$booster_from']);
        $serve = is_array($data['serve'] ?? null) ? $data['serve'] : [];
        $legacySetup = isset($serve['setup']) && is_string($serve['setup']) && $serve['setup'] !== '' ? $serve['setup'] : null;
        $setup = ['mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache'];
        if ($legacySetup !== null) {
            if ($from === $composerRoot || $composerRoot === '.') {
                $setup[] = '(cd ' . self::LEGACY . ' && ' . $legacySetup . ')';
            } else {
                $setup[] = $legacySetup;
                $this->manual[] = sprintf('booster-replay.json: the setup command was written for the repository root, and %s/ is now legacy/; check its paths.', $composerRoot);
            }
        }
        $env = is_array($serve['env'] ?? null) ? $serve['env'] : [];
        $laravelEnv = [
            'APP_KEY' => self::REPLAY_APP_KEY,
            'APP_ENV' => 'local',
            'APP_DEBUG' => 'false',
            'SESSION_DRIVER' => 'file',
            // Pinned, so compare.ignore_cookies names it whatever APP_NAME is.
            'SESSION_COOKIE' => self::LARAVEL_SESSION_COOKIE,
            'CACHE_STORE' => 'file',
            'QUEUE_CONNECTION' => 'sync',
            'LOG_CHANNEL' => 'stderr',
            // No BOOSTER_REPLAY here: the replay container sets it on every
            // serve, and the dispatcher then says which half answered.
        ];
        foreach ($laravelEnv as $key => $value) {
            if (array_key_exists($key, $env)) {
                if ((string) $env[$key] !== $value) {
                    $this->manual[] = sprintf('booster-replay.json: the legacy half already sets %s for the replay, and the Laravel half reads it too; check that one value suits both.', $key);
                }
                continue;
            }
            $env[$key] = $value;
        }
        $newServe = ['docroot' => 'public', 'front_controller' => 'index.php', 'setup' => implode(' && ', $setup), 'env' => $env];
        foreach ($serve as $key => $value) {
            if (!array_key_exists($key, $newServe) && !in_array($key, ['docroot', 'front_controller', 'setup', 'env'], true)) {
                $newServe[$key] = $value;
            }
        }
        $newServe['$booster_mount'] = sprintf('Written by the strangler mount: served from public/ (the dispatcher), legacy setup run in legacy/. Before the mount: %s.', self::serveLine($serve));
        $data['serve'] = $newServe;

        // The strangler compare block (design 5.2 to 5.5): the strangler
        // preset, the session and CSRF cookies of both halves ignored, and
        // the silent sign-in through the Laravel half's login. None of these
        // keys is in the fingerprint, so the recording stays valid. Written
        // where this Booster's request file reads them; an older parser would
        // refuse the unknown keys.
        $withCompare = $data;
        $compare = is_array($withCompare['compare'] ?? null) ? $withCompare['compare'] : [];
        $compare['preset'] = 'strangler';
        $existing = is_array($compare['ignore_cookies'] ?? null) ? $compare['ignore_cookies'] : [];
        $compare['ignore_cookies'] = array_values(array_unique([...$existing, ...$ignoreCookies]));
        $existing = is_array($compare['silent_sign_in'] ?? null) ? $compare['silent_sign_in'] : [];
        $compare['silent_sign_in'] = array_values(array_unique([...$existing, self::LARAVEL_LOGIN]));
        $withCompare['compare'] = $compare;
        $path = $root . '/' . ReplayFile::DEFAULT_NAME;
        try {
            ReplayFile::fromArray($withCompare, $path);
            $data = $withCompare;
        } catch (ToolError) {
            $this->notes[] = sprintf('This Booster\'s request file does not read the strangler compare keys yet, so the mount did not write them (preset strangler, ignore_cookies %s, silent_sign_in %s).', implode(', ', $ignoreCookies), self::LARAVEL_LOGIN);
        }
        ReplayFile::fromArray($data, $path);
        file_put_contents($path, self::json($data));
        $this->changed[] = ReplayFile::DEFAULT_NAME;
        if ($oldPath !== null && $from !== '.') {
            $this->notes[] = 'booster-replay.json is now at the root; legacy/booster-replay.json still serves legacy/ alone (booster run --path=legacy).';
        }
        $this->notes[] = 'booster-replay.json now serves public/ (the dispatcher). Check this mount against the recording made before it with: booster replay check --serve-changed.';

        return ReplayFile::DEFAULT_NAME;
    }

    /**
     * @param array<string, mixed> $serve
     */
    private static function serveLine(array $serve): string
    {
        $line = 'docroot ' . (is_string($serve['docroot'] ?? null) ? $serve['docroot'] : '.');
        if (is_string($serve['front_controller'] ?? null)) {
            $line .= ', front ' . $serve['front_controller'];
        }

        return $line;
    }

    /**
     * Adds the keys the legacy half reads from the environment to the root
     * .env.example: names from legacy's own example files, values only when
     * they are not secret-looking. The real .env is never read.
     */
    private function mapEnvExample(string $root, string $composerRoot): void
    {
        $sources = ['.env.example', 'config/.env.example', 'config/.env.default', '.env.dist', '.env.sample'];
        $keys = [];
        $from = [];
        foreach ($sources as $source) {
            $path = $root . '/' . self::LEGACY . '/' . $source;
            if (!is_file($path)) {
                continue;
            }
            $from[] = self::LEGACY . '/' . $source;
            foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $m) !== 1) {
                    continue;
                }
                $value = trim($m[2]);
                if (preg_match('/PASS|SECRET|SALT|TOKEN|KEY|PRIVATE|CREDENTIAL/i', $m[1]) === 1) {
                    $value = '';
                }
                $keys[$m[1]] ??= $value;
            }
        }
        if ($keys === []) {
            return;
        }
        $target = $root . '/.env.example';
        $existing = is_file($target) ? (string) file_get_contents($target) : '';
        $lines = [];
        foreach ($keys as $key => $value) {
            if (preg_match('/^\s*#?\s*' . preg_quote($key, '/') . '\s*=/m', $existing) === 1) {
                continue;
            }
            $lines[] = $key . '=' . $value;
        }
        if ($lines === []) {
            return;
        }
        $block = "\n# The legacy half (legacy/) reads these, from " . implode(', ', $from) . ".\n# Both halves read the same environment until the finish step. Secret-looking values are left empty.\n" . implode("\n", $lines) . "\n";
        file_put_contents($target, rtrim($existing) . "\n" . $block);
        $this->changed[] = '.env.example';
        $this->notes[] = sprintf('.env.example lists %d key(s) the legacy half reads.', count($lines));
    }

    private function parity(string $root): void
    {
        $path = $root . '/parity.json';
        if (!is_file($path)) {
            return;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            $this->manual[] = 'parity.json: set "public_dir": "public" (the file is not valid JSON, so the mount left it).';

            return;
        }
        $data['public_dir'] = 'public';
        file_put_contents($path, self::json($data));
        $this->changed[] = 'parity.json';
    }

    /**
     * The legacy root .htaccess lines (outside the docroot) other than the
     * standard front-controller block: they stop applying once the docroot is
     * public/ (and the replay never saw them either). The docroot's own file
     * moves into public/.htaccess (LegacyHtaccess).
     *
     * @return list<string>
     */
    private function htaccessLines(string $legacySource, string $composerRoot, string $legacyDocroot): array
    {
        $standard = [
            '/^<IfModule\s+mod_rewrite\.c>$/i', '/^<\/IfModule>$/i', '/^RewriteEngine\s+on$/i', '/^RewriteBase\s+\S+$/i',
            '/^RewriteCond\s+%\{REQUEST_FILENAME\}\s+!-[fdl]$/i', '/^RewriteRule\s+\S+\s+(\/?index\.php|\S*\/index\.php)(\s+\[[A-Z,]+\])?$/i',
            '/^RewriteRule\s+\^\$\s+\S+\/\s+\[L\]$/i', '/^RewriteRule\s+\(\.\*\)\s+\S+\/\$1\s+\[L\]$/i', '/^RewriteRule\s+\^\(\\\\\.well-known\/\.\*\)\$\s+\$1\s+\[L\]$/i',
            '/^Options(\s+(All|[+-]?FollowSymLinks|[+-]?SymLinksIfOwnerMatch|-MultiViews|-Indexes))+$/i', '/^<IfModule\s+mod_negotiation\.c>$/i',
            // The dotfile guard (the dispatcher never serves dotfiles) and the
            // Slim skeleton's BASE lines (the dispatcher derives the base itself).
            '/^<Files\s+"?~?\s*"?\\\\?\.ht\S*>$/i', '/^<\/Files>$/i', '/^order\s+allow,\s*deny$/i', '/^deny\s+from\s+all$/i', '/^Require\s+all\s+denied$/i',
            '/^RewriteCond\s+%\{REQUEST_URI\}::\$1\s+\^\(\/\.\+\)\/\(\.\*\)::\\\\2\$$/i', '/^RewriteRule\s+\^\(\.\*\)\s+-\s+\[E=BASE:%1\]$/i',
        ];
        $lines = [];
        foreach (['.htaccess'] as $file) {
            $path = $legacySource . '/' . $file;
            if (!is_file($path) || in_array($legacyDocroot, ['', '.'], true)) {
                continue;
            }
            foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $i => $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                foreach ($standard as $pattern) {
                    if (preg_match($pattern, $line) === 1) {
                        continue 2;
                    }
                }
                $lines[] = sprintf('%s:%d: "%s" no longer applies once the docroot is public/ (the file is legacy/%s now). Carry it to public/.htaccess or the Citadel config, or drop it.', self::join($composerRoot, $file), $i + 1, $line, $file);
            }
        }

        return $lines;
    }

    /** The moved .htaccess lines as a note, the ones left behind as MANUAL rows. */
    private function htaccessReport(LegacyHtaccess $moved, string $file, string $legacyFile): void
    {
        if ($moved->moved() !== []) {
            $this->notes[] = sprintf('public/.htaccess carries the rules of %s (lines %s), rewritten for the new layout.', $file, self::ranges($moved->moved()));
        }
        foreach ($moved->manual() as $row) {
            $this->htaccessManual[] = sprintf('%s:%d: "%s" could not move to public/.htaccess: %s. The file is legacy/%s now; carry it to public/.htaccess or the Citadel config by hand, or drop it.', $file, $row['line'], $row['text'], $row['why'], $legacyFile);
        }
    }

    /** @param list<int> $lines */
    private static function ranges(array $lines): string
    {
        $out = [];
        foreach ($lines as $line) {
            $last = count($out) - 1;
            if ($last >= 0 && $out[$last][1] === $line - 1) {
                $out[$last][1] = $line;
            } else {
                $out[] = [$line, $line];
            }
        }

        return implode(', ', array_map(static fn(array $r): string => $r[0] === $r[1] ? (string) $r[0] : $r[0] . '-' . $r[1], $out));
    }

    /**
     * Code moved out of legacy/ gets its input as legacy gave it: Laravel's
     * TrimStrings and ConvertEmptyStringsToNull skip the requests the
     * dispatcher sent to a moved unit (it sets BOOSTER_MOVED_UNIT), so
     * neither mover has to. Laravel's own pages keep both.
     */
    private function movedInputAsLegacy(string $root): void
    {
        $app = $root . '/bootstrap/app.php';
        $open = '->withMiddleware(function (Middleware $middleware) {';
        $use = "use Illuminate\\Foundation\\Configuration\\Middleware;\n";
        $text = is_file($app) ? (string) file_get_contents($app) : '';
        if (substr_count($text, $open) !== 1 || !str_contains($text, $use)) {
            $this->manual[] = 'bootstrap/app.php: skip TrimStrings and ConvertEmptyStringsToNull for moved legacy routes ($middleware->trimStrings(except: [fn ($r) => $r->server->has(\'BOOSTER_MOVED_UNIT\')]), and the same for convertEmptyStringsToNull); the mount could not find the stock withMiddleware line.';

            return;
        }
        if (!str_contains($text, "use Illuminate\\Http\\Request;\n")) {
            $text = str_replace($use, $use . "use Illuminate\\Http\\Request;\n", $text);
        }
        $text = str_replace($open, $open . "\n" . self::MOVED_INPUT, $text);
        file_put_contents($app, $text);
        $this->changed[] = 'bootstrap/app.php';
    }

    /** The legacy session cookie name, as far as the config says (PHPSESSID when it says nothing). */
    private static function legacySessionCookie(string $framework, string $legacySource): string
    {
        if ($framework === 'cakephp') {
            $app = (string) @file_get_contents($legacySource . '/config/app.php');
            if (preg_match("/'Session'\s*=>\s*\[(.*?)\n\s*\],/s", $app, $m) === 1) {
                if (preg_match("/'cookie'\s*=>\s*'([^']+)'/", $m[1], $c) === 1) {
                    return $c[1];
                }
                if (preg_match("/'session\.name'\s*=>\s*'([^']+)'/", $m[1], $c) === 1) {
                    return $c[1];
                }
                if (preg_match("/'defaults'\s*=>\s*'(cake|database|cache)'/", $m[1]) === 1) {
                    return 'CAKEPHP';
                }
            }

            return 'PHPSESSID';
        }
        foreach (['src', 'app', 'lib', 'config', 'public'] as $dir) {
            $found = self::grepSessionName($legacySource . '/' . $dir, 0);
            if ($found !== null) {
                return $found;
            }
        }

        return 'PHPSESSID';
    }

    private static function grepSessionName(string $dir, int $depth): ?string
    {
        if (!is_dir($dir) || $depth > 6 || basename($dir) === 'vendor') {
            return null;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $found = self::grepSessionName($path, $depth + 1);
                if ($found !== null) {
                    return $found;
                }
            } elseif (str_ends_with($entry, '.php') && preg_match("/session_name\(\s*'([^']+)'\s*\)/", (string) file_get_contents($path), $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    private static function appName(string $root): string
    {
        $name = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', basename($root)));
        $name = trim($name, '-');

        return $name === '' || !ctype_alpha($name[0]) ? 'app' : $name;
    }

    private static function join(string $base, string $path): string
    {
        return $base === '.' ? $path : $base . '/' . $path;
    }

    private function resource(string $name): string
    {
        $path = ($this->resourceDir ?? PharFiles::path('resources/strangler')) . '/' . $name;
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new ToolError(sprintf('Could not read the strangler resource %s.', $path));
        }

        return $contents;
    }

    /**
     * @param array<mixed> $data
     */
    private static function json(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            @unlink($dir);

            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::removeTree($dir . '/' . $entry);
        }
        @rmdir($dir);
    }
}
