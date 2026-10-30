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
use Waynestate\Booster\Scan\RemovalsScan;
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
            ->addOption('removals', null, InputOption::VALUE_NONE, 'List uses of functions and constructs removed in PHP up to --php, instead of the PHPCompatibility scan.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format (text, json).', 'text');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $format = $this->format($input);
        $php = $this->stringOption($input, 'php');
        if ($php === null) {
            throw new ToolError('Missing required option --php (the PHP version to ask about, major.minor).');
        }
        $chain = (new HopRegistry())->phpChain();
        if (preg_match('/^\d+\.\d+$/', $php) !== 1 || !in_array($php, $chain, true)) {
            throw new ToolError(sprintf(
                '--php "%s" is not a PHP version BOOSTER knows (use major.minor, from %s to %s).',
                $php,
                $chain[0],
                $chain[count($chain) - 1],
            ));
        }

        if ((bool) $input->getOption('removals')) {
            // Everything removed between the oldest PHP Booster plans (the
            // chain floor) and --php, no processes involved.
            $result = (new RemovalsScan())->scan($this->stringOption($input, 'path') ?? '.', $chain[0], $php);

            $output->writeln(
                $format === 'json' ? (new JsonWriter())->write(['removals' => $result->toArray()]) : implode("\n", (new TextWriter())->removals($result, null)),
                OutputInterface::OUTPUT_RAW,
            );

            return self::EXIT_PASS;
        }

        $scan = (new PhpCompatScan($this->processes))->scan($this->stringOption($input, 'path') ?? '.', $php);

        $output->writeln(
            $format === 'json' ? (new JsonWriter())->write($scan->toArray()) : (new TextWriter())->phpCompat($scan),
            OutputInterface::OUTPUT_RAW,
        );

        return self::EXIT_PASS;
    }
}
