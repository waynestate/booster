<?php

declare(strict_types=1);

namespace Waynestate\Booster\Console;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Waynestate\Booster\Hop\EngineId;
use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Plan\Plan;
use Waynestate\Booster\Replay\ReplayFile;
use Waynestate\Booster\Replay\ReplayGate;
use Waynestate\Booster\Report\HopRecord;
use Waynestate\Booster\Report\JsonWriter;
use Waynestate\Booster\Report\TextWriter;
use Waynestate\Booster\Run\CacheMount;
use Waynestate\Booster\Run\Cake\CakeUpgradeMigration;
use Waynestate\Booster\Run\CakeUpgradeRunner;
use Waynestate\Booster\Run\ComposerEditor;
use Waynestate\Booster\Run\FrameworkEngine;
use Waynestate\Booster\Run\GateImage;
use Waynestate\Booster\Run\HopExecutor;
use Waynestate\Booster\Run\NodeRunner;
use Waynestate\Booster\Run\ProcessRunner;
use Waynestate\Booster\Run\ProctorRunner;
use Waynestate\Booster\Run\RectorRunner;
use Waynestate\Booster\Run\RunOptions;
use Waynestate\Booster\Run\RunReport;
use Waynestate\Booster\Run\Slim\SlimUpgradeMigration;
use Waynestate\Booster\Run\StepResult;
use Waynestate\Booster\Run\TargetImageRunner;
use Waynestate\Booster\ToolError;

final class RunCommand extends BaseCommand
{
    public function __construct(ProcessRunner $processes)
    {
        parent::__construct($processes);
    }

    protected function configure(): void
    {
        $this->setName('run')->setDescription('Run the plan: Rector, composer.json edits and a test gate per hop. Edits the working copy only.');
        $this->addAppOptions();
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show the diffs Rector would make and change nothing.')
            ->addOption('max-changes', null, InputOption::VALUE_REQUIRED, 'Hard limit on files changed per hop, with no prompt (0 = no limit). Without it, a hop over 200 files asks first, or stops when not interactive.', null)
            ->addOption('test-env', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Environment variable (KEY=VALUE) passed to the baseline and every gate container with -e; repeat it. Values never appear in the report.')
            ->addOption('memory-limit', null, InputOption::VALUE_REQUIRED, 'Memory limit for the Rector process (for example 2G), or -1 for no limit.', RectorRunner::DEFAULT_MEMORY_LIMIT)
            ->addOption('skip-gate', null, InputOption::VALUE_NONE, 'Do not run the tests in the target image after each hop, or the PROCTOR check at the end.')
            ->addOption('image', null, InputOption::VALUE_REQUIRED, 'Run the gate in this Docker image instead of building booster-target (it needs php and composer on PATH): one name for every PHP version, a {php} template such as registry/php-apache:{php}, or a map such as 8.0=foo:1,8.1=bar:2.')
            ->addOption('allow-failing-baseline', null, InputOption::VALUE_NONE, 'Continue even when the baseline (the app\'s tests on its current PHP) fails; later gate failures are labeled as pre-existing.')
            ->addOption('allow-dirty', null, InputOption::VALUE_NONE, 'Run even when the working copy has uncommitted changes.')
            ->addOption('test-command', null, InputOption::VALUE_REQUIRED, 'Command that runs the app tests inside the image (default: composer test, else vendor/bin/phpunit).')
            ->addOption('proctor-command', null, InputOption::VALUE_REQUIRED, 'Extra command run in the image after the tests, for example a PROCTOR check.')
            ->addOption('proctor-advisory', null, InputOption::VALUE_NONE, 'Report the PROCTOR result but do not fail the run on it.')
            ->addOption('replay', null, InputOption::VALUE_OPTIONAL, 'Also gate on request replay: record how the app answers before the first hop, replay after each hop, fail on a changed answer. Takes the request file (default: booster-replay.json).', false)
            ->addOption('replay-out', null, InputOption::VALUE_REQUIRED, 'Folder for the replay transcripts (default: a new booster-replay-<app>-<time> folder in the system temp folder).')
            ->addOption('moves', null, InputOption::VALUE_REQUIRED, 'Strangler runs after the mount: move this many units onto Laravel this run, in move order (default 1).')
            ->addOption('route', null, InputOption::VALUE_REQUIRED, 'Strangler runs after the mount: move just this unit (its id from booster-strangler.json, for example "GET /users/{id}").')
            ->addOption('hand', null, InputOption::VALUE_NONE, 'Strangler runs, with --route: a person ported this unit; check route:list, flip it in the route table and gate it.')
            ->addOption('finish', null, InputOption::VALUE_NONE, 'Strangler runs, once no unit is left in legacy: delete legacy/, put back the stock front controller, move /login to Laravel.');
    }

    /**
     * The strangler's later-run options, checked against each other: --hand
     * needs --route, and --finish, --moves and --route are three different
     * runs.
     *
     * @return array{0: ?int, 1: ?string, 2: bool, 3: bool}
     */
    private static function stranglerOptions(InputInterface $input): array
    {
        $movesRaw = $input->getOption('moves');
        $moves = null;
        if ($movesRaw !== null) {
            if (preg_match('/^[1-9]\d*$/', (string) $movesRaw) !== 1) {
                throw new ToolError(sprintf('Invalid --moves value "%s". Expected a whole number of units, 1 or more.', $movesRaw));
            }
            $moves = (int) $movesRaw;
        }
        $routeRaw = $input->getOption('route');
        $route = is_string($routeRaw) && trim($routeRaw) !== '' ? trim($routeRaw) : null;
        $hand = (bool) $input->getOption('hand');
        $finish = (bool) $input->getOption('finish');
        if ($hand && $route === null) {
            throw new ToolError('--hand needs --route "<id>": the unit a person ported (the ids are in booster-strangler.json).');
        }
        if ($finish && ($moves !== null || $route !== null)) {
            throw new ToolError('--finish is a run of its own: drop --moves and --route.');
        }
        if ($moves !== null && $route !== null) {
            throw new ToolError('--moves and --route are two ways to pick units: use one.');
        }

        return [$moves, $route, $hand, $finish];
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

        $testEnv = [];
        foreach ((array) $input->getOption('test-env') as $pair) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', (string) $pair) !== 1) {
                throw new ToolError(sprintf('Invalid --test-env value "%s". Expected KEY=VALUE, where KEY is a valid environment variable name.', $pair));
            }
            $testEnv[] = $pair;
        }

