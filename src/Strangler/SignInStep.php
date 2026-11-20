<?php

declare(strict_types=1);

namespace Waynestate\Booster\Strangler;

use Waynestate\Booster\Run\CakeSignInMigration;
use Waynestate\Booster\Run\SignInPlan;
use Waynestate\Booster\Run\SlimSignInMigration;

/**
 * Strangler Step 0 and the Laravel half's sign-in (design sections 3, 9.1,
 * 9.6, 10.5 and 10.6; ADR-0024).
 *
 * migrate() reads the layout and is safe to run again:
 * - before the mount (no legacy/), it moves the old app's sign-in onto SESAME
 *   (Step 0): CakePHP through SESAME's CakePHP adapter, Slim through its raw
 *   NativeSession, with the shared sign-out store;
 * - after the mount (legacy/ and artisan), it signs the Laravel half in
 *   through SESAME's Laravel guard under /_laravel/, sharing that store.
 * finish() puts SESAME's routes back at the root once legacy/ is gone.
 *
 * Signed in on both halves, signed out of both with one click: the strangler's
 * answer to "who are you?" is the same on either side of the fence.
 */
final class SignInStep
{
    /** Where Step 0's fake settings for the replay live. */
    public const REPLAY_FILE = 'booster-replay.json';

    /** The fake username the replay signs in as. */
    public const REPLAY_USER = 'replay.user';

    /** The CAS server the replay's legacy half hands guests to: never reached, so the Location is stable. */
    public const REPLAY_CAS = 'https://cas.replay.invalid/cas';

    public static function inspect(string $appRoot, string $framework): SignInReport
    {
        $framework = self::framework($framework);
        $root = rtrim(str_replace('\\', '/', $appRoot), '/');
        $mounted = is_dir($root . '/legacy') && is_file($root . '/artisan');
        $legacy = $mounted ? $root . '/legacy' : $root;

        $stops = [];
        $findings = [];
        $manual = [];

        $sources = self::sources($legacy);

        // LTI: no strangler yet.
        $lti = self::lti($legacy, $sources);
        if ($lti !== null) {
            $stops[] = 'No strangler yet for LTI apps, see the guide (Booster docs, guides/cakephp-and-slim-to-laravel.md): ' . $lti . '.';
        }

        // The legacy session must never share Laravel's cookie name.
        $sessionName = self::sessionName($framework, $sources);
        if ($sessionName !== null && strcasecmp($sessionName['name'], 'laravel_session') === 0) {
            $stops[] = sprintf('%s:%d names the legacy session "laravel_session", the Laravel half\'s default cookie. Rename it (any other name), then run the strangler again.', $sessionName['file'], $sessionName['line']);
        }

        [$shape, $shapeFindings, $store] = self::shape($framework, $sources);
        array_push($findings, ...$shapeFindings);

        if ($shape === 'custom') {
            $manual[] = 'MANUAL: Booster cannot map this app\'s sign-in onto SESAME (no validUser cookie check, form login or SESAME call it knows). Move it by hand (SESAME docs: guides/cakephp.md or guides/raw-php.md), then run the strangler again; Step 0 stays pending until then.';
        }

        $step0Done = $shape === 'none' || ($shape === 'sesame' && $store);

        return new SignInReport(
            $framework,
            $mounted,
            $shape,
            $step0Done,
            $mounted && SignInLaravelHalf::wired($root),
            $sessionName['name'] ?? null,
            $stops,
            $findings,
            $manual,
        );
    }

