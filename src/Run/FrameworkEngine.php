<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

use Waynestate\Booster\Hop\Hop;

/**
 * What runs a HopKind::Framework hop. HopExecutor picks the engine the hop
 * names (Hop::$engine) and hands over the app; everything after the call
 * (refusals, the dry run, the gate, the messages) is the executor's.
 *
 * VendoredMigrationResult is the shared result shape for every engine: the
 * changes, the files that changed, the MANUAL lines, the refusals (a refused
 * move changed nothing) and the Composer packages the gate should update.
 * An engine reads its settings (rule sets, Composer pins, notes) from the
 * Hop it is handed, so one engine can serve several hops.
 */
interface FrameworkEngine
{
    /**
     * $php is the PHP the hop lands on. A dry run or a refusal writes nothing.
     */
    public function migrate(string $appPath, bool $dryRun, string $php, ?Hop $hop = null): VendoredMigrationResult;
}
