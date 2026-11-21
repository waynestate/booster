<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests\Update;

use Symfony\Component\Console\Tester\ApplicationTester;
use Waynestate\Booster\Console\BoosterApplication;
use Waynestate\Booster\Update\HttpUpdateFetcher;
use Waynestate\Booster\Update\InstallKind;
use Waynestate\Booster\Update\UpdateCache;
use Waynestate\Booster\Update\UpdateFetcher;
use Waynestate\Booster\Update\UpdateNotice;
use Waynestate\Booster\Tests\TestCase;

/**
 * The update notice: one stderr line after a command when a newer stable
 * release exists, with the command that updates however Booster was installed.
 * Every test runs through the injected fetcher and clock seams, so nothing
 * here touches the network, the real cache dir, or the wall clock.
 */
final class UpdateNoticeTest extends TestCase
{
    /** A fixed "now" every test runs with. */
    private const NOW = 1_700_000_000;

    private const PACKAGE = 'waynestate/booster';

    private ?string $savedCi = null;

    private ?string $savedOptOut = null;

    protected function setUp(): void
    {
        parent::setUp();
        $ci = getenv('CI');
        $optOut = getenv('WAYNE_NO_UPDATE_CHECK');
        $this->savedCi = $ci === false ? null : $ci;
        $this->savedOptOut = $optOut === false ? null : $optOut;
        putenv('CI');
        putenv('WAYNE_NO_UPDATE_CHECK');
    }

    protected function tearDown(): void
    {
        $this->restoreEnv('CI', $this->savedCi);
        $this->restoreEnv('WAYNE_NO_UPDATE_CHECK', $this->savedOptOut);
        parent::tearDown();
    }

    // -- the line, per install kind ------------------------------------------

    public function testLineForPhar(): void
    {
        $notice = $this->notice(
            new InstallKind(InstallKind::PHAR, 'booster', '/tmp/booster.phar'),
            '1.19.0',
            ['1.19.0', '1.20.0'],
        );

        self::assertSame(
            'booster 1.19.0 is out of date: 1.20.0 is the newest release. '
            . 'Update: curl -L -o booster.phar https://github.com/waynestate/booster/releases/latest/download/booster.phar',
            $notice->line(),
        );
    }

    public function testLineForSourceCheckout(): void
    {
        $notice = $this->notice(
            new InstallKind(InstallKind::SOURCE, 'booster', '/home/dev/booster'),
            '1.19.0',
            ['1.19.0', '1.20.0'],
        );

        self::assertSame(
            'booster 1.19.0 is out of date: 1.20.0 is the newest release. Update: git pull',
            $notice->line(),
        );
    }

    public function testLineForComposerGlobal(): void
    {
        $notice = $this->notice($this->globalInstall(), '1.19.0', ['1.19.0', '1.20.0']);

        self::assertSame(
            'booster 1.19.0 is out of date: 1.20.0 is the newest release. Update: composer global update waynestate/booster',
            $notice->line(),
        );
    }

    public function testLineForProjectDependency(): void
    {
        $project = $this->tempDir();
        file_put_contents($project . '/composer.json', json_encode(['require' => [self::PACKAGE => '^1.0']]));
        $notice = $this->notice(
            new InstallKind(InstallKind::PROJECT, 'booster', $project . '/vendor/waynestate/booster', $project),
            '1.19.0',
            ['1.19.0', '1.20.0'],
        );

        self::assertSame(
            'booster 1.19.0 is out of date: 1.20.0 is the newest release. Update: composer update waynestate/booster',
            $notice->line(),
        );
    }

