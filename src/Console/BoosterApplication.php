<?php

declare(strict_types=1);

namespace Waynestate\Booster\Console;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waynestate\Booster\Run\ProcessRunner;
use Waynestate\Booster\Run\SymfonyProcessRunner;

/**
 * The booster CLI. An unknown command exits 2 (a tool error), not Symfony's
 * default 1, so exit 1 always means "the upgrade did not finish".
 */
final class BoosterApplication extends Application
{
    public const VERSION = '1.3.0';

    public function __construct(?ProcessRunner $processes = null)
    {
        parent::__construct('booster', self::VERSION);
        $processes ??= new SymfonyProcessRunner();

        $this->add(new PlanCommand($processes));
        $this->add(new RunCommand($processes));
        $this->add(new HopsCommand());
        $this->add(new DetectCommand());
        $this->add(new InitCommand());
        $this->add(new ScanCommand($processes));
    }

    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        $name = $this->getCommandName($input);
        if ($name !== null && $name !== '' && !$this->has($name)) {
            $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $errors->writeln(sprintf('Command "%s" is not defined.', $name), OutputInterface::OUTPUT_RAW);

            return 2;
        }

        return parent::doRun($input, $output);
    }
}
