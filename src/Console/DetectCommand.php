<?php

declare(strict_types=1);

namespace Waynestate\Booster\Console;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Waynestate\Booster\Project\ProjectDetector;
use Waynestate\Booster\Report\JsonWriter;
use Waynestate\Booster\Report\TextWriter;

final class DetectCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('detect')->setDescription('Say which of the five stack permutations an app is, and its PHP, Laravel and Vue versions.');
        $this
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Path to the application.', '.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format (text, json).', 'text');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $project = (new ProjectDetector())->detect($this->stringOption($input, 'path') ?? '.');

        if ($this->format($input) === 'json') {
            $output->writeln((new JsonWriter())->write($project->toArray()), OutputInterface::OUTPUT_RAW);

            return self::EXIT_PASS;
        }

        foreach ($project->toArray() as $key => $value) {
            $shown = is_bool($value) ? ($value ? 'yes' : 'no') : (string) ($value ?? 'unknown');
            $output->writeln(sprintf('%-14s %s', $key, TextWriter::clean($shown)), OutputInterface::OUTPUT_RAW);
        }

        return self::EXIT_PASS;
    }
}
