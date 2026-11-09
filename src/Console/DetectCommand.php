<?php

declare(strict_types=1);

namespace Waynestate\Booster\Console;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Waynestate\Booster\Project\Project;
use Waynestate\Booster\Project\ProjectDetector;
use Waynestate\Booster\Report\JsonWriter;
use Waynestate\Booster\Report\TextWriter;
use Waynestate\Booster\Scan\WayneAuthScan;

final class DetectCommand extends BaseCommand
{
    /**
     * The PHP a Next line names when the app does not say: 5.6, what the
     * oldest apps Booster meets still run (a 2012 app with no composer.json).
     */
    private const GUESS_PHP = '5.6';

    protected function configure(): void
    {
        $this->setName('detect')->setDescription('Say which of the five stack permutations an app is, and its PHP, Laravel and Vue versions.');
        $this
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Path to the application.', '.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format (text, json).', 'text');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $project = (new ProjectDetector())->detect($this->stringOption($input, 'path') ?? '.');

        if ($this->format($input) === 'json') {
            $output->writeln((new JsonWriter())->write($project->toArray()), OutputInterface::OUTPUT_RAW);

            return self::EXIT_PASS;
        }

        $warning = $project->lockFloorWarning();
        if ($warning !== null) {
            $output->writeln('Warning: ' . TextWriter::clean($warning), OutputInterface::OUTPUT_RAW);
            $output->writeln('', OutputInterface::OUTPUT_RAW);
        }
        $stopSign = WayneAuthScan::stopSign($project->wayneAuthHits);
        if ($stopSign !== []) {
            // Before the keys: a sign-in that dies on the first PHP hop
            // outranks everything detect has to say.
            $output->writeln('Warning: ' . TextWriter::clean(implode(' ', $stopSign)), OutputInterface::OUTPUT_RAW);
            $output->writeln('', OutputInterface::OUTPUT_RAW);
        }

        foreach ($project->toArray() as $key => $value) {
            $shown = is_array($value)
                ? self::lockRangeText($value)
                : (is_bool($value) ? ($value ? 'yes' : 'no') : (string) ($value ?? 'unknown'));
            $output->writeln(sprintf('%-14s %s', $key, TextWriter::clean($shown)), OutputInterface::OUTPUT_RAW);
        }
        $output->writeln('', OutputInterface::OUTPUT_RAW);
        $output->writeln($this->footer($input, $project), OutputInterface::OUTPUT_RAW);

        return self::EXIT_PASS;
    }

    /**
     * The closing note: what detect read, what the yes/no keys mean, and the
     * command that goes next. With no PHP to read, plan stops without
     * --from-php, so the Next line carries one and says to fix its value.
     */
    private function footer(InputInterface $input, Project $project): string
    {
        $lines = [
            'Nothing was changed. This is what Booster read from composer.json and package.json; "php" is the lowest PHP version',
            'composer.json allows, not what your server runs (if it is wrong, add --from-php=X.Y to plan).',
            'Keys: composer_json = composer.json found, mix_file = webpack.mix.js found, wayne_cas = the cit-web-infra/wayne-cas package, wayne_auth = the old Wayne Auth sign-in in the app\'s own code, wayneui_1 = the cit-web-infra/wayne-ui package, boost = the laravel/boost package, helmet = the waynestate/helmet package.',
        ];
        $flags = [];
        if ($project->phpVersion === null) {
            $lines[] = sprintf(
                '%s, so plan needs --from-php: the PHP your server runs today. The line below says %s; change it if your server runs another.',
                $project->hasComposer ? 'composer.json does not say which PHP the app needs' : 'There is no composer.json to say which PHP the app needs',
                self::GUESS_PHP,
            );
            $flags[] = '--from-php=' . self::GUESS_PHP;
        }
        $lines[] = sprintf('Next: %s plan --path=%s%s', Invocation::program(), Invocation::pathArg($this->pathAsTyped($input)), Invocation::flagsText($flags));

        return implode("\n", $lines);
    }

    /**
     * The one array value detect prints: the lock's PHP range as a span.
     *
     * @param array<string, mixed> $range
     */
    private static function lockRangeText(array $range): string
    {
        $floor = is_string($range['floor'] ?? null) ? $range['floor'] : null;
        $ceiling = is_string($range['ceiling'] ?? null) ? $range['ceiling'] : null;
        if ($floor !== null && $ceiling !== null) {
            return $floor . ' to <' . $ceiling;
        }
        if ($floor !== null) {
            return '>= ' . $floor;
        }

        return '< ' . (string) $ceiling;
    }
}