    public function testLineForPinnedProjectDependency(): void
    {
        $project = $this->tempDir();
        file_put_contents($project . '/composer.json', json_encode(['require' => [self::PACKAGE => '^1.0']]));
        $notice = $this->notice(
            new InstallKind(InstallKind::PROJECT, 'booster', $project . '/vendor/waynestate/booster', $project),
            '1.19.0',
            ['1.19.0', '2.0.0'],
        );

        self::assertSame(
            'booster 1.19.0 is out of date: 2.0.0 is the newest release. Update: composer require waynestate/booster:^2.0',
            $notice->line(),
        );

        $devProject = $this->tempDir();
        file_put_contents($devProject . '/composer.json', json_encode(['require-dev' => [self::PACKAGE => '~1.2']]));
        $devNotice = $this->notice(
            new InstallKind(InstallKind::PROJECT, 'booster', $devProject . '/vendor/waynestate/booster', $devProject),
            '1.19.0',
            ['1.19.0', '2.0.0'],
        );

        self::assertSame(
            'booster 1.19.0 is out of date: 2.0.0 is the newest release. Update: composer require waynestate/booster:^2.0 --dev',
            $devNotice->line(),
        );
    }

    public function testLineForUnknownInstall(): void
    {
        $notice = $this->notice(new InstallKind(InstallKind::UNKNOWN, 'booster'), '1.19.0', ['1.19.0', '1.20.0']);

        self::assertSame(
            'booster 1.19.0 is out of date: 1.20.0 is the newest release. Update: composer global update waynestate/booster',
            $notice->line(),
        );
    }

    // -- the cache and the network -------------------------------------------

    public function testCachedResultMakesNoFetch(): void
    {
        $cacheFile = $this->tempDir() . '/update-check.json';
        file_put_contents($cacheFile, json_encode(['checked_at' => self::NOW, 'latest' => '1.20.0']));
        $fetcher = new FixedUpdateFetcher($this->p2Document(['1.19.0']));
        $notice = $this->noticeWith($fetcher, $cacheFile, '1.19.0');

        self::assertSame(
            'booster 1.19.0 is out of date: 1.20.0 is the newest release. Update: composer global update waynestate/booster',
            $notice->line(),
        );
        self::assertSame(
            'booster 1.19.0 is out of date: 1.20.0 is the newest release. Update: composer global update waynestate/booster',
            $notice->line(),
        );
        self::assertSame(0, $fetcher->calls);
    }

    public function testStaleCacheFetchesOnce(): void
    {
        $cacheFile = $this->tempDir() . '/update-check.json';
        file_put_contents($cacheFile, json_encode(['checked_at' => self::NOW - 86_401, 'latest' => '1.0.0']));
        $fetcher = new FixedUpdateFetcher($this->p2Document(['1.0.0', '1.20.0']));
        $notice = $this->noticeWith($fetcher, $cacheFile, '1.19.0');

        self::assertSame(
            'booster 1.19.0 is out of date: 1.20.0 is the newest release. Update: composer global update waynestate/booster',
            $notice->line(),
        );
        self::assertSame(1, $fetcher->calls);

        $stored = json_decode((string) file_get_contents($cacheFile), true);
        self::assertIsArray($stored);
        self::assertSame(self::NOW, $stored['checked_at']);
        self::assertSame('1.20.0', $stored['latest']);

        $notice->line();
        self::assertSame(1, $fetcher->calls);
    }

    public function testOfflineIsSilent(): void
    {
        $cacheFile = $this->tempDir() . '/update-check.json';
        $fetcher = new FixedUpdateFetcher(null);
        $notice = $this->noticeWith($fetcher, $cacheFile, '1.19.0');

        self::assertNull($notice->line());
        self::assertSame(1, $fetcher->calls);

        $stored = json_decode((string) file_get_contents($cacheFile), true);
        self::assertIsArray($stored);
        self::assertSame(self::NOW, $stored['checked_at']);
        self::assertNull($stored['latest']);
    }

    public function testMalformedJsonIsSilent(): void
    {
        // At the HTTP layer: a body that is not JSON decodes to null.
        $bad = $this->tempDir() . '/not-json.txt';
        file_put_contents($bad, '{ this is not json');
        self::assertNull((new HttpUpdateFetcher())->fetch('file:///' . str_replace('\\', '/', $bad)));

        // At the notice layer: a document without the package list is silent.
        $fetcher = new FixedUpdateFetcher(['packages' => []]);
        $notice = $this->noticeWith($fetcher, $this->tempDir() . '/update-check.json', '1.19.0');
        self::assertNull($notice->line());
    }

    // -- silence rules ---------------------------------------------------------