    public static function migrate(string $appRoot, string $framework): SignInResult
    {
        $report = self::inspect($appRoot, $framework);
        $root = rtrim(str_replace('\\', '/', $appRoot), '/');

        if ($report->blocked()) {
            return new SignInResult(false, false, [], [], $report->manual, $report->stops);
        }

        if ($report->shape === 'custom') {
            return new SignInResult(false, false, [], [], $report->manual, []);
        }

        $changes = [];
        $manual = $report->manual;
        $changed = [];
        $replayChanges = [];
        $replayEnv = [];

        // Step 0, on the legacy half (before the mount, or still to do after it).
        if (! $report->step0Done) {
            if ($report->mounted) {
                return new SignInResult(false, false, [], [], $manual, ['Step 0 is not done: the legacy half under legacy/ does not sign in through SESAME yet. Run Step 0 before the mount.']);
            }

            $resources = dirname(__DIR__, 2) . '/resources/strangler-signin';
            // An app already on SESAME only needs the shared store, whatever its framework.
            $plan = $report->framework === 'cakephp' && $report->shape !== 'sesame'
                ? (new CakeSignInMigration($resources))->plan($root, $report->shape)
                : (new SlimSignInMigration($resources))->plan($root, $report->shape);

            $replayEnv = self::legacyReplayEnv($report->shape);
            self::replayEnv($plan, $replayEnv);

            $written = $plan->write();
            if ($plan->failures !== []) {
                return new SignInResult(false, false, [], $plan->changes, [...$manual, ...$plan->manual], $plan->failures, $plan->replayChanges);
            }

            array_push($changes, ...$plan->changes);
            array_push($manual, ...$plan->manual);
            array_push($changed, ...$written);
            $replayChanges = array_values(array_unique($plan->replayChanges));
        } elseif ($report->shape === 'none') {
            $changes[] = 'Step 0: the app signs nobody in, so there is nothing to move.';
        }

        // The Laravel half, once it exists.
        $wired = $report->laravelWired;
        if ($report->mounted && ! $wired) {
            $plan = new SignInPlan($root);
            SignInLaravelHalf::wire($plan);
            $replayEnv = [...$replayEnv, ...self::laravelReplayEnv($report->shape)];
            self::replayEnv($plan, self::laravelReplayEnv($report->shape));
            $written = $plan->write();
            if ($plan->failures !== []) {
                return new SignInResult($report->step0Done, false, $changed, $changes, [...$manual, ...$plan->manual], $plan->failures, $replayChanges);
            }

            array_push($changes, ...$plan->changes);
            array_push($manual, ...$plan->manual);
            array_push($changed, ...$written);
            $wired = true;
        }

        return new SignInResult(true, $wired, array_values(array_unique($changed)), $changes, array_values(array_unique($manual)), [], $replayChanges, $replayEnv);
    }

    /** At the finish (legacy/ gone or going): SESAME's routes back at /login and POST /logout, no shared store. */
    public static function finish(string $appRoot, string $framework): SignInResult
    {
        self::framework($framework);
        $root = rtrim(str_replace('\\', '/', $appRoot), '/');
        if (! is_file($root . '/artisan')) {
            return new SignInResult(false, false, [], [], [], ['There is no Laravel half at the root (no artisan), so there is nothing to finish.']);
        }

        $plan = new SignInPlan($root);
        SignInLaravelHalf::finish($plan);
        self::dropReplayEnv($plan);
        $written = $plan->write();
        if ($plan->failures !== []) {
            return new SignInResult(true, true, [], $plan->changes, $plan->manual, $plan->failures);
        }

        return new SignInResult(true, false, $written, $plan->changes, $plan->manual, []);
    }

    private static function framework(string $framework): string
    {
        return match (strtolower($framework)) {
            'cakephp', 'cake' => 'cakephp',
            'slim' => 'slim',
            default => throw new \InvalidArgumentException(sprintf('The strangler signs in CakePHP and Slim apps, not "%s".', $framework)),
        };
    }

    /**
     * The replay's fake settings for the legacy half after Step 0. An app whose
     * sign-in reads more than SESAME (the validUser apps read their profile
     * from Oracle) hands guests to a CAS that is never reached, so the
     * hand-off's Location is stable; the others sign in on the spot with the
     * fake driver, so signed-in sequences work.
     *
     * @return array<string, string>
     */
    private static function legacyReplayEnv(string $shape): array
    {
        return $shape === 'validuser'
            ? ['SESAME_DRIVER' => 'cas', 'SESAME_CAS_BASE_URL' => self::REPLAY_CAS]
            : ['SESAME_DRIVER' => 'fake', 'SESAME_FAKE_USERNAME' => self::REPLAY_USER, 'APP_ENV' => 'local'];
    }

