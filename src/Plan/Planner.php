<?php

declare(strict_types=1);

namespace Waynestate\Booster\Plan;

use DateTimeImmutable;
use Waynestate\Booster\Hop\HopKind;
use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Policy\Policy;
use Waynestate\Booster\Policy\PolicyException;
use Waynestate\Booster\Project\Project;
use Waynestate\Booster\Refresh\HopDataRefresher;
use Waynestate\Booster\ToolError;
use Waynestate\Creed\State;

/**
 * Orders the hops. The rules, all from the best-practices guide:
 *  - one PHP minor or one Laravel major per step;
 *  - the app only ever sits on a PHP and Laravel pair that Laravel supports;
 *  - the target must be a version the policy knows; a target past security
 *    support is allowed as a stop on the way up, with a warning.
 */
final class Planner
{
    public const GATE_TESTS = 'target-image-tests';

    public const GATE_MANUAL = 'manual-review';

    public function __construct(
        private readonly Policy $policy,
        private readonly HopRegistry $registry,
        private readonly ?PackageFetcher $packages = null,
    ) {}

    public function plan(Project $project, PlanOptions $options, DateTimeImmutable $today): Plan
    {
        $warnings = [];

        $fromPhp = $options->fromPhp ?? $project->phpVersion;
        if ($fromPhp === null) {
            throw new ToolError(
                $project->hasComposer
                    ? 'composer.json has no usable "php" constraint. Pass --from-php=<major.minor>.'
                    : 'No composer.json found. Pass --from-php=<major.minor> to say which PHP the app runs on.',
            );
        }
        $fromPhp = $this->normalisePhp($fromPhp, $options->fromPhp !== null ? '--from-php' : 'composer.json');

        $fromLaravel = null;
        if ($project->type->isLaravel()) {
            $fromLaravel = $options->fromLaravel ?? $project->laravelMajor;
            if ($fromLaravel === null) {
                throw new ToolError('Could not read the Laravel version from composer.json. Pass --from-laravel=<major>.');
            }
            $laravelChain = $this->registry->laravelChain();
            if (!in_array($fromLaravel, $laravelChain, true)) {
                throw new ToolError(sprintf('Laravel %s is outside what BOOSTER can plan (%s to %s).', $fromLaravel, $laravelChain[0], $laravelChain[count($laravelChain) - 1]));
            }
            if (!$this->registry->laravelSupportsPhp($fromLaravel, $fromPhp)) {
                $warnings[] = sprintf('Laravel %s does not list PHP %s; the plan assumes the app really runs on that pair.', $fromLaravel, $fromPhp);
            }
        }

        $supportedPhp = $this->policy->supported('php', $today);
        $toPhp = $options->toPhp !== null ? $this->normalisePhp($options->toPhp, '--to-php') : $this->highest($supportedPhp, 'php');
        $eolTarget = $this->assertTarget('php', $toPhp, $today, $warnings);

        $toLaravel = null;
        if ($fromLaravel !== null) {
            $toLaravel = $options->toLaravel ?? $this->highest($this->policy->supported('laravel', $today), 'laravel');
            $eolTarget = $this->assertTarget('laravel', $toLaravel, $today, $warnings) || $eolTarget;
            if (version_compare($toLaravel, $fromLaravel, '<')) {
                throw new ToolError(sprintf('Target Laravel %s is older than the current Laravel %s.', $toLaravel, $fromLaravel));
            }
            if (!$this->registry->laravelSupportsPhp($toLaravel, $toPhp)) {
                throw new ToolError(sprintf('Laravel %s does not run on PHP %s. Pick a PHP and Laravel pair that Laravel supports.', $toLaravel, $toPhp));
            }
        }
        if (version_compare($toPhp, $fromPhp, '<')) {
            throw new ToolError(sprintf('Target PHP %s is older than the current PHP %s.', $toPhp, $fromPhp));
        }

        $fromPhpunit = $project->phpunitMajor;
        $toPhpunit = null;
        $phpunitChain = $this->registry->phpunitChain();
        $phpunitBeforeManual = $fromPhpunit;
        $phpunitManualPending = false;
        if ($fromPhpunit !== null) {
            if (!in_array($fromPhpunit, $phpunitChain, true)) {
                if (version_compare($fromPhpunit, $phpunitChain[0], '<')) {
                    // PHPUnit 7 and 8 do not run on PHP 8, so the plan carries a
                    // manual move to 9 instead of skipping the PHPUnit hops.
                    $phpunitManualPending = true;
                    $fromPhpunit = $phpunitChain[0];
                } else {
                    throw new ToolError(sprintf('PHPUnit %s is outside what BOOSTER can plan (%s to %s).', $fromPhpunit, $phpunitChain[0], $phpunitChain[count($phpunitChain) - 1]));
                }
            }
        }
        if ($fromPhpunit !== null) {
            $toPhpunit = $options->toPhpunit ?? $this->newestPhpunitForPhp($toPhp);
            if (!in_array($toPhpunit, $phpunitChain, true)) {
                throw new ToolError(sprintf('--to-phpunit "%s" is not a PHPUnit version BOOSTER knows (use a major from %s to %s).', $toPhpunit, $phpunitChain[0], $phpunitChain[count($phpunitChain) - 1]));
            }
            $laravelFloor = $toLaravel !== null ? ($this->registry->laravelPhpunit()[$toLaravel] ?? null) : null;
            if ($laravelFloor !== null && version_compare($toPhpunit, $laravelFloor, '<')) {
                $toPhpunit = $laravelFloor;
            }
            $phpunitPhp = $this->registry->phpunitPhp();
            if (version_compare($toPhp, $phpunitPhp[$toPhpunit], '<')) {
                throw new ToolError(sprintf('PHPUnit %s needs PHP %s or newer, but the target PHP is %s.', $toPhpunit, $phpunitPhp[$toPhpunit], $toPhp));
            }
            if (version_compare($toPhpunit, $fromPhpunit, '<')) {
                throw new ToolError(sprintf('Target PHPUnit %s is older than the current PHPUnit %s.', $toPhpunit, $fromPhpunit));
            }
        }

        $steps = [];
        $php = $fromPhp;
        $laravel = $fromLaravel;
        $phpunit = $fromPhpunit;
        $sesamePlanned = false;
        $n = 0;

        for ($guard = 0; $guard < 64; $guard++) {
            $nextLaravel = $laravel !== null && $toLaravel !== null && $laravel !== $toLaravel ? $this->registry->nextLaravel($laravel) : null;
            $nextPhpunit = $phpunit !== null && $toPhpunit !== null && $phpunit !== $toPhpunit ? $this->registry->nextPhpunit($phpunit) : null;
            $nextPhp = $php !== $toPhp ? $this->registry->nextPhp($php) : null;

            // PHPUnit below 9 cannot run the gates on PHP 8, so the manual move
            // to 9 lands before the first step that targets PHP 8.0 or newer.
            if ($phpunitManualPending && (version_compare($php, '8.0', '>=') || ($nextPhp !== null && version_compare($nextPhp, '8.0', '>=')))) {
                $hop = $this->registry->get(HopRegistry::PHPUNIT_TO_9_MANUAL_ID);
                $steps[] = $this->step(++$n, $hop, $php, $php, $laravel, $laravel, $phpunitBeforeManual, $phpunit);
                $phpunitManualPending = false;
                continue;
            }

            // The SESAME swap needs PHP 8.3, so it goes in just before the hop
            // from Laravel 10 to 11. While PHP is still below 8.3 that Laravel
            // hop waits and the PHP hops go first.
            $sesameCrossing = $options->sesame
                && $project->usesWayneCas
                && !$sesamePlanned
                && $laravel === '10'
                && $nextLaravel === '11'
                && version_compare($toPhp, '8.3', '>=');
            if ($sesameCrossing && version_compare($php, '8.3', '>=')) {
                $steps[] = $this->step(++$n, $this->registry->get('cas-to-sesame'), $php, $php, $laravel, $laravel, $phpunit, $phpunit);
                $sesamePlanned = true;
                continue;
            }
            $holdLaravel = $sesameCrossing;

            if (!$holdLaravel && $nextLaravel !== null && $this->registry->has(HopRegistry::laravelHopId((string) $laravel, $nextLaravel)) && $this->registry->laravelSupportsPhp($nextLaravel, $php) && $this->phpunitMeetsLaravelFloor($phpunit, $nextLaravel)) {
                $hop = $this->registry->get(HopRegistry::laravelHopId((string) $laravel, $nextLaravel));
                $steps[] = $this->step(++$n, $hop, $php, $php, $laravel, $nextLaravel, $phpunit, $phpunit);
                $laravel = $nextLaravel;
                continue;
            }
            if ($nextPhpunit !== null && $this->registry->has(HopRegistry::phpunitHopId($phpunit, $nextPhpunit)) && version_compare($php, $this->registry->phpunitPhp()[$nextPhpunit], '>=')) {
                $hop = $this->registry->get(HopRegistry::phpunitHopId($phpunit, $nextPhpunit));
                $steps[] = $this->step(++$n, $hop, $php, $php, $laravel, $laravel, $phpunit, $nextPhpunit);
                $phpunit = $nextPhpunit;
                continue;
            }
            if ($nextPhp !== null && $this->registry->has(HopRegistry::phpHopId($php, $nextPhp)) && ($laravel === null || $this->registry->laravelSupportsPhp($laravel, $nextPhp))) {
                $hop = $this->registry->get(HopRegistry::phpHopId($php, $nextPhp));
                $steps[] = $this->step(++$n, $hop, $php, $nextPhp, $laravel, $laravel, $phpunit, $phpunit);
                $php = $nextPhp;
                continue;
            }
            if ($nextLaravel !== null || $nextPhpunit !== null || $nextPhp !== null) {
                $missing = [];
                if ($nextLaravel !== null && !$this->registry->has(HopRegistry::laravelHopId((string) $laravel, $nextLaravel))) {
                    $missing[] = 'Laravel ' . $nextLaravel;
                }
                if ($nextPhpunit !== null && !$this->registry->has(HopRegistry::phpunitHopId($phpunit, $nextPhpunit))) {
                    $missing[] = 'PHPUnit ' . $nextPhpunit;
                }
                if ($nextPhp !== null && !$this->registry->has(HopRegistry::phpHopId($php, $nextPhp))) {
                    $missing[] = 'PHP ' . $nextPhp;
                }
                if ($missing !== []) {
                    $warnings[] = sprintf(
                        'BOOSTER stops short of the target: it has no hop for %s yet because Rector ships no ruleset for it. The plan below is as far as BOOSTER can take you today.',
                        implode(', ', $missing),
                    );
                    break;
                }
                throw new ToolError(sprintf(
                    'No safe next step from PHP %s, Laravel %s and PHPUnit %s toward PHP %s, Laravel %s and PHPUnit %s.',
                    $php,
                    (string) $laravel,
                    (string) $phpunit,
                    $toPhp,
                    (string) $toLaravel,
                    (string) $toPhpunit,
                ));
            }
            break;
        }

        // The lock may allow a narrower PHP span than composer.json claims.
        // Say so before the hops: the floor moved the baseline, and a ceiling
        // below the first hop's landing point means that hop needs a
        // composer update to keep the lock installable.
        $lockFloorWarning = $project->lockFloorWarning();
        if ($lockFloorWarning !== null) {
            $warnings[] = $lockFloorWarning;
        }
        $firstPhpTarget = null;
        foreach ($steps as $step) {
            if ($step->hop->kind === HopKind::Php && $step->phpAfter !== null) {
                $firstPhpTarget = $step->phpAfter;
                break;
            }
        }
        $lockCeilingWarning = $project->lockCeilingWarning($firstPhpTarget);
        if ($lockCeilingWarning !== null) {
            $warnings[] = $lockCeilingWarning;
        }

        $frontEndHops = $this->frontEndHopIds($project, $laravel);
        $vueHops = $this->vueHopIds($project);
        $nodeHops = array_merge($frontEndHops, $vueHops);
        $nodeLeftOut = $nodeHops !== [] && $this->targetsBound($options) && !$options->withNode;
        if ($nodeLeftOut) {
            $warnings[] = sprintf(
                'Node front-end hops were left out because you passed a target version: %s. Add --with-node to include them.',
                implode(', ', $nodeHops),
            );
        } else {
            foreach ($frontEndHops as $id) {
                $steps[] = $this->step(++$n, $this->registry->get($id), $php, $php, $laravel, $laravel, $phpunit, $phpunit);
            }
        }

        if ($options->sesame && !$project->usesWayneCas) {
            $warnings[] = 'Asked for the SESAME swap, but composer.json does not require cit-web-infra/wayne-cas; skipped.';
        } elseif ($options->sesame && !$sesamePlanned && version_compare($php, '8.3', '<')) {
            $warnings[] = 'SESAME needs PHP 8.3 or newer; the swap was left out because the plan ends below that.';
        } elseif ($options->sesame && !$sesamePlanned) {
            $steps[] = $this->step(++$n, $this->registry->get('cas-to-sesame'), $php, $php, $laravel, $laravel, $phpunit, $phpunit);
        } elseif ($project->usesWayneCas) {
            $warnings[] = 'The app uses wayne-cas. Add --sesame to plan the swap to SESAME.';
        }

        if (!$nodeLeftOut) {
            foreach ($vueHops as $id) {
                $steps[] = $this->step(++$n, $this->registry->get($id), $php, $php, $laravel, $laravel, $phpunit, $phpunit);
            }
        }

        $steps = $this->withoutSkipped($steps, $options->skip);
        $selected = $options->until !== null || $options->only !== null;
        $beforeSelection = count($steps);
        $steps = $this->applyStepSelection($steps, $project, $options, $fromPhp, $fromLaravel, $phpunitBeforeManual);
        $stopsEarly = $eolTarget || ($selected && count($steps) < $beforeSelection);
        if ($selected) {
            // The cut plan ends where its last step lands, so the report and
            // the gates agree with the steps that remain.
            $last = $steps[count($steps) - 1];
            $toPhp = $last->phpAfter;
            $toLaravel = $last->laravelAfter;
            $toPhpunit = $last->phpunitAfter;
        }

        // The 8 to 9 hop drops fideloper/proxy, and HELMET takes over trusted
        // proxies for WSU apps. Suggest it once, only while the plan still
        // carries the hop and the app does not already require HELMET.
        foreach ($steps as $step) {
            if ($step->hop->id === HopRegistry::laravelHopId('8', '9') && !$project->hasHelmet) {
                $warnings[] = 'HELMET sets trusted proxies, security headers and session defaults for WSU apps: composer require waynestate/helmet';
                break;
            }
        }

        if ($steps === []) {
            $warnings[] = 'Nothing to do: the app is already at the target.';
        }

        $packageChecks = [];
        if ($this->packages !== null && !$options->noPackageCheck) {
            $report = (new PackageCompat($this->packages, $this->registry))->check($project, $steps);
            $warnings = [...$warnings, ...$report->warnings()];
            $packageChecks = $report->checks;
        }

        return new Plan($project, $today->format('Y-m-d'), $this->policy->origin(), $toPhp, $toLaravel, $toPhpunit, $steps, $warnings, $packageChecks, $stopsEarly);
    }