    public function testCiEnvIsSilent(): void
    {
        putenv('CI=true');
        $fetcher = new FixedUpdateFetcher($this->p2Document(['1.19.0', '1.20.0']));
        $notice = $this->noticeWith($fetcher, $this->tempDir() . '/update-check.json', '1.19.0');

        self::assertNull($notice->line());
        self::assertSame(0, $fetcher->calls);

        foreach (['0', 'false', ''] as $value) {
            putenv('CI=' . $value);
            $loose = $this->noticeWith(
                new FixedUpdateFetcher($this->p2Document(['1.19.0', '1.20.0'])),
                $this->tempDir() . '/update-check.json',
                '1.19.0',
            );
            self::assertNotNull($loose->line());
        }
    }

    public function testOptOutEnvIsSilent(): void
    {
        putenv('WAYNE_NO_UPDATE_CHECK=1');
        $fetcher = new FixedUpdateFetcher($this->p2Document(['1.19.0', '1.20.0']));
        $notice = $this->noticeWith($fetcher, $this->tempDir() . '/update-check.json', '1.19.0');

        self::assertNull($notice->line());
        self::assertSame(0, $fetcher->calls);
    }

    public function testQuietIsSilent(): void
    {
        $fetcher = new FixedUpdateFetcher($this->p2Document(['1.19.0', '1.20.0']));
        $notice = $this->noticeWith($fetcher, $this->tempDir() . '/update-check.json', '1.19.0');

        self::assertNull($notice->line(true));
        self::assertSame(0, $fetcher->calls);
    }

    public function testStdoutUntouchedByJsonFormat(): void
    {
        $install = $this->globalInstall();

        $appOutdated = new BoosterApplication(null, $this->noticeWith(
            new FixedUpdateFetcher($this->p2Document(['1.19.0', '1.20.0'])),
            $this->tempDir() . '/outdated.json',
            '1.19.0',
            $install,
        ));
        $appOutdated->setAutoExit(false);
        $outdated = new ApplicationTester($appOutdated);
        $exit = $outdated->run(['command' => 'hops', '--format' => 'json'], ['capture_stderr_separately' => true]);

        $appCurrent = new BoosterApplication(null, $this->noticeWith(
            new FixedUpdateFetcher($this->p2Document(['1.19.0'])),
            $this->tempDir() . '/current.json',
            '1.19.0',
            $install,
        ));
        $appCurrent->setAutoExit(false);
        $current = new ApplicationTester($appCurrent);
        $current->run(['command' => 'hops', '--format' => 'json'], ['capture_stderr_separately' => true]);

        self::assertSame(0, $exit);
        self::assertSame($current->getDisplay(), $outdated->getDisplay());
        self::assertIsArray(json_decode($outdated->getDisplay(), true));
        self::assertStringContainsString(
            'booster 1.19.0 is out of date: 1.20.0 is the newest release. Update: composer global update waynestate/booster',
            $outdated->getErrorOutput(),
        );
        self::assertStringNotContainsString('out of date', $current->getErrorOutput());
    }

    // -- versions ----------------------------------------------------------------

    public function testNormalisesVPrefix(): void
    {
        $notice = $this->notice($this->globalInstall(), 'v1.19.0', ['v1.19.0', '1.20.0', 'v1.21.0']);

        self::assertSame(
            'booster 1.19.0 is out of date: 1.21.0 is the newest release. Update: composer global update waynestate/booster',
            $notice->line(),
        );
    }

    public function testIgnoresPrereleases(): void
    {
        $notice = $this->notice($this->globalInstall(), '1.19.0', [
            '1.19.0',
            '1.20.0',
            '1.21.0-alpha1',
            '1.21.0-RC2',
            '2.0.0-dev',
        ]);

        self::assertSame(
            'booster 1.19.0 is out of date: 1.20.0 is the newest release. Update: composer global update waynestate/booster',
            $notice->line(),
        );
    }

