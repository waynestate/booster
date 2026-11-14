<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

/**
 * The a11y gate runs Preflight's own checker, vendored into this repo so that
 * local runs and CI runs cannot drift apart. This test pins that vendor copy:
 * the SHA-256 of tools/a11y/check.mjs must match the Preflight tag below, and
 * the image, playwright version and axe-core version in the a11y scripts and
 * tools/a11y/package.json must match the component defaults of that tag.
 */
final class A11yCheckerPinTest extends TestCase
{
    /** Preflight tag the vendored checker comes from (the checker is unchanged since 0.3.1). */
    private const PREFLIGHT_TAG = '0.9.0';

    /** SHA-256 of tools/a11y/check.mjs at that tag. */
    private const CHECKER_SHA256 = '8f11379ac03cee8b52e39835639430abe1c749eeb5ffb2abb43aeaa300f1c663';

    /** Image default of the a11y component at that tag (digest included). */
    private const IMAGE = 'mcr.microsoft.com/playwright:v1.65.0-noble@sha256:6e3fb709361521eb27161606e6da6d2674e5e89fa16516cb37d6fdd91372273d';

    /** @axe-core/playwright version in tools/a11y/package.json at that tag. */
    private const AXE_PLAYWRIGHT_VERSION = '4.14.0';

    /** axe-core version in tools/a11y/package.json at that tag. */
    private const AXE_CORE_VERSION = '4.14.0';

    private const UPDATE = 'Update the vendored checker and its pins: copy tools/a11y/check.mjs from the new '
        . 'Preflight tag, recompute its SHA-256 for CHECKER_SHA256, update PREFLIGHT_TAG, IMAGE and the '
        . 'version constants, then bump @axe-core/playwright and axe-core in tools/a11y/package.json and '
        . 'regenerate tools/a11y/package-lock.json. Also update the image string in '
        . 'scripts/a11y.sh and scripts/a11y.ps1.';

    public function test_the_vendored_checker_matches_preflight(): void
    {
        $path = dirname(__DIR__) . '/tools/a11y/check.mjs';
        self::assertFileExists($path, 'tools/a11y/check.mjs is missing. ' . self::UPDATE);

        // Normalize CRLF before hashing so the pin holds on every checkout.
        $content = str_replace("\r\n", "\n", (string) file_get_contents($path));
        $hash = hash('sha256', $content);
        self::assertSame(
            self::CHECKER_SHA256,
            $hash,
            'tools/a11y/check.mjs no longer matches Preflight ' . self::PREFLIGHT_TAG . '. ' . self::UPDATE,
        );
    }

    public function test_ci_still_uses_the_pinned_preflight_tag(): void
    {
        $ci = (string) file_get_contents(dirname(__DIR__) . '/.gitlab-ci.yml');
        self::assertStringContainsString(
            'preflight/a11y@' . self::PREFLIGHT_TAG,
            $ci,
            '.gitlab-ci.yml no longer uses preflight/a11y@' . self::PREFLIGHT_TAG . ', the tag the vendored '
            . 'checker was copied from. ' . self::UPDATE,
        );
    }

    public function test_the_scripts_use_the_pinned_image(): void
    {
        foreach (['scripts/a11y.sh', 'scripts/a11y.ps1'] as $script) {
            $text = (string) file_get_contents(dirname(__DIR__) . '/' . $script);
            self::assertStringContainsString(
                self::IMAGE,
                $text,
                $script . ' no longer uses the pinned Playwright image of Preflight ' . self::PREFLIGHT_TAG . '. ' . self::UPDATE,
            );
        }
    }

    public function test_the_package_pins_match_preflight(): void
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__) . '/tools/a11y/package.json'), true);
        self::assertIsArray($manifest, 'tools/a11y/package.json is not valid JSON.');
        $deps = $manifest['dependencies'] ?? [];
        self::assertSame(
            self::AXE_PLAYWRIGHT_VERSION,
            $deps['@axe-core/playwright'] ?? null,
            '@axe-core/playwright in tools/a11y/package.json no longer matches Preflight ' . self::PREFLIGHT_TAG . '. ' . self::UPDATE,
        );
        self::assertSame(
            self::AXE_CORE_VERSION,
            $deps['axe-core'] ?? null,
            'axe-core in tools/a11y/package.json no longer matches Preflight ' . self::PREFLIGHT_TAG . '. ' . self::UPDATE,
        );
    }
}