    /**
     * @param list<PlanStep> $steps
     * @param list<string>   $skip
     *
     * @return list<PlanStep>
     */
    private function withoutSkipped(array $steps, array $skip): array
    {
        $skipped = [];
        foreach ($skip as $id) {
            if (!$this->registry->has($id)) {
                throw new ToolError(sprintf('Cannot skip unknown hop "%s".', $id));
            }
            $skipped[$id] = true;
            foreach ($this->registry->get($id)->aliasOf as $aliased) {
                $skipped[$aliased] = true;
            }
        }

        $kept = [];
        foreach ($steps as $step) {
            if (!isset($skipped[$step->hop->id])) {
                $kept[] = new PlanStep(count($kept) + 1, $step->hop, $step->phpBefore, $step->phpAfter, $step->laravelBefore, $step->laravelAfter, $step->phpunitBefore, $step->phpunitAfter, $step->gates);
            }
        }

        return $kept;
    }

    /**
     * Applies --until and --only. --until keeps the named step and everything
     * before it. --only keeps just the named step, which must be the first
     * step of the plan, so the app really sits on its starting versions. An
     * id that is not in the plan is an error listing the step ids, like a bad
     * --skip.
     *
     * @param list<PlanStep> $steps
     *
     * @return list<PlanStep>
     */
    private function applyStepSelection(array $steps, Project $project, PlanOptions $options, string $fromPhp, ?string $fromLaravel, ?string $fromPhpunit): array
    {
        if ($options->until === null && $options->only === null) {
            return $steps;
        }
        if ($options->until !== null && $options->only !== null) {
            throw new ToolError('Pass either --until or --only, not both.');
        }
        $id = $options->only ?? $options->until;
        $flag = $options->only !== null ? 'only' : 'until';

        $index = null;
        foreach ($steps as $i => $step) {
            if ($step->hop->id === $id) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            $ids = array_map(static fn(PlanStep $step): string => $step->hop->id, $steps);
            throw new ToolError(sprintf(
                '--%s "%s" is not a step in the plan. %s',
                $flag,
                $id,
                $ids === [] ? 'The plan has no steps.' : 'The plan\'s step ids are: ' . implode(', ', $ids) . '.',
            ));
        }

        if ($options->only === null) {
            return array_slice($steps, 0, $index + 1);
        }

        $step = $steps[$index];
        $mismatch = $this->onlyStartMismatch($step, $project, $fromPhp, $fromLaravel, $fromPhpunit);
        if ($mismatch !== null) {
            throw new ToolError(sprintf('--only "%s": %s', $id, $mismatch));
        }
        if ($index > 0) {
            throw new ToolError(sprintf(
                '--only "%s": the plan runs this hop after "%s"; run the earlier steps first.',
                $id,
                $steps[$index - 1]->hop->id,
            ));
        }

        return [new PlanStep(1, $step->hop, $step->phpBefore, $step->phpAfter, $step->laravelBefore, $step->laravelAfter, $step->phpunitBefore, $step->phpunitAfter, $step->gates)];
    }