    public function testMajorBumpAddsUpgradingLink(): void
    {
        $major = $this->notice($this->globalInstall(), '1.19.0', ['1.19.0', '2.0.0']);
        self::assertSame(
            'booster 1.19.0 is out of date: 2.0.0 is the newest release. '
            . 'Update: composer global update waynestate/booster Upgrading: https://booster.apps.wayne.edu/docs/upgrading/',
            $major->line(),
        );

        $minor = $this->notice($this->globalInstall(), '1.19.0', ['1.19.0', '1.20.0']);
        self::assertStringNotContainsString('Upgrading:', $minor->line());
    }

    public function testNotNewerIsSilent(): void
    {
        $same = $this->notice($this->globalInstall(), '1.20.0', ['1.19.0', '1.20.0']);
        self::assertNull($same->line());

        $ahead = $this->notice($this->globalInstall(), '1.21.0', ['1.19.0', '1.20.0']);
        self::assertNull($ahead->line());
    }

    // -- detection -----------------------------------------------------------------

    public function testDetectsSourceCheckout(): void
    {
        $root = $this->tempDir();
        file_put_contents($root . '/.git', "gitdir: /home/dev/booster/.git/worktrees/root\n");

        self::assertSame(InstallKind::SOURCE, InstallKind::detected($root)->kind);
    }

    public function testDetectsComposerGlobalHome(): void
    {
        $home = $this->tempDir();
        mkdir($home . '/vendor/waynestate/booster', 0775, true);
        putenv('COMPOSER_HOME=' . $home);

        self::assertSame(InstallKind::COMPOSER_GLOBAL, InstallKind::detected($home . '/vendor/waynestate/booster')->kind);
    }

    public function testDetectsProjectDependency(): void
    {
        $project = $this->tempDir();
        mkdir($project . '/vendor/waynestate/booster', 0775, true);
        putenv('COMPOSER_HOME=' . $this->tempDir());

        $kind = InstallKind::detected($project . '/vendor/waynestate/booster');

        self::assertSame(InstallKind::PROJECT, $kind->kind);
        self::assertSame($project, $kind->projectRoot);
    }

    public function testDetectsUnknownInstall(): void
    {
        self::assertSame(InstallKind::UNKNOWN, InstallKind::detected($this->tempDir())->kind);
    }

    // -- helpers ---------------------------------------------------------------------

    /** A Composer-global install at a fixed path, for the line tests. */
    private function globalInstall(): InstallKind
    {
        return new InstallKind(InstallKind::COMPOSER_GLOBAL, 'booster', '/home/dev/.config/composer/vendor/waynestate/booster');
    }

    /**
     * A notice wired to the default install and a fixed clock, fed by a p2
     * document listing $versions.
     *
     * @param list<string> $versions
     */
    private function notice(InstallKind $install, string $version, array $versions): UpdateNotice
    {
        return $this->noticeWith(
            new FixedUpdateFetcher($this->p2Document($versions)),
            $this->tempDir() . '/update-check.json',
            $version,
            $install,
        );
    }

    private function noticeWith(UpdateFetcher $fetcher, string $cacheFile, string $version, ?InstallKind $install = null): UpdateNotice
    {
        $install ??= $this->globalInstall();

        return new UpdateNotice(
            fetcher: $fetcher,
            cache: new UpdateCache($cacheFile),
            install: $install,
            tool: 'booster',
            version: $version,
            clock: static fn(): int => self::NOW,
        );
    }

    /**
     * @param list<string> $versions
     * @return array<string, mixed>
     */
    private function p2Document(array $versions): array
    {
        $releases = [];
        foreach ($versions as $version) {
            $releases[] = ['name' => self::PACKAGE, 'version' => $version];
        }

        return ['packages' => [self::PACKAGE => $releases]];
    }

    private function restoreEnv(string $name, ?string $value): void
    {
        if ($value === null) {
            putenv($name);
        } else {
            putenv($name . '=' . $value);
        }
    }
}

/**
 * The fetcher seam for the update-notice tests: it answers every fetch with
 * one fixed document (or null) and counts its calls, so a test can prove the
 * network was, or was not, touched.
 */
final class FixedUpdateFetcher implements UpdateFetcher
{
    public int $calls = 0;

    public function __construct(private readonly ?array $document) {}

    public function fetch(string $url): ?array
    {
        $this->calls++;

        return $this->document;
    }
}
