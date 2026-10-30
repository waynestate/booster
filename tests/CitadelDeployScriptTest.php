<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Run\CitadelDeployScript;

final class CitadelDeployScriptTest extends TestCase
{
    /** The full script body for an app that has an artisan file (Laravel). */
    private const WITH_ARTISAN = "#!/bin/sh\n"
        . "# Added by Booster: this upgrade changed composer.lock. Citadel runs each deployment script once, in name order,\n"
        . "# so a new name makes existing deployments install the new vendor/ before anything else runs.\n"
        . "set -e\n"
        . "composer install --no-dev --no-interaction --optimize-autoloader\n"
        . "php artisan migrate --force\n";

    /** The script body for an app with no artisan file (raw PHP): no migrate line. */
    private const WITHOUT_ARTISAN = "#!/bin/sh\n"
        . "# Added by Booster: this upgrade changed composer.lock. Citadel runs each deployment script once, in name order,\n"
        . "# so a new name makes existing deployments install the new vendor/ before anything else runs.\n"
        . "set -e\n"
        . "composer install --no-dev --no-interaction --optimize-autoloader\n";

    /**
     * Builds a temp app with a deployments/ directory and a composer.lock,
     * returning [dir, the lock's original fingerprint]. $withArtisan adds an
     * artisan file so the writer knows whether to include the migrate line.
     */
    private function app(bool $withArtisan): array
    {
        $dir = $this->tempDir();
        mkdir($dir . '/deployments', 0775, true);
        if ($withArtisan) {
            file_put_contents($dir . '/artisan', '<?php // Laravel entry point');
        }
        file_put_contents($dir . '/composer.lock', '{"original":true}');

        return [$dir, md5_file($dir . '/composer.lock')];
    }

    public function test_it_writes_the_script_when_the_lock_changed_and_deployments_exists(): void
    {
        [$dir, $before] = $this->app(true);
        file_put_contents($dir . '/composer.lock', '{"updated":true}');

        $name = (new CitadelDeployScript())->writeIfLockChanged($dir, $before, '202610301234');

        self::assertSame('0000_composer_install_202610301234', $name);
        $file = $dir . '/deployments/' . $name;
        self::assertFileExists($file);
        self::assertSame(self::WITH_ARTISAN, file_get_contents($file));
        if (PHP_OS_FAMILY !== 'Windows') {
            self::assertNotSame(0, fileperms($file) & 0111, 'the deployment script should be executable');
        }
    }

    public function test_it_omits_the_migrate_line_without_an_artisan_file(): void
    {
        [$dir, $before] = $this->app(false);
        file_put_contents($dir . '/composer.lock', '{"updated":true}');

        $name = (new CitadelDeployScript())->writeIfLockChanged($dir, $before, '202610301234');

        self::assertNotNull($name);
        self::assertSame(self::WITHOUT_ARTISAN, file_get_contents($dir . '/deployments/' . $name));
    }

    public function test_it_writes_nothing_when_the_lock_is_unchanged(): void
    {
        [$dir, $before] = $this->app(true);

        self::assertNull((new CitadelDeployScript())->writeIfLockChanged($dir, $before, '202610301234'));
        self::assertSame(['.', '..'], scandir($dir . '/deployments'));
    }

    public function test_it_writes_nothing_when_there_is_no_deployments_directory(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/composer.lock', '{"original":true}');
        $before = md5_file($dir . '/composer.lock');
        file_put_contents($dir . '/composer.lock', '{"updated":true}');

        self::assertNull((new CitadelDeployScript())->writeIfLockChanged($dir, $before, '202610301234'));
    }

    public function test_it_writes_nothing_when_there_is_no_lock_at_all(): void
    {
        $dir = $this->tempDir();
        mkdir($dir . '/deployments', 0775, true);

        self::assertNull((new CitadelDeployScript())->writeIfLockChanged($dir, null, '202610301234'));
    }
}
