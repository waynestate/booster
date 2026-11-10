<?php

declare(strict_types=1);

namespace Waynestate\Booster\Console;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
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
    public const VERSION = '1.16.0';

    /** The first line of the overview that `booster --help` and `booster -h` print. */
    private const OVERVIEW_INTRO = 'Booster moves an app to a supported PHP or Laravel version, one step at a time. Nothing is committed for you.';

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
        $this->add(new ReplayCommand($processes));
    }

    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        $name = $this->getCommandName($input);
        if ($name !== null && $name !== '' && !$this->has($name)) {
            $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $errors->writeln(sprintf('Command "%s" is not defined.', $name), OutputInterface::OUTPUT_RAW);

            return 2;
        }
        if (($name === null || $name === '') && $input->hasParameterOption(['--help', '-h'], true)) {
            // Symfony would answer with the help of the internal list command;
            // newcomers get the command list instead, with one sentence up front.
            $this->printOverview($output);

            return 0;
        }

        return parent::doRun($input, $output);
    }

    /**
     * The overview behind `booster --help`: the same command list as bare
     * `booster`, with the intro sentence as its first line.
     */
    private function printOverview(OutputInterface $output): void
    {
        $buffer = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, $output->isDecorated());
        $this->find('list')->run(new ArrayInput([]), $buffer);
        $output->writeln(self::OVERVIEW_INTRO . "\n" . $buffer->fetch());
    }
}