    /**
     * After the mount: the Laravel half signs in on the spot (fake), which
     * the replay's silent sign-in follows; the legacy half keeps its driver.
     *
     * @return array<string, string>
     */
    private static function laravelReplayEnv(string $shape): array
    {
        $env = ['SESAME_DRIVER' => 'fake', 'SESAME_FAKE_USERNAME' => self::REPLAY_USER];
        if ($shape === 'validuser') {
            $env['SESAME_LEGACY_DRIVER'] = 'cas';
            $env['SESAME_CAS_BASE_URL'] = self::REPLAY_CAS;
        }

        return $env;
    }

    /**
     * Sets fake settings in the replay file's serve.env, keeping every other
     * value and the file's objects as they are.
     *
     * @param array<string, string> $values
     */
    private static function replayEnv(SignInPlan $plan, array $values): void
    {
        $content = $plan->read(self::REPLAY_FILE);
        if ($content === null) {
            return;
        }

        $data = json_decode($content);
        if (! $data instanceof \stdClass || ! isset($data->serve) || ! $data->serve instanceof \stdClass) {
            $plan->manual[] = sprintf('%s has no serve object, so Booster could not add the replay\'s SESAME settings: %s.', self::REPLAY_FILE, self::envLine($values));

            return;
        }

        $env = isset($data->serve->env) && $data->serve->env instanceof \stdClass ? $data->serve->env : new \stdClass();
        $set = [];
        foreach ($values as $key => $value) {
            if (($env->{$key} ?? null) !== $value) {
                $env->{$key} = $value;
                $set[$key] = $value;
            }
        }
        if ($set === []) {
            return;
        }

        $data->serve->env = $env;
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $plan->put(self::REPLAY_FILE, $json . "\n", sprintf('%s: serve.env gets the replay\'s SESAME settings (%s); the gate after this step runs with the serve change named', self::REPLAY_FILE, self::envLine($set)));
    }

