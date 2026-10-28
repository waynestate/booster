<?php

declare(strict_types=1);

namespace Waynestate\Booster\Console;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waynestate\Booster\Report\JsonWriter;
use Waynestate\Booster\Report\TextWriter;

final class PlanCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('plan')->setDescription('Show the ordered hops from the app to a supported version. Changes nothing.');
        $this->addAppOptions();
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $format = $this->format($input);
        $plan = $this->buildPlan($input);

        $output->writeln(
            $format === 'json' ? (new JsonWriter())->write($plan->toArray()) : (new TextWriter())->plan($plan),
            OutputInterface::OUTPUT_RAW,
        );

        return self::EXIT_PASS;
    }
}