    /**
     * What --only refuses: the hop's starting versions versus what the app is
     * on now. Names each dimension that differs, or null when the hop starts
     * exactly where the plan starts.
     */
    private function onlyStartMismatch(PlanStep $step, Project $project, string $fromPhp, ?string $fromLaravel, ?string $fromPhpunit): ?string
    {
        $expected = [];
        $actual = [];
        if ($step->phpBefore !== null && $step->phpBefore !== $fromPhp) {
            $expected[] = 'PHP ' . $step->phpBefore;
            $actual[] = 'PHP ' . ($project->phpVersion ?? $fromPhp);
        }
        if ($step->laravelBefore !== null && $step->laravelBefore !== $fromLaravel) {
            $expected[] = 'Laravel ' . $step->laravelBefore;
            $actual[] = 'Laravel ' . ($project->laravelMajor ?? $fromLaravel);
        }
        if ($step->phpunitBefore !== null && $step->phpunitBefore !== $fromPhpunit) {
            $expected[] = 'PHPUnit ' . $step->phpunitBefore;
            $actual[] = 'PHPUnit ' . ($project->phpunitMajor ?? $fromPhpunit);
        }
        if ($expected === []) {
            return null;
        }

        return sprintf('it starts from %s, but the app is on %s.', implode(' and ', $expected), implode(' and ', $actual));
    }