    private static function dropReplayEnv(SignInPlan $plan): void
    {
        $content = $plan->read(self::REPLAY_FILE);
        $data = $content === null ? null : json_decode($content);
        if (! $data instanceof \stdClass || ! isset($data->serve->env) || ! $data->serve->env instanceof \stdClass) {
            return;
        }

        $env = $data->serve->env;
        if (! isset($env->SESAME_LEGACY_DRIVER)) {
            return;
        }

        unset($env->SESAME_LEGACY_DRIVER);
        if (($env->SESAME_CAS_BASE_URL ?? null) === self::REPLAY_CAS) {
            unset($env->SESAME_CAS_BASE_URL);
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $plan->put(self::REPLAY_FILE, $json . "\n", sprintf('%s: serve.env drops the legacy half\'s SESAME settings', self::REPLAY_FILE));
    }

    /**
     * @param array<string, string> $values
     */
    private static function envLine(array $values): string
    {
        $parts = [];
        foreach ($values as $key => $value) {
            $parts[] = $key . '=' . $value;
        }

        return implode(', ', $parts);
    }

    /**
     * The legacy app's PHP sources, app-relative path => content, outside
     * vendor, tests and caches.
     *
     * @return array<string, string>
     */
    private static function sources(string $root): array
    {
        $sources = [];
        if (! is_dir($root)) {
            return $sources;
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            static fn(\SplFileInfo $file): bool => ! ($file->isDir() && in_array($file->getFilename(), ['vendor', 'node_modules', 'tests', 'var', 'tmp', 'logs', 'cache', '.git', 'legacy', 'webroot'], true)),
        ));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $content = file_get_contents($file->getPathname());
                if ($content !== false) {
                    $sources[str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1))] = $content;
                }
            }
        }
        ksort($sources);

        return $sources;
    }

    /**
     * Why this is an LTI app, or null.
     *
     * @param array<string, string> $sources
     */
    private static function lti(string $root, array $sources): ?string
    {
        $composer = @file_get_contents($root . '/composer.json');
        $data = is_string($composer) ? json_decode($composer, true) : null;
        if (is_array($data) && is_array($data['require'] ?? null)) {
            foreach (array_keys($data['require']) as $package) {
                if (preg_match('/(^|[\/-])lti|lti([\/-]|$)|lti1p3/i', (string) $package) === 1) {
                    return sprintf('composer.json requires %s', $package);
                }
            }
        }

        foreach ($sources as $file => $content) {
            if (preg_match('/lti_message_hint|lti_message_type|LtiMessageLaunch|IMSGlobal\\\\LTI|Packback\\\\Lti1p3|ceLTIc\\\\/', $content, $m, PREG_OFFSET_CAPTURE) === 1) {
                return sprintf('%s:%d reads an LTI launch (%s)', $file, SignInSource::lineAt($content, $m[0][1]), $m[0][0]);
            }
        }

        return null;
    }

    /**
     * The legacy session cookie's name when the app sets one.
     *
     * @param array<string, string> $sources
     *
     * @return array{name: string, file: string, line: int}|null
     */
    private static function sessionName(string $framework, array $sources): ?array
    {
        $patterns = $framework === 'cakephp'
            ? ['/[\'"]Session[\'"]\s*=>\s*\[[^\]]*?[\'"]cookie[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/s', '/[\'"]session\.name[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/']
            : ['/session_name\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', '/protected\s+\$name\s*=\s*[\'"]([^\'"]+)[\'"]/', '/[\'"]session\.name[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/'];

        foreach ($sources as $file => $content) {
            foreach ($patterns as $pattern) {
                if (str_contains($pattern, 'protected') && ! str_contains(strtolower($file), 'session')) {
                    continue;
                }
                if (preg_match($pattern, $content, $m, PREG_OFFSET_CAPTURE) === 1) {
                    return ['name' => $m[1][0], 'file' => $file, 'line' => SignInSource::lineAt($content, $m[0][1])];
                }
            }
        }

        return null;
    }

    /**
     * How the app signs people in: the shape, where it was read from, and
     * whether SESAME already has the shared store.
     *
     * @param array<string, string> $sources
     *
     * @return array{0: string, 1: list<array{file: string, line: int, what: string}>, 2: bool}
     */
    private static function shape(string $framework, array $sources): array
    {
        $findings = [];
        $sesame = false;
        $store = false;
        $validUser = false;
        $form = false;
        $other = false;
        $generated = false;

        foreach ($sources as $file => $content) {
            if (str_contains($file, 'Sesame/') && str_contains($content, 'Booster\'s strangler Step 0')) {
                if (str_ends_with($file, 'Sesame/LegacySignIn.php')) {
                    $generated = true;
                    $findings[] = ['file' => $file, 'line' => 1, 'what' => 'sesame: Step 0 already moved the sign-in onto SESAME'];
                }

                continue;
            }

            $checks = [
                'sesame' => '/new\s+\\\\?(?:Waynestate\\\\Sesame\\\\)?Sesame\s*\(|WayneAuth::boot\s*\(|SesameAuthenticator|sesame_login\s*\(/',
                'validuser' => '/[\'"]validUser[\'"]/',
                'form' => '/[\'"]Authentication\.Form[\'"]/',
                'other' => '/ldap_bind\s*\(|password_verify\s*\(|AuthenticationMiddleware|\$_SESSION\s*\[\s*[\'"](?:user|uid|auth|username|logged_in)/i',
            ];
            foreach ($checks as $kind => $pattern) {
                if (preg_match($pattern, $content, $m, PREG_OFFSET_CAPTURE) !== 1) {
                    continue;
                }
                if ($kind === 'sesame' && ! str_contains($content, 'Sesame') && ! str_contains($content, 'sesame_')) {
                    continue;
                }
                $findings[] = ['file' => $file, 'line' => SignInSource::lineAt($content, $m[0][1]), 'what' => $kind . ': ' . trim($m[0][0])];
                match ($kind) {
                    'sesame' => $sesame = true,
                    'validuser' => $validUser = true,
                    'form' => $form = true,
                    default => $other = true,
                };
                if ($kind === 'sesame' && preg_match('/revocations\s*:|RevocationStore/', $content) === 1) {
                    $store = true;
                }
            }
        }

        if ($generated) {
            return ['sesame', $findings, true];
        }

        $shape = match (true) {
            $validUser => 'validuser',
            $sesame => 'sesame',
            $form && $framework === 'cakephp' => 'form',
            $other || $form => 'custom',
            default => 'none',
        };

        if ($shape === 'none') {
            $findings = [];
        }

        return [$shape, $findings, $store];
    }
}
