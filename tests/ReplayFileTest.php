<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Replay\ReplayFile;
use Waynestate\Booster\ToolError;

final class ReplayFileTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/fixtures/replay-app/booster-replay.json';

    public function test_the_fixture_file_parses_into_numbered_requests_across_sequences(): void
    {
        $file = ReplayFile::load(self::FIXTURE);

        self::assertSame(['browse', 'save then read back', 'stale form'], $file->sequences);
        self::assertCount(11, $file->requests);
        self::assertSame('public', $file->docroot);
        self::assertSame('index.php', $file->frontController);
        self::assertSame('php replay/seed.php', $file->setup);
        self::assertSame(['NOTES_DB' => '/tmp/notes.sqlite'], $file->env);
        self::assertSame([], $file->todos);

        $save = $file->requests[7];
        self::assertSame(8, $save->number);
        self::assertSame('08 save then read back / save: POST /notes', $save->label());
        self::assertSame(302, $save->expect);
        self::assertSame(['id' => 'Location: /notes/(\d+)'], $save->capture);
        // The defaults apply to every request; a request's own header wins.
        self::assertSame(['Accept' => 'text/html,application/xhtml+xml'], $file->requests[0]->headers);
        self::assertSame(['Accept' => 'application/json'], $file->requests[1]->headers);
    }

    public function test_a_form_request_is_sent_urlencoded_with_its_placeholders_kept_for_the_client(): void
    {
        $file = ReplayFile::load(self::FIXTURE);
        $client = $file->requests[7]->toClientArray();

        self::assertSame('POST', $client['method']);
        self::assertSame('/notes', $client['target']);
        self::assertSame('_token=%7B%7Btoken%7D%7D&title=Replay+note', $client['body']);
        self::assertContains(['Content-Type', 'application/x-www-form-urlencoded'], $client['headers']);
    }

    public function test_a_query_object_becomes_the_query_string(): void
    {
        $file = ReplayFile::load(__DIR__ . '/fixtures/replay-app/booster-replay-compare.json');

        self::assertSame('/compare?code=abc', $file->requests[1]->target());
        self::assertSame('requests', $file->requests[1]->sequence);
    }

    public function test_an_unknown_key_is_a_typo_and_stops(): void
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('unknown key "pth"');

        ReplayFile::fromArray(['requests' => [['pth' => '/']]]);
    }

    public function test_keys_starting_with_a_dollar_sign_are_comments(): void
    {
        $file = ReplayFile::fromArray(['$comment' => 'hi', 'requests' => [['$why' => 'home page', 'path' => '/']]]);

        self::assertCount(1, $file->requests);
    }

    public function test_a_placeholder_needs_an_earlier_capture_in_the_same_sequence(): void
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('uses {{id}}, which no earlier request in sequence "requests" captures');

        ReplayFile::fromArray(['requests' => [['path' => '/notes/{{id}}']]]);
    }

    public function test_a_path_must_be_a_path_not_an_address(): void
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('"path" must start with /');

        ReplayFile::fromArray(['requests' => [['path' => 'https://example.edu/']]]);
    }

    public function test_one_body_kind_per_request(): void
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('use one of "form", "json" or "body"');

        ReplayFile::fromArray(['requests' => [['method' => 'POST', 'path' => '/', 'form' => ['a' => '1'], 'body' => 'x']]]);
    }

    public function test_todos_are_collected_from_the_file_the_serve_section_and_requests(): void
    {
        $file = ReplayFile::fromArray([
            'todo' => 'check the whole thing',
            'serve' => ['todo' => 'name the setup command'],
            'requests' => [['path' => '/notes/{id}', 'todo' => 'use an id from the fake data']],
        ]);

        self::assertSame([
            'the file: check the whole thing',
            'serve: name the setup command',
            '01 requests: GET /notes/{id}: use an id from the fake data',
        ], $file->todos);
    }

    public function test_an_unknown_normalizer_preset_names_the_presets(): void
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('The presets are csrf, session-id, timestamps, asset-hash');

        ReplayFile::fromArray(['normalize' => ['csrf-tokens'], 'requests' => [['path' => '/']]]);
    }

    public function test_a_bad_custom_pattern_is_caught_before_the_run(): void
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('is not a valid regular expression');

        ReplayFile::fromArray(['normalize' => [['pattern' => 'build (']], 'requests' => [['path' => '/']]]);
    }

    public function test_the_fingerprint_ignores_normalizers_but_not_requests(): void
    {
        $base = ['requests' => [['path' => '/']]];
        $a = ReplayFile::fromArray($base);
        $b = ReplayFile::fromArray([...$base, 'normalize' => ['timestamps'], 'compare' => ['json' => 'exact']]);
        $c = ReplayFile::fromArray(['requests' => [['path' => '/other']]]);

        self::assertSame($a->fingerprint, $b->fingerprint);
        self::assertNotSame($a->fingerprint, $c->fingerprint);
    }

    public function test_the_combined_fingerprint_keeps_its_value_when_the_file_gains_split_fingerprints(): void
    {
        $file = ReplayFile::load(self::FIXTURE);

        // Verified against the pre-split code (git HEAD) on the same fixture:
        // the combined fingerprint must not move when a recording is compared.
        self::assertSame('8b0d7b2e06a1d5de5b04753e4aa3a31cd73074bb1ffe634d75e91a1d04c28c32', $file->fingerprint);
        self::assertSame('711cd9b6aba6d0a1b9c2c5467b5f55ec59f546a50fd3e616c7e2366741e3cc77', $file->requestsFingerprint);
        self::assertSame('4f6575b8c2b58b38842bf2ae0122659ab9ae7ec6b3b914e0756515f649b4a691', $file->serveFingerprint);

        $compare = ReplayFile::load(__DIR__ . '/fixtures/replay-app/booster-replay-compare.json');
        self::assertSame('57ff8a1cafdd2e5330ed3dd2bc8c96e7eeb6e1286c98c290557df4a534e541c0', $compare->fingerprint);
        self::assertSame('b6edef20f333d988dd9e2c3cf8ec376127c7e03e2ce217cda7174fe0266da585', $compare->requestsFingerprint);
        self::assertSame('3770337f52bc6631d2c0354ee2ed99b0ecb7850bdf091d3c7b48a4320a7e1e11', $compare->serveFingerprint);
    }

    public function test_a_serve_only_change_keeps_the_requests_fingerprint(): void
    {
        $requests = [['name' => 'home', 'path' => '/']];
        $before = ReplayFile::fromArray(['serve' => ['docroot' => 'public', 'front_controller' => 'index.php'], 'requests' => $requests]);
        $after = ReplayFile::fromArray(['serve' => ['docroot' => 'web', 'front_controller' => 'index.php', 'setup' => 'php seed.php'], 'requests' => $requests]);

        self::assertSame($before->requestsFingerprint, $after->requestsFingerprint);
        self::assertNotSame($before->serveFingerprint, $after->serveFingerprint);
        self::assertNotSame($before->fingerprint, $after->fingerprint);
    }

    public function test_a_request_only_change_keeps_the_serve_fingerprint(): void
    {
        $serve = ['docroot' => 'public', 'front_controller' => 'index.php'];
        $before = ReplayFile::fromArray(['serve' => $serve, 'requests' => [['path' => '/']]]);
        $after = ReplayFile::fromArray(['serve' => $serve, 'requests' => [['path' => '/other']]]);

        self::assertSame($before->serveFingerprint, $after->serveFingerprint);
        self::assertNotSame($before->requestsFingerprint, $after->requestsFingerprint);
        self::assertNotSame($before->fingerprint, $after->fingerprint);
    }

    public function test_the_serve_summary_names_docroot_front_controller_and_setup(): void
    {
        $full = ReplayFile::fromArray(['serve' => ['docroot' => 'public', 'front_controller' => 'index.php', 'setup' => 'php seed.php'], 'requests' => [['path' => '/']]]);
        self::assertSame('docroot public, front index.php, setup "php seed.php"', $full->serveSummary());
        self::assertSame('docroot public, front index.php', ReplayFile::load(__DIR__ . '/fixtures/replay-app/booster-replay-compare.json')->serveSummary());
        self::assertSame('docroot .', ReplayFile::fromArray(['requests' => [['path' => '/']]])->serveSummary());
    }

    public function test_the_docroot_must_stay_inside_the_app(): void
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('"serve.docroot" must be a folder inside the app');

        ReplayFile::fromArray(['serve' => ['docroot' => '../elsewhere'], 'requests' => [['path' => '/']]]);
    }

    public function test_a_file_with_no_requests_says_so(): void
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('has no requests');

        ReplayFile::fromArray(['version' => 1]);
    }

    public function test_a_missing_file_points_at_init(): void
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('booster replay init');

        ReplayFile::load(__DIR__ . '/fixtures/replay-app/no-such-file.json');
    }

    public function test_locate_keeps_absolute_paths_and_resolves_relative_ones_against_the_app(): void
    {
        self::assertSame('/app/booster-replay.json', ReplayFile::locate('/app', null));
        self::assertSame('/app/replay/requests.json', ReplayFile::locate('/app/', 'replay/requests.json'));
        self::assertSame('/elsewhere/r.json', ReplayFile::locate('/app', '/elsewhere/r.json'));
        self::assertSame('C:\\r.json', ReplayFile::locate('/app', 'C:\\r.json'));
    }
}
