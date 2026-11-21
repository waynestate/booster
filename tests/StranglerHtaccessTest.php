<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use PHPUnit\Framework\TestCase;
use Waynestate\Booster\Strangler\LegacyHtaccess;

/**
 * The legacy docroot's .htaccess moving into public/.htaccess (design
 * section 2.4, amended by 10.7). The Apache half of the proof is prove.sh's
 * opt-in step 6; these pin the text the mount writes.
 */
final class StranglerHtaccessTest extends TestCase
{
    private const PUBLIC = "<IfModule mod_rewrite.c>\n    RewriteEngine On\n\n" . LegacyHtaccess::FRONT_MARKER . "\n    RewriteCond %{REQUEST_FILENAME} !-d\n    RewriteCond %{REQUEST_FILENAME} !-f\n    RewriteRule ^ index.php [L]\n</IfModule>\n";

    private const FILE_TEST = "    RewriteCond %{CONTEXT_DOCUMENT_ROOT}::%{REQUEST_FILENAME}%{PATH_INFO} ^(.*?)/?::\\1(.*)$\n";

    /** @return list<string> */
    private static function why(LegacyHtaccess $moved): array
    {
        return array_map(static fn(array $r): string => $r['line'] . ': ' . $r['why'], $moved->manual());
    }

    public function test_the_fleet_shape_moves_with_its_file_tests_pointed_at_legacy(): void
    {
        $legacy = <<<'HTACCESS'
            # Mitigate 1: a PHP setting for every request
            php_value memory_limit 512M
            php_flag session.upload_progress.enabled off

            RewriteEngine On
            RewriteRule ^(css|js|images|assets|build) - [L]

            # Allow font assets to be used across domains and subdomains
            <FilesMatch "\.(ttf|otf|eot|woff|woff2)$">
              <IfModule mod_headers.c>
                 Header set Access-Control-Allow-Origin "*"
              </IfModule>
            </FilesMatch>

            # Add a trailing slash to any non-files
            RewriteCond %{REQUEST_FILENAME} !-f
            RewriteCond %{REQUEST_METHOD} GET
            RewriteCond %{REQUEST_URI} !(.*)/$
            RewriteRule ^(.*)$ %{REQUEST_URI}/ [R,L]

            # Pass all non-file requests to index.php
            RewriteCond %{REQUEST_FILENAME} !-f
            RewriteRule ^(.*)$ index.php [QSA,L]
            HTACCESS;
        $moved = LegacyHtaccess::move($legacy, 'legacy/public');

        $expected = "# Moved by Booster's strangler mount from src/public/.htaccess, as written.\n"
            . "# Mitigate 1: a PHP setting for every request\nphp_value memory_limit 512M\nphp_flag session.upload_progress.enabled off\n\n"
            . "<IfModule mod_rewrite.c>\n    RewriteEngine On\n\n"
            . "    # Moved by Booster's strangler mount from src/public/.htaccess, in order.\n"
            . "    # Rewritten for the new layout: file tests look in legacy/public, and rules\n"
            . "    # that served or ran a legacy file still do, through index.php.\n"
            . "    # Laravel's own prefix never meets the legacy rules below.\n"
            . "    RewriteRule ^_laravel(?:/|$) index.php [END]\n\n"
            . "    # A pass-through: a file in public/, a legacy file (the dispatcher serves it), or Apache's 404.\n"
            . "    RewriteCond %{REQUEST_FILENAME} -f\n    RewriteRule ^(css|js|images|assets|build) - [L]\n"
            . self::FILE_TEST . "    RewriteCond %1/../legacy/public%2 -f\n"
            . "    RewriteRule ^(css|js|images|assets|build) index.php [END,E=BOOSTER_LEGACY_FILE:1]\n"
            . "    RewriteRule ^(css|js|images|assets|build) - [L]\n\n"
            . "    # Add a trailing slash to any non-files\n"
            . self::FILE_TEST . "    RewriteCond %1/../legacy/public%2 !-f\n"
            . "    RewriteCond %{REQUEST_METHOD} GET\n    RewriteCond %{REQUEST_URI} !(.*)/$\n    RewriteRule ^(.*)$ %{REQUEST_URI}/ [R,L]\n\n"
            . LegacyHtaccess::FRONT_MARKER . "\n    RewriteCond %{REQUEST_FILENAME} !-d\n    RewriteCond %{REQUEST_FILENAME} !-f\n    RewriteRule ^ index.php [L]\n</IfModule>\n";
        self::assertSame($expected, $moved->apply(self::PUBLIC, 'src/public/.htaccess'));
        self::assertSame([2, 3, 6, 16, 17, 18, 19], $moved->moved(), 'the front-controller rule (22-23) is the dispatcher\'s now');
        self::assertSame(['9: lines 9-13, a filename block: the dispatcher serves legacy files now, so it would never match them'], self::why($moved));
    }

