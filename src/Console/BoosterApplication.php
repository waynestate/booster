<?php

declare(strict_types=1);

namespace Waynestate\Booster\Console;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use Waynestate\Booster\Run\ProcessRunner;
use Waynestate\Booster\Run\SymfonyProcessRunner;
use Waynestate\Booster\Update\HttpUpdateFetcher;
use Waynestate\Booster\Update\InstallKind;
use Waynestate\Booster\Update\UpdateCache;
use Waynestate\Booster\Update\UpdateNotice;

/**
 * The booster CLI. An unknown command exits 2 (a tool error), not Symfony's
 * default 1, so exit 1 always means "the upgrade did not finish".
 */
final class BoosterApplication extends Application
{
    public const VERSION = '1.21.0';

    /** The first line of the overview that `booster --help` and `booster -h` print. */
    private const OVERVIEW_INTRO = 'Booster moves an app to a supported PHP or Laravel version, one step at a time. Nothing is committed for you.';

    public function __construct(?ProcessRunner $processes = null, private readonly ?UpdateNotice $updateNotice = null)
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

        // Symfony 7 dropped Application::getDispatcher(), so the notice hooks
        // the run here instead of a TERMINATE listener.
        $exitCode = parent::doRun($input, $output);
        $this->finishWithUpdateNotice($output);

        return $exitCode;
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

    /**
     * After every command, one stderr line when a newer stable release exists.
     * It never throws, never changes the exit code, and writes nothing to
     * stdout (including --format=json output).
     */
    private function finishWithUpdateNotice(OutputInterface $output): void
    {
        try {
            if (! $output instanceof ConsoleOutputInterface) {
                // No separate stderr stream to write to; stdout must stay untouched.
                return;
            }

            $notice = $this->updateNotice ?? new UpdateNotice(
                fetcher: new HttpUpdateFetcher(),
                cache: new UpdateCache(UpdateCache::defaultPath('booster')),
                install: InstallKind::detected(),
                tool: 'booster',
                version: self::VERSION,
                clock: static fn(): int => time(),
            );
            $notice->run($output->getErrorOutput());
        } catch (Throwable) {
            // The notice is advisory; the command's own result wins.
        }
    }
}
