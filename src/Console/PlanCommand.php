<?php

declare(strict_types=1);

namespace Waynestate\Booster\Console;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Waynestate\Booster\Hop\HopKind;
use Waynestate\Booster\Plan\Plan;
use Waynestate\Booster\Report\JsonWriter;
use Waynestate\Booster\Report\TextWriter;
use Waynestate\Booster\Run\ProcessRunner;
use Waynestate\Booster\Scan\PhpCompatScan;
use Waynestate\Booster\Scan\RemovalsScan;

final class PlanCommand extends BaseCommand
{
    public function __construct(private readonly ProcessRunner $processes)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('plan')->setDescription('Show the ordered hops from the app to a supported version. Changes nothing.');
        $this->addAppOptions();
        $this->addOption('no-scan', null, InputOption::VALUE_NONE, 'Skip the advisory PHPCompatibility scan of the first PHP hop target.');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $format = $this->format($input);
        $plan = $this->buildPlan($input);

        $scan = null;
        if (!(bool) $input->getOption('no-scan')) {
            $target = $this->firstPhpTarget($plan);
            if ($target !== null) {
                $scan = (new PhpCompatScan($this->processes))->scan($plan->project->path, $target);
            }
        }

        // The removals scan is cheap (no processes), so it always runs, unlike
        // the PHPCompatibility scan behind --no-scan.
        $removalsByStep = [];
        $removalsJson = [];
        $removals = new RemovalsScan();
        foreach ($plan->steps as $step) {
            if ($step->hop->kind !== HopKind::Php || $step->phpBefore === null || $step->phpAfter === null) {
                continue;
            }
            $result = $removals->scan($plan->project->path, $step->phpBefore, $step->phpAfter);
            $removalsByStep[$step->number] = $result;
            $removalsJson[] = ['step' => $step->number, 'hop' => $step->hop->id, ...$result->toArray()];
        }

        if ($format === 'json') {
            $data = $plan->toArray();
            if ($scan !== null) {
                $data['php_compat'] = $scan->toArray();
            }
            $data['removals'] = $removalsJson;
            $text = (new JsonWriter())->write($data);
        } else {
            $writer = new TextWriter();
            $text = $writer->plan($plan, $removalsByStep);
            if ($scan !== null) {
                $text .= "\n\n" . $writer->phpCompat($scan);
            }
        }

        $output->writeln($text, OutputInterface::OUTPUT_RAW);

        return self::EXIT_PASS;
    }

    /**
     * The PHP version the first PHP hop lands on. The scan asks "what breaks
     * there?", because that is the first wall the code will hit; later hops
     * start from code the earlier hops already fixed.
     */
    private function firstPhpTarget(Plan $plan): ?string
    {
        foreach ($plan->steps as $step) {
            if ($step->hop->kind === HopKind::Php && $step->phpAfter !== null) {
                return $step->phpAfter;
            }
        }

        return null;
    }
}
