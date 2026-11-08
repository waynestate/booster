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
        $this->setName('hops')->setDescription('List every hop Booster knows.');
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
        $output->writeln('', OutputInterface::OUTPUT_RAW);
        $output->writeln(implode("\n", [
            'These are every step Booster knows; `plan` picks the ones your app needs.',
            'Use an id with --until=<id>, --only=<id> or --skip=<id>. [playbook] steps are done by hand, not by Booster.',
        ]), OutputInterface::OUTPUT_RAW);

        return self::EXIT_PASS;
    }
}