    /**
     * The Vue hops a Vue 2 app still needs, in run order: the 2.6 to 2.7 step
     * when the app sits below 2.7, then the compat build that lands on Vue 3,
     * then the codemod that rewrites what it can. The manual playbook
     * (vue-2-to-3) is never planned; it stands in for all of them and skipping
     * it skips them.
     *
     * @return list<string>
     */
    private function vueHopIds(Project $project): array
    {
        if (!$project->hasPackageJson || $project->vueMajor !== '2') {
            return [];
        }
        $ids = [HopRegistry::VUE_27_TO_COMPAT_ID, HopRegistry::VUE_COMPAT_CODEMOD_ID];
        if (version_compare($project->vueMinor ?? '2.0', '2.7', '<')) {
            array_unshift($ids, HopRegistry::VUE_26_TO_27_ID);
        }

        return $ids;
    }

    private function step(int $n, \Waynestate\Booster\Hop\Hop $hop, string $phpBefore, string $phpAfter, ?string $lBefore, ?string $lAfter, ?string $puBefore, ?string $puAfter): PlanStep
    {
        return new PlanStep($n, $hop, $phpBefore, $phpAfter, $lBefore, $lAfter, $puBefore, $puAfter, $hop->isManual() ? [self::GATE_MANUAL] : [self::GATE_TESTS]);
    }

