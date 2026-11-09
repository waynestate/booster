<?php

declare(strict_types=1);

namespace Waynestate\Booster\Report;

use Waynestate\Booster\Console\Invocation;
use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Plan\Plan;
use Waynestate\Booster\Plan\PlanStep;
use Waynestate\Booster\Plan\Planner;
use Waynestate\Booster\Run\BaselineResult;
use Waynestate\Booster\Run\ProctorResult;
use Waynestate\Booster\Run\RunReport;
use Waynestate\Booster\Run\StepResult;
use Waynestate\Booster\Scan\BehaviourEntry;
use Waynestate\Booster\Scan\BehaviourResult;
use Waynestate\Booster\Scan\ComposerAuditResult;
use Waynestate\Booster\Scan\DynamicPropertiesResult;
use Waynestate\Booster\Scan\DynamicPropertiesScan;
use Waynestate\Booster\Scan\RemovalsResult;
use Waynestate\Booster\Scan\ScanResult;

/**
 * Plain text for people. Everything printed from an app (paths, diffs) is
 * stripped of control characters first, so a hostile file name cannot move the
 * terminal cursor or rewrite the report.
 */
final class TextWriter
{
    /** How many findings the text report shows before it says "and more". */
    private const MAX_FINDINGS = 20;

    /** How many PHPStan findings the plan shows before it points at `booster scan --phpstan`. */
    private const SHORT_FINDINGS = 5;

    /**
     * @param array<int, RemovalsResult>  $removalsByStep removals result per PHP step number
     * @param array<int, BehaviourResult> $behaviourByStep behaviour changes per step number
     * @param array<int, DynamicPropertiesResult> $dynamicByStep dynamic properties on the step that crosses PHP 8.2
     */
    public function plan(Plan $plan, array $removalsByStep = [], array $behaviourByStep = [], array $dynamicByStep = []): string
    {
        $p = $plan->project;
        $lines = [
            sprintf('BOOSTER plan for %s', self::clean($p->path)),
            sprintf('  app type: %s', $p->type->value),
            sprintf('  PHP: %s -> %s', $plan->fromPhp ?? $p->phpVersion ?? 'unknown', $plan->targetPhp ?? '?'),
        ];
        if ($plan->targetLaravel !== null) {
            $lines[] = sprintf('  Laravel: %s -> %s', $p->laravelMajor ?? 'unknown', $plan->targetLaravel);
        }
        if ($plan->targetPhpunit !== null) {
            $lines[] = sprintf('  PHPUnit: %s -> %s', $p->phpunitMajor ?? 'unknown', $plan->targetPhpunit);
        }
        $lines[] = sprintf('  policy: %s (today %s)', self::clean($plan->policyOrigin), $plan->today);
        if ($p->hasBoost) {
            $lines[] = '  Laravel Boost found: the playbooks include Boost prompts.';
        }
        $lines[] = '';

        foreach ($plan->steps as $step) {
            $lines[] = $this->stepLine($step);
            $lines[] = '   ' . $step->hop->summary;
            if (in_array(Planner::GATE_TESTS, $step->gates, true)) {
                $lines[] = '   After this step your tests run in Docker.';
            }
            if ($step->hop->extraReview) {
                $lines[] = '   Give this hop the most review time.';
            }
            $removals = $removalsByStep[$step->number] ?? null;
            if ($removals instanceof RemovalsResult) {
                foreach ($this->removals($removals) as $line) {
                    $lines[] = '   ' . $line;
                }
            }
            $dynamic = $dynamicByStep[$step->number] ?? null;
            if ($dynamic instanceof DynamicPropertiesResult) {
                foreach ($this->dynamicProperties($dynamic) as $line) {
                    $lines[] = '   ' . $line;
                }
            }
            $behaviour = $behaviourByStep[$step->number] ?? null;
            if ($behaviour instanceof BehaviourResult) {
                foreach ($this->behaviour($behaviour) as $line) {
                    $lines[] = '   ' . $line;
                }
            }
        }
        if ($plan->steps === []) {
            $lines[] = '(no steps)';
        }
        $vendored = $this->vendoredLibraries($plan);
        if ($vendored !== []) {
            $lines[] = '';
            foreach ($vendored as $line) {
                $lines[] = $line;
            }
        }
        foreach ($plan->warnings as $warning) {
            $lines[] = '';
            $lines[] = 'Warning: ' . $warning;
        }

        return implode("\n", $lines);
    }

