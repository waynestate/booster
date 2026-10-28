<?php

declare(strict_types=1);

namespace Waynestate\Booster\Console;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Skill\SkillRenderer;
use Waynestate\Booster\ToolError;

final class InitCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('init')->setDescription('Write the coding-tab playbooks (.agents/skills). With --check, fail when they are stale.');
        $this
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Path to the application.', '.')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Skills directory (default: <path>/.agents/skills).')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Change nothing; exit 1 when a playbook is missing or out of date.');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $path = $this->stringOption($input, 'path') ?? '.';
        $dir = rtrim($this->stringOption($input, 'dir') ?? $path . '/.agents/skills', '/\\');
        $files = (new SkillRenderer())->render(new HopRegistry());
        $check = (bool) $input->getOption('check');

        $stale = [];
        foreach ($files as $relative => $content) {
            $target = $dir . '/' . $relative;
            if (is_file($target) && file_get_contents($target) === $content) {
                continue;
            }
            $stale[] = $relative;
            if ($check) {
                continue;
            }
            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
                throw new ToolError(sprintf('Could not create "%s".', dirname($target)));
            }
            if (file_put_contents($target, $content) === false) {
                throw new ToolError(sprintf('Could not write "%s".', $target));
            }
        }

        if ($check) {
            foreach ($stale as $relative) {
                $output->writeln('stale: ' . $relative, OutputInterface::OUTPUT_RAW);
            }
            $output->writeln($stale === [] ? 'Playbooks are current.' : 'Run `booster init` to refresh them.', OutputInterface::OUTPUT_RAW);

            return $stale === [] ? self::EXIT_PASS : self::EXIT_FAIL;
        }

        $output->writeln(sprintf('Wrote %d playbooks, %d changed.', count($files), count($stale)), OutputInterface::OUTPUT_RAW);

        return self::EXIT_PASS;
    }
}
