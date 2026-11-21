<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

use Waynestate\Booster\Strangler\SignInSource;

/**
 * Strangler Step 0 for a Slim app, on SESAME's raw-PHP NativeSession (design
 * sections 3, 9.6 and 10.5), beside CasMigration.
 *
 * Two shapes are mapped:
 * - `sesame`: the app already builds a Sesame (`new Sesame(...)`) or boots the
 *   Wayne Auth bridge (`WayneAuth::boot(...)`); Step 0 only hands it the shared
 *   sign-out store, as a named `revocations:` argument.
 * - `validuser`: the WSUCookie family (testeval and its siblings). The method
 *   that read the validUser cookie now asks SESAME, keeping the app's own
 *   authz check and user session; the login page hands a guest to CAS instead
 *   of showing the form; the logout signs SESAME out too. The LDAP form's POST
 *   handler is left as it is (an LDAP-form app goes through Step 0 like any
 *   other: SESAME is now the way in).
 *
 * Every edit is planned first and written only when all of them are known.
 */
final class SlimSignInMigration
{
    private const HELPER = '\\App\\Sesame\\LegacySignIn';

    private const STORE = '\\Waynestate\\Sesame\\Revocation\\FileRevocationStore';

    public function __construct(
        private readonly string $resources,
    ) {}

    public function plan(string $root, string $shape): SignInPlan
    {
        $plan = new SignInPlan($root);

        if ($shape === 'sesame') {
            $this->addStore($plan);
        } else {
            $this->validUser($plan);
            $plan->requirePackage('composer.json', 'guzzlehttp/guzzle', '^7.9', '7.9');
        }

        $plan->requirePackage('composer.json', 'waynestate/sesame', '^0.10.1', '0.10.1');

        return $plan;
    }

    private function addStore(SignInPlan $plan): void
    {
        $calls = 0;
        foreach (self::phpFiles($plan->root) as $relative) {
            $code = $plan->read($relative);
            if ($code === null || ! preg_match('/new\s+\\\\?(?:Waynestate\\\\Sesame\\\\)?Sesame\s*\(|WayneAuth::boot\s*\(/', $code)) {
                continue;
            }

            $tokens = \PhpToken::tokenize($code);
            $edits = [];
            foreach ($tokens as $i => $token) {
                $isNew = $token->is(T_NEW) && self::nextName($tokens, $i) !== null && preg_match('/(^|\\\\)Sesame$/', (string) self::nextName($tokens, $i)) === 1;
                $isBoot = $token->is(T_STRING) && $token->text === 'boot' && $i >= 2 && $tokens[$i - 1]->is(T_DOUBLE_COLON) && preg_match('/(^|\\\\)WayneAuth$/', $tokens[$i - 2]->text) === 1;
                if (! $isNew && ! $isBoot) {
                    continue;
                }

                $open = null;
                for ($j = $i + 1; $j < count($tokens); $j++) {
                    if ($tokens[$j]->text === '(') {
                        $open = $j;
                        break;
                    }
                    if ($tokens[$j]->text === ';') {
                        break;
                    }
                }
                if ($open === null) {
                    continue;
                }

                [$close, $args, $named] = self::arguments($tokens, $open);
                if ($close === null) {
                    continue;
                }
                $calls++;
                if ($args >= 8 || in_array('revocations', $named, true)) {
                    continue;
                }

                $before = rtrim(substr($code, $tokens[$open]->pos + 1, $tokens[$close]->pos - $tokens[$open]->pos - 1));
                $comma = $before === '' || str_ends_with($before, ',') ? '' : ',';
                $levels = substr_count(trim(dirname($relative), '.'), '/') + (dirname($relative) === '.' ? 1 : 2);
                $store = sprintf(
                    'revocations: %1$s::fromEnvironment() ?? (is_file(dirname(__DIR__, %2$d) . \'/artisan\') ? new %1$s(dirname(__DIR__, %2$d) . \'/storage/sesame-revocations\') : null)',
                    self::STORE,
                    $levels,
                );
                $edits[] = [$tokens[$open]->pos + 1 + strlen($before), $comma . ' ' . $store];
                $plan->changes[] = sprintf('%s:%d: SESAME gets the shared sign-out store (a logout on either half ends both)', $relative, $token->line);
            }

            foreach (array_reverse($edits) as [$at, $text]) {
                $code = substr($code, 0, $at) . $text . substr($code, $at);
            }
            if ($edits !== []) {
                $plan->put($relative, $code, sprintf('%s: shared sign-out store', $relative));
            }
        }

        if ($calls === 0) {
            $plan->failures[] = 'The app reads SESAME, but Booster finds no `new Sesame(...)` or `WayneAuth::boot(...)` to hand the shared sign-out store to. MANUAL: pass `revocations: \Waynestate\Sesame\Revocation\FileRevocationStore::fromEnvironment()` where the app builds its Sesame.';
        }
    }