    /**
     * @param list<string> $versions
     */
    private function highest(array $versions, string $component): string
    {
        if ($versions === []) {
            throw new ToolError(sprintf('The policy lists no supported %s version today.', $component));
        }

        return $versions[count($versions) - 1];
    }

    /**
     * The target must be a version the policy knows. A target past security
     * support is allowed as a stop on the way up, with a warning naming the
     * date the security support ended; the plan then marks itself stopsEarly
     * so the PROCTOR gate cannot block a run that never reaches the final
     * target.
     *
     * @param list<string> $warnings
     *
     * @return bool whether the target is past security support
     */
    private function assertTarget(string $component, string $version, DateTimeImmutable $today, array &$warnings): bool
    {
        try {
            $state = $this->policy->stateOf($component, $version, $today);
        } catch (PolicyException) {
            throw new ToolError(sprintf('%s %s is not a version the policy supports. Pick one of: %s.', $this->componentName($component), $version, implode(', ', $this->policy->supported($component, $today))));
        }
        if ($state === State::Eol || $state === State::EolGraceExpired) {
            $until = $this->policy->securityUntil($component, $version);
            $since = $until instanceof DateTimeImmutable ? ' since ' . $until->format('Y-m-d') : '';
            $warnings[] = sprintf('%s %s is past security support%s. Use it only as a stop on the way up.', $this->componentName($component), $version, $since);

            return true;
        }
        if ($state === State::Security) {
            $warnings[] = sprintf('%s %s only gets security fixes now; a newer version is a better place to stop.', $this->componentName($component), $version);
        }
        if ($state === State::Active && $this->policy->isAheadOfPolicy($component, $version)) {
            $warnings[] = sprintf('%s %s is newer than CREED\'s policy; treated as current.', $this->componentName($component), $version);
        }

        return false;
    }

