<?php

declare(strict_types=1);

namespace Waynestate\Booster\Console;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Plan\Plan;
use Waynestate\Booster\Report\JsonWriter;
use Waynestate\Booster\Report\TextWriter;
use Waynestate\Booster\Run\ComposerEditor;
use Waynestate\Booster\Run\GateImage;
use Waynestate\Booster\Run\HopExecutor;
use Waynestate\Booster\Run\NodeRunner;
use Waynestate\Booster\Run\ProcessRunner;
use Waynestate\Booster\Run\ProctorRunner;
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
            ->addOption('max-changes', null, InputOption::VALUE_REQUIRED, 'Hard limit on files changed per hop, with no prompt (0 = no limit). Without it, a hop over 200 files asks first, or stops when not interactive.', null)
            ->addOption('memory-limit', null, InputOption::VALUE_REQUIRED, 'Memory limit for the Rector process (for example 2G), or -1 for no limit.', RectorRunner::DEFAULT_MEMORY_LIMIT)
            ->addOption('skip-gate', null, InputOption::VALUE_NONE, 'Do not run the tests in the target image after each hop, or the PROCTOR check at the end.')
            ->addOption('image', null, InputOption::VALUE_REQUIRED, 'Run the gate in this Docker image instead of building booster-target (it needs php and composer on PATH): one name for every PHP version, a {php} template such as registry/php-apache:{php}, or a map such as 8.0=foo:1,8.1=bar:2.')
            ->addOption('allow-failing-baseline', null, InputOption::VALUE_NONE, 'Continue even when the baseline (the app\'s tests on its current PHP) fails; later gate failures are labelled as pre-existing.')
            ->addOption('allow-dirty', null, InputOption::VALUE_NONE, 'Run even when the working copy has uncommitted changes.')
            ->addOption('test-command', null, InputOption::VALUE_REQUIRED, 'Command that runs the app tests inside the image (default: composer test, else vendor/bin/phpunit).')
            ->addOption('proctor-command', null, InputOption::VALUE_REQUIRED, 'Extra command run in the image after the tests, for example a PROCTOR check.')
            ->addOption('proctor-advisory', null, InputOption::VALUE_NONE, 'Report the PROCTOR result but do not fail the run on it.');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $format = $this->format($input);

        $capRaw = $input->getOption('max-changes');
        $cap = null;
        if ($capRaw !== null) {
            if (preg_match('/^\d+$/', (string) $capRaw) !== 1) {
                throw new ToolError(sprintf('Invalid --max-changes value "%s". Expected a whole number.', $capRaw));
            }
            $cap = (int) $capRaw;
        }

        $memoryLimit = $this->stringOption($input, 'memory-limit') ?? RectorRunner::DEFAULT_MEMORY_LIMIT;
        RectorRunner::assertMemoryLimit($memoryLimit);

        $plan = $this->buildPlan($input);
        $gateImage = GateImage::fromOption($this->stringOption($input, 'image'));
        if ($gateImage !== null) {
            $this->assertImageCoversPlan($gateImage, $plan);
        }
        $registry = new HopRegistry();
        $executor = new HopExecutor(
            new RectorRunner($this->processes),
            new ComposerEditor(),
            new TargetImageRunner($this->processes),
            $this->processes,
            new NodeRunner($this->processes, (int) $registry->nodePinTarget()),
            $registry,
            new ProctorRunner($this->processes),
        );
        // The prompt only makes sense where a person is watching: text output on an interactive terminal. JSON and
        // --no-interaction runs stop at the threshold instead of asking nobody.
        $confirm = null;
        if ($format === 'text' && $input->isInteractive()) {
            $confirm = static function (int $count, array $summary) use ($input, $output): bool {
                foreach ($summary as $line) {
                    $output->writeln($line, OutputInterface::OUTPUT_RAW);
                }

                return (new SymfonyStyle($input, $output))->confirm('Apply this hop?', false);
            };
        }

        $report = $executor->execute($plan, new RunOptions(
            dryRun: (bool) $input->getOption('dry-run'),
            skipGate: (bool) $input->getOption('skip-gate'),
            allowDirty: (bool) $input->getOption('allow-dirty'),
            allowFailingBaseline: (bool) $input->getOption('allow-failing-baseline'),
            maxChanges: $cap,
            confirm: $confirm,
            testCommand: $this->stringOption($input, 'test-command'),
            gateImage: $gateImage,
            proctorCommand: $this->stringOption($input, 'proctor-command'),
            memoryLimit: $memoryLimit,
            proctorAdvisory: (bool) $input->getOption('proctor-advisory'),
        ));

        $output->writeln(
            $format === 'json' ? (new JsonWriter())->write($report->toArray()) : (new TextWriter())->run($report),
            OutputInterface::OUTPUT_RAW,
        );

        return $report->failed() ? self::EXIT_FAIL : self::EXIT_PASS;
    }

    /**
     * A --image map must carry an image for every PHP version the run will
     * gate on: the baseline's PHP and each hop's destination. Checked here so
     * a missing version stops the run before the first hop, not mid-upgrade.
     */
    private function assertImageCoversPlan(GateImage $image, Plan $plan): void
    {
        $versions = [];
        if ($plan->steps === []) {
            $versions[] = $plan->project->phpVersion;
        } else {
            // The same two lookups HopExecutor makes: the baseline starts from
            // the first step's starting PHP, and each gate uses its step's phpAfter.
            $versions[] = $plan->steps[0]->phpBefore ?? $plan->project->phpVersion;
            foreach ($plan->steps as $step) {
                $versions[] = $step->phpAfter;
            }
        }
        foreach ($versions as $php) {
            if ($php !== null && $php !== '') {
                $image->forPhp($php);
            }
        }
    }
}