    private function validUser(SignInPlan $plan): void
    {
        $autoload = self::appDirectory($plan);
        if ($autoload === null) {
            $plan->failures[] = 'composer.json maps no App\\ namespace (autoload psr-4), so Booster has nowhere to put App\\Sesame\\LegacySignIn. MANUAL: move the sign-in onto SESAME by hand (SESAME docs, guides/raw-php.md).';

            return;
        }

        $helper = $autoload . '/Sesame/LegacySignIn.php';
        $template = (string) file_get_contents($this->resources . '/slim/Sesame/LegacySignIn.php');
        $levels = substr_count($autoload, '/') + 3;
        $template = str_replace('2 /* booster:levels */', (string) $levels, $template);
        $current = $plan->read($helper);
        if ($current !== null && ! str_contains($current, 'Booster\'s strangler Step 0')) {
            $plan->failures[] = sprintf('%s already exists and Booster did not write it; move it aside, then run Step 0 again.', $helper);

            return;
        }
        if ($current === null) {
            $plan->put($helper, $template, sprintf('%s: written (SESAME sign-in for the legacy half)', $helper));
        }

        $found = false;
        foreach (self::phpFiles($plan->root) as $relative) {
            if ($relative === $helper) {
                continue;
            }
            $code = $plan->read($relative);
            if ($code === null || ! preg_match('/[\'"]validUser[\'"]/', $code)) {
                continue;
            }

            foreach (array_reverse(SignInSource::methods($code)) as $method) {
                $body = SignInSource::body($code, $method);
                if (! preg_match('/[\'"]validUser[\'"]/', $body)) {
                    continue;
                }

                $line = SignInSource::lineAt($code, $method['start']);
                if (! str_contains($body, '$this->user->id($uid)') || ! str_contains($body, '$this->auth_redirect($response, $request)')) {
                    $plan->failures[] = sprintf('%s:%d: %s() reads the validUser cookie in a shape Booster does not map (the WSUCookie family sets $this->user->id($uid) and returns $this->auth_redirect($response, $request)). MANUAL: ask \App\Sesame\LegacySignIn::username($request) instead.', $relative, $line, $method['name']);

                    continue;
                }

                $lines = [
                    '// Booster strangler Step 0: SESAME replaced the validUser cookie check.',
                    sprintf('$uid = %s::username($request);', self::HELPER),
                ];
                $lines[] = str_contains($body, '$this->authz->check($uid)')
                    ? 'if ($uid === null || !$this->authz->check($uid)) {'
                    : 'if ($uid === null) {';
                $lines[] = '    return false;';
                $lines[] = '}';
                $lines[] = '';
                $lines[] = '$this->user->id($uid);';
                if (str_contains($body, '$this->unset_logout_cookie($response)')) {
                    $lines[] = '$response = $this->unset_logout_cookie($response);';
                }
                $lines[] = 'return $this->auth_redirect($response, $request);';

                $code = SignInSource::replaceBody($code, $method, $lines);
                $plan->put($relative, $code, sprintf('%s:%d: %s() asks SESAME who signed in, then runs the app\'s own authz check', $relative, $line, $method['name']));
                $found = true;
            }

            if (! $found) {
                continue;
            }

            $code = $this->loginPage($plan, $relative, $code);
            $this->logoutAction($plan, $relative, $code);
        }

        if (! $found) {
            $plan->failures[] = 'No method reads the validUser cookie the way Booster maps. MANUAL: move the sign-in onto SESAME by hand (SESAME docs, guides/raw-php.md).';

            return;
        }

        $plan->manual[] = 'The LDAP login form no longer shows: guests go to CAS through SESAME. Its POST handler still works; delete it, the form template and the LDAP settings once SESAME sign-in is confirmed.';
        $plan->manual[] = 'The middleware that calls CheckWSUCookie() ignores its answer, so after a sign-in the browser lands on the base path instead of the page it asked for. Return that answer when it is a response to keep the page.';
        $plan->manual[] = 'SESAME stores into the PHP session the app already starts; its own session-settings warning is off in App\\Sesame\\LegacySignIn. Give the app\'s session cookie HttpOnly, Secure and SameSite=Lax and session.use_strict_mode, then turn the check back on (checkSessionSettings: null).';
        $plan->manual[] = 'Set SESAME_CAS_BASE_URL in the environment unless Playbook supplies it. On Citadel it goes in the app\'s environment settings.';
    }

