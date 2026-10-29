#!/usr/bin/env php
<?php

declare(strict_types=1);

use Waynestate\Booster\Policy\PolicyLocator;
use Waynestate\Booster\Refresh\HopDataRefresher;
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
Usage: php bin/refresh-hop-data.php [options]

Recomputes data/versions.json from the public feeds and CREED's version policy.

  --offline <dir>   Read the feeds from <dir> (frozen copies) instead of fetching them
  --policy <path>   Use this policy.json instead of the bundled snapshot (BOOSTER_POLICY also works)
  --today <date>    Date to judge the policy against, YYYY-MM-DD (default: the real today)
  --dry-run         Print what would change; do not write the file
  --check           Like --dry-run, for CI: exit 3 when the file is stale
  -h, --help        Show this help

Exit codes: 0 the file is current, 3 the file changed or would change, 1 something failed.

TXT;
}

/** @return array{offline: ?string, policy: ?string, today: ?string, dry_run: bool, check: bool} */
function parseArgs(array $args): array
{
    $options = ['offline' => null, 'policy' => null, 'today' => null, 'dry_run' => false, 'check' => false];
    for ($i = 0; $i < count($args); $i++) {
        $arg = $args[$i];
        if ($arg === '--dry-run') {
            $options['dry_run'] = true;
            continue;
        }
        if ($arg === '--check') {
            $options['check'] = true;
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
            'offline' => $options['offline'] = $value,
            'policy' => $options['policy'] = $value,
            'today' => $options['today'] = $value,
            default => fail(sprintf('Unknown option --%s. Try --help.', $name)),
        };
    }

    return $options;
}

/** @return array<mixed> */
function decodeJsonFile(string $path, string $label): array
{
    if (!is_file($path) || !is_readable($path)) {
        fail(sprintf('%s "%s" does not exist or is not readable.', ucfirst($label), $path));
    }
    $raw = file_get_contents($path);
    $data = json_decode($raw === false ? '' : $raw, true);
    if (!is_array($data)) {
        fail(sprintf('%s "%s" is not a JSON object.', ucfirst($label), $path));
    }

    return $data;
}

/** @return array<mixed> */
function fetchFeedJson(string $url): array
{
    $context = stream_context_create(['http' => ['timeout' => 30, 'header' => "User-Agent: waynestate-booster refresh-hop-data\r\n"]]);
    $raw = @file_get_contents($url, false, $context);
    if ($raw === false) {
        fail(sprintf('Could not fetch %s.', $url));
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        fail(sprintf('%s did not return a JSON object.', $url));
    }

    return $data;
}

/** @return string */
function showValue(mixed $value): string
{
    if ($value === null) {
        return '(none)';
    }
    if (is_array($value)) {
        return (string) json_encode($value);
    }

    return (string) $value;
}

/**
 * One line per changed key, sub-keys included ("laravel_phpunit[11]: 10 -> 11").
 *
 * @param array<string, mixed> $old
 * @param array<string, mixed> $new
 *
 * @return list<string>
 */
function describeChanges(array $old, array $new): array
{
    $lines = [];
    foreach (array_keys($new) as $key) {
        $o = $old[$key] ?? null;
        $n = $new[$key];
        if ($o === $n) {
            continue;
        }
        if (is_array($o) && is_array($n) && !array_is_list($o) && !array_is_list($n)) {
            foreach (array_unique(array_merge(array_map(strval(...), array_keys($o)), array_map(strval(...), array_keys($n)))) as $sub) {
                $os = $o[$sub] ?? null;
                $ns = $n[$sub] ?? null;
                if ($os !== $ns) {
                    $lines[] = sprintf('%s[%s]: %s -> %s', $key, $sub, showValue($os), showValue($ns));
                }
            }
            continue;
        }
        $lines[] = sprintf('%s: %s -> %s', $key, showValue($o), showValue($n));
    }

    return $lines;
}

$options = parseArgs(array_slice($argv, 1));

