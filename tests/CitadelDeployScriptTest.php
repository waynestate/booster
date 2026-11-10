<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Run\CitadelDeployScript;

final class CitadelDeployScriptTest extends TestCase
{
    /** The full script body: it installs and never migrates, Laravel or not. */
    private const SCRIPT = "#!/bin/sh\n"
        . "# Added by Booster: this upgrade changed the production packages in composer.lock. Citadel runs each deployment\n"
        . "# script once, in name order, so a new name makes existing deployments install the new vendor/ first.\n"
        . "# It only installs: migrations and other release steps stay with the app's own deploy scripts.\n"
        . "set -e\n"
        . "composer install --no-dev --no-interaction --optimize-autoloader\n";

    /**
     * A composer.lock with the given production and dev packages, each
     * name => version, the way Composer writes them.
     *
     * @param array<string, string> $packages
     * @param array<string, string> $dev
     */
    private static function lock(array $packages, array $dev = [], string $hash = 'aaa', string $platformPhp = '8.3.0'): string
    {
        $entries = static fn(array $list): array => array_map(
            static fn(string $name, string $version): array => ['name' => $name, 'version' => $version, 'dist' => ['reference' => md5($name . $version)]],
            array_keys($list),
            array_values($list),
        );

        return (string) json_encode([
            'content-hash' => $hash,
            'packages' => $entries($packages),
            'packages-dev' => $entries($dev),
            'platform-overrides' => ['php' => $platformPhp],
        ], JSON_PRETTY_PRINT);
    }

    /**
     * A temp app holding $lock as composer.lock, with a deployments/ folder
     * when $deployments, an artisan file when $laravel, and a Squire-style
     * scripts/release.sh when $releaseScript.
     */
    private function app(string $lock, bool $deployments = true, bool $laravel = true, bool $releaseScript = false): string
    {
        $dir = $this->tempDir();
        if ($deployments) {
            mkdir($dir . '/deployments', 0775, true);
        }
        if ($laravel) {
            file_put_contents($dir . '/artisan', '<?php // Laravel entry point');
        }
        if ($releaseScript) {
            mkdir($dir . '/scripts', 0775, true);
            file_put_contents($dir . '/scripts/release.sh', "#!/usr/bin/env bash\ncomposer install --no-dev --optimize-autoloader\nphp artisan migrate --force\n");
        }
        file_put_contents($dir . '/composer.lock', $lock);

        return $dir;
    }

    public function test_a_production_package_change_writes_an_install_only_script(): void
    {
        $before = self::lock(['laravel/framework' => 'v13.1.0']);
        $dir = $this->app($before);
        file_put_contents($dir . '/composer.lock', self::lock(['laravel/framework' => 'v13.2.0'], [], 'bbb'));

        $result = (new CitadelDeployScript())->decide($dir, $before, '202610301234');

        self::assertSame('0000_composer_install_202610301234', $result->script);
        $file = $dir . '/deployments/' . $result->script;
        self::assertFileExists($file);
        self::assertSame(self::SCRIPT, file_get_contents($file));
        self::assertStringNotContainsString('migrate', (string) file_get_contents($file));
        if (PHP_OS_FAMILY !== 'Windows') {
            self::assertNotSame(0, fileperms($file) & 0111, 'the deployment script should be executable');
        }
        self::assertSame([
            'Added deployments/0000_composer_install_202610301234: Citadel runs it once, first, so existing deployments install the upgraded production packages.',
            'It runs composer install only. Migrations stay with your own deploy scripts: if none of them runs php artisan migrate --force, run it after this deploy.',
            'Undo with: rm deployments/0000_composer_install_202610301234 (inside the app).',
        ], $result->lines);
    }

    public function test_a_raw_php_app_gets_the_same_script_and_no_migrate_advice(): void
    {
        $before = self::lock(['slim/slim' => '4.14.0']);
        $dir = $this->app($before, laravel: false);
        file_put_contents($dir . '/composer.lock', self::lock(['slim/slim' => '4.15.0']));

        $result = (new CitadelDeployScript())->decide($dir, $before, '202610301234');

        self::assertNotNull($result->script);
        self::assertSame(self::SCRIPT, file_get_contents($dir . '/deployments/' . $result->script));
        self::assertSame('It runs composer install only; the rest of your release stays with your own deploy scripts.', $result->lines[1]);
    }

