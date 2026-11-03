<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Console\BoosterApplication;

/**
 * The version lives in one constant, BoosterApplication::VERSION, and the
 * release process copies it into CHANGELOG.md, README.md and the front page.
 * Nothing else forces those copies to move when the constant moves, so this
 * test fails when a release heading is cut without bumping the constant, or
 * when the README or the front page still show the old major.minor.
 */
final class VersionTest extends TestCase
{
    public function test_the_version_is_semver(): void
    {
        self::assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+$/',
            BoosterApplication::VERSION,
            'BoosterApplication::VERSION must be an X.Y.Z semver string',
        );
    }

    public function test_the_version_matches_the_newest_changelog_heading(): void
    {
        $root = dirname(__DIR__);
        $changelogPath = $root . '/CHANGELOG.md';
        self::assertFileExists($changelogPath);

        $changelog = (string) file_get_contents($changelogPath);
        self::assertNotSame('', $changelog);

        $matches = preg_match('/^## \[(\d+\.\d+\.\d+)\]/m', $changelog, $heading);
        self::assertSame(1, $matches, 'CHANGELOG.md has no numbered release heading');

        self::assertSame(
            BoosterApplication::VERSION,
            $heading[1],
            'BoosterApplication::VERSION does not match the newest CHANGELOG.md release heading: cut the heading or bump the constant',
        );
    }

    public function test_the_readme_and_front_page_carry_the_same_major_minor(): void
    {
        $root = dirname(__DIR__);
        $majorMinor = implode('.', array_slice(explode('.', BoosterApplication::VERSION), 0, 2));

        $readmePath = $root . '/README.md';
        self::assertFileExists($readmePath);
        $readme = (string) file_get_contents($readmePath);

        $matches = preg_match('/^Version (\d+\.\d+)\./m', $readme, $readmeVersion);
        self::assertSame(1, $matches, 'README.md has no "Version X.Y." line');
        self::assertSame(
            $majorMinor,
            $readmeVersion[1],
            'The README.md "Version X.Y." line does not match the major.minor of BoosterApplication::VERSION',
        );

        $sitePath = $root . '/site/index.html';
        self::assertFileExists($sitePath);
        $site = (string) file_get_contents($sitePath);

        $matches = preg_match('/masthead__ver">(\d+\.\d+)</', $site, $siteVersion);
        self::assertSame(1, $matches, 'site/index.html has no masthead__ver span');
        self::assertSame(
            $majorMinor,
            $siteVersion[1],
            'The site/index.html masthead__ver span does not match the major.minor of BoosterApplication::VERSION',
        );
    }
}
