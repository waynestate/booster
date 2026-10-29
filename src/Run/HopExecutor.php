<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Waynestate\Booster\Hop\Hop;
use Waynestate\Booster\Hop\HopKind;
use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Node\MixBuild;
use Waynestate\Booster\Node\MixFile;
use Waynestate\Booster\Plan\Plan;
use Waynestate\Booster\Plan\PlanStep;
use Waynestate\Booster\Prepass\NewByReferenceFixer;
use Waynestate\Booster\Prepass\PrepassResult;
use Waynestate\Booster\ToolError;

/**
 * Walks a plan. For each step: preview with Rector, stop if the diff is bigger
 * than the cap, apply, edit composer.json, then run the gate before the next
 * step. It edits the working copy only: no commits, no branches, no pushes.
 */
final class HopExecutor
{
    public function __construct(
        private readonly RectorRunner $rector,
        private readonly ComposerEditor $composer,
        private readonly TargetImageRunner $gate,
        private readonly ProcessRunner $processes,
        private readonly ?NodeRunner $node = null,
        private readonly ?HopRegistry $registry = null,
    ) {}

    private function registry(): HopRegistry
    {
        return $this->registry ?? new HopRegistry();
    }

    private ?NewByReferenceFixer $fixer = null;

    private function fixer(): NewByReferenceFixer
    {
        return $this->fixer ??= new NewByReferenceFixer();
    }

    public function execute(Plan $plan, RunOptions $options): RunReport
    {
        $path = realpath($plan->project->path);
        if ($path === false) {
            throw new ToolError(sprintf('Path "%s" does not exist.', $plan->project->path));
        }
        if (!$options->dryRun && !$options->allowDirty) {
            $this->assertCleanGitTree($path);
        }

        $baseline = $this->runBaseline($plan, $path, $options);
        if ($baseline->failed() && !$options->allowFailingBaseline) {
            // The suite is red before Booster touches anything, so a later red gate would be
            // unprovable. Stop before the first hop and say so plainly.
            $results = [];
            foreach ($plan->steps as $step) {
                $results[] = new StepResult($step->number, $step->hop->id, StepResult::NOT_RUN, [], [
                    'Not run because the baseline failed: the app\'s tests fail before any change. Fix them or pass --allow-failing-baseline.',
                ]);
            }

            return new RunReport($plan, $options->dryRun, $baseline, $results);
        }

        $results = [];
        foreach ($plan->steps as $step) {
            $result = $this->runStep($step, $path, $plan, $options, $baseline);
            $results[] = $result;
            if ($result->failed()) {
                // Later steps assume this one worked, so they are not attempted.
                foreach ($plan->steps as $later) {
                    if ($later->number > $step->number) {
                        $results[] = new StepResult($later->number, $later->hop->id, StepResult::NOT_RUN, [], ['Not run because step ' . $step->number . ' did not finish.']);
                    }
                }
                break;
            }
        }

        return new RunReport($plan, $options->dryRun, $baseline, $results);
    }

    /**
     * Runs the app's own tests on the PHP the app is on now, before the first
     * hop, so a later red gate can be told apart from a suite that was already
     * red. Skipped (not run) for a dry run or when the gate is skipped.
     */
    private function runBaseline(Plan $plan, string $path, RunOptions $options): BaselineResult
    {
        $php = $this->startingPhp($plan);
        if ($options->dryRun || $options->skipGate || $php === null) {
            return new BaselineResult(BaselineResult::SKIPPED, $php, null);
        }

        $test = $options->testCommand ?? $this->defaultTestCommand($path);
        $result = $this->gate->run($path, $php, $test, $options->proctorCommand);
        $output = self::lastLines($result->stdout . "\n" . $result->stderr, 20);

        return new BaselineResult(
            $result->successful() ? BaselineResult::PASSED : BaselineResult::FAILED,
            $php,
            $result->exitCode,
            $output,
        );
    }

    /**
     * The PHP the planner starts from: the first step's starting version, or the
     * manifest's when the plan has no steps.
     */
    private function startingPhp(Plan $plan): ?string
    {
        if ($plan->steps === []) {
            return $plan->project->phpVersion;
        }

        return $plan->steps[0]->phpBefore ?? $plan->project->phpVersion;
    }

    /**
     * @return list<string>
     */
    private static function lastLines(string $text, int $count): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        $lines = explode("\n", $text);
        if (count($lines) <= $count) {
            return $lines;
        }
        $kept = [];
        foreach (array_slice($lines, -$count) as $line) {
            $kept[] = $line;
        }

