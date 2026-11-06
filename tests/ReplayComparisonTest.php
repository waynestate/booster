<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Replay\ReplayAnswer;
use Waynestate\Booster\Replay\ReplayComparison;
use Waynestate\Booster\Replay\ReplayFile;
use Waynestate\Booster\Replay\ReplayNormalizer;

final class ReplayComparisonTest extends TestCase
{
    /**
     * @param list<mixed> $normalize
     * @param array<string, string> $compare
     */
    private function file(array $normalize = [], array $compare = []): ReplayFile
    {
        return ReplayFile::fromArray(['normalize' => $normalize, 'compare' => $compare, 'requests' => [['path' => '/']]]);
    }

    /**
     * @param list<array{string, string}> $headers
     */
    private static function answer(string $body, array $headers = [['Content-Type', 'text/html; charset=UTF-8']], int $status = 200): ReplayAnswer
    {
        return new ReplayAnswer(1, $status, $headers, $body);
    }

    public function test_the_view_compares_status_key_headers_cookie_names_and_body(): void
    {
        $answer = self::answer("<p>Hi</p>\n", [
            ['Content-Type', 'text/html;charset=UTF-8'],
            ['Location', 'http://127.0.0.1:8765/notes/3'],
            ['Set-Cookie', 'PHPSESSID=abc123; path=/'],
            ['Set-Cookie', 'last_note=3; path=/'],
            ['Date', 'Thu, 05 Nov 2026 20:00:00 GMT'],
            ['X-Powered-By', 'PHP/7.4.33'],
        ], 302);

        self::assertSame([
            'status: 302',
            'content-type: text/html; charset=utf-8',
            'location: <app>/notes/3',
            'allow: -',
            'set-cookie: PHPSESSID, last_note',
            '--- body',
            '<p>Hi</p>',
        ], ReplayComparison::view($answer, $this->file()));
    }

    public function test_identical_answers_have_no_differences_and_a_changed_status_does(): void
    {
        $file = $this->file();
        $same = ReplayComparison::differences($file, [self::answer('ok')], [self::answer('ok')]);
        $changed = ReplayComparison::differences($file, [self::answer('ok')], [self::answer('Fatal error', status: 500)]);

        self::assertSame([], $same);
        self::assertCount(1, $changed);
        self::assertSame('01 requests: GET /', $changed[0]['request']->label());
        self::assertContains('- status: 200', $changed[0]['diff']);
        self::assertContains('+ status: 500', $changed[0]['diff']);
        self::assertContains('- ok', $changed[0]['diff']);
        self::assertContains('+ Fatal error', $changed[0]['diff']);
    }

    public function test_whitespace_is_collapsed_by_default_and_kept_when_exact(): void
    {
        $a = self::answer("<ul>\n  <li>One</li>\n\n</ul>");
        $b = self::answer("<ul>\n<li>One</li>\n</ul>\n");

        self::assertSame([], ReplayComparison::differences($this->file(), [$a], [$b]));
        self::assertNotSame([], ReplayComparison::differences($this->file([], ['whitespace' => 'exact']), [$a], [$b]));
    }

    public function test_json_is_compared_by_value_unless_exact(): void
    {
        $json = [['Content-Type', 'application/json']];
        $old = self::answer('{"id":9000001,"score":3,"title":"x"}', $json);
        $new = self::answer('{"title":"x","id":9000001.0,"score":3.0}', $json);

        self::assertSame([], ReplayComparison::differences($this->file(), [$old], [$new]));
        self::assertNotSame([], ReplayComparison::differences($this->file([], ['json' => 'exact']), [$old], [$new]));
    }

    public function test_a_real_json_change_shows_the_changed_key(): void
    {
        $json = [['Content-Type', 'application/json']];
        $diff = ReplayComparison::differences($this->file(), [self::answer('{"code":"abc","matches_zero":true}', $json)], [self::answer('{"code":"abc","matches_zero":false}', $json)]);

        self::assertSame(['      "code": "abc",', '-     "matches_zero": true', '+     "matches_zero": false', '  }'], $diff[0]['diff']);
    }

    public function test_the_csrf_preset_hides_tokens_in_fields_and_meta_tags(): void
    {
        $csrf = ReplayNormalizer::preset('csrf');

        self::assertSame('<input type="hidden" name="_token" value="<CSRF>">', $csrf->apply('<input type="hidden" name="_token" value="a1b2c3d4">'));
        self::assertSame('<input value="<CSRF>" name="csrf_token">', $csrf->apply('<input value="zz99" name="csrf_token">'));
        self::assertSame('<meta name="csrf-token" content="<CSRF>">', $csrf->apply('<meta name="csrf-token" content="Qx7">'));
        self::assertSame('<input name="title" value="Keep me">', $csrf->apply('<input name="title" value="Keep me">'));
    }