$dataPath = dirname(__DIR__) . '/data/versions.json';
$existing = decodeJsonFile($dataPath, 'hop data file');

try {
    $policy = PolicyLocator::locate($options['policy'], getenv('BOOSTER_POLICY') ?: null);
} catch (ToolError $e) {
    fail($e->getMessage());
}

$todayString = $options['today'] ?? date('Y-m-d');
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $todayString) !== 1) {
    fail(sprintf('Bad --today date "%s"; use YYYY-MM-DD.', $todayString));
}
$today = new DateTimeImmutable($todayString);

$files = [
    HopDataRefresher::FEED_PHPUNIT => 'packagist-phpunit-phpunit.json',
    HopDataRefresher::FEED_LARAVEL_FRAMEWORK => 'packagist-laravel-framework.json',
    HopDataRefresher::FEED_LARAVEL_SKELETON => 'packagist-laravel-laravel.json',
    HopDataRefresher::FEED_ENDOFLIFE => 'endoflife-laravel.json',
    HopDataRefresher::FEED_VITE => 'npm-vite-dist-tags.json',
    HopDataRefresher::FEED_LARAVEL_VITE_PLUGIN => 'npm-laravel-vite-plugin-dist-tags.json',
    HopDataRefresher::FEED_VUE_PLUGIN => 'npm-vitejs-plugin-vue-dist-tags.json',
];
$urls = [
    HopDataRefresher::FEED_PHPUNIT => 'https://repo.packagist.org/p2/phpunit/phpunit.json',
    HopDataRefresher::FEED_LARAVEL_FRAMEWORK => 'https://repo.packagist.org/p2/laravel/framework.json',
    HopDataRefresher::FEED_LARAVEL_SKELETON => 'https://repo.packagist.org/p2/laravel/laravel.json',
    HopDataRefresher::FEED_ENDOFLIFE => 'https://endoflife.date/api/laravel.json',
    HopDataRefresher::FEED_VITE => 'https://registry.npmjs.org/vite',
    HopDataRefresher::FEED_LARAVEL_VITE_PLUGIN => 'https://registry.npmjs.org/laravel-vite-plugin',
    HopDataRefresher::FEED_VUE_PLUGIN => 'https://registry.npmjs.org/@vitejs/plugin-vue',
];

$feeds = [];
if ($options['offline'] !== null) {
    if (!is_dir($options['offline'])) {
        fail(sprintf('--offline directory "%s" does not exist.', $options['offline']));
    }
    foreach ($files as $name => $file) {
        $feeds[$name] = decodeJsonFile($options['offline'] . '/' . $file, "feed $name");
    }
} else {
    foreach ($urls as $name => $url) {
        $payload = fetchFeedJson($url);
        if (str_starts_with($name, 'npm-')) {
            $tags = $payload['dist-tags'] ?? null;
            if (!is_array($tags)) {
                fail(sprintf('%s has no dist-tags object.', $url));
            }
            $feeds[$name] = $tags;
        } else {
            $feeds[$name] = $payload;
        }
    }
}

try {
    $refreshed = (new HopDataRefresher($policy))->refresh($existing, $feeds, $today);
} catch (ToolError $e) {
    fail($e->getMessage());
}

$source = $options['offline'] !== null ? 'the frozen feeds in ' . $options['offline'] : 'the live feeds';
if ($refreshed === $existing) {
    echo sprintf("data/versions.json is current (checked against %s as of %s).\n", $source, $todayString);
    exit(0);
}

$lines = describeChanges($existing, $refreshed);
if ($options['dry_run'] || $options['check']) {
    echo 'data/versions.json would change:' . "\n";
    foreach ($lines as $line) {
        echo '  ' . $line . "\n";
    }
    exit(3);
}

if (file_put_contents($dataPath, (string) json_encode($refreshed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false) {
    fail(sprintf('Could not write %s.', $dataPath));
}
echo 'data/versions.json updated:' . "\n";
foreach ($lines as $line) {
    echo '  ' . $line . "\n";
}
exit(3);
