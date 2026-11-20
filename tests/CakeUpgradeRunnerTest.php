<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Run\CacheMount;
use Waynestate\Booster\Run\CakeUpgradeRunner;
use Waynestate\Booster\Run\ProcessResult;
use Waynestate\Booster\Run\TargetImageRunner;
use Waynestate\Booster\ToolError;

final class CakeUpgradeRunnerTest extends TestCase
{
    private function dockerDir(): string
    {
        return dirname(__DIR__) . '/docker/cakephp-upgrade';
    }

    public function test_rector_runs_the_ruleset_on_the_subdir_in_the_tool_container(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(0, '', ''));
        $runner = new CakeUpgradeRunner($fake);

        $result = $runner->run($runner->rectorCommand('/srv/my app', 'cakephp45', 'src'));

        self::assertTrue($result->successful());
        self::assertSame(
            ['docker', 'run', '--rm', '-v', '/srv/my app:/app', CakeUpgradeRunner::imageTag(), 'bin/cake', 'upgrade', 'rector', '--rules', 'cakephp45', '/app/src'],
            $fake->calls[0]['command'],
        );
        self::assertNull($fake->calls[0]['cwd']);
    }

    public function test_a_trailing_slash_on_the_app_path_is_dropped(): void
    {
        $command = (new CakeUpgradeRunner(new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(0, '', ''))))
            ->rectorCommand('/srv/my app/', 'cakephp45', 'src');

        self::assertSame('/srv/my app:/app', $command[4]);
    }

    /**
     * @dataProvider invalidRuleSets
     */
    public function test_an_invalid_rule_set_is_refused(string $ruleSet): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new CakeUpgradeRunner(new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(0, '', ''))))
            ->rectorCommand('/srv/app', $ruleSet, 'src');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidRuleSets(): array
    {
        return [
            'a space' => ['cakephp 45'],
            'a semicolon' => ['cakephp45; rm -rf /'],
            'uppercase' => ['CakePHP45'],
        ];
    }

    /**
     * @dataProvider unsafeSubdirs
     */
    public function test_an_unsafe_subdir_is_refused(string $subdir): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new CakeUpgradeRunner(new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(0, '', ''))))
            ->rectorCommand('/srv/app', 'cakephp45', $subdir);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeSubdirs(): array
    {
        return [
            'a dot-dot segment' => ['src/../config'],
            'an absolute path' => ['/etc'],
            'a shell metacharacter' => ['src && whoami'],
        ];
    }

    public function test_file_rename_accepts_templates_and_locales(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(0, '', ''));
        $runner = new CakeUpgradeRunner($fake);

        $runner->run($runner->fileRenameCommand('/srv/app', 'templates'));
        $runner->run($runner->fileRenameCommand('/srv/app', 'locales'));

        self::assertSame(
            ['docker', 'run', '--rm', '-v', '/srv/app:/app', CakeUpgradeRunner::imageTag(), 'bin/cake', 'upgrade', 'file_rename', 'templates', '/app'],
            $fake->calls[0]['command'],
        );
        self::assertSame('locales', $fake->calls[1]['command'][count($fake->calls[1]['command']) - 2]);
    }

    public function test_file_rename_refuses_anything_else(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new CakeUpgradeRunner(new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(0, '', ''))))
            ->fileRenameCommand('/srv/app', 'src');
    }

    public function test_ensure_image_does_not_build_when_the_image_exists(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(0, '', ''));
        (new CakeUpgradeRunner($fake))->ensureImage();

        self::assertCount(1, $fake->calls);
        self::assertSame(['docker', 'image', 'inspect', CakeUpgradeRunner::imageTag()], $fake->calls[0]['command']);
    }

    public function test_ensure_image_builds_with_both_build_args_when_the_image_is_missing(): void
    {
        $fake = new FakeProcessRunner(static fn(array $command): ProcessResult => new ProcessResult(
            in_array('inspect', $command, true) ? 1 : 0,
            '',
            '',
        ));
        (new CakeUpgradeRunner($fake))->ensureImage();

        self::assertSame(
            ['docker', 'build', '--build-arg', 'COMPOSER_VERSION=' . TargetImageRunner::COMPOSER_VERSION_CURRENT, '--build-arg', 'UPGRADE_REF=' . CakeUpgradeRunner::UPGRADE_REF, '-t', CakeUpgradeRunner::imageTag(), $this->dockerDir()],
            $fake->calls[1]['command'],
        );
    }

    public function test_a_failed_build_is_a_tool_error_with_the_end_of_the_output(): void
    {
        $fake = new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(
            1,
            '',
            implode("\n", array_map(static fn(int $i): string => "step $i failed", range(1, 30))),
        ));

        try {
            (new CakeUpgradeRunner($fake))->ensureImage();
            self::fail('A failed build was not reported.');
        } catch (ToolError $e) {
            self::assertStringContainsString('Could not build ' . CakeUpgradeRunner::imageTag(), $e->getMessage());
            self::assertStringContainsString('https://github.com/cakephp/upgrade', $e->getMessage());
            // The last 20 lines of the build output, not all 30.
            self::assertStringNotContainsString("step 10 failed\n", $e->getMessage() . "\n");
            self::assertStringContainsString('step 30 failed', $e->getMessage());
        }
    }

    public function test_the_composer_cache_is_mounted_and_named_when_given(): void
    {
        $cache = CacheMount::parse(CacheMount::COMPOSER, 'stack-composer-cache');
        $command = (new CakeUpgradeRunner(new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(0, '', '')), composerCache: $cache))
            ->rectorCommand('/srv/app', 'cakephp45', 'src');

        self::assertSame(
            ['docker', 'run', '--rm', '-v', '/srv/app:/app', '-v', 'stack-composer-cache:/tmp/composer-cache', '-e', 'COMPOSER_CACHE_DIR=/tmp/composer-cache', CakeUpgradeRunner::imageTag(), 'bin/cake', 'upgrade', 'rector', '--rules', 'cakephp45', '/app/src'],
            $command,
        );
    }

    public function test_no_composer_cache_is_mounted_by_default(): void
    {
        $command = (new CakeUpgradeRunner(new FakeProcessRunner(static fn(): ProcessResult => new ProcessResult(0, '', ''))))
            ->fileRenameCommand('/srv/app', 'templates');

        self::assertNotContains('-e', $command);
        self::assertNotContains('COMPOSER_CACHE_DIR=/tmp/composer-cache', $command);
    }

    public function test_cakephp_upgrade_dockerfile_installs_intl(): void
    {
        // CakePHP's upgrade tool dies at start without intl, and the composer base image lacks it.
        $dockerfile = (string) file_get_contents($this->dockerDir() . '/Dockerfile');

        self::assertStringContainsString('docker-php-ext-install intl', $dockerfile);
        self::assertStringContainsString('icu-libs', $dockerfile);
    }

    public function test_cakephp_upgrade_dockerfile_points_the_cakephp41_rule_set_at_defaults(): void
    {
        // Upstream's cakephp41 set imports a default-rector.php that does not exist, so Rector never loads it.
        $dockerfile = (string) file_get_contents($this->dockerDir() . '/Dockerfile');

        self::assertStringContainsString("sed -i 's#/default-rector\\.php#/defaults.php#' /upgrade/config/rector/cakephp41.php", $dockerfile);
    }

    public function test_cakephp_upgrade_dockerfile_drops_only_the_socket_block_from_cakephp43(): void
    {
        // Rector 2 removed PropertyFetchToMethodCall; the rest of cakephp43 (fetchTable() and all) stays.
        $dockerfile = (string) file_get_contents($this->dockerDir() . '/Dockerfile');

        self::assertStringContainsString("sed -i '/ruleWithConfiguration(PropertyFetchToMethodCallRector::class/,/^    \\]);\$/d' /upgrade/config/rector/sets/cakephp43.php", $dockerfile);
        self::assertStringContainsString('php -l /upgrade/config/rector/sets/cakephp43.php', $dockerfile);
    }
}