    public function test_the_other_presets_replace_only_their_values(): void
    {
        self::assertSame('<a href="/x?PHPSESSID=<SESSION>">', ReplayNormalizer::preset('session-id')->apply('<a href="/x?PHPSESSID=8f14e45fceea167a">'));
        self::assertSame('Built <TIMESTAMP>, id 42', ReplayNormalizer::preset('timestamps')->apply('Built 2026-11-05 20:15:03, id 42'));
        self::assertSame('"t": <TIMESTAMP>', ReplayNormalizer::preset('timestamps')->apply('"t": 1793995200'));
        self::assertSame('<script src="/build/app.<HASH>.js"></script> <link href="/css/app.css?id=<HASH>">', ReplayNormalizer::preset('asset-hash')->apply('<script src="/build/app.3f9a1c2b7e.js"></script> <link href="/css/app.css?id=4c1d2e">'));
    }

    /** Found on WELD, 2026-11-06: Vite's base64url hashes slipped past the hex-only rule. */
    public function test_asset_hash_hides_vite_hashes_and_leaves_plain_names_alone(): void
    {
        $preset = ReplayNormalizer::preset('asset-hash');

        self::assertSame(
            '<link href="/build/assets/app-<HASH>.css"><script src="/build/assets/app-<HASH>.js"></script>',
            $preset->apply('<link href="/build/assets/app-CCXBMKGl.css"><script src="/build/assets/app-L1fqnEWe.js"></script>'),
        );
        // A hash may hold a dash or an underscore itself.
        self::assertSame('/build/assets/AppHeader-<HASH>.css /build/assets/wsu-shield-<HASH>.svg', $preset->apply('/build/assets/AppHeader-DK7UwdK-.css /build/assets/wsu-shield-C3_lVA08.svg'));
        // Plain lowercase words are names, not hashes.
        self::assertSame('/js/app-settings.js /js/my-dashboard.css', $preset->apply('/js/app-settings.js /js/my-dashboard.css'));
    }

    public function test_the_inertia_preset_matches_an_inertia_2_page_with_its_inertia_3_answer(): void
    {
        // The two answers WELD's home page gave before and after inertia-2-to-3 (2026-11-06), shortened.
        $two = '<div id="app" data-page="{&quot;component&quot;:&quot;Welcome&quot;,&quot;props&quot;:{&quot;errors&quot;:{}},&quot;url&quot;:&quot;\/&quot;,&quot;version&quot;:&quot;0123456789abcdef0123456789abcdef&quot;,&quot;clearHistory&quot;:false,&quot;encryptHistory&quot;:false}"></div> </body>';
        $three = '<div id="app" data-page="{&quot;component&quot;:&quot;Welcome&quot;,&quot;props&quot;:{&quot;errors&quot;:{}},&quot;url&quot;:&quot;\/&quot;,&quot;version&quot;:&quot;fedcba9876543210fedcba9876543210&quot;,&quot;sharedProps&quot;:[&quot;errors&quot;]}"></div> <script data-inertia-devtools-id type="application/json">"01M6SADF0YG6RSS5JEQ35YQGJ5"</script></body>';

        self::assertNotSame([], ReplayComparison::differences($this->file(), [self::answer($two)], [self::answer($three)]));
        self::assertSame([], ReplayComparison::differences($this->file(['inertia']), [self::answer($two)], [self::answer($three)]));

        // The JSON answer of a client visit, with the fields first or last.
        $preset = ReplayNormalizer::preset('inertia');
        self::assertSame(
            '{"component":"Profile","props":{},"version":"<VERSION>"}',
            $preset->apply('{"clearHistory":false,"component":"Profile","props":{},"version":"0123456789abcdef0123456789abcdef","encryptHistory":false,"sharedProps":["auth","name"]}'),
        );
        // A history flag that is on, a changed prop or a changed component still differs.
        self::assertSame('{"clearHistory":true}', $preset->apply('{"clearHistory":true}'));
        self::assertNotSame(
            $preset->apply(str_replace('Welcome', 'Dashboard', $three)),
            $preset->apply($two),
        );
    }

