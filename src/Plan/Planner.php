<?php

declare(strict_types=1);

namespace Waynestate\Booster\Plan;

use DateTimeImmutable;
use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Policy\PolicyException;
use Waynestate\Booster\Policy\PolicySource;
use Waynestate\Booster\Policy\State;
use Waynestate\Booster\Project\Project;
use Waynestate\Booster\ToolError;

/**
 * Orders the hops. The rules, all from the best-practices guide:
 *  - one PHP minor or one Laravel major per step;
 *  - the app only ever sits on a PHP and Laravel pair that Laravel supports;
 *  - the target must be a version the policy still supports today.
 */
final class Planner
{
    public const GATE_TESTS = 'target-image-tests';

    public const GATE_MANUAL = 'manual-review';

    public function __construct(
        private readonly PolicySource $policy,
        private readonly HopRegistry $registry,
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
        $fromPhp = $this->normalisePhp($fromPhp, '--from-php');

        $fromLaravel = null;
        if ($project->type->isLaravel()) {
            $fromLaravel = $options->fromLaravel ?? $project->laravelMajor;
            if ($fromLaravel === null) {
                throw new ToolError('Could not read the Laravel version from composer.json. Pass --from-laravel=<major>.');
            }
            if (!in_array($fromLaravel, HopRegistry::LARAVEL_CHAIN, true)) {
                throw new ToolError(sprintf('Laravel %s is outside what BOOSTER can plan (%s to %s).', $fromLaravel, HopRegistry::LARAVEL_CHAIN[0], HopRegistry::LARAVEL_CHAIN[count(HopRegistry::LARAVEL_CHAIN) - 1]));
            }
            if (!HopRegistry::laravelSupportsPhp($fromLaravel, $fromPhp)) {
                $warnings[] = sprintf('Laravel %s does not list PHP %s; the plan assumes the app really runs on that pair.', $fromLaravel, $fromPhp);
            }
        }

        $supportedPhp = $this->policy->supported('php', $today);
        $toPhp = $options->toPhp !== null ? $this->normalisePhp($options->toPhp, '--to-php') : $this->highest($supportedPhp, 'php');
        $this->assertTarget('php', $toPhp, $today, $warnings);

        $toLaravel = null;
        if ($fromLaravel !== null) {
            $toLaravel = $options->toLaravel ?? $this->highest($this->policy->supported('laravel', $today), 'laravel');
            $this->assertTarget('laravel', $toLaravel, $today, $warnings);
            if (version_compare($toLaravel, $fromLaravel, '<')) {
                throw new ToolError(sprintf('Target Laravel %s is older than the current Laravel %s.', $toLaravel, $fromLaravel));
            }
            if (!HopRegistry::laravelSupportsPhp($toLaravel, $toPhp)) {
                throw new ToolError(sprintf('Laravel %s does not run on PHP %s. Pick a PHP and Laravel pair that Laravel supports.', $toLaravel, $toPhp));
            }
        }
        if (version_compare($toPhp, $fromPhp, '<')) {
            throw new ToolError(sprintf('Target PHP %s is older than the current PHP %s.', $toPhp, $fromPhp));
        }

        $steps = [];
        $php = $fromPhp;
        $laravel = $fromLaravel;
        $n = 0;

        for ($guard = 0; $guard < 64; $guard++) {
            $nextLaravel = $laravel !== null && $toLaravel !== null && $laravel !== $toLaravel ? HopRegistry::nextLaravel($laravel) : null;
            $nextPhp = $php !== $toPhp ? HopRegistry::nextPhp($php) : null;

            if ($nextLaravel !== null && HopRegistry::laravelSupportsPhp($nextLaravel, $php)) {
                $hop = $this->registry->get(HopRegistry::laravelHopId((string) $laravel, $nextLaravel));
                $steps[] = $this->step(++$n, $hop, $php, $php, $laravel, $nextLaravel);
                $laravel = $nextLaravel;
                continue;
            }
            if ($nextPhp !== null && ($laravel === null || HopRegistry::laravelSupportsPhp($laravel, $nextPhp))) {
                $hop = $this->registry->get(HopRegistry::phpHopId($php, $nextPhp));
                $steps[] = $this->step(++$n, $hop, $php, $nextPhp, $laravel, $laravel);
                $php = $nextPhp;
                continue;
            }
            if ($nextLaravel !== null || $nextPhp !== null) {
                throw new ToolError(sprintf(
                    'No safe next step from PHP %s and Laravel %s toward PHP %s and Laravel %s.',
                    $php,
                    (string) $laravel,
                    $toPhp,
                    (string) $toLaravel,
                ));
            }
            break;
        }

        if ($options->sesame) {
            if (!$project->usesWayneCas) {
                $warnings[] = 'Asked for the SESAME swap, but composer.json does not require cit-web-infra/wayne-cas; skipped.';
            } elseif (version_compare($php, '8.3', '<')) {
                $warnings[] = 'SESAME needs PHP 8.3 or newer; the swap was left out because the plan ends below that.';
            } else {
                $steps[] = $this->step(++$n, $this->registry->get('cas-to-sesame'), $php, $php, $laravel, $laravel);
            }
        } elseif ($project->usesWayneCas) {
            $warnings[] = 'The app uses wayne-cas. Add --sesame to plan the swap to SESAME.';
        }

        if ($project->vueMajor === '2') {
            $steps[] = $this->step(++$n, $this->registry->get('vue-2-to-3'), $php, $php, $laravel, $laravel);
        }

        $steps = $this->withoutSkipped($steps, $options->skip);

        if ($steps === []) {
            $warnings[] = 'Nothing to do: the app is already at the target.';
        }

        return new Plan($project, $today->format('Y-m-d'), $this->policy->origin(), $toPhp, $toLaravel, $steps, $warnings);
    }