    /**
     * The hand-copied libraries block: Booster names the cargo it refuses to
     * rewrite, the Composer package that would carry the same load, and why
     * the app still has to prove the copies run on the new PHP itself. Empty
     * when the plan found none.
     *
     * @return list<string>
     */
    public function vendoredLibraries(Plan $plan): array
    {
        if ($plan->vendoredLibraries === []) {
            return [];
        }
        $target = $plan->targetPhp !== null ? self::clean($plan->targetPhp) : 'the target PHP';
        $lines = [sprintf('Warning: hand-copied libraries found. Booster never rewrites them and cannot show they run on PHP %s:', $target)];
        foreach ($plan->vendoredLibraries as $library) {
            $name = self::clean($library->name);
            $version = $library->version !== null ? self::clean($library->version) : 'version unknown';
            $label = $library->version !== null ? $name . ' ' . $version : $name . ', ' . $version;
            $replace = $library->replaceWith !== null ? ': replace with the Composer package ' . self::clean($library->replaceWith) : '';
            $lines[] = sprintf('  - %s (%s)%s', $label, self::clean($library->path), $replace);
        }
        $lines[] = "Libraries copied in by hand are often written for an older PHP and can crash even when Booster's changes to your own code are clean. Replace them with Composer packages, then run your tests on the new PHP.";
        foreach ($plan->steps as $step) {
            if ($step->hop->id === HopRegistry::VENDORED_TO_COMPOSER_ID) {
                $lines[] = sprintf('Step %d (%s) does that for the Doctrine 2, Smarty 3 and Slim 1 copies; any other copy stays and is listed in its result.', $step->number, $step->hop->id);
                break;
            }
        }

        return $lines;
    }

    /**
     * The heading line of one plan step: the number and the id, plus a bracket
     * only when it says something. The two built-in gates say their thing in
     * plain words (the Docker note after the summary, the manual marker in the
     * bracket), so they never appear as ids; any other gate keeps its id in
     * the bracket.
     */
    private function stepLine(PlanStep $step): string
    {
        $gates = array_values(array_diff($step->gates, [Planner::GATE_TESTS, Planner::GATE_MANUAL]));
        if ($step->hop->isManual()) {
            $gates = ['manual: you do this one by hand', ...$gates];
        }
        if ($gates === []) {
            return sprintf('%d. %s', $step->number, $step->hop->id);
        }

        return sprintf('%d. %s  [%s]', $step->number, $step->hop->id, implode(', ', $gates));
    }

    /**
     * The closing note after a plan: nothing was changed, what the list means,
     * and the two commands that go next, with the plan's own flags so they
     * run the plan just shown (a 2012 app with no composer.json needs its
     * --from-php on every line).
     *
     * @param list<string> $flags the plan-shaping flags as typed (BaseCommand::planFlags)
     */
    public function planFooter(Plan $plan, string $path, string $program, array $flags = []): string
    {
        if ($plan->steps === []) {
            return 'Nothing was changed. Your app is already at the target, so there is nothing to run.';
        }

        $manual = false;
        foreach ($plan->steps as $step) {
            if ($step->hop->isManual()) {
                $manual = true;
                break;
            }
        }
        $pathArg = Invocation::pathArg($path) . Invocation::flagsText($flags);

        return implode("\n", [
            'Nothing was changed. The numbered list above is the order Booster would do things; "PHPCompatibility check" and "PHPStan" are advice',
            'for you to read, not errors.',
            'Booster does every numbered step above when you run it.' . ($manual ? ' Steps marked [manual: you do this one by hand] are yours to do by hand.' : ''),
            sprintf('Preview the edits: %s run --path=%s --dry-run', $program, $pathArg),
            sprintf('(The real run needs Docker, and your app committed in git: %s run --path=%s)', $program, $pathArg),
        ]);
    }

