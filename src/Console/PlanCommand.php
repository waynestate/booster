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
use Waynestate\Booster\Scan\BehaviourEntry;
use Waynestate\Booster\Scan\BehaviourScan;
use Waynestate\Booster\Scan\PhpCompatScan;
use Waynestate\Booster\Scan\PhpStanScan;
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
        $this->addOption('no-scan', null, InputOption::VALUE_NONE, 'Skip the advisory scans (PHPCompatibility and PHPStan).');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $format = $this->format($input);
        $plan = $this->buildPlan($input);

        $scan = null;
        $phpstan = null;
        if (!(bool) $input->getOption('no-scan')) {
            $target = $this->firstPhpTarget($plan);
            if ($target !== null) {
                $scan = (new PhpCompatScan($this->processes))->scan($plan->project->path, $target);
            }
            if ($plan->targetPhp !== null) {
                // The PHPStan scan asks about the destination, not the first
                // wall: by then the earlier hops have fixed what they fix, so
                // this is what static analysis will say where the plan lands.
                $phpstan = (new PhpStanScan($this->processes))->scan($plan->project->path, $plan->targetPhp);
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

        // The behaviour scan is cheap (no processes), so it always runs, like
        // the removals scan: curated silent changes per PHP and Laravel hop,
        // plus the package rows on the last step, where the lock lands.
        $behaviourByStep = [];
        $behaviourJson = [];
        $behaviour = new BehaviourScan();
        $require = BehaviourScan::composerRequire($plan->project->path);
        foreach ($plan->steps as $step) {
            $isLast = $step->number === count($plan->steps);
            if ($step->hop->kind !== HopKind::Php && $step->hop->kind !== HopKind::Laravel && !$isLast) {
                continue;
            }
            $result = $behaviour->scan($plan->project->path, $step->hop->id, $require);
            if (!$isLast) {
                $result = $result->withoutPackages();
            }
            if ($result->entries === []) {
                continue;
            }
            $behaviourByStep[$step->number] = $result;
            $behaviourJson[] = [
                'step' => $step->number,
                'hop' => $step->hop->id,
                'entries' => array_map(static fn(BehaviourEntry $entry): array => $entry->toArray(), $result->entries),
            ];
        }

        if ($format === 'json') {
            $data = $plan->toArray();
            if ($scan !== null) {
                $data['php_compat'] = $scan->toArray();
            }
            if ($phpstan !== null) {
                $data['phpstan'] = $phpstan->toArray();
            }
            $data['removals'] = $removalsJson;
            // Always present, even when empty, so consumers can rely on the key.
            $data['behaviour_changes'] = $behaviourJson;
            $text = (new JsonWriter())->write($data);
        } else {
            $writer = new TextWriter();
            $text = $writer->plan($plan, $removalsByStep, $behaviourByStep);
            if ($scan !== null) {
                $text .= "\n\n" . $writer->phpCompat($scan);
            }
            if ($phpstan !== null) {
                $text .= "\n\n" . $writer->phpStan($phpstan);
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