    public function test_redirects_denials_and_deny_blocks_move_ahead_of_everything(): void
    {
        $legacy = <<<'HTACCESS'
            <IfModule mod_rewrite.c>
                RewriteEngine On
                RewriteCond %{HTTPS} off
                RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
                RewriteRule ^old-reports/(.*)$ /reports/$1 [R=302,L,NE]
                RewriteRule ^(vendor|tmp)/ - [F,NC]
                RewriteRule ^retired$ - [G]
                RewriteCond %{REQUEST_FILENAME} !-f
                RewriteRule ^ index.php [L]
            </IfModule>
            <Files "composer.*">
                Order allow,deny
                Deny from all
            </Files>
            <FilesMatch "\.(ini|log)$">
                <IfModule mod_authz_core.c>
                    Require all denied
                </IfModule>
                <IfModule !mod_authz_core.c>
                    Order allow,deny
                    Deny from all
                </IfModule>
            </FilesMatch>
            Redirect 301 /handbook https://example.edu/handbook
            HTACCESS;
        $moved = LegacyHtaccess::move($legacy, 'legacy/webroot');
        self::assertSame([], self::why($moved));
        self::assertSame(['Redirect 301 /handbook https://example.edu/handbook'], $moved->top());
        self::assertSame([
            '    # Laravel\'s own prefix never meets the legacy rules below.',
            '    RewriteRule ^_laravel(?:/|$) index.php [END]',
            '',
            '    # Was <Files "composer.*"> (denied): the last path segment, as Apache matched the filename.',
            '    RewriteCond $1 "^composer\.[^/]*$"',
            '    RewriteRule (?:^|/)([^/]*)$ - [F]',
            '',
            '    # Was <FilesMatch "\.(ini|log)$"> (denied): the last path segment, as Apache matched the filename.',
            '    RewriteCond $1 "\.(ini|log)$"',
            '    RewriteRule (?:^|/)([^/]*)$ - [F]',
            '',
            '    RewriteCond %{HTTPS} off',
            '    RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]',
            '',
            '    RewriteRule ^old-reports/(.*)$ /reports/$1 [R=302,L,NE]',
            '',
            '    RewriteRule ^(vendor|tmp)/ - [F,NC]',
            '',
            '    RewriteRule ^retired$ - [G]',
            '',
        ], $moved->rewrite());
    }

    public function test_rules_that_rewrote_to_a_file_go_through_the_dispatcher(): void
    {
        $legacy = <<<'HTACCESS'
            RewriteEngine On
            RewriteRule ^report/(\d+)$ export.php?id=$1 [QSA,L]
            RewriteRule ^feed$ /static/feed.xml [L]
            RewriteCond %{HTTP_HOST} ^api\. [NC]
            RewriteRule ^ index.php?api=1 [QSA,L]
            RewriteRule ^ - [E=APP_FLAVOR:legacy]
            HTACCESS;
        $rewrite = LegacyHtaccess::move($legacy, 'legacy/public')->rewrite();
        self::assertContains('    RewriteRule ^report/(\d+)$ index.php?id=$1 [QSA,END,E=BOOSTER_LEGACY_TARGET:export.php]', $rewrite);
        self::assertContains('    RewriteRule ^feed$ index.php [END,E=BOOSTER_LEGACY_TARGET:static/feed.xml]', $rewrite);
        // The legacy front controller is the dispatcher now, which picks the half.
        self::assertContains("    RewriteCond %{HTTP_HOST} ^api\\. [NC]", $rewrite);
        self::assertContains('    RewriteRule ^ index.php?api=1 [QSA,L]', $rewrite);
        self::assertContains('    RewriteRule ^ - [E=APP_FLAVOR:legacy]', $rewrite, 'an environment rule moves as written');
    }