    /**
     * The advisory PHPCompatibility section that follows a plan: what will
     * break on the first PHP hop's target, or why the scan could not run.
     */
    public function phpCompat(ScanResult $scan): string
    {
        if (!$scan->ok) {
            $lines = ['PHPCompatibility scan: could not run'];
            foreach (explode("\n", self::clean((string) $scan->error)) as $line) {
                $lines[] = '   ' . $line;
            }

            return implode("\n", $lines);
        }

        if ($scan->findings === []) {
            return sprintf('PHPCompatibility check for PHP %s: no findings.', $scan->testVersion);
        }

        $errors = $scan->errorCount();
        $warnings = $scan->warningCount();
        $lines = [sprintf(
            'PHPCompatibility check for PHP %s: %d %s, %d %s.',
            $scan->testVersion,
            $errors,
            $errors === 1 ? 'error' : 'errors',
            $warnings,
            $warnings === 1 ? 'warning' : 'warnings',
        )];
        foreach (array_slice($scan->findings, 0, self::MAX_FINDINGS) as $finding) {
            $lines[] = sprintf('   %s:%d [%s] %s', self::clean($finding->file), $finding->line, $finding->severity, self::clean($finding->message));
        }
        $hidden = count($scan->findings) - self::MAX_FINDINGS;
        if ($hidden > 0) {
            $lines[] = sprintf('   (%d more findings...)', $hidden);
        }

        return implode("\n", $lines);
    }

    /**
     * The advisory PHPStan section that follows a plan: what static analysis
     * says at the target PHP version, or why the scan could not run. With
     * $short (the plan) only the first five findings print, and the tail
     * points at `booster scan --phpstan` for the rest; $short false (the
     * scan command) lists up to MAX_FINDINGS as before.
     */
    public function phpStan(ScanResult $scan, bool $short = false): string
    {
        if (!$scan->ok) {
            $lines = ['PHPStan scan: could not run'];
            foreach (explode("\n", self::clean((string) $scan->error)) as $line) {
                $lines[] = '   ' . $line;
            }

            return implode("\n", $lines);
        }

        $count = count($scan->findings);
        $lines = [$count === 0
            ? sprintf('PHPStan on PHP %s: no findings.', $scan->testVersion)
            : sprintf('PHPStan on PHP %s: %d %s (code quality hints, not upgrade blockers).', $scan->testVersion, $count, $count === 1 ? 'finding' : 'findings')];
        $limit = $short ? self::SHORT_FINDINGS : self::MAX_FINDINGS;
        foreach (array_slice($scan->findings, 0, $limit) as $finding) {
            $tag = $finding->source !== '' ? $finding->source : $finding->severity;
            $lines[] = sprintf('   %s:%d [%s] %s', self::clean($finding->file), $finding->line, $tag, self::clean($finding->message));
        }
        $hidden = count($scan->findings) - $limit;
        if ($hidden > 0) {
            $lines[] = $short
                ? sprintf('   (%d more; run `booster scan --phpstan` to see all)', $hidden)
                : sprintf('   (%d more findings...)', $hidden);
        }
        foreach ($scan->hints as $hint) {
            $lines[] = '   Hint: ' . self::clean($hint);
        }

        return implode("\n", $lines);
    }

    /**
     * The advisory removals block for one PHP hop: what the language removes
     * between the hop's two versions, and where the app still uses it.
     * $maxFindings caps the listed lines; null lists every finding
     * (booster scan --removals).
     *
     * @return list<string>
     */
    public function removals(RemovalsResult $result, ?int $maxFindings = self::MAX_FINDINGS): array
    {
        if ($result->total === 0) {
            return [sprintf('Removed between PHP %s and PHP %s: none found.', $result->from, $result->to)];
        }

        $lines = [sprintf(
            'Removed between PHP %s and PHP %s: %d hit%s (advisory).',
            $result->from,
            $result->to,
            $result->total,
            $result->total === 1 ? '' : 's',
        )];
        $shown = $maxFindings === null ? $result->findings : array_slice($result->findings, 0, $maxFindings);
        foreach ($shown as $finding) {
            $lines[] = sprintf('%s:%d %s -> %s', self::clean($finding->file), $finding->line, self::clean($finding->label), self::clean($finding->replacement));
        }
        $hidden = $result->total - count($shown);
        if ($hidden > 0) {
            $lines[] = sprintf('(%d more...)', $hidden);
        }

        return $lines;
    }