    public function test_preload_hints_hides_modulepreload_links_and_keeps_the_tags_that_load_the_page(): void
    {
        $preset = ReplayNormalizer::preset('preload-hints');

        self::assertSame(
            '<link rel="stylesheet" href="/build/app.css" /><script type="module" src="/build/app.js"></script>',
            $preset->apply('<link rel="modulepreload" as="script" href="/build/app.js" /><link rel="modulepreload" as="script" href="/build/wayne-ui-vue.js" nonce="x" /><link rel="stylesheet" href="/build/app.css" /><script type="module" src="/build/app.js"></script>'),
        );

        // In a real comparison the server's address is already "<app>", whose ">" must not end the tag (WELD, 2026-11-06).
        $old = self::answer('<link rel="modulepreload" as="script" href="http://localhost:8765/build/app-L1fqnEWe.js" /><script type="module" src="http://localhost:8765/build/app-L1fqnEWe.js"></script>');
        $new = self::answer('<link rel="modulepreload" as="script" href="http://localhost:8765/build/app-L1fqnEWe.js" /><link rel="modulepreload" as="script" href="http://localhost:8765/build/wayne-ui-vue-DK7UwdK-.js" /><script type="module" src="http://localhost:8765/build/app-L1fqnEWe.js"></script>');
        self::assertSame([], ReplayComparison::differences($this->file(['preload-hints']), [$old], [$new]));
    }

    public function test_a_declared_normalizer_hides_a_volatile_part_that_would_otherwise_differ(): void
    {
        $old = self::answer('<footer>Page built at 2026-11-05 20:00:00</footer>');
        $new = self::answer('<footer>Page built at 2026-11-06 08:30:12</footer>');

        self::assertNotSame([], ReplayComparison::differences($this->file(), [$old], [$new]));
        self::assertSame([], ReplayComparison::differences($this->file(['timestamps']), [$old], [$new]));
        self::assertSame([], ReplayComparison::differences($this->file([['pattern' => 'built at [^<]+', 'replace' => 'built at NOW']]), [$old], [$new]));
    }

    public function test_a_header_normalizer_applies_to_header_lines_only_when_asked(): void
    {
        $old = self::answer('x', [['Content-Type', 'text/html']]);
        $new = self::answer('x', [['Content-Type', 'text/html; charset=UTF-8']]);
        $rule = ['pattern' => '; charset=utf-8', 'replace' => '', 'in' => 'headers'];

        self::assertNotSame([], ReplayComparison::differences($this->file(), [$old], [$new]));
        self::assertSame([], ReplayComparison::differences($this->file([$rule]), [$old], [$new]));
    }

    public function test_no_answer_and_binary_bodies_have_their_own_lines(): void
    {
        $file = $this->file();

        self::assertSame(['no answer: no answer within 30 seconds'], ReplayComparison::view(new ReplayAnswer(1, 0, [], '', 'no answer within 30 seconds'), $file));
        $binary = ReplayComparison::view(self::answer("\xff\xd8\xff\xe0", [['Content-Type', 'image/jpeg']]), $file);
        self::assertStringStartsWith('(binary, 4 bytes, sha256 ', (string) end($binary));
    }

    public function test_the_diff_trims_unchanged_lines_to_one_line_of_context(): void
    {
        $before = ['a', 'b', 'c', 'd', 'e', 'f', 'g'];
        $after = ['a', 'b', 'c', 'X', 'e', 'f', 'g'];

        self::assertSame(['  c', '- d', '+ X', '  e'], ReplayComparison::diff($before, $after));
        self::assertSame(['  a', '- b', '+ B', '  c', '  ...', '  e', '- f', '+ F', '  g'], ReplayComparison::diff(['a', 'b', 'c', 'd', 'e', 'f', 'g'], ['a', 'B', 'c', 'd', 'e', 'F', 'g']));
    }

    public function test_the_transcript_lists_every_request_with_its_view(): void
    {
        $file = ReplayFile::load(__DIR__ . '/fixtures/replay-app/booster-replay-compare.json');
        $json = [['Content-Type', 'application/json']];
        $text = ReplayComparison::transcript($file, [
            new ReplayAnswer(1, 200, $json, '{"code":"0","matches_zero":true}'),
            new ReplayAnswer(2, 200, $json, '{"code":"abc","matches_zero":false}'),
        ], 'Booster replay: test');

        self::assertStringContainsString("=== 01 requests / a number: GET /compare?code=0\nstatus: 200\ncontent-type: application/json\n", $text);
        self::assertStringContainsString("=== 02 requests / a word: GET /compare?code=abc\n", $text);
        self::assertStringContainsString('"matches_zero": false', $text);
    }
}
