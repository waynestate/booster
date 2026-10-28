<?php

declare(strict_types=1);

namespace Waynestate\Booster\Console;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Waynestate\Booster\Hop\Hop;
use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Report\JsonWriter;

final class HopsCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('hops')->setDescription('List every hop BOOSTER knows.');
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format (text, json).', 'text');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $hops = (new HopRegistry())->all();
        if ($this->format($input) === 'json') {
            $output->writeln((new JsonWriter())->write(['hops' => array_map(static fn(Hop $h): array => $h->toArray(), $hops)]), OutputInterface::OUTPUT_RAW);

            return self::EXIT_PASS;
        }

        foreach ($hops as $hop) {
            $output->writeln(sprintf('%-18s %s%s', $hop->id, $hop->summary, $hop->isManual() ? ' [playbook]' : ''), OutputInterface::OUTPUT_RAW);
        }

        return self::EXIT_PASS;
    }
}
