<?php

// prove.sh's mount step: runs Booster's real strangler mount on one app copy
// (Sourdough bakes in the gate image, through BOOSTER_COMPOSER_CACHE when it
// is set) and prints the result. Usage: php mount.php <app dir> <slim|cakephp>

declare(strict_types=1);

use Waynestate\Booster\Run\CacheMount;
use Waynestate\Booster\Run\SymfonyProcessRunner;
use Waynestate\Booster\Run\TargetImageRunner;
use Waynestate\Booster\Strangler\Mount;
use Waynestate\Booster\Strangler\MountSourdough;

$booster = dirname(__DIR__, 3);
require $booster . '/vendor/autoload.php';

[$self, $app, $framework] = $argv + [null, null, null];
if (!is_string($app) || !is_string($framework)) {
    fwrite(STDERR, "usage: php mount.php <app dir> <slim|cakephp>\n");
    exit(2);
}

$gate = new TargetImageRunner(new SymfonyProcessRunner(), $booster . '/docker', composerCache: CacheMount::fromEnvironment(CacheMount::COMPOSER));
$mount = new Mount(new MountSourdough($gate, playbook: false, advisor: false), $booster . '/resources/strangler');
$result = $mount->run($app, $framework);

foreach ($result->refusals as $refusal) {
    echo 'REFUSED ', $refusal['file'], ':', $refusal['line'], ' ', $refusal['reason'], "\n";
}
foreach ($result->notes as $note) {
    echo 'note: ', $note, "\n";
}
foreach ($result->manual as $line) {
    echo 'MANUAL: ', $line, "\n";
}
echo 'changed: ', implode(', ', $result->changedFiles), "\n";
echo 'legacy docroot: ', $result->legacyDocroot, ' (was ', $result->oldPublicDir, ")\n";
echo 'ignore cookies: ', implode(', ', $result->ignoreCookies), "\n";
echo $result->mounted ? "MOUNTED\n" : "NOT MOUNTED\n";
exit($result->mounted ? 0 : 1);
