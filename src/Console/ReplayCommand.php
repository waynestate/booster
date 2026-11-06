<?php

declare(strict_types=1);

namespace Waynestate\Booster\Console;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Waynestate\Booster\Project\ProjectDetector;
use Waynestate\Booster\Replay\ReplayDrafter;
use Waynestate\Booster\Replay\ReplayFile;
use Waynestate\Booster\Replay\ReplayGate;
use Waynestate\Booster\Replay\ReplayOutcome;
use Waynestate\Booster\Replay\ReplayRecording;
use Waynestate\Booster\Replay\ReplayRunner;
use Waynestate\Booster\Report\JsonWriter;
use Waynestate\Booster\Run\GateImage;
use Waynestate\Booster\Run\ProcessRunner;
use Waynestate\Booster\Run\TargetImageRunner;
use Waynestate\Booster\ToolError;

/**
 * The replay gate on its own, outside a run: draft a request file, record
 * how the app answers now, check it answers the same later.
 */
final class ReplayCommand extends BaseCommand
{
    public function __construct(ProcessRunner $processes, private readonly ?ReplayRunner $runner = null)
    {
        parent::__construct($processes);
    }

    protected function configure(): void
    {
        $this->setName('replay')->setDescription('The replay gate on its own: init drafts booster-replay.json, record saves how the app answers now, check fails when an answer changed.');
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'init, record or check.')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Path to the application.', '.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format (text, json).', 'text')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'The request file, relative to the app or absolute.', ReplayFile::DEFAULT_NAME)
            ->addOption('recording', null, InputOption::VALUE_REQUIRED, 'Where record saves the answers and check reads them, relative to the app or absolute.', ReplayRecording::DEFAULT_NAME)
            ->addOption('php', null, InputOption::VALUE_REQUIRED, 'The PHP version to serve the app on (default: the version the app declares).')
            ->addOption('image', null, InputOption::VALUE_REQUIRED, 'Serve the app in this Docker image instead of booster-target, in the same three forms as run --image.')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Folder for the transcripts (default: a new booster-replay-<app>-<time> folder in the system temp folder).')
            ->addOption('no-install', null, InputOption::VALUE_NONE, 'Do not run composer install first; use the vendor/ the app already has.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'init only: overwrite an existing request file.');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $action = (string) $input->getArgument('action');
        $format = $this->format($input);
        $path = $this->stringOption($input, 'path') ?? '.';
        $appPath = realpath($path);
        if ($appPath === false || !is_dir($appPath)) {
            throw new ToolError(sprintf('Path "%s" does not exist.', $path));
        }
        $filePath = ReplayFile::locate($appPath, $this->stringOption($input, 'file'));

        return match ($action) {
            'init' => $this->init($appPath, $filePath, (bool) $input->getOption('force'), $output),
            'record', 'check' => $this->replay($action, $appPath, $filePath, $input, $output, $format),
            default => throw new ToolError(sprintf('Unknown replay action "%s". Use init, record or check.', $action)),
        };
    }

    private function init(string $appPath, string $filePath, bool $force, OutputInterface $output): int
    {
        if (is_file($filePath) && !$force) {
            throw new ToolError(sprintf('%s already exists. Edit it, or pass --force to draft a new one over it.', $filePath));
        }
        $draft = (new ReplayDrafter($this->processes))->draft($appPath);
        if (file_put_contents($filePath, $draft['json']) === false) {
            throw new ToolError(sprintf('Could not write %s.', $filePath));
        }
        $output->writeln(implode("\n", [
            sprintf('Drafted %s with %d request%s, from %s.', basename($filePath), $draft['requests'], $draft['requests'] === 1 ? '' : 's', $draft['source'] === '' ? 'nothing (add requests by hand)' : $draft['source']),
            sprintf('It has %d todo item%s for you: the fake data, ids in paths, the fields each form posts.', $draft['todos'], $draft['todos'] === 1 ? '' : 's'),
            'Next: fill in every "todo" and delete it, then record the answers on the PHP the app runs on now:',
            sprintf('  %s replay record --path %s', Invocation::program(), Invocation::pathArg($appPath)),
        ]), OutputInterface::OUTPUT_RAW);

        return self::EXIT_PASS;
    }

    private function replay(string $action, string $appPath, string $filePath, InputInterface $input, OutputInterface $output, string $format): int
    {
        $file = ReplayFile::load($filePath);
        if ($file->todos !== []) {
            throw new ToolError(ReplayGate::todoMessage($file));
        }
        $php = $this->stringOption($input, 'php') ?? (new ProjectDetector())->detect($appPath)->phpVersion;
        if ($php === null || preg_match('/^\d+\.\d+$/', $php) !== 1) {
            throw new ToolError('Booster could not tell which PHP the app runs on. Pass --php, for example --php 7.4.');
        }
        $recordingPath = ReplayFile::locate($appPath, $this->stringOption($input, 'recording') ?? ReplayRecording::DEFAULT_NAME);
        $outDir = $this->stringOption($input, 'out') ?? ReplayGate::defaultOutDir($appPath);
        $runner = $this->runner ?? new ReplayRunner(new TargetImageRunner($this->processes), $this->processes);
        $gate = new ReplayGate($runner, $file, $appPath, $outDir, GateImage::fromOption($this->stringOption($input, 'image')));
        $install = !(bool) $input->getOption('no-install');

        if ($action === 'record') {
            $outcome = $gate->record($php, $install);
            $recording = $gate->recording();
            $lines = $outcome->lines;
            if ($recording !== null) {
                if (file_put_contents($recordingPath, $recording->toJson()) === false) {
                    throw new ToolError(sprintf('Could not write %s.', $recordingPath));
                }
                $lines[] = sprintf('Saved the answers to %s. It holds answers to fake data only; commit it or ignore it, your call.', $recordingPath);
                $lines[] = sprintf('Next: after a change, %s replay check --path %s --php <new version>', Invocation::program(), Invocation::pathArg($appPath));
            }
        } else {
            $gate->useRecording(ReplayRecording::load($recordingPath), $recordingPath);
            $outcome = $gate->check($php, 'check-php' . $php, $install);
            $lines = $outcome->lines;
        }

        if ($format === 'json') {
            $output->writeln((new JsonWriter())->write(['tool' => 'booster', 'action' => $action, 'result' => $outcome->failed() ? 'fail' : 'pass', 'replay' => $outcome->toArray()]), OutputInterface::OUTPUT_RAW);
        } else {
            $lines[] = '';
            $lines[] = 'RESULT: ' . ($outcome->failed() ? 'FAIL' : 'PASS');
            $output->writeln(implode("\n", $lines), OutputInterface::OUTPUT_RAW);
        }

        return $outcome->failed() || $outcome->status === ReplayOutcome::SKIPPED ? self::EXIT_FAIL : self::EXIT_PASS;
    }
}
