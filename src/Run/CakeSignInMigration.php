<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

use Waynestate\Booster\Strangler\SignInSource;

/**
 * Strangler Step 0 for a CakePHP 5 app: its sign-in moves onto SESAME before
 * the mount (design sections 3, 9.6 and 10.5), beside CasMigration.
 *
 * Two shapes are mapped:
 * - `validuser`: the old validUser cookie check, in an AuthController
 *   (attendance's shape) or in the six fleet apps' shared SsoController plus
 *   SsoAuthenticator. The method that read the cookie and posted it to the
 *   old validator now asks SESAME, keeping its return shape; the hand-off that
 *   sent guests to the old SSO page now sends them to CAS through SESAME,
 *   back to the same destination; TicketMiddleware finishes the sign-in on
 *   whatever page the ticket lands on.
 * - `form`: CakePHP's own form login. `Authentication.Form` becomes SESAME's
 *   SesameAuthenticator, and the login action hands a guest to SESAME.
 *
 * Every edit is planned first and written only when all of them are known
 * (SignInPlan); anything it cannot place exactly is a failure or a MANUAL line.
 */
final class CakeSignInMigration
{
    private const HELPER = '\\App\\Sesame\\LegacySignIn';

    public function __construct(
        private readonly string $resources,
    ) {}

    public function plan(string $root, string $shape): SignInPlan
    {
        $plan = new SignInPlan($root);

        $this->helpers($plan);
        $this->middleware($plan);

        if ($shape === 'validuser') {
            $this->validUser($plan);
        } else {
            $this->form($plan);
        }

        $this->logout($plan);

        $plan->requirePackage('composer.json', 'waynestate/sesame', '^0.10', '0.10');
        $plan->requirePackage('composer.json', 'guzzlehttp/guzzle', '^7.9', '7.9');

        $plan->manual[] = 'Set SESAME_CAS_BASE_URL in the environment unless Playbook supplies it (config/sesame.php reads the environment first, then Playbook). On Citadel it goes in the app\'s environment settings.';
        $plan->manual[] = 'Everyone signed in before this deploy signs in once more: the old sessions carry no SESAME sign-in, so TicketMiddleware drops them.';

        return $plan;
    }

    private function helpers(SignInPlan $plan): void
    {
        foreach (['src/Sesame/LegacySignIn.php', 'src/Sesame/TicketMiddleware.php', 'src/Sesame/UserResolver.php', 'config/sesame.php'] as $relative) {
            $template = (string) file_get_contents($this->resources . '/cake/' . $relative);
            $current = $plan->read($relative);
            if ($current !== null && $current !== $template && ! str_contains($current, 'Booster\'s strangler Step 0')) {
                $plan->failures[] = sprintf('%s already exists and Booster did not write it; move it aside, then run Step 0 again.', $relative);

                continue;
            }
            if ($current === null) {
                $plan->put($relative, $template, sprintf('%s: written (SESAME sign-in for the legacy half)', $relative));
            }
        }
    }

    private function middleware(SignInPlan $plan): void
    {
        $relative = 'src/Application.php';
        $code = $plan->read($relative);
        if ($code === null) {
            $plan->failures[] = 'src/Application.php is missing, so TicketMiddleware has nowhere to go.';

            return;
        }
        if (str_contains($code, 'TicketMiddleware')) {
            return;
        }

        $pattern = '/(\R[ \t]*)?(->add\(\s*new\s+(?:\\\\?Authentication\\\\Middleware\\\\)?AuthenticationMiddleware\()/';
        if (preg_match($pattern, $code, $m, PREG_OFFSET_CAPTURE) !== 1) {
            $plan->failures[] = 'src/Application.php adds no AuthenticationMiddleware, so Booster cannot place TicketMiddleware before it. Add `->add(new \App\Sesame\TicketMiddleware())` right before the app\'s own sign-in middleware, then run Step 0 again.';

            return;
        }

        $at = $m[2][1];
        $updated = substr($code, 0, $at) . '->add(new \\App\\Sesame\\TicketMiddleware())' . ($m[1][1] >= 0 ? $m[1][0] : '') . substr($code, $at);
        $plan->put($relative, $updated, sprintf('%s:%d: TicketMiddleware added before AuthenticationMiddleware (it finishes the CAS sign-in)', $relative, SignInSource::lineAt($code, $at)));
    }