    public function test_what_it_cannot_place_stays_behind_as_manual_rows(): void
    {
        $legacy = <<<'HTACCESS'
            RewriteEngine On
            RewriteBase /portal/
            RewriteRule ^api/(.*)$ http://127.0.0.1:9000/$1 [P]
            RewriteCond %{REQUEST_URI} -U
            RewriteRule ^x$ - [F]
            RewriteCond %{DOCUMENT_ROOT}/cache/%{REQUEST_URI} -f
            RewriteRule ^ cache/%{REQUEST_URI} [L]
            RewriteCond %{HTTP_HOST} ^(.+)$
            RewriteCond %{REQUEST_FILENAME} !-f
            RewriteRule ^ /%1 [R,L]
            RewriteCond %{REQUEST_FILENAME} !-f [OR]
            RewriteCond %{REQUEST_METHOD} POST
            RewriteRule ^old$ /new [R,L]
            RewriteRule ^a$ b [C]
            RewriteRule ^up$ ../outside.php [L]
            <If "%{HTTP_HOST} == 'old.example.edu'">
                Redirect 301 / https://new.example.edu/
            </If>
            <IfModule mod_expires.c>
                ExpiresActive On
            </IfModule>
            ErrorDocument 404 /missing.html
            RewriteCond %{HTTP_HOST} dangling
            RewriteEngine Off
            HTACCESS;
        $moved = LegacyHtaccess::move($legacy, 'legacy/public');
        self::assertSame([
            '2: public/.htaccess has no RewriteBase; add it there if the app sits under an Alias',
            '3: the [P] flag',
            '5: the RewriteCond on line 4 tests a filesystem path Booster cannot place (with its RewriteCond lines from line 4)',
            '7: the RewriteCond on line 6 tests a filesystem path Booster cannot place (with its RewriteCond lines from line 6)',
            '10: a %N back-reference next to a file test Booster rewrites (with its RewriteCond lines from line 8)',
            '13: an [OR] condition next to a file test Booster rewrites (with its RewriteCond lines from line 11)',
            '14: the [C] flag',
            '15: a rewrite target Booster cannot send through the dispatcher',
            '16: lines 16-18, a block Booster does not move',
            '19: lines 19-21, a module block that holds more than per-directory settings',
            '22: not a rule Booster moves',
            '24: not a rule Booster moves',
            '23: a RewriteCond with no RewriteRule after it',
        ], self::why($moved));
        self::assertSame([], $moved->rewrite());
        self::assertSame(self::PUBLIC, $moved->apply(self::PUBLIC, 'public/.htaccess'), 'nothing moved, nothing written');
    }

    public function test_the_skeletons_standard_files_move_nothing(): void
    {
        $fixtures = dirname(__DIR__) . '/tests/fixtures/strangler';
        $cake = LegacyHtaccess::move((string) file_get_contents($fixtures . '/cake5/before/webroot/.htaccess'), 'legacy/webroot');
        self::assertSame([[], [], []], [$cake->top(), $cake->rewrite(), $cake->manual()]);
        $laravelLike = LegacyHtaccess::move("<IfModule mod_rewrite.c>\n    <IfModule mod_negotiation.c>\n        Options -MultiViews -Indexes\n    </IfModule>\n    RewriteEngine On\n    RewriteCond %{HTTP:Authorization} .\n    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\n    RewriteCond %{REQUEST_FILENAME} !-d\n    RewriteCond %{REQUEST_FILENAME} !-f\n    RewriteRule ^ index.php [L]\n</IfModule>\n", 'legacy/public');
        self::assertSame([[], [], []], [$laravelLike->top(), $laravelLike->rewrite(), $laravelLike->manual()]);
    }

    public function test_a_php_module_block_moves_as_written(): void
    {
        $moved = LegacyHtaccess::move("<IfModule mod_php.c>\n    php_value upload_max_filesize 25M\n</IfModule>\nHeader always set X-Content-Type-Options nosniff\n", 'legacy/public');
        self::assertSame(['<IfModule mod_php.c>', '    php_value upload_max_filesize 25M', '</IfModule>', 'Header always set X-Content-Type-Options nosniff'], $moved->top());
        self::assertSame([1, 2, 3, 4], $moved->moved());
    }
}