    /**
     * The dynamic properties block for the step that crosses PHP 8.2, as
     * plan prints it, or as the run's MANUAL lines ($manual). A run that
     * found nothing says nothing; a plan says how many classes it read, so
     * "none found" is a reading, not a guess.
     *
     * @return list<string>
     */
    public function dynamicProperties(DynamicPropertiesResult $result, bool $manual = false): array
    {
        $php = DynamicPropertiesScan::DEPRECATED_IN;
        $count = count($result->findings);
        if ($count === 0 && $result->unchecked === []) {
            return $manual ? [] : [sprintf('Dynamic properties (deprecated in PHP %s): none found in the %d class%s Booster read.', $php, $result->classes, $result->classes === 1 ? '' : 'es')];
        }
        $lines = [];
        if ($count > 0 && !$manual) {
            $lines[] = sprintf('Dynamic properties (deprecated in PHP %s): %d found. Booster does not change them; declare each one in its class, or mark the class #[\AllowDynamicProperties]:', $php, $count);
        }
        foreach (array_slice($result->findings, 0, self::MAX_FINDINGS) as $finding) {
            $where = sprintf('%s:%d %s::$%s', self::clean($finding->file), $finding->line, self::clean($finding->class), self::clean($finding->property));
            $lines[] = $manual
                ? sprintf('MANUAL: %s is a dynamic property, deprecated in PHP %s: declare it in the class, or mark the class #[\AllowDynamicProperties].', $where, $php)
                : '  ' . $where;
        }
        if ($count > self::MAX_FINDINGS) {
            $lines[] = sprintf($manual ? 'MANUAL: %d more dynamic properties; booster plan --format=json lists them all.' : '  (%d more...)', $count - self::MAX_FINDINGS);
        }
        if ($result->unchecked !== []) {
            $lines[] = sprintf(
                '%sDynamic properties (deprecated in PHP %s) not checked in %d class%s, because a parent or a trait is outside the app\'s own code: %s. Check %s by hand.',
                $manual ? 'MANUAL: ' : '',
                $php,
                count($result->unchecked),
                count($result->unchecked) === 1 ? '' : 'es',
                self::clean(implode('; ', $result->unchecked)),
                count($result->unchecked) === 1 ? 'it' : 'them',
            );
        }

        return $lines;
    }

    /**
     * The advisory behaviour-changes block for one hop: the curated silent
     * changes that apply, and where the app touches them. Sub-lines carry two
     * leading spaces; the caller adds its own indent.
     *
     * @return list<string>
     */
    public function behaviour(BehaviourResult $result): array
    {
        if ($result->entries === []) {
            return [];
        }
        $lines = ['Behavior changes to check:'];
        foreach ($result->entries as $entry) {
            $lines[] = $this->behaviourLine($entry);
            if ($entry->keepOld !== null) {
                $lines[] = '  keep old: ' . $entry->keepOld;
            }
            $lines[] = '  check: ' . $entry->check;
        }
        // One observable thing per row: record the before value now, then
        // compare it after the hop, so the change is visible or not.
        $parityEntries = array_values(array_filter($result->entries, static fn(BehaviourEntry $entry): bool => $entry->parity !== null));
        if ($parityEntries !== []) {
            $lines[] = 'Parity checks (record the before value now, compare after the hop):';
            foreach ($parityEntries as $entry) {
                /** @var array{what: string, before: string, after: string, how: string} $parity */
                $parity = $entry->parity;
                $lines[] = '  [ ] ' . self::clean($parity['what']);
                $lines[] = '      before: ' . self::clean($parity['before']);
                $lines[] = '      after: ' . self::clean($parity['after']);
                $lines[] = '      how: ' . self::clean($parity['how']);
            }
        }

        return $lines;
    }

    /**
     * One entry as a report line: the label, then where the app touches it.
     * Lexed kinds count hits per file; config and package rows name their
     * file; always rows carry no location.
     */
    private function behaviourLine(BehaviourEntry $entry): string
    {
        if ($entry->kind === 'always') {
            return $entry->label;
        }
        if ($entry->kind === 'config' || $entry->kind === 'package') {
            $hits = array_map(static fn(string $hit): string => self::clean($hit), $entry->hits);

            return $entry->label . ' (' . implode(', ', $hits) . ')';
        }
        $hits = array_map(static fn(string $hit): string => self::clean($hit), $entry->hits);
        $files = [];
        foreach ($hits as $hit) {
            $files[] = explode(':', $hit, 2)[0];
        }
        $files = array_values(array_unique($files));
        $shown = implode(', ', $hits);
        if ($entry->total > count($hits)) {
            $shown .= ' ...';
        }

        return sprintf('%s (%d in %d file%s: %s)', $entry->label, $entry->total, count($files), count($files) === 1 ? '' : 's', $shown);
    }

