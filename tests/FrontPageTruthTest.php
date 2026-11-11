<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Symfony\Component\Console\Tester\ApplicationTester;
use Waynestate\Booster\Console\BoosterApplication;
use Waynestate\Booster\Hop\HopRegistry;

/**
 * The front page says its record card and its Commands panel are real Booster
 * output. They are copied by hand into site/index.html and site/assets/booster.js,
 * so nothing used to stop them drifting when a hop was added, renamed or
 * re-summarized. These tests hold every copy to its source:
 *
 * - the record card (static HTML and every scenario in booster.js) to the
 *   captured `booster plan` output in site/proof-data, the same capture the
 *   Real output page is built from;
 * - each dose's summary to the hop registry;
 * - the hops and detect panels to the commands themselves, run in-process;
 * - the plan panel to its captured plan.
 *
 * A booster shot with the wrong vaccine on the card is a bad day at the clinic.
 */
final class FrontPageTruthTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';
    private const INDEX = self::ROOT . '/site/index.html';
    private const SCRIPT = self::ROOT . '/site/assets/booster.js';
    private const PROOF = self::ROOT . '/site/proof-data';

    private static function read(string $file): string
    {
        $text = file_get_contents($file);
        if ($text === false) {
            throw new \RuntimeException('Missing ' . $file);
        }

        return str_replace("\r\n", "\n", $text);
    }

    /**
     * A captured `booster plan`: its header and its numbered steps.
     *
     * @return array{type: string, from: array<string, string>, to: array<string, string>, policy: string, today: string, steps: list<array{hop: string, sum: string}>, text: string}
     */
    private static function capture(string $app): array
    {
        $text = self::read(self::PROOF . '/' . $app . '.plan.txt');
        self::assertSame(1, preg_match('/^  app type: (.+)$/m', $text, $type), $app . ': no app type line');
        self::assertSame(1, preg_match('/^  policy: (\S+ \S+) \(today (\d{4}-\d{2}-\d{2})\)$/m', $text, $policy), $app . ': no policy line');
        $from = [];
        $to = [];
        preg_match_all('/^  (PHP|Laravel|PHPUnit): (\S+) -> (\S+)$/m', $text, $pairs, PREG_SET_ORDER);
        foreach ($pairs as $pair) {
            $from[$pair[1]] = $pair[2];
            $to[$pair[1]] = $pair[3];
        }
        preg_match_all('/^\d+\. (\S+)\n   (.+)$/m', $text, $steps, PREG_SET_ORDER);
        $list = array_map(static fn(array $s): array => ['hop' => $s[1], 'sum' => $s[2]], $steps);

        return ['type' => $type[1], 'from' => $from, 'to' => $to, 'policy' => $policy[1], 'today' => $policy[2], 'steps' => $list, 'text' => $text];
    }

    /**
     * The doses in one `[ dose(...), ... ]` list of booster.js.
     *
     * @return list<array{hop: string, sum: string, php: string, lara: ?string}>
     */
    private static function doses(string $js): array
    {
        preg_match_all('/dose\("([^"]+)", "([^"]+)", "([^"]+)", (null|"([^"]*)")/', $js, $found, PREG_SET_ORDER);

        return array_map(static fn(array $d): array => [
            'hop' => $d[1],
            'sum' => $d[2],
            'php' => $d[3],
            'lara' => $d[4] === 'null' ? null : $d[5],
        ], $found);
    }

    /**
     * Every scenario in booster.js's SCENARIOS, keyed by name.
     *
     * @return array<string, array{title: string, type: string, course: string, policy: ?string, start: array{php: string, lara: ?string}, doses: list<array{hop: string, sum: string, php: string, lara: ?string}>, illustration: bool}>
     */
    private static function scenarios(): array
    {
        $js = self::read(self::SCRIPT);
        self::assertSame(1, preg_match('/var bladeDoses = \[(.*?)\n  \];/s', $js, $blade), 'booster.js: no bladeDoses list');
        $bladeDoses = self::doses($blade[1]);
        self::assertSame(1, preg_match('/var SCENARIOS = \{\n(.*?)\n  \};/s', $js, $block), 'booster.js: no SCENARIOS object');
        $parts = preg_split('/^    (\w+): \{$/m', $block[1], -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        self::assertIsArray($parts);

        $scenarios = [];
        for ($i = 0; $i + 1 < count($parts); $i += 2) {
            $name = $parts[$i];
            $body = $parts[$i + 1];
            self::assertSame(1, preg_match('/title: "([^"]+)"/', $body, $title), $name . ': no title');
            self::assertSame(1, preg_match('/type: "([^"]+)"/', $body, $type), $name . ': no type');
            self::assertSame(1, preg_match('/course: "([^"]+)"/', $body, $course), $name . ': no course');
            self::assertSame(1, preg_match('/start: \{ php: "([^"]+)", lara: (null|"([^"]*)") \}/', $body, $start), $name . ': no start');
            $scenarios[$name] = [
                'title' => $title[1],
                'type' => $type[1],
                'course' => $course[1],
                'policy' => null,
                'start' => ['php' => $start[1], 'lara' => $start[2] === 'null' ? null : $start[3]],
                'doses' => str_contains($body, 'doses: bladeDoses') ? $bladeDoses : self::doses($body),
                'illustration' => str_contains($type[1], 'illustration'),
            ];
        }

        return $scenarios;
    }

    /** The app folder a scenario's title names (examples/<app>). */
    private static function app(string $title): string
    {
        self::assertStringStartsWith('examples/', $title);

        return substr($title, strlen('examples/'));
    }

    /**
     * "PHP 8.2 → 8.5 · Laravel 10 → 13 · PHPUnit 10 → 13", from a capture's header.
     *
     * @param array{from: array<string, string>, to: array<string, string>} $capture
     */
    private static function course(array $capture): string
    {
        $parts = [];
        foreach ($capture['from'] as $component => $from) {
            $parts[] = $component . ' ' . $from . ' → ' . $capture['to'][$component];
        }

        return implode(' · ', $parts);
    }

    /**
     * One Commands panel: the arguments on its `$ bin/booster` line, and the
     * output under it with tags stripped and entities decoded.
     *
     * @return array{string, string}
     */
    private static function panel(string $id): array
    {
        $html = self::read(self::INDEX);
        self::assertSame(1, preg_match('/<pre[^>]* id="' . $id . '"[^>]*>(.*?)<\/pre>/s', $html, $pre), 'site/index.html: no #' . $id);
        $text = html_entity_decode(strip_tags($pre[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = explode("\n", $text);
        $command = array_shift($lines);
        self::assertStringStartsWith('$ bin/booster ', (string) $command, '#' . $id . ' must open with its command line');

        return [substr((string) $command, strlen('$ bin/booster ')), implode("\n", $lines)];
    }

    /**
     * Runs Booster in-process from the repo root and returns its standard output.
     *
     * @param array<string, string> $input
     */
    private static function booster(array $input): string
    {
        $cwd = getcwd();
        chdir(self::ROOT);
        try {
            $app = new BoosterApplication();
            $app->setAutoExit(false);
            $tester = new ApplicationTester($app);
            $tester->run($input, ['capture_stderr_separately' => true]);

            return str_replace("\r\n", "\n", $tester->getDisplay());
        } finally {
            chdir((string) $cwd);
        }
    }

    public function test_every_scenario_is_a_captured_plan(): void
    {
        $scenarios = self::scenarios();
        self::assertSame(['blade', 'raw', 'cas', 'capped'], array_keys($scenarios), 'booster.js scenarios changed: teach this test the new one');

        foreach ($scenarios as $name => $scenario) {
            $capture = self::capture(self::app($scenario['title']));
            $ids = array_column($scenario['doses'], 'hop');
            $planned = array_column($capture['steps'], 'hop');

            if ($name === 'cas') {
                // The page shows the plan with --sesame: the captured plan (made
                // without it) and the swap at the end, which the capture warns about.
                self::assertStringContainsString('Add --sesame to plan the swap to SESAME.', $capture['text']);
                $planned[] = 'cas-to-sesame';
            }
            self::assertSame($planned, $ids, $name . ': the doses must be the captured plan for ' . $scenario['title']);

            if (!$scenario['illustration']) {
                self::assertStringStartsWith($capture['type'], $scenario['type'], $name . ': app type');
            }
            self::assertStringStartsWith(self::course($capture), $scenario['course'], $name . ': course');
            self::assertSame($capture['from']['PHP'], $scenario['start']['php'], $name . ': starting PHP');
            self::assertSame($capture['from']['Laravel'] ?? null, $scenario['start']['lara'], $name . ': starting Laravel');
            $last = $scenario['doses'][count($scenario['doses']) - 1];
            self::assertSame($capture['to']['PHP'], $last['php'], $name . ': PHP after the last dose');
            self::assertSame($capture['to']['Laravel'] ?? null, $last['lara'], $name . ': Laravel after the last dose');
        }
    }

    public function test_every_dose_carries_its_hop_summary_and_lands_on_its_pair(): void
    {
        $registry = new HopRegistry();
        foreach (self::scenarios() as $name => $scenario) {
            $php = $scenario['start']['php'];
            $lara = $scenario['start']['lara'];
            foreach ($scenario['doses'] as $dose) {
                self::assertTrue($registry->has($dose['hop']), $name . ': unknown hop ' . $dose['hop']);
                self::assertSame($registry->get($dose['hop'])->summary, $dose['sum'], $name . ': summary of ' . $dose['hop']);
                if (preg_match('/^php-(\d)(\d)-to-(\d)(\d)$/', $dose['hop'], $m) === 1) {
                    self::assertSame($m[1] . '.' . $m[2], $php, $name . ': ' . $dose['hop'] . ' starts from the PHP before it');
                    $php = $m[3] . '.' . $m[4];
                }
                if (preg_match('/^laravel-(\d+)-to-(\d+)$/', $dose['hop'], $m) === 1) {
                    self::assertSame($m[1], $lara, $name . ': ' . $dose['hop'] . ' starts from the Laravel before it');
                    $lara = $m[2];
                }
                self::assertSame($php, $dose['php'], $name . ': PHP after ' . $dose['hop']);
                self::assertSame($lara, $dose['lara'], $name . ': Laravel after ' . $dose['hop']);
            }
        }
    }

    public function test_the_static_record_card_is_the_first_scenario(): void
    {
        $html = self::read(self::INDEX);
        $blade = self::scenarios()['blade'];
        $capture = self::capture(self::app($blade['title']));

        self::assertStringContainsString('data-card-title>' . $blade['title'] . '<', $html);
        self::assertStringContainsString('data-card-type>' . $blade['type'] . '<', $html);
        self::assertStringContainsString('data-card-course>' . $blade['course'] . '<', $html);
        self::assertStringContainsString('<dt>Policy</dt><dd>' . $capture['policy'] . '</dd>', $html, 'the card names the policy the plans were captured with');

        preg_match_all('/<span class="dose__hop"><code>([^<]+)<\/code><span class="dose__sum">([^<]+)<\/span><\/span>\s*<span class="dose__pair">([^<]+)<\/span>\s*<span class="dose__gate">([^<]+)<\/span>/', $html, $rows, PREG_SET_ORDER);
        $static = array_map(static fn(array $r): array => [$r[1], $r[2], $r[3], $r[4]], $rows);
        $expected = array_map(static fn(array $d): array => [
            $d['hop'],
            $d['sum'],
            'PHP ' . $d['php'] . ($d['lara'] !== null ? ' · Laravel ' . $d['lara'] : ''),
            'booster-target:' . $d['php'],
        ], $blade['doses']);
        self::assertSame($expected, $static, 'the record card in site/index.html must match the blade scenario in booster.js');
        self::assertStringContainsString('--steps:' . count($expected) . '; --lvl:' . count($expected), $html, 'the altimeter counts the doses');
    }

    public function test_the_hops_panel_is_the_hops_command(): void
    {
        [$command, $text] = self::panel('out-hops');
        self::assertSame('hops', $command);
        self::assertSame(rtrim(self::booster(['command' => 'hops'])), rtrim($text));
    }

    public function test_the_detect_panel_is_the_detect_command(): void
    {
        [$command, $text] = self::panel('out-detect');
        self::assertSame(1, preg_match('/^detect --path=(examples\/[\w-]+)$/', $command, $m), 'the detect panel runs detect on an example');
        self::assertSame(rtrim(self::booster(['command' => 'detect', '--path' => $m[1]])), rtrim($text));
    }

    public function test_the_plan_panel_is_a_captured_plan(): void
    {
        [$command, $text] = self::panel('out-plan');
        self::assertSame(1, preg_match('/^plan --path=examples\/([\w-]+) --today=(\d{4}-\d{2}-\d{2})$/', $command, $m), 'the plan panel runs plan on an example with --today');
        $capture = self::capture($m[1]);
        self::assertSame(
            rtrim($capture['text']),
            rtrim(str_replace($m[2], $capture['today'], $text)),
            'the plan panel must be site/proof-data/' . $m[1] . '.plan.txt (dates aside)',
        );
    }
}
