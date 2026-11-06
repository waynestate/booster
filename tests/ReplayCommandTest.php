<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Symfony\Component\Console\Tester\ApplicationTester;
use Waynestate\Booster\Console\BoosterApplication;
use Waynestate\Booster\Replay\ReplayDrafter;
use Waynestate\Booster\Replay\ReplayFile;
use Waynestate\Booster\Run\ProcessResult;

final class ReplayCommandTest extends TestCase
{
    private const JSON = [['Content-Type', 'application/json']];

    /**
     * @param array<string, mixed> $input
     *
     * @return array{int, string}
     */
    private function booster(array $input, ?FakeProcessRunner $fake = null): array
    {
        $app = new BoosterApplication($fake ?? ReplayFake::runner([]));
        $app->setAutoExit(false);
        $tester = new ApplicationTester($app);
        $exit = $tester->run($input, ['capture_stderr_separately' => true, 'interactive' => false]);

        return [$exit, $tester->getDisplay() . $tester->getErrorOutput()];
    }

    private function fixtureCopy(): string
    {
        $dir = $this->tempDir();
        foreach (['booster-replay-compare.json', 'composer.json'] as $name) {
            copy(__DIR__ . '/fixtures/replay-app/' . $name, $dir . '/' . $name);
        }

        return $dir;
    }

    public function test_record_saves_the_answers_and_check_passes_when_they_match(): void
    {
        $app = $this->fixtureCopy();
        $out = $this->tempDir();
        $answers = [[200, '{"code":"0","matches_zero":true}', self::JSON], [200, '{"code":"abc","matches_zero":true}', self::JSON]];

        [$exit, $text] = $this->booster(['command' => 'replay', 'action' => 'record', '--path' => $app, '--file' => 'booster-replay-compare.json', '--out' => $out, '--no-install' => true], ReplayFake::runner([$answers]));

        self::assertSame(0, $exit, $text);
        // The PHP comes from composer.json (^7.4).
        self::assertStringContainsString('Replay: recorded 2 answers on PHP 7.4 from booster-replay-compare.json.', $text);
        self::assertStringContainsString('Saved the answers to ' . realpath($app) . '/booster-replay.recording.json', $text);
        self::assertStringContainsString('RESULT: PASS', $text);
        self::assertFileExists($app . '/booster-replay.recording.json');

        [$exit, $text] = $this->booster(['command' => 'replay', 'action' => 'check', '--path' => $app, '--file' => 'booster-replay-compare.json', '--php' => '8.4', '--out' => $out, '--no-install' => true], ReplayFake::runner([$answers]));

        self::assertSame(0, $exit, $text);
        self::assertStringContainsString('Replay passed: all 2 answers match the baseline from PHP 7.4.', $text);
        self::assertFileExists($out . '/check-php8.4.txt');
    }

    public function test_check_exits_1_with_the_first_difference(): void
    {
        $app = $this->fixtureCopy();
        $before = [[200, '{"code":"0","matches_zero":true}', self::JSON], [200, '{"code":"abc","matches_zero":true}', self::JSON]];
        $after = [[200, '{"code":"0","matches_zero":true}', self::JSON], [200, '{"code":"abc","matches_zero":false}', self::JSON]];
        $this->booster(['command' => 'replay', 'action' => 'record', '--path' => $app, '--file' => 'booster-replay-compare.json', '--out' => $this->tempDir(), '--no-install' => true], ReplayFake::runner([$before]));

        [$exit, $text] = $this->booster(['command' => 'replay', 'action' => 'check', '--path' => $app, '--file' => 'booster-replay-compare.json', '--php' => '8.0', '--out' => $this->tempDir(), '--no-install' => true, '--format' => 'json'], ReplayFake::runner([$after]));

        self::assertSame(1, $exit);
        $json = json_decode($text, true);
        self::assertIsArray($json);
        self::assertSame('fail', $json['result']);
        self::assertSame('differs', $json['replay']['status']);
        self::assertSame(1, $json['replay']['differences']);
        self::assertSame('First difference: 02 requests / a word: GET /compare?code=abc (- baseline, + now)', $json['replay']['lines'][1]);
    }

    public function test_check_without_a_recording_says_to_record_first(): void
    {
        [$exit, $text] = $this->booster(['command' => 'replay', 'action' => 'check', '--path' => $this->fixtureCopy(), '--file' => 'booster-replay-compare.json', '--no-install' => true]);

        self::assertSame(2, $exit);
        self::assertStringContainsString('Record one first: booster replay record', $text);
    }