        $memoryLimit = $this->stringOption($input, 'memory-limit') ?? RectorRunner::DEFAULT_MEMORY_LIMIT;
        RectorRunner::assertMemoryLimit($memoryLimit);

        // --replay with no value means the default request file; absent means off.
        $replayOption = $input->getOption('replay');
        $replay = null;
        if ($replayOption !== false) {
            if ((bool) $input->getOption('skip-gate')) {
                throw new ToolError('--replay runs as part of the gate, so it cannot go with --skip-gate. Drop --skip-gate, or run booster replay check on its own.');
            }
            $appPath = realpath($this->stringOption($input, 'path') ?? '.');
            $replay = ReplayFile::load(ReplayFile::locate($appPath === false ? '.' : $appPath, is_string($replayOption) && $replayOption !== '' ? $replayOption : null));
            if ($replay->todos !== []) {
                throw new ToolError(ReplayGate::todoMessage($replay));
            }
        }

        [$moves, $route, $hand, $finish] = self::stranglerOptions($input);

        $plan = $this->buildPlan($input);
        $gateImage = GateImage::fromOption($this->stringOption($input, 'image'));
        if ($gateImage !== null) {
            $this->assertImageCoversPlan($gateImage, $plan);
        }
        // The registry the plan was built with, so the Node pin and its container match the plan.
        $registry = $this->planRegistry ?? new HopRegistry();
        // The download caches are opt-in: unset, every gate starts empty, as before.
        $composerEditor = new ComposerEditor();
        $executor = new HopExecutor(
            new RectorRunner($this->processes),
            $composerEditor,
            new TargetImageRunner($this->processes, composerCache: CacheMount::fromEnvironment(CacheMount::COMPOSER)),
            $this->processes,
            new NodeRunner($this->processes, (int) $registry->nodePinTarget(), npmCache: CacheMount::fromEnvironment(CacheMount::NPM)),
            $registry,
            new ProctorRunner($this->processes),
            frameworkEngines: self::frameworkEngines($this->processes, $composerEditor),
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

        // One line per hop to standard error, so a long run shows which hop it
        // is in while stdout (text or JSON) stays the report alone.
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : null;
        $progress = null;
        if ($errorOutput !== null) {
            $progress = static function (string $line) use ($errorOutput): void {
                $errorOutput->writeln($line);
            };
        } elseif (!$output instanceof BufferedOutput && !$output instanceof NullOutput) {
            // A real stream without a console error output; skip test buffers so
            // captured stdout stays clean.
            $progress = static function (string $line): void {
                fwrite(STDERR, $line . PHP_EOL);
            };
        }

        $dryRun = (bool) $input->getOption('dry-run');
        // What the baseline's composer install may leave in an app that had
        // neither, so a run that never started can say so (bug 8).
        $root = rtrim($plan->project->path, '/\\');
        $had = ['composer.lock' => is_file($root . '/composer.lock'), 'vendor/' => is_dir($root . '/vendor')];
        $startedAt = gmdate(DATE_ATOM);
        $report = $executor->execute($plan, new RunOptions(
            dryRun: $dryRun,
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
            testEnv: $testEnv,
            progress: $progress,
            replay: $replay,
            replayOut: $this->stringOption($input, 'replay-out'),
            moves: $moves,
            route: $route,
            hand: $hand,
            finish: $finish,
        ));

        $finishedAt = gmdate(DATE_ATOM);

        // A real run leaves its machine-readable hop record at the app root for
        // ROLLCALL to read from the app repo. A dry run changes nothing, so it
        // writes none, and neither does a run refused before its first hop:
        // there is no hop to commit it with, and it would overwrite the record
        // of a stopped hop the next run re-gates from. Booster never commits
        // the file; the developer does.
        $notStarted = self::notStarted($report);
        $recordFile = null;
        if (!$dryRun && !$notStarted) {
            $appPath = realpath($report->plan->project->path);
            if ($appPath === false) {
                throw new ToolError(sprintf('Could not resolve the app path "%s" to write the hop record.', $report->plan->project->path));
            }
            $recordFile = HopRecord::write($appPath, HopRecord::build(
                $report,
                $startedAt,
                $finishedAt,
                RectorRunner::installedVersions(HopRecord::RULE_PACKAGES),
            ));
        }

        $body = $format === 'json'
            ? (new JsonWriter())->write($report->toArray())
            : (new TextWriter())->run($report, $this->pathAsTyped($input), null, $this->runFlags($input));
        if ($format === 'text' && $recordFile !== null) {
            $body .= PHP_EOL . sprintf('Hop record: %s (commit it with the hop).', $recordFile);
        }
        $left = [];
        foreach ($had as $name => $existed) {
            if (!$existed && ($name === 'vendor/' ? is_dir($root . '/vendor') : is_file($root . '/' . $name))) {
                $left[] = $name;
            }
        }
        if ($format === 'text' && $notStarted && $left !== []) {
            $body .= PHP_EOL . sprintf(
                'Left in the folder by the baseline\'s composer install: %s. They are not your code: delete %s, or keep %s for the next run.',
                implode(' and ', $left),
                count($left) === 1 ? 'it' : 'them',
                count($left) === 1 ? 'it' : 'them',
            );
        }
        $output->writeln($body, OutputInterface::OUTPUT_RAW);

        return $report->failed() ? self::EXIT_FAIL : self::EXIT_PASS;
    }