    /**
     * The advisory composer audit block for one hop: what the audit found in
     * the app's packages after the hop changed its composer files, or why the
     * audit could not run. Null and a clean audit print nothing: the hop's
     * own lines already say what changed, and an empty section would only add
     * noise. Sub-lines carry three leading spaces; the caller adds its own
     * indent.
     *
     * @return list<string>
     */
    public function composerAudit(?ComposerAuditResult $audit): array
    {
        if ($audit === null || ($audit->ok && !$audit->hasFindings())) {
            return [];
        }
        if (!$audit->ok) {
            $lines = ['Composer audit: could not run'];
            foreach (explode("\n", self::clean((string) $audit->error)) as $line) {
                $lines[] = '   ' . $line;
            }

            return $lines;
        }

        $advisories = count($audit->advisories);
        $abandoned = count($audit->abandoned);
        $lines = [sprintf(
            'Composer audit after this hop: %d %s, %d %s (hints, not upgrade blockers).',
            $advisories,
            $advisories === 1 ? 'advisory' : 'advisories',
            $abandoned,
            $abandoned === 1 ? 'abandoned package' : 'abandoned packages',
        )];
        foreach ($audit->advisories as $finding) {
            $id = $finding['id'] !== '' ? $finding['id'] : $finding['cve'];
            if ($finding['id'] !== '' && $finding['cve'] !== '') {
                $id .= ' (' . $finding['cve'] . ')';
            }
            $line = self::clean($finding['package']);
            if ($id !== '') {
                $line .= ': ' . self::clean($id);
            }
            if ($finding['affected_versions'] !== '') {
                $line .= ' affects ' . self::clean($finding['affected_versions']);
            }
            if ($finding['title'] !== '') {
                $line .= ': ' . self::clean($finding['title']);
            }
            $lines[] = '   ' . $line;
        }
        foreach ($audit->abandoned as $package => $replacement) {
            $line = self::clean($package) . ' is abandoned';
            $lines[] = '   ' . $line . ($replacement !== '' ? ' (use ' . self::clean($replacement) . ' instead).' : '.');
        }

        return $lines;
    }

