<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Waynestate\Booster\Run\StrictSession;

/**
 * The session-strict-mode engine on small apps written into a temp folder:
 * where the guarded line lands for each statement shape, that every result
 * still parses, that a second run changes nothing, and what counts as
 * already covered or as a stop sign. Two tests run the result under real PHP.
 */
final class StrictSessionTest extends TestCase
{
    /** @param array<string, string> $files relative path to content */
    private function app(array $files): string
    {
        $dir = $this->tempDir();
        foreach ($files as $path => $content) {
            if (!is_dir(dirname($dir . '/' . $path))) {
                mkdir(dirname($dir . '/' . $path), 0775, true);
            }
            file_put_contents($dir . '/' . $path, $content);
        }

        return $dir;
    }

    private static function parses(string $source): bool
    {
        try {
            \PhpToken::tokenize($source, TOKEN_PARSE);

            return true;
        } catch (\ParseError) {
            return false;
        }
    }

    /**
     * Each shape, and the line that must come right after the guard: the
     * start of the whole statement that holds the call.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function shapes(): array
    {
        return [
            'a plain call' => ["<?php\nsession_start();\n", 'session_start();'],
            'inside a function' => ["<?php\nfunction a()\n{\n    session_start();\n}\n", '    session_start();'],
            'a braceless if' => ["<?php\nif (!isset(\$_SESSION)) session_start();\n", 'if (!isset($_SESSION)) session_start();'],
            'a braceless else' => ["<?php\nif (\$a) foo(); else session_start();\n", 'if ($a) foo(); else session_start();'],
            'an else after a block' => ["<?php\nif (\$a) {\n    foo();\n} else session_start();\n", 'if ($a) {'],
            'nested braceless ifs' => ["<?php\nif (\$a) if (\$b) x(); else session_start();\n", 'if ($a) if ($b) x(); else session_start();'],
            'an alternative-syntax else' => ["<?php\nif (\$a):\n    foo();\nelse:\n    session_start();\nendif;\n", '    session_start();'],
            'a case label' => ["<?php\nswitch (\$x) {\n    case 1:\n        session_start();\n        break;\n}\n", '        session_start();'],
            'a ternary' => ["<?php\n\$ok = \$a ? false : session_start();\n", '$ok = $a ? false : session_start();'],
            'a closure body' => ["<?php\n\$f = function () use (\$x): bool {\n    return session_start();\n};\n", '    return session_start();'],
            'an arrow function' => ["<?php\n\$f = fn(): bool => session_start();\n", '$f = fn(): bool => session_start();'],
            'a match arm' => ["<?php\n\$r = match (\$x) {\n    1 => session_start(),\n    default => false,\n};\n", '$r = match ($x) {'],
            'a do-while condition' => ["<?php\ndo {\n    x();\n} while (!session_start());\n", 'do {'],
            'a condition' => ["<?php\nif (session_status() === PHP_SESSION_NONE && !session_start(['name' => 'x'])) {\n    die();\n}\n", "if (session_status() === PHP_SESSION_NONE && !session_start(['name' => 'x'])) {"],
            'a method' => ["<?php\nclass A\n{\n    public function go(): bool\n    {\n        return session_start();\n    }\n}\n", '        return session_start();'],
            'a namespaced file' => ["<?php\ndeclare(strict_types=1);\nnamespace App;\n\\session_start();\n", '\\session_start();'],
            'after a try block' => ["<?php\ntry { x(); } catch (E \$e) { y(); }\nsession_start();\n", 'session_start();'],
            'after interpolated strings' => ["<?php\n\$s = \"{\$a['x']} \${b}\";\nsession_start();\n", 'session_start();'],
        ];
    }

    #[DataProvider('shapes')]
    public function test_the_guard_goes_on_its_own_line_before_the_whole_statement(string $source, string $statement): void
    {
        $dir = $this->app(['index.php' => $source]);

        $result = (new StrictSession())->migrate($dir, false);

        $out = (string) file_get_contents($dir . '/index.php');
        self::assertSame([], $result->refusals);
        self::assertTrue(self::parses($out), $out);
        self::assertSame(1, substr_count($out, StrictSession::GUARD), $out);
        $lines = explode("\n", $out);
        $at = array_search(trim(StrictSession::GUARD), array_map('trim', $lines), true);
        self::assertIsInt($at, $out);
        self::assertSame(trim(StrictSession::MARKER), trim($lines[$at - 1]));
        self::assertSame($statement, $lines[$at + 1], $out);
        // Same indent as the statement it guards.
        self::assertSame(strspn($statement, ' '), strspn($lines[$at], ' '));
    }

    public function test_a_call_sharing_its_line_gets_the_guard_inline(): void
    {
        $dir = $this->app([
            'page.php' => "<html><?php session_start(); ?></html>\n",
            'min.php' => "<?php if(!defined(\"SID\")){session_name('a');session_start();}function r(){if(!ini_bool(\"session.use_cookies\"))session_start();}\n",
        ]);

        (new StrictSession())->migrate($dir, false);

        self::assertSame('<html><?php ' . StrictSession::GUARD . " session_start(); ?></html>\n", file_get_contents($dir . '/page.php'));
        $min = (string) file_get_contents($dir . '/min.php');
        self::assertTrue(self::parses($min));
        self::assertSame(2, substr_count($min, StrictSession::GUARD));
        self::assertStringContainsString('function r(){' . StrictSession::GUARD . ' if(!ini_bool(', $min);
    }

    public function test_one_statement_with_two_calls_gets_one_guard(): void
    {
        $dir = $this->app(['index.php' => "<?php\nsession_id() !== '' || session_start() || session_start();\n"]);

        $result = (new StrictSession())->migrate($dir, false);

        self::assertSame(1, substr_count((string) file_get_contents($dir . '/index.php'), StrictSession::GUARD));
        self::assertSame(['index.php' => [2]], (new StrictSession())->inspect($this->app(['index.php' => "<?php\nsession_start() || session_start();\n"]))->calls);
        self::assertStringContainsString('index.php: strict session IDs set before its session_start() call (line 2).', $result->changes[0]);
    }

    public function test_crlf_files_keep_their_line_endings(): void
    {
        $dir = $this->app(['index.php' => "<?php\r\nif (true) {\r\n\tsession_start();\r\n}\r\n"]);

        (new StrictSession())->migrate($dir, false);

        self::assertSame("<?php\r\nif (true) {\r\n\t" . StrictSession::MARKER . "\r\n\t" . StrictSession::GUARD . "\r\n\tsession_start();\r\n}\r\n", file_get_contents($dir . '/index.php'));
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $dir = $this->app(['index.php' => "<?php\nsession_start();\n", 'lib/auth.php' => "<?php\nfunction login() { session_start(); }\n"]);
        $first = (new StrictSession())->migrate($dir, false);
        $after = [file_get_contents($dir . '/index.php'), file_get_contents($dir . '/lib/auth.php')];

        $second = (new StrictSession())->migrate($dir, false);

        self::assertCount(2, $first->files);
        self::assertSame([], $second->files);
        self::assertSame($after, [file_get_contents($dir . '/index.php'), file_get_contents($dir . '/lib/auth.php')]);
        self::assertContains('Nothing to do: every session_start() call outside vendor/ and node_modules/ already runs with strict session IDs.', $second->notes);
        self::assertContains('index.php names session.use_strict_mode already; left alone.', $second->notes);
    }

    public function test_a_dry_run_writes_nothing_and_says_would(): void
    {
        $source = "<?php\nsession_start();\n";
        $dir = $this->app(['index.php' => $source]);

        $result = (new StrictSession())->migrate($dir, true);

        self::assertSame($source, file_get_contents($dir . '/index.php'));
        self::assertSame('Dry run: would edit index.php: strict session IDs set before its session_start() call (line 2).', $result->changes[0]);
        self::assertSame('Dry run: would cover 1 session_start() call in 1 file: each one now sets session.use_strict_mode and session.use_only_cookies to 1 first.', $result->changes[1]);
        self::assertSame("@@ line 2\n+ " . StrictSession::MARKER . "\n+ " . StrictSession::GUARD . "\n  session_start();", $result->files[0]['diff']);
    }

    public function test_comments_methods_and_vendor_are_not_calls(): void
    {
        $dir = $this->app([
            'index.php' => "<?php\n// session_start();\n/* session_start(); */\n\$s->session_start();\nFoo::session_start();\n\$x = 'session_start()';\n",
            'vendor/lib/a.php' => "<?php\nsession_start();\n",
            'node_modules/x/a.php' => "<?php\nsession_start();\n",
        ]);

        $result = (new StrictSession())->migrate($dir, false);

        self::assertSame([], $result->files);
        self::assertSame(['Nothing to do: found no session_start() call outside vendor/ and node_modules/.'], $result->notes);
        self::assertSame("<?php\nsession_start();\n", file_get_contents($dir . '/vendor/lib/a.php'));
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function stopSigns(): array
    {
        return [
            'session_id with an ID' => [['index.php' => "<?php\nsession_id(\$_GET['sid']);\nsession_start();\n"], 'index.php:2 sets the session ID itself with session_id(...)'],
            'the SID constant' => [['index.php' => "<?php\nsession_start();\necho '<a href=\"x.php?' . SID . '\">';\n"], 'index.php:3 uses the SID constant'],
            'trans_sid on' => [['index.php' => "<?php\nini_set('session.use_trans_sid', 1);\nsession_start();\n"], 'index.php:2 turns session.use_trans_sid on'],
            'only_cookies off as an option' => [['index.php' => "<?php\nsession_start(['use_only_cookies' => 0]);\n"], 'index.php:2 turns session.use_only_cookies off'],
            'use_cookies from a variable' => [['index.php' => "<?php\nini_set('session.use_cookies', \$flag);\nsession_start();\n"], 'index.php:2 turns session.use_cookies off (or to a value Booster cannot read)'],
            'trans_sid on in .htaccess' => [['.htaccess' => "php_flag session.use_trans_sid on\n", 'index.php' => "<?php\nsession_start();\n"], '.htaccess turns session.use_trans_sid on'],
            'an echo tag' => [['index.php' => "<p><?= session_start() ?></p>\n"], 'index.php:1 starts the session inside a <?= ?> tag'],
        ];
    }

    /** @param array<string, string> $files */
    #[DataProvider('stopSigns')]
    public function test_a_stop_sign_refuses_and_changes_nothing(array $files, string $reason): void
    {
        $dir = $this->app($files);

        $result = (new StrictSession())->migrate($dir, false);

        self::assertTrue($result->refused());
        self::assertStringStartsWith($reason, $result->refusals[0]);
        foreach ($files as $path => $content) {
            self::assertSame($content, file_get_contents($dir . '/' . $path));
        }
        self::assertFalse((new StrictSession())->inspect($dir)->pending());
    }

    public function test_settings_that_keep_ids_in_cookies_are_not_stop_signs(): void
    {
        $dir = $this->app(['index.php' => "<?php\n@ini_set('session.use_trans_sid', false);\nini_set('session.use_only_cookies', '1');\nif (!ini_get('session.use_cookies')) { die(); }\nif (!defined('SID')) { session_start(); }\nsession_id();\n"]);

        $result = (new StrictSession())->migrate($dir, false);

        self::assertSame([], $result->refusals);
        self::assertCount(1, $result->files);
    }

    public function test_a_root_htaccess_or_squire_ini_covers_the_whole_app(): void
    {
        $htaccess = $this->app(['.htaccess' => "php_flag session.use_strict_mode on\n", 'index.php' => "<?php\nsession_start();\n"]);
        $squire = $this->app(['.squire/php.ini' => "session.use_strict_mode = 1\n", 'index.php' => "<?php\nsession_start();\n"]);

        self::assertSame(['Nothing to do: .htaccess sets session.use_strict_mode for the whole app.'], (new StrictSession())->migrate($htaccess, false)->notes);
        self::assertSame(['Nothing to do: .squire/php.ini sets session.use_strict_mode, and Squire\'s PHP reads it.'], (new StrictSession())->migrate($squire, false)->notes);
        self::assertSame("<?php\nsession_start();\n", file_get_contents($htaccess . '/index.php'));
    }

    public function test_a_folder_htaccess_covers_its_folder_and_other_ini_files_do_not_count(): void
    {
        $dir = $this->app([
            'admin/.htaccess' => "php_value session.use_strict_mode 1\n",
            'admin/index.php' => "<?php\nsession_start();\n",
            'docker/php.ini' => "session.use_strict_mode = 1\n",
            'index.php' => "<?php\nsession_start();\n",
        ]);

        $result = (new StrictSession())->migrate($dir, false);

        self::assertSame(['index.php'], array_column($result->files, 'file'));
        self::assertSame("<?php\nsession_start();\n", file_get_contents($dir . '/admin/index.php'));
        self::assertContains('admin/.htaccess sets session.use_strict_mode for admin/; files there were left alone.', $result->notes);
        self::assertContains('docker/php.ini sets session.use_strict_mode, but Citadel\'s PHP does not read that file, so the hop still adds the lines.', $result->notes);
    }

    public function test_an_app_that_boots_helmet_is_already_covered(): void
    {
        $dir = $this->app(['public/index.php' => "<?php\nrequire __DIR__ . '/../vendor/autoload.php';\nhelmet_boot();\nsession_start();\n"]);

        $inspection = (new StrictSession())->inspect($dir);

        self::assertFalse($inspection->pending());
        self::assertSame('the app calls helmet_boot() (public/index.php:3), which turns strict session IDs on. Check that every page that starts a session boots HELMET first', $inspection->appWide);
    }

    public function test_manual_lines_name_custom_handlers_and_offer_helmet_when_the_app_requires_it(): void
    {
        $dir = $this->app([
            'composer.json' => (string) json_encode(['require' => ['php' => '^8.3', 'waynestate/helmet' => '^1.0']]),
            'index.php' => "<?php\nsession_set_save_handler(new DbHandler(), true);\nsession_start();\n",
        ]);

        $manual = (new StrictSession())->migrate($dir, true)->manual;

        self::assertStringStartsWith('MANUAL: Sign in, use a page that keeps session data, and sign out on pre-production.', $manual[0]);
        self::assertStringStartsWith('MANUAL: index.php:2 registers its own session handler. PHP only enforces strict mode when that handler implements validateId()', $manual[1]);
        self::assertStringStartsWith('MANUAL: The app requires HELMET. Calling helmet_boot() before the session starts', $manual[2]);
    }

    public function test_unbalanced_brackets_are_refused_not_guessed(): void
    {
        $dir = $this->app(['index.php' => "<?php\nif (\$a) {\n    session_start();\n"]);

        $result = (new StrictSession())->migrate($dir, false);

        self::assertStringStartsWith('index.php: Booster could not follow the statements around its session_start() call', $result->refusals[0]);
    }

    public function test_the_replay_fixture_app_gets_one_guard_and_still_parses(): void
    {
        $dir = $this->tempDir();
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/fixtures/replay-app', FilesystemIterator::SKIP_DOTS)) as $file) {
            /** @var SplFileInfo $file */
            $to = $dir . substr($file->getPathname(), strlen(__DIR__ . '/fixtures/replay-app'));
            if (!is_dir(dirname($to))) {
                mkdir(dirname($to), 0775, true);
            }
            copy($file->getPathname(), $to);
        }

        $result = (new StrictSession())->migrate($dir, false);

        self::assertSame(['src/app.php'], array_column($result->files, 'file'));
        $source = (string) file_get_contents($dir . '/src/app.php');
        self::assertTrue(self::parses($source));
        self::assertStringContainsString("{\n    " . StrictSession::MARKER . "\n    " . StrictSession::GUARD . "\n    session_start();\n", $source);
    }

    /**
     * Real PHP, the platform default (strict mode off): after the hop the
     * session starts with both settings on, and a call that runs while a
     * session is already active adds no warning.
     */
    public function test_under_real_php_the_session_starts_strict_and_quietly(): void
    {
        $dir = $this->app([
            // The settings are read in their own file: a file that names them is covered and left alone.
            'index.php' => "<?php\nsession_start();\nrequire __DIR__ . '/show.php';\nrequire __DIR__ . '/again.php';\n",
            'show.php' => "<?php\necho ini_get('session.use_strict_mode'), ini_get('session.use_only_cookies');\n",
            'again.php' => "<?php\nif (session_status() !== PHP_SESSION_ACTIVE) session_start();\necho ' done';\n",
        ]);
        (new StrictSession())->migrate($dir, false);
        $sessions = $this->tempDir();

        $command = [PHP_BINARY, '-n', '-d', 'session.use_strict_mode=0', '-d', 'session.save_path=' . $sessions, '-d', 'error_reporting=-1', '-d', 'display_errors=1', $dir . '/index.php'];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        proc_close($process);

        if (!extension_loaded('session')) {
            self::markTestSkipped('The session extension is not loaded.');
        }
        self::assertSame('11 done', trim($out));
    }
}