    /**
     * @param list<PlanStep> $steps
     * @param list<string>   $skip
     *
     * @return list<PlanStep>
     */
    private function withoutSkipped(array $steps, array $skip): array
    {
        foreach ($skip as $id) {
            if (!$this->registry->has($id)) {
                throw new ToolError(sprintf('Cannot skip unknown hop "%s".', $id));
            }
        }

        $kept = [];
        foreach ($steps as $step) {
            if (!in_array($step->hop->id, $skip, true)) {
                $kept[] = new PlanStep(count($kept) + 1, $step->hop, $step->phpBefore, $step->phpAfter, $step->laravelBefore, $step->laravelAfter, $step->gates);
            }
        }

        return $kept;
    }

    private function step(int $n, \Waynestate\Booster\Hop\Hop $hop, string $phpBefore, string $phpAfter, ?string $lBefore, ?string $lAfter): PlanStep
    {
        return new PlanStep($n, $hop, $phpBefore, $phpAfter, $lBefore, $lAfter, $hop->isManual() ? [self::GATE_MANUAL] : [self::GATE_TESTS]);
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
     * @param list<string> $warnings
     */
    private function assertTarget(string $component, string $version, DateTimeImmutable $today, array &$warnings): void
    {
        try {
            $state = $this->policy->stateOf($component, $version, $today);
        } catch (PolicyException) {
            throw new ToolError(sprintf('%s %s is not a version the policy supports. Pick one of: %s.', ucfirst($component), $version, implode(', ', $this->policy->supported($component, $today))));
        }
        if ($state === State::EOL || $state === State::EOL_GRACE_EXPIRED) {
            throw new ToolError(sprintf('%s %s is past security support; pick a version the policy still supports.', ucfirst($component), $version));
        }
        if ($state === State::SECURITY) {
            $warnings[] = sprintf('%s %s only gets security fixes now; a newer version is a better place to stop.', ucfirst($component), $version);
        }
    }

    private function normalisePhp(string $version, string $option): string
    {
        if (preg_match('/^\d+\.\d+$/', $version) !== 1 || !in_array($version, HopRegistry::PHP_CHAIN, true)) {
            throw new ToolError(sprintf('%s "%s" is not a PHP version BOOSTER knows (use major.minor, from %s to %s).', $option, $version, HopRegistry::PHP_CHAIN[0], HopRegistry::PHP_CHAIN[count(HopRegistry::PHP_CHAIN) - 1]));
        }

        return $version;
    }
}
