<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Run\Strangler\StranglerReport;

/**
 * The strangler report's refusal lines: one line per unit for its own
 * reasons, one line for a reason many units share (attendance's 56 units
 * repeated the same AppController refusals 2,057 times, 2026-11-20).
 */
final class StranglerReportTest extends TestCase
{
    public function test_a_reason_every_unit_shares_is_one_line_and_names_the_file_to_port_first(): void
    {
        $report = new StranglerReport('moves', 'cakephp');
        foreach (['GET /a', 'GET /b', 'GET /c', 'GET /d', 'GET /e'] as $unit) {
            $report->refuse($unit, 'legacy/src/Controller/AppController.php', 12, '`loadComponent(\'Banner\')` has no mapping');
            $report->refuse($unit, 'legacy/src/Controller/AppController.php', 20, '`oci_connect()` has no mapping');
        }
        $report->refuse('GET /e', 'legacy/src/Controller/EController.php', 7, '`$this->Flash->foo()` has no mapping');

        $lines = $report->refusalLines();

        self::assertSame([
            'Refused 5 units (GET /a, GET /b, GET /c and 2 more): legacy/src/Controller/AppController.php:12: `loadComponent(\'Banner\')` has no mapping.',
            'Refused 5 units (GET /a, GET /b, GET /c and 2 more): legacy/src/Controller/AppController.php:20: `oci_connect()` has no mapping.',
            'Refused GET /e: legacy/src/Controller/EController.php:7: `$this->Flash->foo()` has no mapping. Port it by hand, then run --hand --route "GET /e".',
            'MANUAL (route moves): every refused unit runs legacy/src/Controller/AppController.php, so port that file first (in CakePHP, a base controller\'s initialize() and beforeFilter() run before every action), then move again. Port a unit by hand meanwhile with --hand --route "<id>".',
        ], $lines);
        self::assertCount(11, $report->toArray()['refusals'], 'The JSON report keeps every refusal.');
    }

    public function test_one_unit_keeps_its_own_line(): void
    {
        $report = new StranglerReport('moves', 'slim');
        $report->refuse('GET /x', '', 0, 'it reads $_SESSION directly');

        self::assertSame(['Refused GET /x: it reads $_SESSION directly. Port it by hand, then run --hand --route "GET /x".'], $report->refusalLines());
    }
}