    /**
     * True when the run was refused before its first hop (the baseline or
     * the replay baseline failed) and re-gated nothing: no step ran and no
     * deploy script was added.
     */
    private static function notStarted(RunReport $report): bool
    {
        if ($report->regate !== null || $report->deployScript !== null || !($report->baseline->failed() || ($report->replay?->failed() ?? false))) {
            return false;
        }
        foreach ($report->results as $result) {
            if ($result->status !== StepResult::NOT_RUN) {
                return false;
            }
        }

        return true;
    }

    /**
     * The flags a dry run's "Next:" line hands to the real run: the plan's
     * own, plus the gate's (--replay, --test-command, --image and the rest),
     * as typed. --dry-run, --format and --allow-dirty stay behind (the real
     * run wants a clean folder), and so does --test-env, whose values may be
     * credentials and never reach a report. One giant leap: the same command,
     * minus the dry run.
     *
     * @return list<string>
     */
    private function runFlags(InputInterface $input): array
    {
        $flags = $this->planFlags($input);
        $replay = $input->getOption('replay');
        if ($replay !== false) {
            $flags[] = is_string($replay) && $replay !== '' ? '--replay=' . Invocation::valueArg($replay) : '--replay';
        }
        foreach (['replay-out', 'test-command', 'proctor-command', 'image', 'max-changes'] as $name) {
            $value = $input->getOption($name);
            if (is_string($value) && $value !== '') {
                $flags[] = '--' . $name . '=' . Invocation::valueArg($value);
            }
        }
        $memory = $input->getOption('memory-limit');
        if (is_string($memory) && $memory !== '' && $memory !== RectorRunner::DEFAULT_MEMORY_LIMIT) {
            $flags[] = '--memory-limit=' . Invocation::valueArg($memory);
        }
        foreach (['proctor-advisory', 'allow-failing-baseline', 'skip-gate'] as $name) {
            if ($input->getOption($name) === true) {
                $flags[] = '--' . $name;
            }
        }

        return $flags;
    }

    /**
     * The framework engines the executor picks from by Hop::$engine, keyed
     * by EngineId value (the vendored engine is the executor's own default).
     * One CakeUpgradeRunner serves both CakePHP hops, on the same process
     * runner and opt-in Composer cache as the gate; the Slim engine needs
     * only the Composer editor.
     *
     * @return array<string, FrameworkEngine>
     */
    public static function frameworkEngines(ProcessRunner $processes, ComposerEditor $composerEditor): array
    {
        $cakeUpgradeRunner = new CakeUpgradeRunner($processes, composerCache: CacheMount::fromEnvironment(CacheMount::COMPOSER));

        return [
            EngineId::CakeUpgrade->value => new CakeUpgradeMigration($cakeUpgradeRunner, $composerEditor),
            EngineId::SlimUpgrade->value => new SlimUpgradeMigration($composerEditor),
        ];
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
