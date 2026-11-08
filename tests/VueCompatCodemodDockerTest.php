<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Symfony\Component\Process\Process;
use Waynestate\Booster\Hop\HopRegistry;

/**
 * The vue-compat-codemod hop against the real tool: the pinned
 * vue-upgrade-tool from npm, run in the same Node container that NodeRunner
 * uses, over two minimal .vue fixtures.
 *
 * - changed/: a Vue 2.7 component that still uses $children, which Vue 3
 *   removed. The tool cannot fix that by machine, so it reports the usage as
 *   a manual migration and names the component file in its output.
 * - keep/: a clean Vue 3 component. The tool leaves every file
 *   byte-identical and lists no manual items.
 *
 * VueCompatCodemodTest covers the hop itself with a fake runner; this is the
 * proof that the real tool does what Booster claims about it. It needs Docker
 * and the npm registry, so it is not part of `composer ci`. Run it with:
 *
 *     BOOSTER_DOCKER_TESTS=1 vendor/bin/phpunit tests/VueCompatCodemodDockerTest.php
 */
final class VueCompatCodemodDockerTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('BOOSTER_DOCKER_TESTS') !== '1') {
            self::markTestSkipped('Set BOOSTER_DOCKER_TESTS=1 to run the Docker-gated vue-compat codemod tests.');
        }
    }

    public function test_the_real_tool_reports_the_removed_api_and_names_the_component_file(): void
    {
        [$app, $before] = $this->fixture('changed');
        $output = $this->runTool($app);

        self::assertStringContainsString('Processed 1 of 1 matching files with 0 errors', $output);
        self::assertStringContainsString('MANUAL MIGRATIONS:', $output);
        self::assertStringContainsString('/app/src/App.vue', $output);
        self::assertStringContainsString('$children has been removed.', $output);
        // Manual items are reported, not fixed: the tree stays untouched.
        self::assertSame($before, $this->hashes($app));
    }

    public function test_the_real_tool_leaves_a_clean_vue_3_component_alone(): void
    {
        [$app, $before] = $this->fixture('keep');
        $output = $this->runTool($app);

        self::assertStringContainsString('Processed 1 of 1 matching files with 0 errors', $output);
        self::assertStringNotContainsString('MANUAL MIGRATIONS:', $output);
        self::assertSame($before, $this->hashes($app));
    }

    /**
     * Copies the named fixture into a fresh temp directory.
     *
     * @return array{0: string, 1: array<string, string>} the app directory and a hash of every file in it
     */
    private function fixture(string $name): array
    {
        $app = $this->tempDir();
        $this->copyTree(__DIR__ . '/fixtures/vue-compat/' . $name, $app);
        // The container may run as another user; let it read everything.
        @chmod($app, 0777);

        return [$app, $this->hashes($app)];
    }

    private function runTool(string $app): string
    {
        // The same docker run NodeRunner builds, plus the shared npm cache
        // volume so the npx download never hits the registry cold twice.
        $process = new Process([
            'docker', 'run', '--rm',
            '-v', rtrim($app, '/\\') . ':/app',
            '-v', 'stack-npm-cache:/npm-cache',
            '-e', 'npm_config_cache=/npm-cache',
            '-w', '/app',
            'node:24-bookworm-slim',
            'npx', '--yes', 'vue-upgrade-tool@' . (new HopRegistry())->vueUpgradeToolVersion(),
            '--files', 'src/**/*',
        ], null, null, null, 600.0);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());

        return $process->getOutput();
    }

    private function copyTree(string $from, string $to): void
    {
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir($from . '/' . $entry)) {
                mkdir($to . '/' . $entry, 0775, true);
                $this->copyTree($from . '/' . $entry, $to . '/' . $entry);
            } else {
                copy($from . '/' . $entry, $to . '/' . $entry);
            }
        }
    }

    /**
     * A hash of every file in the tree, keyed by its relative path, so a run
     * that rewrites even one byte shows up as a difference.
     *
     * @return array<string, string>
     */
    private function hashes(string $dir): array
    {
        $hashes = [];
        foreach ($this->files($dir) as $relative) {
            $hashes[$relative] = sha1_file($dir . '/' . $relative);
        }
        ksort($hashes);

        return $hashes;
    }

    /**
     * @return list<string> relative paths of every file under $dir
     */
    private function files(string $dir, string $base = ''): array
    {
        $found = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $found = [...$found, ...$this->files($path, $base . $entry . '/')];
            } else {
                $found[] = $base . $entry;
            }
        }

        return $found;
    }
}