    private function validUser(SignInPlan $plan): void
    {
        $readers = 0;
        $handOffs = 0;

        foreach ($this->phpFiles($plan->root, 'src') as $relative) {
            $code = $plan->read($relative);
            if ($code === null) {
                continue;
            }

            // 1. The method that read the cookie and asked the old validator.
            foreach (array_reverse(SignInSource::methods($code)) as $method) {
                $body = SignInSource::body($code, $method);
                if (! preg_match('/[\'"]validUser[\'"]/', $body) || stripos($body, 'accessid') === false) {
                    continue;
                }

                $request = $this->requestExpression($method);
                if ($request === null) {
                    $plan->failures[] = sprintf('%s:%d: %s() reads the validUser cookie, but Booster finds no request there to ask SESAME with. MANUAL: return SESAME\'s username from \App\Sesame\LegacySignIn::username($request) instead.', $relative, SignInSource::lineAt($code, $method['start']), $method['name']);

                    continue;
                }

                $line = SignInSource::lineAt($code, $method['start']);
                $code = SignInSource::replaceBody($code, $method, [
                    '// Booster strangler Step 0: SESAME replaced the validUser cookie and the old validator.',
                    sprintf('$username = %s::username(%s);', self::HELPER, $request),
                    'if ($username === null) {',
                    '    return false;',
                    '}',
                    '',
                    'return [',
                    '    "accessid" => $username,',
                    sprintf('    "timeout" => %s::secondsLeft(%s),', self::HELPER, $request),
                    '    "verified" => true,',
                    '];',
                ]);
                $plan->put($relative, $code, sprintf('%s:%d: %s() asks SESAME who signed in instead of posting the validUser cookie to the old validator', $relative, $line, $method['name']));
                $readers++;
            }

            // 2. The hand-off that sent a guest to the old SSO page.
            foreach (array_reverse(SignInSource::stringsContaining($code, 'need_validuser')) as $string) {
                $methods = SignInSource::methods($code);
                $method = SignInSource::methodAt($methods, $string['start']);
                $line = SignInSource::lineAt($code, $string['start']);
                $request = $method !== null ? $this->requestExpression($method) : null;
                $destination = preg_match('/destination_url=\{?(\$[A-Za-z_]\w*(?:->[A-Za-z_]\w*)*)\}?/', $string['text'], $d) === 1 ? $d[1] : null;

                if ($request === null || $destination === null) {
                    $plan->failures[] = sprintf('%s:%d: the old SSO hand-off is built here, but Booster cannot read its destination or request. MANUAL: send the guest to \App\Sesame\LegacySignIn::handOff($request, $destination).', $relative, $line);

                    continue;
                }

                $code = substr($code, 0, $string['start'])
                    . sprintf('%s::handOff(%s, %s)', self::HELPER, $request, $destination)
                    . substr($code, $string['end']);
                $plan->put($relative, $code, sprintf('%s:%d: the guest hand-off goes to CAS through SESAME, back to the same destination', $relative, $line));
                $handOffs++;

                // A request was found, so the hand-off sits in a method.
                $plan->replayChanges[] = $this->handOffPath($method);
            }
        }

        if ($readers === 0) {
            $plan->failures[] = 'No method reads the validUser cookie the way Booster maps (a body naming validUser and returning accessid). MANUAL: move the sign-in onto SESAME by hand (SESAME docs, guides/cakephp.md).';
        }
        if ($handOffs === 0) {
            $plan->failures[] = 'No guest hand-off to the old SSO page (a URL with need_validuser) was found. MANUAL: send guests to \App\Sesame\LegacySignIn::handOff($request, $destination).';
        }

        $plan->manual[] = 'The logout action still sends the browser to the old SSO logout page afterwards; SESAME has already ended both halves\' sessions by then. Point it at the CAS logout when you are ready.';
        $plan->manual[] = 'The old validUser validator is gone from the sign-in path; nothing else in the app should read the validUser cookie any more.';
    }