    /** The login page: a guest goes to CAS through SESAME instead of seeing the form. */
    private function loginPage(SignInPlan $plan, string $relative, string $code): string
    {
        $pattern = '/return\s+\$this->render\(\s*\$response\s*,\s*[\'"][^\'"]*login[^\'"]*[\'"]\s*\)\s*;/i';
        foreach (array_reverse(SignInSource::methods($code)) as $method) {
            $body = SignInSource::body($code, $method);
            if (strcasecmp($method['name'], 'login') !== 0 || preg_match($pattern, $body, $m, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            $at = $method['open'] + 1 + $m[0][1];
            $replacement = '// Booster strangler Step 0: a guest goes to CAS through SESAME and comes back to this page.' . "\n"
                . SignInSource::indentAt($code, $at)
                . sprintf('return $response->withStatus(302)->withHeader(\'Location\', %s::handOff($request, (string) $request->getUri()));', self::HELPER);
            $code = substr($code, 0, $at) . $replacement . substr($code, $at + strlen($m[0][0]));
            $plan->put($relative, $code, sprintf('%s:%d: the login page hands a guest to CAS through SESAME instead of showing the form', $relative, SignInSource::lineAt($code, $at)));
            $plan->replayChanges[] = '*';

            return $code;
        }

        $plan->manual[] = sprintf('%s: no login page rendering a login template was found. Send guests to \App\Sesame\LegacySignIn::handOff($request, $url) so they can sign in.', $relative);

        return $code;
    }

    private function logoutAction(SignInPlan $plan, string $relative, string $code): void
    {
        if (str_contains($code, 'LegacySignIn::signOut(')) {
            return;
        }

        $at = strpos($code, '$this->user->logout()');
        if ($at === false) {
            $plan->manual[] = sprintf('%s: no logout calling $this->user->logout() was found. Call \App\Sesame\LegacySignIn::signOut() in yours, or a logout on this half will not end the Laravel half\'s session.', $relative);

            return;
        }

        $start = SignInSource::statementStart($code, $at);
        $code = substr($code, 0, $start) . self::HELPER . '::signOut();' . "\n" . SignInSource::indentAt($code, $start) . substr($code, $start);
        $plan->put($relative, $code, sprintf('%s:%d: the logout also signs SESAME out, which ends the Laravel half\'s session too', $relative, SignInSource::lineAt($code, $start)));
    }

    /** The app-relative folder composer.json maps App\ to, without a trailing slash. */
    private static function appDirectory(SignInPlan $plan): ?string
    {
        $data = json_decode((string) $plan->read('composer.json'), true);
        $path = is_array($data) ? ($data['autoload']['psr-4']['App\\'] ?? null) : null;
        if (is_array($path)) {
            $path = $path[0] ?? null;
        }
        if (! is_string($path)) {
            return null;
        }

        $path = trim(str_replace('\\', '/', $path), '/');
        $path = (string) preg_replace('#^\./#', '', $path);

        return $path === '' || $path === '.' ? null : $path;
    }

    /**
     * @param array<int, \PhpToken> $tokens
     */
    private static function nextName(array $tokens, int $i): ?string
    {
        for ($j = $i + 1; $j < count($tokens); $j++) {
            if ($tokens[$j]->isIgnorable()) {
                continue;
            }

            return $tokens[$j]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) ? $tokens[$j]->text : null;
        }

        return null;
    }

    /**
     * The closing parenthesis of a call, its top-level argument count and the
     * names of its named arguments.
     *
     * @param array<int, \PhpToken> $tokens
     *
     * @return array{0: int|null, 1: int, 2: list<string>}
     */
    private static function arguments(array $tokens, int $open): array
    {
        $depth = 0;
        $args = 0;
        $named = [];
        $seen = false;
        for ($j = $open; $j < count($tokens); $j++) {
            $text = $tokens[$j]->text;
            if (in_array($text, ['(', '[', '{'], true)) {
                $depth++;
                if ($j === $open) {
                    continue;
                }
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;
                if ($depth === 0) {
                    return [$j, $args + ($seen ? 1 : 0), $named];
                }
            } elseif ($depth === 1 && $text === ',') {
                $args += $seen ? 1 : 0;
                $seen = false;

                continue;
            }

            if ($depth === 1 && ! $tokens[$j]->isIgnorable()) {
                if (! $seen && $tokens[$j]->is(T_STRING) && isset($tokens[$j + 1]) && $tokens[$j + 1]->text === ':') {
                    $named[] = $text;
                }
                $seen = true;
            }
        }

        return [null, $args, $named];
    }

    /**
     * @return list<string> app-relative PHP files outside vendor, tests and caches, sorted
     */
    private static function phpFiles(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            static fn(\SplFileInfo $file): bool => ! ($file->isDir() && in_array($file->getFilename(), ['vendor', 'node_modules', 'tests', 'var', 'tmp', 'logs', 'cache', '.git', 'legacy'], true)),
        ));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            }
        }
        sort($files);

        return $files;
    }
}
