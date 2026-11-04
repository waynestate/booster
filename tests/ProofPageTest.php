<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Console\BoosterApplication;
use Waynestate\Booster\Site\ProofPage;
use Waynestate\Booster\ToolError;

/**
 * The proof page renderer: the front page's chrome around the captured plan
 * and dry-run text of the example apps, plus the check that keeps the
 * committed page honest. The tests read the committed capture in
 * site/proof-data against site/index.html; failure cases build throwaway
 * data folders in the system temp dir.
 */
final class ProofPageTest extends TestCase
{
    private const DATA_DIR = __DIR__ . '/../site/proof-data';
    private const INDEX_FILE = __DIR__ . '/../site/index.html';

    private function indexHtml(): string
    {
        $html = file_get_contents(self::INDEX_FILE);
        if ($html === false) {
            throw new \RuntimeException('Missing site/index.html.');
        }

        return $html;
    }

    /** @return list<string> */
    private function apps(): array
    {
        $apps = [];
        foreach (glob(self::DATA_DIR . '/*.plan.txt') ?: [] as $file) {
            $apps[] = substr(basename($file), 0, -strlen('.plan.txt'));
        }
        sort($apps);

        return $apps;
    }

    public function test_renders_one_h1_and_a_section_per_app(): void
    {
        $page = ProofPage::render(self::DATA_DIR, $this->indexHtml());

        self::assertSame(1, preg_match_all('/<h1\b/', $page));
        self::assertStringContainsString('<h1 id="proof-title">Real output, from Booster\'s own example apps</h1>', $page);
        self::assertStringContainsString('<section class="wsu-container proof" aria-labelledby="proof-title">', $page);
        self::assertStringContainsString('<nav aria-label="Example apps">', $page);
        foreach ($this->apps() as $app) {
            self::assertStringContainsString(sprintf('<li><a href="#app-%s">%s</a></li>', $app, $app), $page);
            self::assertStringContainsString(sprintf('<section id="app-%s" aria-labelledby="h-%s">', $app, $app), $page);
            self::assertStringContainsString(sprintf('<h2 id="h-%s">%s</h2>', $app, $app), $page);
            self::assertStringContainsString(sprintf('<p><code>booster plan --path=examples/%s</code></p>', $app), $page);
            self::assertStringContainsString(sprintf('<p><code>booster run --path=examples/%s --dry-run</code></p>', $app), $page);
        }
        self::assertSame(count($this->apps()), preg_match_all('/<section id="app-/', $page));
        self::assertSame(2 * count($this->apps()), preg_match_all('/<pre class="out" tabindex="0">/', $page));
    }

    public function test_the_chrome_comes_from_the_front_page(): void
    {
        $page = ProofPage::render(self::DATA_DIR, $this->indexHtml());

        self::assertStringContainsString('<title>BOOSTER proof: real output</title>', $page);
        self::assertStringNotContainsString('<title>Booster: Boosts your versions.</title>', $page);
        // Page-local anchors in the chrome point back at the front page; the
        // #main skip link stays where it is.
        self::assertStringContainsString('href="index.html#record"', $page);
        self::assertStringContainsString('href="index.html#install"', $page);
        self::assertStringNotContainsString('href="#record"', $page);
        self::assertStringContainsString('href="#main"', $page);
        self::assertStringContainsString('aria-label="Main"', $page);
        self::assertStringNotContainsString('aria-label="On this page"', $page);
        // The front page's own content is gone; its footer remains.
        self::assertStringNotContainsString('id="hero-title"', $page);
        self::assertStringContainsString('class="foot"', $page);
    }

    public function test_ids_are_unique(): void
    {
        $page = ProofPage::render(self::DATA_DIR, $this->indexHtml());

        preg_match_all('/\bid="([^"]*)"/', $page, $matches);
        self::assertNotEmpty($matches[1]);
        self::assertSame(count($matches[1]), count(array_unique($matches[1])));
    }

    public function test_every_pre_is_focusable(): void
    {
        $page = ProofPage::render(self::DATA_DIR, $this->indexHtml());

        preg_match_all('/<pre\b[^>]*>/', $page, $matches);
        self::assertSame(2 * count($this->apps()), count($matches[0]));
        foreach ($matches[0] as $tag) {
            self::assertStringContainsString('tabindex="0"', $tag);
        }
    }

    public function test_captured_text_is_html_escaped(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/meta.json', '{"version":"1.8.0","captured":"2026-01-02"}');
        file_put_contents($dir . '/alpha.plan.txt', "line one\nHello <script>alert('x & y')</script> world\n");
        file_put_contents($dir . '/alpha.run.txt', "nothing changed\n");
        file_put_contents($dir . '/beta.plan.txt', "plain\n");
        file_put_contents($dir . '/beta.run.txt', "plain\n");

        $page = ProofPage::render($dir, $this->indexHtml());

        self::assertStringNotContainsString("<script>alert('x & y')</script>", $page);
        self::assertStringContainsString("Hello &lt;script&gt;alert(&#039;x &amp; y&#039;)&lt;/script&gt; world", $page);
    }

    public function test_check_returns_null_for_the_rendered_page(): void
    {
        $page = ProofPage::render(self::DATA_DIR, $this->indexHtml());

        self::assertNull(ProofPage::check(self::DATA_DIR, $this->indexHtml(), $page, BoosterApplication::VERSION));
        self::assertNull(ProofPage::check(self::DATA_DIR, $this->indexHtml(), $page, BoosterApplication::VERSION, false));
    }

    public function test_check_names_an_out_of_date_page(): void
    {
        $page = ProofPage::render(self::DATA_DIR, $this->indexHtml());

        $message = ProofPage::check(self::DATA_DIR, $this->indexHtml(), $page . "tampered\n", BoosterApplication::VERSION);

        self::assertIsString($message);
        self::assertStringContainsString('out of date', $message);
    }

    public function test_check_names_a_version_mismatch(): void
    {
        $dir = $this->copyDataToTemp();
        file_put_contents($dir . '/meta.json', '{"version":"9.9.9","captured":"2026-11-03"}');
        $page = ProofPage::render($dir, $this->indexHtml());

        $message = ProofPage::check($dir, $this->indexHtml(), $page, BoosterApplication::VERSION);

        self::assertIsString($message);
        self::assertStringContainsString('9.9.9', $message);
        self::assertStringContainsString(BoosterApplication::VERSION, $message);
        self::assertNull(ProofPage::check($dir, $this->indexHtml(), $page, BoosterApplication::VERSION, false));
    }

    public function test_missing_run_file_throws(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/meta.json', '{"version":"1.8.0","captured":"2026-11-03"}');
        file_put_contents($dir . '/alpha.plan.txt', "plan\n");

        $this->expectException(ToolError::class);
        ProofPage::render($dir, $this->indexHtml());
    }

    /** Copies the committed proof data into a temp folder and returns it. */
    private function copyDataToTemp(): string
    {
        $dir = $this->tempDir();
        foreach (glob(self::DATA_DIR . '/*') ?: [] as $file) {
            copy($file, $dir . '/' . basename($file));
        }

        return $dir;
    }
}
