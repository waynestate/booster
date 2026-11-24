<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

/**
 * The release wiring lives in two CI files (GitLab primary, GitHub mirror)
 * that must stay in agreement: both build the same phar with the same recipe
 * and only on version tags. This cheap guard reads both files as text and
 * fails when the tag trigger, the shared build recipe or the artifact path
 * disappears from either one, so a refactor that breaks the next tag is
 * caught in CI instead of at release time.
 */
final class ReleaseWorkflowTest extends TestCase
{
    /** The shared build recipe both CI systems must call. */
    private const SCRIPT = 'scripts/build-phar.sh';

    /** The artifact both CI systems must keep or attach. */
    private const ARTIFACT = 'build/booster.phar';

    public function test_gitlab_pipeline_builds_and_releases_the_phar_on_tags(): void
    {
        $text = $this->ciFile('.gitlab-ci.yml');

        self::assertStringContainsString(
            "if: '\$CI_COMMIT_TAG'",
            $text,
            'The GitLab phar job must run only on tags.',
        );
        self::assertStringContainsString(self::SCRIPT, $text, 'GitLab must build with the shared recipe.');
        self::assertStringContainsString(self::ARTIFACT, $text, 'GitLab must keep the phar as an artifact.');
        self::assertStringContainsString('release:', $text, 'GitLab must publish a release.');
    }

    public function test_github_workflow_builds_and_releases_the_phar_on_tags(): void
    {
        $text = $this->ciFile('.github/workflows/release-phar.yml');

        self::assertStringContainsString("'*.*.*'", $text, 'GitHub must trigger on bare semantic version tags.');
        self::assertStringContainsString("'v*'", $text, 'GitHub must also trigger on v-prefixed tags.');
        self::assertStringContainsString(self::SCRIPT, $text, 'GitHub must build with the shared recipe.');
        self::assertStringContainsString(self::ARTIFACT, $text, 'GitHub must attach the phar to the release.');
    }

    public function test_release_publishes_a_checksum_and_never_clobbers(): void
    {
        $script = $this->ciFile(self::SCRIPT);
        self::assertStringContainsString(
            'booster.phar.sha256',
            $script,
            'The build script must write the checksum file the releases publish.',
        );

        $gitlab = $this->ciFile('.gitlab-ci.yml');
        self::assertStringContainsString(
            'build/booster.phar.sha256',
            $gitlab,
            'GitLab must keep the checksum file as an artifact of the phar job.',
        );

        $github = $this->ciFile('.github/workflows/release-phar.yml');
        self::assertStringContainsString(
            'booster.phar.sha256',
            $github,
            'GitHub must attach the checksum file to the release.',
        );
        self::assertStringNotContainsString(
            '--clobber',
            $github,
            'A released asset is never replaced: the upload must fail loudly instead of clobbering it.',
        );
    }

    private function ciFile(string $relative): string
    {
        $path = dirname(__DIR__) . '/' . $relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
