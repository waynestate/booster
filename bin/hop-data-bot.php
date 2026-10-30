#!/usr/bin/env php
<?php

declare(strict_types=1);

use Waynestate\Booster\Refresh\HopDataBot;
use Waynestate\Booster\Run\SymfonyProcessRunner;

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
Usage: php bin/hop-data-bot.php [options]

The hop data robot: refreshes data/versions.json from the public feeds and, when it
changed, pushes the refresh branch with merge request options so a merge request
opens on Booster itself. When nothing changed, it does nothing.

   --dry-run         Print what a run would do (files, branch, merge request title
                     and body); touch no remote
   --today <date>    Date in the merge request title, YYYY-MM-DD (default: the real today)
   -h, --help        Show this help

A real run reads its GitLab environment from the CI variables (CI_SERVER_*,
CI_PROJECT_PATH, CI_DEFAULT_BRANCH, CI_API_V4_URL, CI_PROJECT_ID) and the project
access token from BOOSTER_BOT_TOKEN. A missing variable fails the run before it
touches anything.

Exit codes: 0 nothing to do or the merge request was opened or updated, 1 something failed.

TXT;
    exit(0);
}

/** @return array{dry_run: bool, today: ?string} */
function parseArgs(array $args): array
{
    $options = ['dry_run' => false, 'today' => null];
    for ($i = 0; $i < count($args); $i++) {
        $arg = $args[$i];
        if ($arg === '--dry-run') {
            $options['dry_run'] = true;
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
            'today' => $options['today'] = $value,
            default => fail(sprintf('Unknown option --%s. Try --help.', $name)),
        };
    }

    return $options;
}

$options = parseArgs(array_slice($argv, 1));

if ($options['today'] !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $options['today']) !== 1) {
    fail(sprintf('Bad --today date "%s"; use YYYY-MM-DD.', $options['today']));
}

$bot = new HopDataBot(
    repoRoot: dirname(__DIR__),
    runner: new SymfonyProcessRunner(),
    dryRun: $options['dry_run'],
    today: $options['today'],
);

exit($bot->run());