    /**
     * @param list<string> $flags the flags the real run needs, as typed (RunCommand::runFlags)
     */
    public function run(RunReport $report, ?string $path = null, ?string $program = null, array $flags = []): string
    {
        $path ??= $report->plan->project->path;
        $program ??= Invocation::program();
        $lines = [$report->dryRun ? 'BOOSTER dry run (nothing was changed)' : 'BOOSTER run', ''];
        if ($report->testEnvKeys !== []) {
            // Keys only: the values may hold credentials and never reach a report.
            $lines[] = sprintf('Test environment: %s (values are not shown).', implode(', ', $report->testEnvKeys));
            $lines[] = '';
        }
        foreach ($this->baselineLines($report->baseline) as $line) {
            $lines[] = $line;
        }
        // --replay only: how the replay baseline went, right under the test baseline.
        foreach ($report->replay->lines ?? [] as $line) {
            $lines[] = self::clean($line);
        }
        // Without --replay, on a gate that proves little: the nudge toward it.
        foreach ($report->replaySuggestion?->lines($program, Invocation::pathArg($path)) ?? [] as $line) {
            $lines[] = self::clean($line);
        }
        $lines[] = '';
        $previewedFiles = [];
        foreach ($report->results as $r) {
            $count = $r->incomplete ? '(incomplete)' : sprintf('(%d file%s)', count($r->files), count($r->files) === 1 ? '' : 's');
            $lines[] = sprintf('%d. %s: %s %s', $r->step, $r->hopId, strtoupper($r->status), $count);
            foreach ($r->messages as $message) {
                foreach (explode("\n", self::clean($message)) as $line) {
                    $lines[] = '   ' . $line;
                }
            }
            if ($report->dryRun) {
                foreach ($r->files as $file) {
                    $previewedFiles[$file['file']] = true;
                    $lines[] = '   --- ' . self::clean($file['file']);
                    foreach (explode("\n", self::clean($file['diff'])) as $line) {
                        $lines[] = '   ' . $line;
                    }
                }
            } else {
                foreach ($r->files as $file) {
                    $lines[] = '   changed ' . self::clean($file['file']);
                }
            }
            if ($r->prepass !== []) {
                $lines[] = '   Pre-pass fixes:';
                foreach ($r->prepass as $fix) {
                    $lines[] = sprintf('      %s: %d %s', self::clean($fix['file']), $fix['rewrites'], $fix['rewrites'] === 1 ? 'rewrite' : 'rewrites');
                }
            }
            // The advisory composer audit, when this hop changed the app's
            // composer files and the audit found something or could not run.
            foreach ($this->composerAudit($r->composerAudit) as $line) {
                $lines[] = '   ' . $line;
            }
        }
        $lines[] = '';
        if ($report->dryRun && $previewedFiles !== []) {
            $lines[] = sprintf('Unique files across hops: %d. Later hops were previewed against the original tree, so the same file can appear in several hops.', count($previewedFiles));
            $lines[] = '';
        }
        foreach ($this->proctorLines($report) as $line) {
            $lines[] = $line;
        }
        if ($report->deployScript !== null) {
            $lines[] = 'Added deployments/' . $report->deployScript . ': Citadel runs it once, first, so existing deployments install the upgraded vendor/.';
            $lines[] = 'Undo with: rm deployments/' . $report->deployScript . ' (inside the app).';
        }
        $vendored = $this->vendoredLibraries($report->plan);
        if ($vendored !== []) {
            $lines[] = '';
            foreach ($vendored as $line) {
                $lines[] = $line;
            }
        }
        if ($report->dryRun && !$report->failed()) {
            // A dry run never runs the gate, so a green preview is not a green app.
            $lines[] = 'RESULT: PASS (previewed, no tests ran)';
        } else {
            $lines[] = 'RESULT: ' . ($report->failed() ? 'FAIL' : 'PASS');
        }
        $lines[] = '';
        foreach (explode("\n", $this->runFooter($report, $path, $program, $previewedFiles, $flags)) as $line) {
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * The closing note after a run: what the result means and what to type
     * next, or where the upgrade stopped. A dry run or a run that changed
     * nothing says "Nothing was changed."; a failed real run names the step
     * it stopped at, or that it never started.
     *
     * @param array<string, true> $previewedFiles the dry-run files, keyed by name
     * @param list<string>        $flags          the flags the real run needs, so the Next line runs the run just previewed
     */
    private function runFooter(RunReport $report, string $path, string $program, array $previewedFiles, array $flags = []): string
    {
        if ($report->dryRun) {
            if ($report->failed() || $previewedFiles === []) {
                return 'Nothing was changed.';
            }

            return implode("\n", [
                'Dry run: these changes were previewed, none were applied.',
                'Nothing was changed. RESULT: PASS means the preview worked; no tests ran.',
                sprintf('Next, if the edits look right: %s run --path=%s%s', $program, Invocation::pathArg($path), Invocation::flagsText($flags)),
                '(It needs Docker and a clean git folder. Commit or stash first.)',
            ]);
        }

        if (!$report->failed()) {
            if (!$this->anythingChanged($report)) {
                return 'Nothing was changed.';
            }

            return implode("\n", [
                sprintf('Booster edited your files but made no commit. Next: review with `git -C %s diff`.', Invocation::pathArg($path)),
                'Also read the notes under each step ("Behavior changes to check", manual lines) and run your own checks.',
                'When you are happy, commit.',
                'Booster never commits or pushes.',
            ]);
        }

        foreach ($report->results as $result) {
            if ($result->failed()) {
                return implode("\n", [
                    sprintf('The upgrade stopped at step %d (%s). Steps after it were not run. Changes so far are still in your folder.', $result->step, $result->hopId),
                    'Read the failure above, then either fix it by hand or undo with the "Undo with:" line under that step.',
                    'Booster made no commit.',
                ]);
            }
        }
        if ($report->baseline->failed() && $this->nothingRan($report)) {
            return 'The upgrade did not start: the app failed its check before any change. Fix that first, then run again. Nothing was changed.';
        }
        if (($report->replay?->failed() ?? false) && $this->nothingRan($report)) {
            return 'The upgrade did not start: the replay baseline could not be recorded. Fix that first (booster replay record shows the same thing faster), then run again. Nothing was changed.';
        }
        if (!$this->anythingChanged($report)) {
            return 'Nothing was changed.';
        }

        return implode("\n", [
            'Changes so far are still in your folder.',
            'Booster made no commit.',
        ]);
    }

    /** Whether a real run left anything in the folder: a step applied, or the deploy script was added. */
    private function anythingChanged(RunReport $report): bool
    {
        if ($report->deployScript !== null) {
            return true;
        }
        foreach ($report->results as $result) {
            if ($result->status === StepResult::APPLIED) {
                return true;
            }
        }

        return false;
    }

    /** Whether no step got as far as doing anything: a stop before the first step. */
    private function nothingRan(RunReport $report): bool
    {
        foreach ($report->results as $result) {
            if ($result->status !== StepResult::NOT_RUN) {
                return false;
            }
        }

        return true;
    }

    /**
     * The PROCTOR gate section: what the policy check said about the upgraded
     * app, its failing findings split against the baseline measured before the
     * first hop, or nothing when the gate did not run.
     *
     * @return list<string>
     */
    private function proctorLines(RunReport $report): array
    {
        $proctor = $report->proctor;
        if ($proctor === null) {
            return [];
        }
        $comparison = $report->proctorComparison;
        $php = $proctor->php ?? '?';
        $advisory = match (true) {
            !$report->proctorAdvisory => '',
            $report->plan->stopsEarly => ' PROCTOR advisory: this run stops before the final target.',
            default => ' Advisory only (--proctor-advisory), so this does not fail the run.',
        };
        $lines = [];
        switch ($proctor->status) {
            case ProctorResult::PASSED:
                $lines[] = sprintf('PROCTOR: PASS on PHP %s (the upgraded app meets the policy).', $php);
                break;
            case ProctorResult::FAILED:
                if ($comparison !== null && $comparison->baselineTaken) {
                    if ($comparison->new === [] && $comparison->alreadyPresent !== []) {
                        // Nothing new: every failure predates the upgrade, so the gate reports it as an advisory.
                        $lines[] = sprintf('PROCTOR: no new findings on PHP %s (%d already present before the upgrade; advisory).', $php, count($comparison->alreadyPresent));
                    } else {
                        $lines[] = sprintf('PROCTOR: FAIL on PHP %s (%d new, %d already present).%s', $php, count($comparison->new), count($comparison->alreadyPresent), $advisory);
                    }
                } else {
                    $count = count($proctor->failures);
                    $lines[] = sprintf('PROCTOR: FAIL on PHP %s (%d finding%s).%s', $php, $count, $count === 1 ? '' : 's', $advisory);
                }
                break;
            default:
                $lines[] = sprintf('PROCTOR: TRIPPED on PHP %s (exit %d). The check could not finish.%s', $php, $proctor->exitCode, $advisory);
                if ($proctor->error !== null && $proctor->error !== '') {
                    foreach (explode("\n", self::clean($proctor->error)) as $line) {
                        $lines[] = '   ' . $line;
                    }
                }
                $lines[] = '   Fix the tool error above, then run the upgrade again.';
                break;
        }
        if ($comparison !== null) {
            if (!$comparison->baselineTaken) {
                $lines[] = '   PROCTOR baseline could not be taken: ' . self::clean((string) $comparison->baselineError) . '. The run blocks on any failure until there is a baseline to compare against.';
            }
            if ($comparison->new !== []) {
                $lines[] = '   New:';
                foreach ($comparison->new as $failure) {
                    $lines[] = $this->findingLine($failure);
                }
                $lines[] = '   Fix the findings above, then run the upgrade again.';
            }
            if ($comparison->alreadyPresent !== []) {
                $lines[] = '   Already present (not blocking):';
                foreach ($comparison->alreadyPresent as $failure) {
                    $lines[] = $this->findingLine($failure);
                }
            }
            if ($comparison->fixed !== []) {
                $lines[] = '   Fixed by the upgrade:';
                foreach ($comparison->fixed as $failure) {
                    $lines[] = $this->findingLine($failure);
                }
                if ($comparison->phpFloorFixed()) {
                    $lines[] = '   php-floor: fixed by the upgrade.';
                }
            }
        }
        if ($comparison === null && $proctor->status === ProctorResult::FAILED && $proctor->failures !== []) {
            // No steps means no before/after to split, so the findings stand as-is.
            $lines[] = '   Findings:';
            foreach ($proctor->failures as $failure) {
                $lines[] = $this->findingLine($failure);
            }
            $lines[] = '   Fix the findings above.';
        }

        return $lines;
    }

    /**
     * One finding as a report line: check, message and where it was found.
     *
     * @param array{check: string, rule: string, message: string, path: ?string, line: int} $failure
     */
    private function findingLine(array $failure): string
    {
        $where = $failure['path'] !== null ? ' (' . self::clean($failure['path']) . ':' . $failure['line'] . ')' : '';

        return '   [' . $failure['check'] . '] ' . self::clean($failure['message']) . $where;
    }

    /**
     * @return list<string>
     */
    private function baselineLines(BaselineResult $baseline): array
    {
        $php = $baseline->php ?? '?';
        $lines = match ($baseline->status) {
            BaselineResult::PASSED => [sprintf('Baseline: PASS on PHP %s (%s).', $php, $this->baselinePassReason($baseline))],
            BaselineResult::FAILED => [
                sprintf('Baseline: FAIL on PHP %s (exit %d).', $php, $baseline->exitCode ?? -1),
                $this->baselineFailReason($baseline),
            ],
            BaselineResult::INSTALL_FAILED => [
                sprintf('Baseline: composer install failed (exit %d) on PHP %s.', $baseline->exitCode ?? -1, $php),
                $baseline->advice ?? "The gate never ran, so its state is unknown; fix the app's composer.json and composer.lock, or pass --allow-failing-baseline.",
            ],
            BaselineResult::SKIPPED => ['Baseline: SKIPPED (no tests ran in this run). A real run first runs your tests as they are now (the baseline), then again after every step (the gate).'],
            default => [sprintf('Baseline: %s.', $baseline->status)],
        };
        // The syntax check is a built-in fallback, so say once what it does
        // and what it cannot prove. Skipped runs check nothing, so no note.
        if ($baseline->gateKind === BaselineResult::GATE_SYNTAX && $baseline->status !== BaselineResult::SKIPPED) {
            $lines[] = 'No tests found, so each step checks that every PHP file still parses (php -l). That proves the code loads on the new PHP, not that it works: click through the app before you ship.';
        }
        if ($baseline->warning !== null) {
            $lines[] = $baseline->warning;
        }
        if (in_array($baseline->status, [BaselineResult::FAILED, BaselineResult::INSTALL_FAILED], true)) {
            $joined = implode("\n", $baseline->output);
            if (preg_match('/mix-manifest\.json|Vite manifest not found|public\/build\/manifest\.json/i', $joined) === 1) {
                $lines[] = 'The output names a missing build artifact (a Mix or Vite manifest): build the front-end assets first (for example npm ci && npm run build), or pass --allow-failing-baseline.';
            }
            foreach ($baseline->output as $line) {
                $lines[] = '   ' . self::clean($line);
            }
        }

        return $lines;
    }

    /** What a green baseline proved, named by what the baseline ran. */
    private function baselinePassReason(BaselineResult $baseline): string
    {
        return match ($baseline->gateKind) {
            BaselineResult::GATE_SYNTAX => 'syntax check: the app has no tests',
            BaselineResult::GATE_CUSTOM => 'your test command passed before any change',
            default => 'tests green before any change',
        };
    }

    /** Why a red baseline stops the run, named by what the baseline ran. */
    private function baselineFailReason(BaselineResult $baseline): string
    {
        if ($baseline->gateKind === BaselineResult::GATE_SYNTAX) {
            return sprintf('A PHP file does not parse on PHP %s before any change. Fix it or pass --allow-failing-baseline.', $baseline->php ?? '?');
        }

        return "The app's tests fail before any change; fix them or pass --allow-failing-baseline.";
    }

    /** Drops control characters except newline and tab. */
    public static function clean(string $text): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $text);
    }
}