    private function form(SignInPlan $plan): void
    {
        $relative = 'src/Application.php';
        $code = $plan->read($relative);
        $strings = $code === null ? [] : SignInSource::stringsContaining($code, 'Authentication.Form');
        if ($code === null || $strings === []) {
            $plan->failures[] = 'src/Application.php loads no Authentication.Form authenticator. MANUAL: load \Waynestate\Sesame\Cake\SesameAuthenticator by hand (SESAME docs, guides/cakephp.md).';

            return;
        }

        $at = $strings[0]['start'];
        $start = SignInSource::statementStart($code, $at);
        $end = strpos($code, ';', $at);
        $statement = $end === false ? '' : substr($code, $start, $end + 1 - $start);
        if (preg_match('/^(\$\w+)\s*->\s*loadAuthenticator\s*\(/', $statement, $m) !== 1 || ! SignInSource::parses("<?php\n" . $statement)) {
            $plan->failures[] = sprintf('%s:%d: Booster cannot read the Authentication.Form statement. MANUAL: replace it with \Waynestate\Sesame\Cake\SesameAuthenticator (SESAME docs, guides/cakephp.md).', $relative, SignInSource::lineAt($code, $at));

            return;
        }

        $indent = SignInSource::indentAt($code, $start);
        $replacement = $m[1] . "->loadAuthenticator(\\Waynestate\\Sesame\\Cake\\SesameAuthenticator::class, [\n"
            . $indent . "    // Booster strangler Step 0: sign-in goes through SESAME; unauthenticatedRedirect still sends guests to the login action.\n"
            . $indent . "    'sesame' => fn (\\Psr\\Http\\Message\\ServerRequestInterface \$request): \\Waynestate\\Sesame\\Sesame => " . self::HELPER . "::sesame(\$request),\n"
            . $indent . ']);';
        $updated = substr($code, 0, $start) . $replacement . substr($code, (int) $end + 1);
        $plan->put($relative, $updated, sprintf('%s:%d: Authentication.Form replaced by SESAME\'s SesameAuthenticator', $relative, SignInSource::lineAt($code, $start)));

        // The login action: a guest goes to SESAME instead of seeing the form.
        $found = false;
        foreach ($this->phpFiles($plan->root, 'src/Controller') as $controller) {
            $source = $plan->read($controller);
            if ($source === null) {
                continue;
            }
            foreach (SignInSource::methods($source) as $method) {
                $body = SignInSource::body($source, $method);
                if ($method['name'] !== 'login' || ! str_contains($body, 'Authentication->getResult()') || str_contains($body, 'LegacySignIn::handOff')) {
                    continue;
                }

                $indent = SignInSource::indentAt($source, $method['start']);
                $inner = $indent . (str_contains($indent, "\t") ? "\t" : '    ');
                $insert = "\n\n" . $inner . "// Booster strangler Step 0: a guest goes to SESAME (CAS) and comes back here signed in.\n"
                    . $inner . 'return $this->redirect(' . self::HELPER . '::handOff($this->request, ' . self::HELPER . '::currentUrl($this->request)));';
                $cut = strrpos(substr($source, 0, $method['close']), "\n");
                if ($cut === false || $cut < $method['open']) {
                    $plan->failures[] = sprintf('%s:%d: login() is on one line; Booster cannot add the SESAME hand-off to it.', $controller, SignInSource::lineAt($source, $method['start']));
                    $found = true;
                    break;
                }
                $source = substr($source, 0, $cut) . $insert . substr($source, $cut);
                $plan->put($controller, $source, sprintf('%s:%d: login() hands a guest to SESAME instead of showing the form', $controller, SignInSource::lineAt($source, $method['start'])));
                $plan->replayChanges[] = $this->handOffPath($method);
                $found = true;
                break;
            }
        }

        if (! $found) {
            $plan->failures[] = 'No login action reading Authentication->getResult() was found. MANUAL: hand guests to \App\Sesame\LegacySignIn::handOff() from the login action.';
        }

        $plan->manual[] = 'The login form template and the password identifier are no longer used: delete them once SESAME sign-in is confirmed.';
        $plan->manual[] = 'The identity is now SESAME\'s user, the array src/Sesame/UserResolver.php returns (only the username). Code that reads getIdentifier() or other user fields (policies, templates) needs that resolver to return the user\'s row: look it up by username there.';
    }

