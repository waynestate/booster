<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Hop\Hop;
use Waynestate\Booster\Run\FrameworkEngine;
use Waynestate\Booster\Run\VendoredMigrationResult;

/**
 * A FrameworkEngine that changes nothing, reports one change and remembers
 * what it was handed.
 */
final class FakeFrameworkEngine implements FrameworkEngine
{
    public ?Hop $received = null;

    public ?bool $dryRun = null;

    public ?string $php = null;

    public function migrate(string $appPath, bool $dryRun, string $php, ?Hop $hop = null): VendoredMigrationResult
    {
        $this->received = $hop;
        $this->dryRun = $dryRun;
        $this->php = $php;

        return new VendoredMigrationResult(['Dry run: would move the fake framework.'], [['file' => 'app.php', 'diff' => "--- a\n+++ b"]], []);
    }
}