    private function normalisePhp(string $version, string $option): string
    {
        $chain = $this->registry->phpChain();
        if (preg_match('/^\d+\.\d+$/', $version) !== 1 || !in_array($version, $chain, true)) {
            throw new ToolError(sprintf('%s "%s" is not a PHP version BOOSTER knows (use major.minor, from %s to %s).', $option, $version, $chain[0], $chain[count($chain) - 1]));
        }

        return $version;
    }

    private function componentName(string $component): string
    {
        return $component === 'php' ? 'PHP' : ucfirst($component);
    }

    private function phpunitMeetsLaravelFloor(?string $phpunit, string $laravel): bool
    {
        if ($phpunit === null) {
            return true;
        }
        $floor = $this->registry->laravelPhpunit()[$laravel] ?? null;
        if ($floor === null) {
            return true;
        }

        return version_compare($phpunit, $floor, '>=');
    }

    /**
     * The app has a webpack.mix.js and will sit on Laravel 9 or newer (the
     * first major whose upgrade guide ships Vite). The hop is added after the
     * PHP and Laravel hops, so it lands after laravel-8-to-9 and after
     * node-pin-24, which it requires.
     */
    private function needsMixToVite(Project $project, ?string $laravel): bool
    {
        if (!$project->hasPackageJson || !$project->hasMixFile || $laravel === null) {
            return false;
        }

        return version_compare($laravel, HopRegistry::VITE_LARAVEL_FLOOR, '>=');
    }

    /**
     * The app has tailwindcss in package.json and its constraint floors below
     * Tailwind 4. Offered after node-pin-24 and mix-to-vite, so the upgrade
     * tool runs on the pinned Node and sees the Vite config when Mix moved.
     */
    private function needsTailwind(Project $project): bool
    {
        if (!$project->hasPackageJson) {
            return false;
        }
        $file = $project->path . '/package.json';
        if (!is_file($file)) {
            return false;
        }
        $package = json_decode((string) file_get_contents($file), true);
        if (!is_array($package)) {
            return false;
        }
        foreach (['dependencies', 'devDependencies'] as $section) {
            $constraint = $package[$section]['tailwindcss'] ?? null;
            if (is_string($constraint)) {
                $floor = HopDataRefresher::lowestMajor($constraint);
                if ($floor !== null && version_compare($floor, '4', '<')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The Node front-end hops that land before the SESAME swap, in run order:
     * the Node pin, Mix to Vite, Tailwind.
     *
     * @return list<string>
     */
    private function frontEndHopIds(Project $project, ?string $laravel): array
    {
        $ids = [];
        if ($this->needsNodePin($project)) {
            $ids[] = HopRegistry::NODE_PIN_ID;
        }
        if ($this->needsMixToVite($project, $laravel)) {
            $ids[] = HopRegistry::MIX_TO_VITE_ID;
        }
        if ($this->needsTailwind($project)) {
            $ids[] = HopRegistry::TAILWIND_ID;
        }

        return $ids;
    }

    /** True when the caller pinned at least one starting or target version. */
    private function targetsBound(PlanOptions $options): bool
    {
        return $options->fromPhp !== null
            || $options->toPhp !== null
            || $options->fromLaravel !== null
            || $options->toLaravel !== null
            || $options->toPhpunit !== null;
    }

    /** The app has a package.json without an engines.node floor at or above the pin target. */
    private function needsNodePin(Project $project): bool
    {
        if (!$project->hasPackageJson) {
            return false;
        }

        return $project->nodeMajor === null || version_compare($project->nodeMajor, $this->registry->nodePinTarget(), '<');
    }

    private function newestPhpunitForPhp(string $php): string
    {
        $chain = $this->registry->phpunitChain();
        $phpunitPhp = $this->registry->phpunitPhp();
        $newest = $chain[0];
        foreach ($chain as $version) {
            if (version_compare($php, $phpunitPhp[$version], '>=')) {
                $newest = $version;
            }
        }

        return $newest;
    }
}
