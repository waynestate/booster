<?php

declare(strict_types=1);

namespace Waynestate\Booster\Console;

use DateTimeImmutable;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Plan\HttpPackageFetcher;
use Waynestate\Booster\Plan\Plan;
use Waynestate\Booster\Plan\PlanOptions;
use Waynestate\Booster\Plan\Planner;
use Waynestate\Booster\Policy\PolicyLocator;
use Waynestate\Booster\Project\ProjectDetector;
use Waynestate\Booster\ToolError;

/**
 * Options shared by every command that looks at an app, and the code that
 * turns them into a Plan. Commands stay thin: no upgrade rule lives here.
 */
abstract class BaseCommand extends Command
{
    public const EXIT_PASS = 0;

    public const EXIT_FAIL = 1;

    public const EXIT_TOOL_ERROR = 2;

    /**
     * Runs the command and turns a ToolError into exit 2 with the message on stderr.
     */
    final protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            return $this->handle($input, $output);
        } catch (ToolError $e) {
            $this->errorOutput($output)->writeln($e->getMessage(), OutputInterface::OUTPUT_RAW);

            return self::EXIT_TOOL_ERROR;
        }
    }

    abstract protected function handle(InputInterface $input, OutputInterface $output): int;

    protected function addAppOptions(): void
    {
        $this
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Path to the application.', '.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format (text, json).', 'text')
            ->addOption('policy', null, InputOption::VALUE_REQUIRED, "Path to CREED's policy.json (default: BOOSTER_POLICY, then the waynestate/creed package).")
            ->addOption('today', null, InputOption::VALUE_REQUIRED, "Override today's date (YYYY-MM-DD).")
            ->addOption('goal', null, InputOption::VALUE_REQUIRED, 'Shorthand such as php-74-to-84: sets --from-php and --to-php.')
            ->addOption('from-php', null, InputOption::VALUE_REQUIRED, 'The PHP version the app runs on (major.minor).')
            ->addOption('to-php', null, InputOption::VALUE_REQUIRED, 'Target PHP version (default: the newest the policy supports).')
            ->addOption('from-laravel', null, InputOption::VALUE_REQUIRED, 'The Laravel major the app runs on.')
            ->addOption('to-laravel', null, InputOption::VALUE_REQUIRED, 'Target Laravel major (default: the newest the policy supports).')
            ->addOption('to-phpunit', null, InputOption::VALUE_REQUIRED, 'Target PHPUnit major (default: the newest that runs on the target PHP).')
            ->addOption('with-node', null, InputOption::VALUE_NONE, 'Include the Node front-end hops (Node pin, Mix to Vite, Tailwind, Vue) even when you passed a target version')
            ->addOption('sesame', null, InputOption::VALUE_NONE, 'Include the wayne-cas to SESAME swap.')
            ->addOption('skip', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Hop id to leave out (repeatable).')
            ->addOption('no-package-check', null, InputOption::VALUE_NONE, 'Skip the Packagist dependency check (for offline use).')
            ->addOption('until', null, InputOption::VALUE_REQUIRED, 'Cut the plan after this hop id: that step and everything before it stay.')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, "Plan and run just this one hop id; the app must sit on the hop's starting versions.");
    }

    protected function format(InputInterface $input): string
    {
        $format = $this->stringOption($input, 'format') ?? 'text';
        if (!in_array($format, ['text', 'json'], true)) {
            throw new ToolError(sprintf('Invalid format "%s". Allowed values: text, json.', $format));
        }

        return $format;
    }

    protected function today(InputInterface $input): DateTimeImmutable
    {
        $value = $this->stringOption($input, 'today');
        if ($value === null) {
            return new DateTimeImmutable('today');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 || $date === false) {
            throw new ToolError(sprintf('Invalid --today value "%s". Expected format: YYYY-MM-DD.', $value));
        }

        return $date;
    }

    protected function buildPlan(InputInterface $input): Plan
    {
        $env = getenv('BOOSTER_POLICY');
        $policy = PolicyLocator::locate($this->stringOption($input, 'policy'), is_string($env) ? $env : null);
        $project = (new ProjectDetector())->detect($this->stringOption($input, 'path') ?? '.');

        $fromPhp = $this->stringOption($input, 'from-php');
        $toPhp = $this->stringOption($input, 'to-php');
        $goal = $this->stringOption($input, 'goal');
        if ($goal !== null) {
            if (preg_match('/^php-(\d)(\d{1,2})-to-(\d)(\d{1,2})$/', $goal, $m) !== 1) {
                throw new ToolError(sprintf('Invalid --goal "%s". Expected something like php-74-to-84.', $goal));
            }
            $fromPhp ??= $m[1] . '.' . $m[2];
            $toPhp ??= $m[3] . '.' . $m[4];
        }

        $skip = $input->getOption('skip');
        $options = new PlanOptions(
            fromPhp: $fromPhp,
            fromLaravel: $this->stringOption($input, 'from-laravel'),
            toPhp: $toPhp,
            toLaravel: $this->stringOption($input, 'to-laravel'),
            toPhpunit: $this->stringOption($input, 'to-phpunit'),
            sesame: (bool) $input->getOption('sesame'),
            withNode: (bool) $input->getOption('with-node'),
            skip: is_array($skip) ? array_values(array_map('strval', $skip)) : [],
            noPackageCheck: (bool) $input->getOption('no-package-check'),
            until: $this->stringOption($input, 'until'),
            only: $this->stringOption($input, 'only'),
        );

        return (new Planner($policy, new HopRegistry(), new HttpPackageFetcher()))->plan($project, $options, $this->today($input));
    }

    protected function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function errorOutput(OutputInterface $output): OutputInterface
    {
        return $output instanceof \Symfony\Component\Console\Output\ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }
}
