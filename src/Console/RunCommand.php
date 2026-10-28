<?php

declare(strict_types=1);

namespace Waynestate\Booster\Console;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Waynestate\Booster\Report\JsonWriter;
use Waynestate\Booster\Report\TextWriter;
use Waynestate\Booster\Run\ComposerEditor;
use Waynestate\Booster\Run\HopExecutor;
use Waynestate\Booster\Run\ProcessRunner;
use Waynestate\Booster\Run\RectorRunner;
use Waynestate\Booster\Run\RunOptions;
use Waynestate\Booster\Run\TargetImageRunner;
use Waynestate\Booster\ToolError;

final class RunCommand extends BaseCommand
{
    public function __construct(private readonly ProcessRunner $processes)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('run')->setDescription('Run the plan: Rector, composer.json edits and a test gate per hop. Edits the working copy only.');
        $this->addAppOptions();
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show the diffs Rector would make and change nothing.')
            ->addOption('max-changes', null, InputOption::VALUE_REQUIRED, 'Stop a hop that would change more than this many files.', '50')
            ->addOption('skip-gate', null, InputOption::VALUE_NONE, 'Do not run the tests in the target image after each hop.')
            ->addOption('allow-failing-baseline', null, InputOption::VALUE_NONE, 'Continue even when the baseline (the app\'s tests on its current PHP) fails; later gate failures are labelled as pre-existing.')
            ->addOption('allow-dirty', null, InputOption::VALUE_NONE, 'Run even when the working copy has uncommitted changes.')
            ->addOption('test-command', null, InputOption::VALUE_REQUIRED, 'Command that runs the app tests inside the image (default: composer test, else vendor/bin/phpunit).')
            ->addOption('proctor-command', null, InputOption::VALUE_REQUIRED, 'Extra command run in the image after the tests, for example a PROCTOR check.');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $format = $this->format($input);

        $cap = (string) $input->getOption('max-changes');
        if (preg_match('/^\d+$/', $cap) !== 1) {
            throw new ToolError(sprintf('Invalid --max-changes value "%s". Expected a whole number.', $cap));
        }

        $plan = $this->buildPlan($input);
        $executor = new HopExecutor(
            new RectorRunner($this->processes),
            new ComposerEditor(),
            new TargetImageRunner($this->processes),
            $this->processes,
        );
        $report = $executor->execute($plan, new RunOptions(
            dryRun: (bool) $input->getOption('dry-run'),
            skipGate: (bool) $input->getOption('skip-gate'),
            allowDirty: (bool) $input->getOption('allow-dirty'),
            allowFailingBaseline: (bool) $input->getOption('allow-failing-baseline'),
            maxChanges: (int) $cap,
            testCommand: $this->stringOption($input, 'test-command'),
            proctorCommand: $this->stringOption($input, 'proctor-command'),
        ));

        $output->writeln(
            $format === 'json' ? (new JsonWriter())->write($report->toArray()) : (new TextWriter())->run($report),
            OutputInterface::OUTPUT_RAW,
        );

        return $report->failed() ? self::EXIT_FAIL : self::EXIT_PASS;
    }
}