    private function logout(SignInPlan $plan): void
    {
        $done = false;
        foreach ($this->phpFiles($plan->root, 'src') as $relative) {
            $code = $plan->read($relative);
            if ($code === null || ! str_contains($code, '->Authentication->logout()')) {
                continue;
            }
            if (str_contains($code, 'LegacySignIn::signOut(')) {
                $done = true;

                continue;
            }

            $offset = 0;
            $updated = $code;
            while (($at = strpos($updated, '->Authentication->logout()', $offset)) !== false) {
                $start = SignInSource::statementStart($updated, $at);
                $indent = SignInSource::indentAt($updated, $start);
                $insert = self::HELPER . '::signOut($this->request);' . "\n" . $indent;
                $updated = substr($updated, 0, $start) . $insert . substr($updated, $start);
                $offset = $at + strlen($insert) + 1;
                $plan->changes[] = sprintf('%s:%d: the logout also signs SESAME out, which ends the Laravel half\'s session too', $relative, SignInSource::lineAt($updated, $start));
            }
            $plan->put($relative, $updated, sprintf('%s: logout through SESAME', $relative));
            $done = true;
        }

        if (! $done) {
            $plan->manual[] = 'No logout action calling $this->Authentication->logout() was found. Call \App\Sesame\LegacySignIn::signOut($this->request) in yours, or a logout on this half will not end the Laravel half\'s session.';
        }
    }

    /**
     * The expression for the request inside a method: a request parameter, or
     * a controller's own request.
     *
     * @param array{name: string, class: string, extends: string, start: int, params: string, open: int, close: int} $method
     */
    private function requestExpression(array $method): ?string
    {
        if (preg_match('/(?:ServerRequestInterface|ServerRequest)\s+(\$\w+)/', $method['params'], $m) === 1) {
            return $m[1];
        }

        if (str_ends_with($method['class'], 'Controller') || str_ends_with($method['extends'], 'Controller')) {
            return '$this->request';
        }

        return null;
    }

    /**
     * The request path that answers with the hand-off: a controller action's
     * fallback route, or "*" for an authenticator, which answers every guarded
     * path.
     *
     * @param array{name: string, class: string, extends: string, start: int, params: string, open: int, close: int} $method
     */
    private function handOffPath(array $method): string
    {
        if (! str_ends_with($method['class'], 'Controller')) {
            return '*';
        }

        $controller = substr($method['class'], 0, -strlen('Controller'));
        $dash = static fn(string $name): string => strtolower((string) preg_replace('/(?<=[a-z0-9])([A-Z])/', '-$1', $name));

        return '/' . $dash($controller) . '/' . $dash($method['name']);
    }

    /**
     * @return list<string> app-relative PHP files under $dir, sorted
     */
    private function phpFiles(string $root, string $dir): array
    {
        $base = $root . '/' . $dir;
        if (! is_dir($base)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                if (! str_starts_with($relative, 'src/Sesame/')) {
                    $files[] = $relative;
                }
            }
        }
        sort($files);

        return $files;
    }
}