        return $kept;
    }

    private function runStep(PlanStep $step, string $path, Plan $plan, RunOptions $options, BaselineResult $baseline): StepResult
    {
        $hop = $step->hop;
        if ($hop->isManual()) {
            return new StepResult($step->number, $hop->id, StepResult::MANUAL, [], ['Follow the playbook; BOOSTER has no automatic change for this hop.', ...$hop->notes]);
        }

        try {
            if ($hop->kind === HopKind::Node) {
                return $hop->id === HopRegistry::MIX_TO_VITE_ID
                    ? $this->runMixHop($step, $path, $plan, $options, $baseline)
                    : $this->runNodeHop($step, $path, $options, $baseline);
            }

            // PHP hops get the new-by-reference pre-pass first: modern PHP refuses to compile
            // "$a = &new Foo();", so without the rewrite Rector never gets a parse tree and the
            // hop stops with nothing changed. The rewrite lands on the working copy, and a dry
            // run puts every original byte back.
            $prepass = $hop->kind === HopKind::Php ? $this->fixer()->fixTree($path, true) : null;
            $keepPrepass = false;
            try {
                $phpunitTarget = $hop->kind === HopKind::Phpunit ? $hop->to : null;
                $preview = $this->rector->run($hop, $path, true, $step->phpAfter ?? '', $step->laravelAfter, $phpunitTarget);
                if ($preview->errors !== []) {
                    return new StepResult($step->number, $hop->id, StepResult::ERROR, self::prepassFiles($prepass), ['Rector reported errors; nothing was changed.', ...self::refusals($preview->errors)], false, $prepass?->files() ?? []);
                }
                $wanted = $preview->changedCount() + ($prepass?->changedCount() ?? 0);
                if ($wanted > $options->maxChanges) {
                    return new StepResult($step->number, $hop->id, StepResult::CAPPED, [...self::prepassFiles($prepass), ...$preview->files], [sprintf(
                        'This hop wants to change %d files (%d from the pre-pass, %d from Rector) and the cap is %d. Nothing was changed. Review with --dry-run, then raise --max-changes or use --skip to split the work.',
                        $wanted,
                        $prepass?->changedCount() ?? 0,
                        $preview->changedCount(),
                        $options->maxChanges,
                    )], false, $prepass?->files() ?? []);
                }
                if ($options->dryRun) {
                    // A Laravel hop previews through the app's own autoloader; without vendor/ Rector
                    // cannot see the app's classes, so the diff is a lower bound, not the whole change.
                    $incomplete = $hop->kind === HopKind::Laravel && !is_file($path . '/vendor/autoload.php');
                    if ($incomplete) {
                        $messages = [sprintf('Preview incomplete: %s needs the app\'s vendor/ to recognise Laravel classes, and it is missing. Run composer install in the app, then dry-run again.', $hop->id), ...$hop->notes];
                    } elseif ($preview->files === [] && ($prepass?->changedCount() ?? 0) === 0) {
                        $messages = ['Dry run: nothing was changed.', ...$hop->notes];
                    } else {
                        $messages = $hop->notes;
                    }

                    return new StepResult($step->number, $hop->id, StepResult::PREVIEWED, [...self::prepassFiles($prepass), ...$preview->files], $messages, $incomplete, $prepass?->files() ?? []);
                }

                $keepPrepass = true;
                $applied = $this->rector->run($hop, $path, false, $step->phpAfter ?? '', $step->laravelAfter, $phpunitTarget);
                $messages = $this->composer->apply($hop, $path);
                if ($applied->errors !== []) {
                    return new StepResult($step->number, $hop->id, StepResult::ERROR, [...self::prepassFiles($prepass), ...$applied->files], ['Rector reported errors while applying.', ...self::refusals($applied->errors)], false, $prepass?->files() ?? []);
                }
                if (($prepass?->changedCount() ?? 0) === 0 && $applied->changedCount() === 0 && $messages === []) {
                    // Nothing changed, so there is nothing for the gate to prove; skip it.
                    return new StepResult($step->number, $hop->id, StepResult::NOTHING_TO_DO, [], [
                        'Nothing to do: Rector changed no files and composer.json already allows the target, so the gate was skipped.',
                        ...$hop->notes,
                    ]);
                }

                if (!$options->skipGate) {
                    $gate = $this->runGate($step, $path, $options, $baseline);
                    if ($gate !== null) {
                        return new StepResult($step->number, $hop->id, StepResult::GATE_FAILED, [...self::prepassFiles($prepass), ...$applied->files], [...$messages, ...$gate], false, $prepass?->files() ?? []);
                    }
                    $messages[] = 'Gate passed: tests are green on PHP ' . ($step->phpAfter ?? '?') . '.';
                } else {
                    $messages[] = 'Gate skipped. Run the tests on PHP ' . ($step->phpAfter ?? '?') . ' before the next step.';
                }

                return new StepResult($step->number, $hop->id, StepResult::APPLIED, [...self::prepassFiles($prepass), ...$applied->files], [...$messages, ...$hop->notes], false, $prepass?->files() ?? []);
            } finally {
                if ($prepass !== null && !$keepPrepass) {
                    $prepass->restore();
                }
            }
        } catch (ToolError $e) {
            return new StepResult($step->number, $hop->id, StepResult::ERROR, [], [$e->getMessage()]);
        }
    }

    /**
     * Pre-pass fixes as StepResult file entries, so they show in the per-step
     * file list and count as changed files.
     *
     * @return list<array{file: string, diff: string}>
     */
    private static function prepassFiles(?PrepassResult $prepass): array
    {
        if ($prepass === null) {
            return [];
        }

        return array_map(static fn(array $fix): array => [
            'file' => $fix['file'],
            'diff' => sprintf('pre-pass: %d new-by-reference rewrite%s', $fix['rewrites'], $fix['rewrites'] === 1 ? '' : 's'),
        ], $prepass->files());
    }

    /**
     * Turns Rector parse errors into named refusals: the file (and the line,
     * when the message carries one) is named, and the reader is told that a
     * person is needed. Every other error passes through untouched.
     *
     * @param list<string> $errors
     *
     * @return list<string>
     */
    private static function refusals(array $errors): array
    {
        $out = [];
        foreach ($errors as $error) {
            $out[] = self::refusal($error) ?? $error;
        }

        return $out;
    }

    /**
     * One refusal for a parse error Rector reports as "Syntax error ..." or
     * "Invalid numeric literal": BOOSTER cannot repair those by tooling, so
     * the file needs a person.
     */
    private static function refusal(string $error): ?string
    {
        foreach (['Syntax error', 'Invalid numeric literal'] as $phrase) {
            $at = strpos($error, $phrase);
            if ($at === false) {
                continue;
            }
            $message = substr($error, $at);
            $file = $at >= 2 && substr($error, $at - 2, 2) === ': ' ? substr($error, 0, $at - 2) : null;
            $where = '';
            if ($file !== null) {
                $where = preg_match('/line (\d+)/i', $message, $m) === 1 ? $file . ' line ' . $m[1] : $file;
            }

            return ($where !== '' ? $where . ': ' : '') . '"' . $message . '" cannot be repaired by tooling, so BOOSTER stops here and the file needs a person.';
        }

        return null;
    }

    /**
     * @return list<string>|null failure messages, or null when the gate passed
     */
    private function runGate(PlanStep $step, string $path, RunOptions $options, BaselineResult $baseline): ?array
    {
        $php = $step->phpAfter ?? '';
        $test = $options->testCommand ?? $this->defaultTestCommand($path);
        $result = $this->gate->run($path, $php, $test, $options->proctorCommand);
        if ($result->successful()) {
            return null;
        }

        $messages = [
            sprintf('Gate failed: "%s" exited %d on PHP %s. The working copy keeps the changes so you can read them.', $test, $result->exitCode, $php),
            trim($result->stdout . "\n" . $result->stderr),
            'Undo with: git checkout . (inside the app).',
        ];
        if ($baseline->failed()) {
            $messages[] = 'The baseline was already failing, so this gate failure may predate the upgrade.';
        }

        return $messages;
    }

    /**
     * A Node hop edits package.json and .nvmrc instead of running Rector. The
     * build gate (when the hop asks for one) runs npm ci and npm run build in
     * a node container; otherwise the usual test gate applies.
     */
    private function runNodeHop(PlanStep $step, string $path, RunOptions $options, BaselineResult $baseline): StepResult
    {
        $hop = $step->hop;
        if ($options->dryRun) {
            return new StepResult($step->number, $hop->id, StepResult::PREVIEWED, [], [
                sprintf('Dry run: would set %s in package.json and write .nvmrc with %s.', implode(', ', self::npmSetPhrases($hop->npmSet)), $hop->to),
                ...$hop->notes,
            ]);
        }

        [$changes, $files] = $this->applyNodeChanges($hop, $path);
        if ($changes === []) {
            return new StepResult($step->number, $hop->id, StepResult::NOTHING_TO_DO, [], [
                'Nothing to do: package.json already sets ' . implode(' and ', self::npmSetPhrases($hop->npmSet)) . ' and .nvmrc already pins ' . $hop->to . ', so the gate was skipped.',
                ...$hop->notes,
            ]);
        }

        if ($hop->buildGate) {
            $failure = $this->runBuildGate($path);
            if ($failure !== null) {
                return new StepResult($step->number, $hop->id, StepResult::GATE_FAILED, $files, [...$changes, ...$failure]);
            }
            $changes[] = 'Build gate passed: npm ci and npm run build are green in the Node container.';
        } elseif (!$options->skipGate) {
            $gate = $this->runGate($step, $path, $options, $baseline);
            if ($gate !== null) {
                return new StepResult($step->number, $hop->id, StepResult::GATE_FAILED, $files, [...$changes, ...$gate]);
            }
            $changes[] = 'Gate passed: tests are green on PHP ' . ($step->phpAfter ?? '?') . '.';
        } else {
            $changes[] = 'Gate skipped. Run the tests on PHP ' . ($step->phpAfter ?? '?') . ' before the next step.';
        }

        return new StepResult($step->number, $hop->id, StepResult::APPLIED, $files, [...$changes, ...$hop->notes]);
    }

    /**
     * The Mix to Vite hop. It converts the simple shape of webpack.mix.js:
     * write vite.config.js, swap laravel-mix for vite and laravel-vite-plugin
     * in package.json, rename MIX_ keys to VITE_ in .env.example, rewrite
     * Blade mix() tags where the mapping is unambiguous, delete
     * webpack.mix.js. Anything outside the simple shape is refused with a
     * message that names the offending call.
     */
    private function runMixHop(PlanStep $step, string $path, Plan $plan, RunOptions $options, BaselineResult $baseline): StepResult
    {
        $hop = $step->hop;
        $mixFile = $path . '/' . MixFile::FILE;
        if (!is_file($mixFile)) {
            throw new ToolError(sprintf('%s is missing, so there is nothing to convert.', MixFile::FILE));
        }

        try {
            $build = MixFile::parse((string) file_get_contents($mixFile));
        } catch (ToolError $e) {
            return $options->dryRun
                ? new StepResult($step->number, $hop->id, StepResult::PREVIEWED, [], ['Dry run: ' . $e->getMessage(), ...$hop->notes])
                : new StepResult($step->number, $hop->id, StepResult::ERROR, [], [$e->getMessage(), ...$hop->notes]);
        }

        if ($build->vue && $plan->project->vueMajor === '2') {
            $message = sprintf(
                'Refusing to convert %s: the front end uses Vue 2, but @vitejs/plugin-vue is Vue 3 only. Move to Vue 3 first with the vue-2-to-3 hop, then run this hop again.',
                MixFile::FILE,
            );

            return $options->dryRun
                ? new StepResult($step->number, $hop->id, StepResult::PREVIEWED, [], ['Dry run: ' . $message, ...$hop->notes])
                : new StepResult($step->number, $hop->id, StepResult::ERROR, [], [$message, ...$hop->notes]);
        }

        if ($options->dryRun) {
            return new StepResult($step->number, $hop->id, StepResult::PREVIEWED, [], [
                sprintf(
                    'Dry run: would convert %s (%d entries%s) to Vite: write vite.config.js, swap laravel-mix for vite and laravel-vite-plugin in package.json, rename MIX_ keys in .env.example, rewrite Blade mix() tags where the mapping is unambiguous, delete %s.',
                    MixFile::FILE,
                    count($build->entries),
                    $build->vue ? ' plus Vue' : '',
                    MixFile::FILE,
                ),
                ...$this->bladeNotes($path, $build),
                ...$hop->notes,
            ]);
        }

        [$changes, $files] = $this->applyMixToVite($path, $build);
        $changes = [...$changes, ...$this->bladeNotes($path, $build), ...$this->withoutMixNotes($path)];

        if (!$options->skipGate) {
            $failure = $this->runBuildGate($path);
            if ($failure === null && !is_file($path . '/public/build/manifest.json')) {
                $failure = ['Build gate failed: npm run build finished but public/build/manifest.json is missing. Vite writes it when the build succeeds, so check the build output.'];
            }
            if ($failure !== null) {
                return new StepResult($step->number, $hop->id, StepResult::GATE_FAILED, $files, [...$changes, ...$failure]);
            }
            $changes[] = 'Build gate passed: npm ci and npm run build are green in the Node container, and public/build/manifest.json exists.';
            $gate = $this->runGate($step, $path, $options, $baseline);
            if ($gate !== null) {
                return new StepResult($step->number, $hop->id, StepResult::GATE_FAILED, $files, [...$changes, ...$gate]);
            }
            $changes[] = 'Gate passed: tests are green on PHP ' . ($step->phpAfter ?? '?') . '.';
        } else {
            $changes[] = 'Gate skipped. Run the tests on PHP ' . ($step->phpAfter ?? '?') . ' before the next step.';
        }

        return new StepResult($step->number, $hop->id, StepResult::APPLIED, $files, [...$changes, ...$hop->notes]);
    }

    /**
     * Writes the Vite shape into the app.
     *
     * @return array{0: list<string>, 1: list<array{file: string, diff: string}>} what changed and which files
     */
    private function applyMixToVite(string $path, MixBuild $build): array
    {
        $changes = [];
        $files = [];
        $mixFile = $path . '/' . MixFile::FILE;

        $config = $this->viteConfig($build);
        $configFile = $path . '/vite.config.js';
        if (!is_file($configFile) || (string) file_get_contents($configFile) !== $config) {
            file_put_contents($configFile, $config);
            $changes[] = 'vite.config.js: written with the laravel-vite-plugin inputs from ' . MixFile::FILE . ($build->vue ? ' plus @vitejs/plugin-vue' : '') . '.';
            $files[] = ['file' => 'vite.config.js', 'diff' => 'written'];
        }

        $packageFile = $path . '/package.json';
        if (is_file($packageFile)) {
            $package = json_decode((string) file_get_contents($packageFile), true);
            if (!is_array($package)) {
                throw new ToolError(sprintf('package.json is not valid JSON: %s', $packageFile));
            }
            $edited = false;
            foreach (['dependencies', 'devDependencies'] as $section) {
                if (is_array($package[$section] ?? null) && array_key_exists('laravel-mix', $package[$section])) {
                    unset($package[$section]['laravel-mix']);
                    $changes[] = 'package.json: removed laravel-mix from ' . $section . '.';
                    $edited = true;
                }
            }
            $registry = $this->registry();
            foreach ([
                'vite' => $registry->viteVersion(),
                'laravel-vite-plugin' => $registry->laravelVitePluginVersion(),
            ] as $name => $version) {
                if (($package['devDependencies'][$name] ?? null) !== $version) {
                    $package['devDependencies'][$name] = $version;
                    $changes[] = 'package.json: devDependencies ' . $name . ' to ' . $version . '.';
                    $edited = true;
                }
            }
            if ($build->vue && ($package['devDependencies']['@vitejs/plugin-vue'] ?? null) !== $registry->vuePluginVersion()) {
                $package['devDependencies']['@vitejs/plugin-vue'] = $registry->vuePluginVersion();
                $changes[] = 'package.json: devDependencies @vitejs/plugin-vue to ' . $registry->vuePluginVersion() . '.';
                $edited = true;
            }
            foreach (['dev' => 'vite', 'build' => 'vite build'] as $script => $command) {
                if (($package['scripts'][$script] ?? null) !== $command) {
                    $package['scripts'][$script] = $command;
                    $changes[] = 'package.json: scripts.' . $script . ' to ' . $command . '.';
                    $edited = true;
                }
            }
            if (($package['type'] ?? null) !== 'module') {
                $package['type'] = 'module';
                $changes[] = 'package.json: type to module.';
                $edited = true;
            }
            if ($edited) {
                file_put_contents($packageFile, json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
                $files[] = ['file' => 'package.json', 'diff' => 'swapped laravel-mix for vite and laravel-vite-plugin'];
            }
        }

        $envFile = $path . '/.env.example';
        if (is_file($envFile)) {
            $lines = explode("\n", (string) file_get_contents($envFile));
            $renamed = 0;
            foreach ($lines as $i => $line) {
                if (str_starts_with($line, 'MIX_')) {
                    $lines[$i] = 'VITE_' . substr($line, 4);
                    $renamed++;
                }
            }
            if ($renamed > 0) {
                file_put_contents($envFile, implode("\n", $lines));
                $changes[] = '.env.example: renamed ' . $renamed . ' MIX_ key' . ($renamed === 1 ? '' : 's') . ' to VITE_.';
                $files[] = ['file' => '.env.example', 'diff' => 'MIX_ keys to VITE_'];
            }
        }

        $this->rewriteBlade($path, $build, $changes, $files);

        unlink($mixFile);
        $changes[] = MixFile::FILE . ': deleted.';
        $files[] = ['file' => MixFile::FILE, 'diff' => 'deleted'];

        return [$changes, $files];
    }

    /**
     * Rewrites Blade mix() script and link tags into one @vite([...]) line per
     * layout, but only when every referenced asset maps to a Mix entry. A file
     * that cannot be moved cleanly is left untouched and earns a manual note.
     *
     * @param list<string>                           $changes
     * @param list<array{file: string, diff: string}> $files
     */
    private function rewriteBlade(string $path, MixBuild $build, array &$changes, array &$files): void
    {
        $map = $build->outputMap();
        foreach ($this->bladeFiles($path . '/resources/views') as $file) {
            $content = (string) file_get_contents($file);
            $tagRefs = self::mixTagRefs($content);
            if ($tagRefs === []) {
                continue;
            }
            // Files that cannot be moved cleanly are left untouched here;
            // bladeNotes() reports them once, in the step result.
            $bareRefs = self::bareMixRefs($content);
            $unmapped = array_values(array_diff($tagRefs, array_keys($map)));
            if ($unmapped !== [] || $bareRefs !== []) {
                continue;
            }
            $inputs = [];
            foreach ($tagRefs as $ref) {
                $input = $map[$ref];
                if (!in_array($input, $inputs, true)) {
                    $inputs[] = $input;
                }
            }
            $directive = '@vite([' . implode(', ', array_map(static fn(string $input): string => "'" . $input . "'", $inputs)) . '])';
            $first = true;
            $rewritten = preg_replace_callback(self::MIX_TAG, static function (array $m) use (&$first, $directive): string {
                if (!$first) {
                    return '';
                }
                $first = false;
                // Groups 1 to 8 are guaranteed by MIX_TAG; PHPStan cannot see
                // through the pattern, so the lookups default to empty.
                $isScript = ($m[2] ?? '') !== '';

                return ($isScript ? ($m[1] ?? '') : ($m[5] ?? '')) . $directive . ($isScript ? ($m[4] ?? '') : ($m[8] ?? ''));
            }, $content);
            if ($rewritten === null || $rewritten === $content) {
                $changes[] = sprintf('%s: BOOSTER could not rewrite the mix() tags; move them by hand.', $this->relative($path, $file));

                continue;
            }
            file_put_contents($file, $rewritten);
            $changes[] = $this->relative($path, $file) . ': mix() tags replaced with ' . $directive . '.';
            $files[] = ['file' => $this->relative($path, $file), 'diff' => 'mix() tags to @vite'];
        }
    }

    /**
     * Manual notes about Blade files Booster will not touch: references with no
     * matching Mix entry, or mix() calls that are not plain script or link tags.
     *
     * @return list<string>
     */
    private function bladeNotes(string $path, MixBuild $build): array
    {
        $map = $build->outputMap();
        $notes = [];
        foreach ($this->bladeFiles($path . '/resources/views') as $file) {
            $content = (string) file_get_contents($file);
            $tagRefs = self::mixTagRefs($content);
            if ($tagRefs === []) {
                continue;
            }
            $bareRefs = self::bareMixRefs($content);
            $unmapped = array_values(array_diff($tagRefs, array_keys($map)));
            if ($unmapped === [] && $bareRefs === []) {
                continue;
            }
            $reason = $unmapped !== [] ? 'references an asset with no matching entry in ' . MixFile::FILE : 'has a mix() call that is not a plain script or link tag';
            $notes[] = sprintf('%s: mix(%s) %s; move it by hand.', $this->relative($path, $file), implode("', '", $tagRefs), $reason);
        }

        return $notes;
    }

    /**
     * Files that still call withoutMix(); the helper becomes withoutVite() and
     * Booster does not rename it on your behalf.
     *
     * @return list<string>
     */
    private function withoutMixNotes(string $path): array
    {
        $notes = [];
        foreach ($this->withoutMixFiles($path) as $file) {
            $notes[] = $file . ': replace withoutMix() with withoutVite() by hand.';
        }

        return $notes;
    }

    /**
     * @return list<string>
     */
    private function withoutMixFiles(string $path): array
    {
        $hits = [];
        foreach ($this->phpFiles($path) as $relative => $absolute) {
            if (str_contains((string) file_get_contents($absolute), 'withoutMix(')) {
                $hits[] = $relative;
            }
        }
        sort($hits);

        return $hits;
    }

    /**
     * The vite.config.js the hop writes: the Mix entries as laravel-vite-plugin
     * inputs, plus the Vue plugin when the Mix file compiled components.
     */
    private function viteConfig(MixBuild $build): string
    {
        $imports = "import { defineConfig } from 'vite';\nimport laravel from 'laravel-vite-plugin';\n";
        $plugins = ['        laravel({', '            input: [', ...array_map(static fn(string $input): string => "                '" . $input . "'", $build->inputs()), '            ]', '            refresh: true', '        })'];
        if ($build->vue) {
            $imports .= "import vue from '@vitejs/plugin-vue';\n";
            $plugins[] = '        vue()';
        }
        $body = [];
        foreach ($plugins as $i => $line) {
            $body[] = $line . ($i < count($plugins) - 1 ? ',' : '');
        }

        return $imports
            . "\n"
            . "export default defineConfig({\n    plugins: [\n"
            . implode("\n", $body)
            . "\n    ],\n});\n";
    }

    /**
     * Every mix() reference inside a script or link tag, in document order.
     *
     * @return list<string>
     */
    private static function mixTagRefs(string $content): array
    {
        $refs = [];
        if (preg_match_all(self::MIX_TAG, $content, $matches, PREG_OFFSET_CAPTURE) !== false) {
            foreach ($matches[0] as $i => $match) {
                $refs[] = [$match[1], $matches[2][$i][1] === -1 ? $matches[7][$i][0] : $matches[3][$i][0]];
            }
        }
        sort($refs);

        return array_map(static fn(array $ref): string => $ref[1], $refs);
    }

    /**
     * mix() references that sit outside any script or link tag.
     *
     * @return list<string>
     */
    private static function bareMixRefs(string $content): array
    {
        $ranges = [];
        if (preg_match_all(self::MIX_TAG, $content, $matches, PREG_OFFSET_CAPTURE) !== false) {
            foreach ($matches[0] as $match) {
                $ranges[] = [$match[1], $match[1] + strlen($match[0])];
            }
        }
        $bare = [];
        if (preg_match_all('/\{\{\s*mix\(\s*([\'"])([^\'"]+)\1\s*\)\s*\}\}/', $content, $calls, PREG_OFFSET_CAPTURE) !== false) {
            foreach ($calls[0] as $i => $call) {
                $inside = false;
                foreach ($ranges as [$start, $end]) {
                    if ($call[1] >= $start && $call[1] < $end) {
                        $inside = true;

                        break;
                    }
                }
                if (!$inside) {
                    $bare[] = $calls[2][$i][0];
                }
            }
        }

        return $bare;
    }

    /**
     * @return list<string> absolute paths, sorted
     */
    private function bladeFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * PHP files outside vendor/, node_modules/, public/ and storage/.
     *
     * @return array<string, string> relative path to absolute path
     */
    private function phpFiles(string $path): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                continue;
            }
            $relative = $this->relative($path, $file->getPathname());
            foreach (['.git/', 'vendor/', 'node_modules/', 'public/', 'storage/', 'bootstrap/cache/'] as $skip) {
                if (str_starts_with($relative, $skip)) {
                    continue 2;
                }
            }
            $files[$relative] = $file->getPathname();
        }

        return $files;
    }

    private function relative(string $base, string $file): string
    {
        $base = str_replace('\\', '/', rtrim($base, '/\\'));
        $file = str_replace('\\', '/', $file);

        return substr($file, strlen($base) + 1);
    }

    /**
     * A script tag whose src is a Blade mix() call, or a link tag whose href
     * is one. Groups 1 to 4 belong to the script branch (indent, quote, asset
     * reference, trailing newline); groups 5 to 8 are the same for the link
     * branch. The quote group can never be empty when its branch matched, so
     * it doubles as the branch discriminator. The lookbehind keeps data-src
     * and data-href out.
     */
    private const MIX_TAG = '/(^[ \t]*)<script\b[^>]*(?<![\w-])src="\{\{\s*mix\(\s*(\x27|\x22)([^\x27\x22]+)\2\s*\)\s*\}\}"[^>]*>\s*<\/script>[ \t]*(\r?\n)?|(^[ \t]*)<link\b[^>]*(?<![\w-])href="\{\{\s*mix\(\s*(\x27|\x22)([^\x27\x22]+)\6\s*\)\s*\}\}"[^>]*>[ \t]*(\r?\n)?/m';

    /**
     * Applies the hop's package.json values and .nvmrc pin.
     *
     * @return array{0: list<string>, 1: list<array{file: string, diff: string}>} what changed and which files
     */
    private function applyNodeChanges(Hop $hop, string $path): array
    {
        $changes = [];
        $files = [];
        $packageFile = $path . '/package.json';

        if (is_file($packageFile)) {
            $package = json_decode((string) file_get_contents($packageFile), true);
            if (!is_array($package)) {
                throw new ToolError(sprintf('package.json is not valid JSON: %s', $packageFile));
            }
            $edited = false;
            foreach ($hop->npmSet as $key => $value) {
                if (self::getNested($package, $key) !== $value) {
                    $package = self::setNested($package, $key, $value);
                    $changes[] = 'package.json: ' . $key . ' to ' . $value;
                    $files[] = ['file' => 'package.json', 'diff' => $key . ' to ' . $value];
                    $edited = true;
                }
            }
            if ($edited) {
                file_put_contents($packageFile, json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
            }
        }

        $nvmrc = $path . '/.nvmrc';
        if (!is_file($nvmrc) || trim((string) file_get_contents($nvmrc)) !== $hop->to) {
            file_put_contents($nvmrc, $hop->to . "\n");
            $changes[] = '.nvmrc: ' . $hop->to;
            $files[] = ['file' => '.nvmrc', 'diff' => 'pins ' . $hop->to];
        }

        return [$changes, $files];
    }

    /**
     * @return list<string>|null failure messages, or null when the build passed
     */
    private function runBuildGate(string $path): ?array
    {
        if ($this->node === null) {
            return ['The build gate needs a Node runner, but this BOOSTER has none configured.'];
        }
        foreach ([['npm', 'ci'], ['npm', 'run', 'build']] as $argv) {
            $result = $this->node->run($path, $argv);
            if (!$result->successful()) {
                return [
                    sprintf('Build gate failed: "%s" exited %d in the Node container. The working copy keeps the changes so you can read them.', implode(' ', $argv), $result->exitCode),
                    trim($result->stdout . "\n" . $result->stderr),
                    'Undo with: git checkout . (inside the app).',
                ];
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $npmSet
     *
     * @return list<string>
     */
    private static function npmSetPhrases(array $npmSet): array
    {
        $phrases = [];
        foreach ($npmSet as $key => $value) {
            $phrases[] = $key . ' to ' . $value;
        }

        return $phrases;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return mixed
     */
    private static function getNested(array $data, string $dotPath): mixed
    {
        $current = $data;
        foreach (explode('.', $dotPath) as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return null;
            }
            $current = $current[$part];
        }

        return $current;
    }

    /**
     * @param array<string, mixed> $data
     * @param mixed                $value
     *
     * @return array<string, mixed>
     */
    private static function setNested(array $data, string $dotPath, mixed $value): array
    {
        $parts = explode('.', $dotPath);
        $target = &$data;
        foreach ($parts as $i => $part) {
            if ($i === count($parts) - 1) {
                $target[$part] = $value;
                break;
            }
            if (!isset($target[$part]) || !is_array($target[$part])) {
                $target[$part] = [];
            }
            $target = &$target[$part];
        }

        return $data;
    }

    private function defaultTestCommand(string $path): string
    {
        $composer = is_file($path . '/composer.json') ? json_decode((string) file_get_contents($path . '/composer.json'), true) : null;
        $scripts = is_array($composer) && is_array($composer['scripts'] ?? null) ? $composer['scripts'] : [];

        return array_key_exists('test', $scripts) ? 'composer test' : 'vendor/bin/phpunit';
    }

    private function assertCleanGitTree(string $path): void
    {
        $status = $this->processes->run(['git', '-C', $path, 'status', '--porcelain'], null, [], 60);
        if (!$status->successful()) {
            throw new ToolError('The app is not a git working copy (or git is missing), so BOOSTER cannot make the change reversible. Put it under git, or pass --allow-dirty.');
        }
        if (trim($status->stdout) !== '') {
            throw new ToolError('The working copy has uncommitted changes. Commit or stash them so the upgrade diff stands alone, or pass --allow-dirty.');
        }
    }
}
