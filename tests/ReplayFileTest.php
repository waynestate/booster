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