    public function test_init_drafts_a_file_with_todos_and_record_refuses_until_they_are_gone(): void
    {
        $app = $this->tempDir();
        mkdir($app . '/public');
        file_put_contents($app . '/public/index.php', "<?php echo 'home';\n");
        file_put_contents($app . '/public/edit.php', "<?php if (\$_POST) { save(\$_POST); }\n");
        mkdir($app . '/public/includes');
        file_put_contents($app . '/public/includes/db.php', "<?php\n");

        [$exit, $text] = $this->booster(['command' => 'replay', 'action' => 'init', '--path' => $app]);

        self::assertSame(0, $exit, $text);
        self::assertStringContainsString('Drafted booster-replay.json with 4 requests, from PHP entry points under public/.', $text);
        $file = ReplayFile::load($app . '/booster-replay.json');
        $paths = array_map(static fn($r): string => $r->method . ' ' . $r->path, $file->requests);
        self::assertSame(['GET /', 'GET /edit.php', 'GET /booster-replay-no-such-page', 'POST /edit.php'], $paths);
        self::assertCount(2, $file->todos);

        [$exit, $text] = $this->booster(['command' => 'replay', 'action' => 'record', '--path' => $app, '--php' => '7.4']);
        self::assertSame(2, $exit);
        self::assertStringContainsString('booster-replay.json still has 2 todo items', $text);

        [$exit, $text] = $this->booster(['command' => 'replay', 'action' => 'init', '--path' => $app]);
        self::assertSame(2, $exit);
        self::assertStringContainsString('already exists', $text);
    }

    public function test_the_drafter_reads_slim_style_route_calls_and_marks_path_placeholders(): void
    {
        $app = $this->tempDir();
        mkdir($app . '/public');
        file_put_contents($app . '/public/index.php', "<?php\n\$app->get('/', 'home');\n\$app->get('/batches/:id', 'show');\n\$app->post('/change_role', 'role');\n");

        $draft = (new ReplayDrafter())->draft($app);
        $file = ReplayFile::fromArray((array) json_decode($draft['json'], true));

        self::assertSame('index.php', $file->frontController);
        self::assertSame(['GET /', 'GET /batches/:id', 'GET /booster-replay-no-such-page', 'POST /change_role'], array_map(static fn($r): string => $r->method . ' ' . $r->path, $file->requests));
        self::assertStringContainsString('replace the placeholders in the path', implode("\n", $file->todos));
        self::assertStringContainsString('fill in the form fields', implode("\n", $file->todos));
    }

    public function test_the_drafter_uses_laravels_route_list_when_the_app_can_print_it(): void
    {
        $app = $this->tempDir();
        file_put_contents($app . '/artisan', "<?php\n");
        mkdir($app . '/vendor');
        file_put_contents($app . '/vendor/autoload.php', "<?php\n");
        mkdir($app . '/public');
        file_put_contents($app . '/public/index.php', "<?php\n");
        $list = [
            ['method' => 'GET|HEAD', 'uri' => '/', 'name' => null],
            ['method' => 'GET|HEAD', 'uri' => 'notes/{note}', 'name' => 'notes.show'],
            ['method' => 'POST', 'uri' => 'notes', 'name' => 'notes.store'],
            ['method' => 'GET|HEAD', 'uri' => '_ignition/health-check', 'name' => null],
        ];
        $fake = new FakeProcessRunner(static fn(array $command): ProcessResult => new ProcessResult(0, (string) json_encode($list), ''));

        $draft = (new ReplayDrafter($fake))->draft($app);
        $file = ReplayFile::fromArray((array) json_decode($draft['json'], true));

        self::assertSame('php artisan route:list', $draft['source']);
        self::assertSame(['artisan', 'route:list', '--json'], array_slice($fake->calls[0]['command'], 1));
        self::assertSame('public', $file->docroot);
        self::assertSame(['GET /', 'GET /notes/{note}', 'GET /booster-replay-no-such-page', 'POST /notes'], array_map(static fn($r): string => $r->method . ' ' . $r->path, $file->requests));
    }

    public function test_run_refuses_replay_with_skip_gate(): void
    {
        [$exit, $text] = $this->booster(['command' => 'run', '--path' => $this->fixtureCopy(), '--replay' => 'booster-replay-compare.json', '--skip-gate' => true, '--no-package-check' => true]);

        self::assertSame(2, $exit);
        self::assertStringContainsString('--replay runs as part of the gate, so it cannot go with --skip-gate', $text);
    }

    public function test_run_with_replay_needs_the_request_file(): void
    {
        [$exit, $text] = $this->booster(['command' => 'run', '--path' => $this->fixtureCopy(), '--replay' => null, '--no-package-check' => true]);

        self::assertSame(2, $exit);
        self::assertStringContainsString('No request file at', $text);
        self::assertStringContainsString('booster-replay.json. Draft one with: booster replay init', $text);
    }
}
