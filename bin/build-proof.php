#!/usr/bin/env php
<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Waynestate\Booster\Console\BoosterApplication;
use Waynestate\Booster\Site\ProofPage;
use Waynestate\Booster\ToolError;

// Works from a clone (vendor next to bin) and as a Composer dependency
// (vendor/waynestate/booster/bin, autoloader two levels up).
$candidates = [__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../autoload.php'];
foreach ($candidates as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        break;
    }
}
if (!isset($autoload) || !is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php not found. Run `composer install` first.\n");
    exit(1);
}

/** @return never */
function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

/** @return never */
function usage(): never
{
    echo <<<'TXT'
Usage: php bin/build-proof.php [options]

Builds site/proof.html, the "Real output" page, from the captured output in
site/proof-data (one .plan.txt and .run.txt per example app, plus meta.json).

  --data <dir>        Proof data folder (default site/proof-data)
  --out <file>        Page to write or check (default site/proof.html)
  --index <file>      Front page whose chrome the proof page borrows (default site/index.html)
  --check             Compare --out with a fresh render; exit 0 when current, 1 when not
  --no-version-check  With --check, do not compare meta.json's version with Booster's
  --capture           Re-capture plan and dry-run output for every app in examples/
  --date <date>       With --capture, the capture date, YYYY-MM-DD (default: today)
  -h, --help          Show this help

Exit codes: 0 the page is current or was written, 1 something failed.

TXT;
    exit(0);
}

/** @return array{data: ?string, out: ?string, index: ?string, date: ?string, check: bool, capture: bool, no_version_check: bool} */
function parseArgs(array $args): array
{
    $options = ['data' => null, 'out' => null, 'index' => null, 'date' => null, 'check' => false, 'capture' => false, 'no_version_check' => false];
    for ($i = 0; $i < count($args); $i++) {
        $arg = $args[$i];
        if ($arg === '--check') {
            $options['check'] = true;
            continue;
        }
        if ($arg === '--capture') {
            $options['capture'] = true;
            continue;
        }
        if ($arg === '--no-version-check') {
            $options['no_version_check'] = true;
            continue;
        }
        if ($arg === '-h' || $arg === '--help') {
            usage();
        }
        if (!str_starts_with($arg, '--')) {
            fail(sprintf('Unexpected argument "%s". Try --help.', $arg));
        }
        $eq = strpos($arg, '=');
        $name = $eq === false ? substr($arg, 2) : substr($arg, 2, $eq - 2);
        $value = $eq === false ? ($args[++$i] ?? null) : substr($arg, $eq + 1);
        if (!is_string($value) || $value === '') {
            fail(sprintf('Missing value for --%s.', $name));
        }
        match ($name) {
            'data' => $options['data'] = $value,
            'out' => $options['out'] = $value,
            'index' => $options['index'] = $value,
            'date' => $options['date'] = $value,
            default => fail(sprintf('Unknown option --%s. Try --help.', $name)),
        };
    }

    return $options;
}

/** Runs one capture command from the repo root and returns its stdout. */
function captureCommand(string $root, string $app, array $args): string
{
    $process = new Process(array_merge([PHP_BINARY, 'bin/booster'], $args), $root, null, null, 900);
    $process->run();
    if (!$process->isSuccessful()) {
        $stderr = trim($process->getErrorOutput());
        fail(sprintf(
            'Capturing %s failed: booster %s exited with %d.%s',
            $app,
            implode(' ', $args),
            $process->getExitCode(),
            $stderr !== '' ? ' ' . $stderr : '',
        ));
    }

    return $process->getOutput();
}

/**
 * --capture: run plan and a dry run for every app in examples/, write the
 * stdout of each into the data folder, and stamp meta.json.
 */
function runCapture(string $root, string $data, ?string $date): void
{
    $date = $date ?? date('Y-m-d');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        fail(sprintf('Bad --date value "%s"; use YYYY-MM-DD.', $date));
    }
    $examples = $root . '/examples';
    if (!is_dir($examples)) {
        fail(sprintf('The examples folder %s does not exist; --capture needs a full Booster checkout.', $examples));
    }
    $apps = [];
    foreach (scandir($examples) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (is_dir($examples . '/' . $entry)) {
            $apps[] = $entry;
        }
    }
    sort($apps);
    if ($apps === []) {
        fail('No example apps found in examples/.');
    }
    if (!is_dir($data) && !mkdir($data, 0775, true)) {
        fail(sprintf('Could not create the proof data folder %s.', $data));
    }
    foreach ($apps as $app) {
        $plan = captureCommand($root, $app, ['plan', '--path=examples/' . $app, '--today=' . $date]);
        if (file_put_contents($data . '/' . $app . '.plan.txt', $plan) === false) {
            fail(sprintf('Could not write %s/%s.plan.txt.', $data, $app));
        }
        $run = captureCommand($root, $app, ['run', '--path=examples/' . $app, '--dry-run', '--today=' . $date]);
        if (file_put_contents($data . '/' . $app . '.run.txt', $run) === false) {
            fail(sprintf('Could not write %s/%s.run.txt.', $data, $app));
        }
    }
    $meta = json_encode(['version' => BoosterApplication::VERSION, 'captured' => $date], JSON_UNESCAPED_SLASHES);
    if (file_put_contents($data . '/meta.json', $meta . "\n") === false) {
        fail(sprintf('Could not write %s/meta.json.', $data));
    }
    echo sprintf('Captured %d example apps into %s.%s', count($apps), $data, "\n");
}

$options = parseArgs(array_slice($argv, 1));

if ($options['check'] && $options['capture']) {
    fail('--check compares the page; --capture writes new data. Pick one.');
}
if ($options['no_version_check'] && !$options['check']) {
    fail('--no-version-check only makes sense with --check.');
}
if ($options['date'] !== null && !$options['capture']) {
    fail('--date only makes sense with --capture.');
}

$root = dirname(__DIR__);
$data = $options['data'] ?? $root . '/site/proof-data';
$out = $options['out'] ?? $root . '/site/proof.html';
$index = $options['index'] ?? $root . '/site/index.html';

if ($options['capture']) {
    runCapture($root, $data, $options['date']);
    exit(0);
}

$indexHtml = file_get_contents($index);
if ($indexHtml === false) {
    fail(sprintf('Could not read %s.', $index));
}

if ($options['check']) {
    $pageHtml = is_file($out) ? (string) file_get_contents($out) : '';
    try {
        $problem = ProofPage::check($data, $indexHtml, $pageHtml, BoosterApplication::VERSION, !$options['no_version_check']);
    } catch (ToolError $e) {
        fail($e->getMessage());
    }
    if ($problem !== null) {
        fwrite(STDERR, $problem . "\n");
        exit(1);
    }
    echo sprintf('%s is current.%s', $out, "\n");
    exit(0);
}

try {
    $page = ProofPage::render($data, $indexHtml);
} catch (ToolError $e) {
    fail($e->getMessage());
}
if (file_put_contents($out, $page) === false) {
    fail(sprintf('Could not write %s.', $out));
}
echo 'Wrote ' . $out . ".\n";
