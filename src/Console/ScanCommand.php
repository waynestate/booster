<?php

declare(strict_types=1);

namespace Waynestate\Booster\Console;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Report\JsonWriter;
use Waynestate\Booster\Report\TextWriter;
use Waynestate\Booster\Run\ProcessRunner;
use Waynestate\Booster\Scan\PhpCompatScan;
use Waynestate\Booster\ToolError;

/**
 * The advisory scan on its own: "what breaks on PHP X.Y?" without a plan.
 * Findings are hints for the person upgrading, so the command exits 0 even
 * when it finds a wall full of them; only a bad --php is a tool error.
 */
final class ScanCommand extends BaseCommand
{
    public function __construct(private readonly ProcessRunner $processes)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('scan')->setDescription('Advisory PHPCompatibility scan: show what will break on a PHP version. Changes nothing.');
        $this
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Path to the application.', '.')
            ->addOption('php', null, InputOption::VALUE_REQUIRED, 'The PHP version to ask about (major.minor).')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format (text, json).', 'text');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $format = $this->format($input);
        $php = $this->stringOption($input, 'php');
        if ($php === null) {
            throw new ToolError('Missing required option --php (the PHP version to ask about, major.minor).');
        }
        if (preg_match('/^\d+\.\d+$/', $php) !== 1 || !in_array($php, HopRegistry::PHP_CHAIN, true)) {
            throw new ToolError(sprintf(
                '--php "%s" is not a PHP version BOOSTER knows (use major.minor, from %s to %s).',
                $php,
                HopRegistry::PHP_CHAIN[0],
                HopRegistry::PHP_CHAIN[count(HopRegistry::PHP_CHAIN) - 1],
            ));
        }

        $scan = (new PhpCompatScan($this->processes))->scan($this->stringOption($input, 'path') ?? '.', $php);

        $output->writeln(
            $format === 'json' ? (new JsonWriter())->write($scan->toArray()) : (new TextWriter())->phpCompat($scan),
            OutputInterface::OUTPUT_RAW,
        );

        return self::EXIT_PASS;
    }
}
