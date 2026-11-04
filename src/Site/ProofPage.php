<?php

declare(strict_types=1);

namespace Waynestate\Booster\Site;

use Waynestate\Booster\ToolError;

/**
 * Renders site/proof.html, the "Real output" page: the captured text of
 * `booster plan` and `booster run --dry-run` for every example app, wrapped
 * in the front page's chrome so the two pages look like one site.
 */
final class ProofPage
{
    /**
     * The whole proof page: the front page's chrome around one section per
     * example app. Deterministic: same inputs, same bytes.
     */
    public static function render(string $dataDir, string $indexHtml): string
    {
        $meta = self::readMeta($dataDir);
        $apps = self::appsIn($dataDir);
        [$head, $tail] = self::splitChrome($indexHtml);

        $body = '<section class="wsu-container proof" aria-labelledby="proof-title">' . "\n";
        $body .= '<h1 id="proof-title">Real output, from Booster\'s own example apps</h1>' . "\n";
        $body .= sprintf(
            '<p>Captured on %s with Booster %s. Each app below is one of the example apps in the <code>examples/</code> folder. A dry run changes nothing. This page is regenerated each release.</p>%s',
            self::escape($meta['captured']),
            self::escape($meta['version']),
            "\n",
        );
        $body .= '<nav aria-label="Example apps">' . "\n<ul>\n";
        foreach ($apps as $app) {
            $body .= sprintf('<li><a href="#app-%s">%s</a></li>%s', $app, self::escape($app), "\n");
        }
        $body .= '</ul>' . "\n</nav>\n";
        foreach ($apps as $app) {
            $plan = self::readText($dataDir, $app . '.plan.txt');
            $run = self::readText($dataDir, $app . '.run.txt');
            $body .= sprintf('<section id="app-%s" aria-labelledby="h-%s">' . "\n", $app, $app);
            $body .= sprintf('<h2 id="h-%s">%s</h2>%s', $app, self::escape($app), "\n");
            $body .= self::block('Plan', 'booster plan --path=examples/' . $app, $plan);
            $body .= self::block('Dry run', 'booster run --path=examples/' . $app . ' --dry-run', $run);
            $body .= '</section>' . "\n";
        }
        $body .= '</section>' . "\n";

        return self::rewriteChrome($head) . $body . self::rewriteChrome($tail);
    }

    /**
     * CI gate: null when $pageHtml matches a fresh render and (when
     * $checkVersion) the data's version matches the running Booster,
     * otherwise a plain one-sentence message.
     */
    public static function check(string $dataDir, string $indexHtml, string $pageHtml, string $currentVersion, bool $checkVersion = true): ?string
    {
        $meta = self::readMeta($dataDir);
        if ($checkVersion && $meta['version'] !== $currentVersion) {
            return sprintf(
                'The proof page is for %s but Booster is %s: run php bin/build-proof.php --capture, then php bin/build-proof.php.',
                $meta['version'],
                $currentVersion,
            );
        }
        if ($pageHtml !== self::render($dataDir, $indexHtml)) {
            return 'site/proof.html is out of date: run php bin/build-proof.php.';
        }

        return null;
    }

    /** One command block: heading, the exact command, the captured output. */
    private static function block(string $heading, string $command, string $output): string
    {
        return '<h3>' . self::escape($heading) . "</h3>\n"
            . '<p><code>' . self::escape($command) . "</code></p>\n"
            . '<pre class="out" tabindex="0">' . self::escape($output) . "</pre>\n";
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    /**
     * The front page cut at its main element: everything up to and including
     * the line with <main id="main">, and everything from the line with
     * </main> to the end. The middle, the front page's own content, is gone.
     *
     * @return array{0: string, 1: string} head and tail
     */
    private static function splitChrome(string $indexHtml): array
    {
        $main = self::lineBounds($indexHtml, '<main id="main">');
        $close = self::lineBounds($indexHtml, '</main>');
        if ($main === null || $close === null || $close[0] < $main[1]) {
            throw new ToolError('The index page has no <main id="main"> line before its </main> line, so the proof page cannot borrow its chrome.');
        }

        return [substr($indexHtml, 0, $main[1]), substr($indexHtml, $close[0])];
    }

    /**
     * The chrome's three rewrites: the title, page-local anchors pointed back
     * at the front page (the #main skip link stays where it is), and the
     * masthead nav's aria-label.
     */
    private static function rewriteChrome(string $chunk): string
    {
        $title = preg_replace('/<title>.*?<\/title>/s', '<title>BOOSTER proof: real output</title>', $chunk, 1);
        if ($title === null) {
            throw new ToolError('Could not rewrite the index page chrome.');
        }
        $anchors = preg_replace('/href="#(?!main")/', 'href="index.html#', $title);
        if ($anchors === null) {
            throw new ToolError('Could not rewrite the index page chrome.');
        }

        return str_replace('aria-label="On this page"', 'aria-label="Main"', $anchors);
    }

    /**
     * Start and end (end exclusive, newline included) of the line that holds
     * $needle, or null when the needle is absent.
     *
     * @return array{0: int, 1: int}|null
     */
    private static function lineBounds(string $haystack, string $needle): ?array
    {
        $pos = strpos($haystack, $needle);
        if ($pos === false) {
            return null;
        }
        $before = strrpos(substr($haystack, 0, $pos), "\n");
        $start = $before === false ? 0 : $before + 1;
        $newline = strpos($haystack, "\n", $pos);
        $end = $newline === false ? strlen($haystack) : $newline + 1;

        return [$start, $end];
    }

    /** @return array{version: string, captured: string} */
    private static function readMeta(string $dataDir): array
    {
        $path = $dataDir . '/meta.json';
        if (!is_file($path)) {
            throw new ToolError(sprintf('The proof data folder %s has no meta.json.', $dataDir));
        }
        $raw = file_get_contents($path);
        $meta = json_decode($raw === false ? '' : $raw, true);
        if (!is_array($meta) || !is_string($meta['version'] ?? null) || !is_string($meta['captured'] ?? null)) {
            throw new ToolError(sprintf('The proof data meta.json in %s needs string "version" and "captured" keys.', $dataDir));
        }

        return ['version' => $meta['version'], 'captured' => $meta['captured']];
    }

    /**
     * App names from the *.plan.txt files, sorted by name; every app must
     * also have its .run.txt.
     *
     * @return list<string>
     */
    private static function appsIn(string $dataDir): array
    {
        if (!is_dir($dataDir)) {
            throw new ToolError(sprintf('The proof data folder %s does not exist.', $dataDir));
        }
        $apps = [];
        foreach (glob($dataDir . '/*.plan.txt') ?: [] as $file) {
            $apps[] = substr(basename($file), 0, -strlen('.plan.txt'));
        }
        sort($apps);
        foreach ($apps as $app) {
            if (!is_file($dataDir . '/' . $app . '.run.txt')) {
                throw new ToolError(sprintf('The proof data has %s.plan.txt but no %s.run.txt in %s.', $app, $app, $dataDir));
            }
        }

        return $apps;
    }

    private static function readText(string $dataDir, string $name): string
    {
        $raw = file_get_contents($dataDir . '/' . $name);
        if ($raw === false) {
            throw new ToolError(sprintf('Could not read %s/%s.', $dataDir, $name));
        }

        return $raw;
    }
}
