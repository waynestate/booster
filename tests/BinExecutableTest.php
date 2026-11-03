<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Symfony\Component\Process\Process;

/**
 * `bin/booster` must stay committed with git mode 100755 so a plain clone can
 * run `bin/booster --version` without an extra chmod. This guard asks git for
 * the index mode of the file and fails when it drifts back to 100644.
 */
final class BinExecutableTest extends TestCase
{
    public function test_bin_booster_is_committed_as_executable(): void
    {
        $process = new Process(['git', 'ls-files', '-s', 'bin/booster'], dirname(__DIR__));

        try {
            $process->run();
        } catch (\Throwable) {
            self::markTestSkipped('git is not available on this machine.');
        }

        if (! $process->isSuccessful()) {
            self::markTestSkipped('git ls-files failed: ' . $process->getErrorOutput());
        }

        $output = trim($process->getOutput());
        if ($output === '') {
            self::markTestSkipped('bin/booster is not tracked by git here (not a work tree?).');
        }

        self::assertStringStartsWith(
            '100755 ',
            $output,
            'bin/booster must be committed as executable; restore the bit with: git update-index --chmod=+x bin/booster',
        );
    }
}
