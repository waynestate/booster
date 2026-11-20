<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run\Strangler;

use Waynestate\Booster\Replay\ReplayFile;
use Waynestate\Booster\Strangler\AddsComposerPackages;
use Waynestate\Booster\Strangler\CakeMover;
use Waynestate\Booster\Strangler\CakeUnitSource;
use Waynestate\Booster\Strangler\Mount;
use Waynestate\Booster\Strangler\MountBaker;
use Waynestate\Booster\Strangler\MoveResult;
use Waynestate\Booster\Strangler\Mover;
use Waynestate\Booster\Strangler\RouteTable;
use Waynestate\Booster\Strangler\SignInResult;
use Waynestate\Booster\Strangler\SignInStep;
use Waynestate\Booster\Strangler\SlimMover;
use Waynestate\Booster\Strangler\SlimUnitSource;
use Waynestate\Booster\Strangler\Unit;
use Waynestate\Booster\Strangler\UnitSource;
use Waynestate\Booster\ToolError;

/**
 * The strangler's real parts behind ENGINE's seam (design section 6): the
 * replay check (REPLAY), Step 0, the Laravel half's sign-in and its finish
 * (SIGNIN), the mount and the route table (MOUNT), and the unit sources and
 * movers (SLIM, CAKE). Each part keeps its own result type; this class only
 * maps them onto PartResult. Like a switchboard operator: it connects the
 * calls and never listens in.
 */
final class LaneStranglerParts implements StranglerParts
{
    public function __construct(
        private readonly MountBaker $baker,
        private readonly ?string $resourceDir = null,
    ) {}

    public function replayUnusable(string $appRoot, ?array $answers, ?string $file): ?string
    {
        return ReplayFile::usableForStrangler($appRoot, $answers, $file);
    }

    public function signInInspect(string $appRoot, string $framework): PartResult
    {
        $report = SignInStep::inspect($appRoot, $framework);
        if ($report->blocked()) {
            return PartResult::refused($report->stops);
        }
        $lines = array_map(static fn(array $f): string => sprintf('Sign-in: %s (%s:%d).', $f['what'], $f['file'], $f['line']), $report->findings);
        if ($report->shape === 'custom') {
            // Step 0 stays pending until a person moves the sign-in by hand; the mount waits for it.
            return new PartResult(PartResult::REFUSED, [], [
                'Step 0 cannot move this app\'s sign-in onto SESAME: Booster does not know its shape. Move it by hand (SESAME docs: guides/cakephp.md or guides/raw-php.md), commit, and run again.',
            ], $report->manual, $lines);
        }
        $status = $report->step0Done && !$report->mounted ? PartResult::ALREADY : PartResult::OK;

        return new PartResult($status, [], [], $report->manual, $lines);
    }

    public function signInMigrate(string $appRoot, string $framework): PartResult
    {
        return self::signInResult(SignInStep::migrate($appRoot, $framework));
    }

    public function mount(string $appRoot, string $framework): PartResult
    {
        try {
            $result = (new Mount($this->baker, $this->resourceDir))->run($appRoot, $framework);
        } catch (ToolError $e) {
            return PartResult::refused([$e->getMessage()]);
        }
        if (!$result->mounted || $result->refusals !== []) {
            $refusals = array_map(static fn(array $r): string => self::where($r['file'], $r['line']) . $r['reason'], $result->refusals);

            return PartResult::refused($refusals === [] ? ['The mount did not run and gave no reason.'] : $refusals);
        }

        return new PartResult(PartResult::OK, $result->changedFiles, [], $result->manual, [
            'Mount: ' . $this->baker->describe() . ' baked the Laravel half at the root; the old Composer root is in legacy/.',
            ...$result->notes,
        ]);
    }

    public function signInFinish(string $appRoot, string $framework): PartResult
    {
        return self::signInResult(SignInStep::finish($appRoot, $framework));
    }

    public function unitSource(string $framework): UnitSource
    {
        return match ($framework) {
            'slim' => new SlimUnitSource(),
            'cakephp' => new CakeUnitSource(),
            default => throw new ToolError(sprintf('The strangler has no unit source for "%s".', $framework)),
        };
    }

    public function mover(string $framework): Mover
    {
        $mover = match ($framework) {
            'slim' => new SlimMover(),
            'cakephp' => new CakeMover(),
            default => throw new ToolError(sprintf('The strangler has no mover for "%s".', $framework)),
        };
        if ($mover instanceof SlimMover && !$mover instanceof AddsComposerPackages) {
            // SlimMover names its additions as lastComposerAdds(); the engine reads AddsComposerPackages.
            return new class ($mover) implements Mover, AddsComposerPackages {
                public function __construct(private readonly SlimMover $inner) {}

                public function framework(): string
                {
                    return $this->inner->framework();
                }

                public function move(Unit $unit, string $appRoot): MoveResult
                {
                    return $this->inner->move($unit, $appRoot);
                }

                public function composerAdds(): array
                {
                    return $this->inner->lastComposerAdds();
                }
            };
        }

        return $mover;
    }

    public function routeAdd(string $appRoot, Unit $unit): void
    {
        $table = RouteTable::load($appRoot);
        $table->add($unit);
        $table->save();
    }

    public function routeRemove(string $appRoot, string $unitId): void
    {
        $table = RouteTable::load($appRoot);
        $table->remove($unitId);
        $table->save();
    }

    public function routeHas(string $appRoot, string $unitId): bool
    {
        return RouteTable::load($appRoot)->has($unitId);
    }

    private static function signInResult(SignInResult $result): PartResult
    {
        if ($result->failures !== []) {
            return PartResult::refused($result->failures);
        }

        return new PartResult(PartResult::OK, $result->changedFiles, [], $result->manual, $result->changes, $result->replayChanges);
    }

    private static function where(string $file, int $line): string
    {
        if ($file === '') {
            return '';
        }

        return $line > 0 ? sprintf('%s:%d: ', $file, $line) : $file . ': ';
    }
}