    public function test_a_dev_only_change_writes_nothing_and_says_why(): void
    {
        // The shape of a PHPUnit major on a PHP floor rise: dev packages, the
        // platform pin and the content hash move; production does not.
        $before = self::lock(['laravel/framework' => 'v13.1.0'], ['phpunit/phpunit' => '12.5.42'], 'aaa', '8.3.0');
        $dir = $this->app($before);
        file_put_contents($dir . '/composer.lock', self::lock(['laravel/framework' => 'v13.1.0'], ['phpunit/phpunit' => '13.4.6', 'sebastian/git-state' => '1.0.0'], 'ccc', '8.5.0'));

        $result = (new CitadelDeployScript())->decide($dir, $before, '202610301234');

        self::assertNull($result->script);
        self::assertSame(['.', '..'], scandir($dir . '/deployments'));
        self::assertSame(['Deploy: composer.lock changed only in dev packages, so a production install (composer install --no-dev) gets the same vendor/. Booster added no deployment script.'], $result->lines);
    }

    public function test_a_release_script_that_installs_means_no_deployment_script(): void
    {
        // A Squire kit: deployments/001-first-release.sh calls scripts/release.sh,
        // which installs (and migrates, after the app's own first-release work)
        // on every release. A 0000_ script would run ahead of all of that.
        $before = self::lock(['laravel/framework' => 'v13.1.0']);
        $dir = $this->app($before, releaseScript: true);
        file_put_contents($dir . '/deployments/001-first-release.sh', "#!/usr/bin/env bash\nbash ./scripts/release.sh\n");
        file_put_contents($dir . '/composer.lock', self::lock(['laravel/framework' => 'v13.2.0']));

        $result = (new CitadelDeployScript())->decide($dir, $before, '202610301234');

        self::assertNull($result->script);
        self::assertSame(['.', '..', '001-first-release.sh'], scandir($dir . '/deployments'));
        self::assertSame(['Deploy: composer.lock changed production packages. scripts/release.sh installs them on every release (run it as the Quick Update post-script, as usual), so Booster added no deployment script.'], $result->lines);
    }

    public function test_an_app_with_no_deploy_scripts_is_told_what_its_deploy_must_do(): void
    {
        $before = self::lock(['laravel/framework' => 'v13.1.0']);
        $dir = $this->app($before, deployments: false);
        file_put_contents($dir . '/composer.lock', self::lock(['laravel/framework' => 'v13.2.0']));

        $result = (new CitadelDeployScript())->decide($dir, $before, '202610301234');

        self::assertNull($result->script);
        self::assertDirectoryDoesNotExist($dir . '/deployments');
        self::assertSame(["Deploy: composer.lock changed production packages, and the app has no deploy scripts (no deployments/ folder, no scripts/release.sh), so Booster wrote none. On its next release every server must run composer install --no-dev --optimize-autoloader, then the app's own release steps (php artisan migrate --force, when its migrations need it). On Citadel, add a deployments/ script that does it, or take Squire's release script."], $result->lines);
    }

    public function test_a_new_lock_counts_as_a_production_change(): void
    {
        // vendored-to-composer creates the first lock: every package is new.
        $dir = $this->app(self::lock(['doctrine/orm' => '2.20.0']), laravel: false);

        $result = (new CitadelDeployScript())->decide($dir, null, '202610301234');

        self::assertSame('0000_composer_install_202610301234', $result->script);
    }

    public function test_it_says_nothing_when_the_lock_is_unchanged(): void
    {
        $lock = self::lock(['laravel/framework' => 'v13.1.0']);
        $dir = $this->app($lock);

        $result = (new CitadelDeployScript())->decide($dir, $lock, '202610301234');

        self::assertNull($result->script);
        self::assertSame([], $result->lines);
        self::assertSame(['.', '..'], scandir($dir . '/deployments'));
    }

    public function test_it_says_nothing_when_there_is_no_lock_at_all(): void
    {
        $dir = $this->tempDir();
        mkdir($dir . '/deployments', 0775, true);

        $result = (new CitadelDeployScript())->decide($dir, null, '202610301234');

        self::assertNull($result->script);
        self::assertSame([], $result->lines);
    }

    public function test_production_packages_ignore_dev_packages_the_platform_and_the_hash(): void
    {
        self::assertSame(
            CitadelDeployScript::productionPackages(self::lock(['a/a' => '1.0.0'], ['b/b' => '1.0.0'], 'x', '8.3.0')),
            CitadelDeployScript::productionPackages(self::lock(['a/a' => '1.0.0'], ['b/b' => '2.0.0'], 'y', '8.5.0')),
        );
        self::assertNotSame(
            CitadelDeployScript::productionPackages(self::lock(['a/a' => '1.0.0'])),
            CitadelDeployScript::productionPackages(self::lock(['a/a' => '1.0.1'])),
        );
        self::assertNull(CitadelDeployScript::productionPackages('not json'));
        self::assertSame([], CitadelDeployScript::productionPackages(null));
    }
}
