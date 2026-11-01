<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Moves an app from WayneUI 1.x (Bootstrap 3 markup) to WayneUI 2.x (wsu-
 * classes). The mechanical class tokens, Font Awesome icons and brand
 * colours are rewritten in place; everything context dependent becomes a
 * manual note that names the file and line. It never runs app code: the old
 * markup is read with conservative patterns. Like a booster shot, it covers
 * the mechanical rows and leaves the judgement calls to a person.
 */
final class WayneUiMigration
{
    /** Directories BOOSTER never touches, even when they hold 1.x markup. */
    private const SKIP_PREFIXES = [
        '.git/',
        'vendor/',
        'node_modules/',
        'storage/',
        'bootstrap/cache/',
        'public/vendor/',
    ];

    /** Manual lines per rule before one summary line takes over. */
    private const MANUAL_CAP = 25;

    /** Class tokens rewritten inside class attributes, whole tokens only. */
    private const CLASS_REWRITES = [
        'navbar-brand' => 'wsu-app-bar__brand',
        'navbar-logo' => 'wsu-app-bar__mark',
        'wayne-ui-footer' => 'wsu-footer',
        'btn' => 'wsu-btn',
        'btn-primary' => 'wsu-btn--primary',
        'btn-default' => 'wsu-btn--outline',
        'btn-link' => 'wsu-btn--ghost',
        'btn-outline' => 'wsu-btn--outline',
        'btn-xs' => 'wsu-btn--sm',
        'btn-sm' => 'wsu-btn--sm',
        'btn-lg' => 'wsu-btn--lg',
        'label' => 'wsu-badge',
        'label-success' => 'wsu-badge--success',
        'badge' => 'wsu-badge',
        'table-responsive' => 'wsu-table-wrap',
        'form-group' => 'wsu-field',
        'control-label' => 'wsu-field__label',
        'checkbox' => 'wsu-checkbox',
        'radio' => 'wsu-radio',
        'sr-only-focusable' => 'wsu-sr-only-focusable',
        'sr-only' => 'wsu-sr-only',
    ];

    /** Font Awesome names to Lucide names, the table in MIGRATING.md. */
    private const ICONS = [
        'fa-bars' => 'menu',
        'fa-times' => 'x',
        'fa-close' => 'x',
        'fa-search' => 'search',
        'fa-user' => 'user',
        'fa-sign-out' => 'log-out',
        'fa-cog' => 'settings',
        'fa-gear' => 'settings',
        'fa-home' => 'home',
        'fa-angle-right' => 'chevron-right',
        'fa-chevron-right' => 'chevron-right',
        'fa-angle-left' => 'chevron-left',
        'fa-angle-down' => 'chevron-down',
        'fa-caret-down' => 'chevron-down',
        'fa-sort' => 'arrow-up-down',
        'fa-check' => 'check',
        'fa-check-circle' => 'circle-check',
        'fa-exclamation-circle' => 'circle-alert',
        'fa-exclamation-triangle' => 'triangle-alert',
        'fa-warning' => 'triangle-alert',
        'fa-info-circle' => 'info',
        'fa-plus' => 'plus',
        'fa-pencil' => 'pencil',
        'fa-edit' => 'pencil',
        'fa-trash' => 'trash-2',
        'fa-trash-o' => 'trash-2',
        'fa-download' => 'download',
        'fa-file-text-o' => 'file-text',
        'fa-users' => 'users',
        'fa-calendar' => 'calendar',
        'fa-bell' => 'bell',
        'fa-external-link' => 'external-link',
        'fa-book' => 'book-open',
        'fa-graduation-cap' => 'graduation-cap',
        'fa-building' => 'building-2',
        'fa-list-alt' => 'clipboard-list',
    ];

    /** Size modifiers a person sets by hand on x-wsu::icon. */
    private const ICON_SIZE_MODIFIERS = ['fa-lg', 'fa-2x', 'fa-3x', 'fa-4x', 'fa-5x', 'fa-xs', 'fa-sm', 'fa-fw'];

    /** Brand hexes (lowercase, without the #) to CSS variables. */
    private const COLOURS = [
        '006666' => 'var(--wsu-brand)',
        '066' => 'var(--wsu-brand)',
        '007a7a' => 'var(--wsu-brand-hover)',
        '005757' => 'var(--wsu-brand-active)',
        'cfdedc' => 'var(--wsu-brand-subtle)',
    ];

    /** Hexes the mapping leaves to a person, with the note to carry. */
    private const COLOUR_MANUAL = [
        '0c5449' => '#0c5449 doubles as the new brand value; use var(--wsu-surface-inverse) only for dark blocks',
        'cedddb' => '#cedddb has no single token; use var(--wsu-green-50) or var(--wsu-text-on-inverse)',
        '8b2145' => '#8B2145 becomes var(--wsu-nonproduction), with var(--wsu-on-nonproduction) for text',
        '671931' => '#671931 becomes var(--wsu-nonproduction), with var(--wsu-on-nonproduction) for text',
        '093f39' => '#093f39 belongs to the official header component; delete it',
    ];

    /**
     * Class tokens that need a person: rule name, exact tokens, token
     * prefixes and the note each occurrence carries (%s is the token).
     * Rules are tried in order; the first match wins.
     *
     * @var list<array{0: string, 1: list<string>, 2: list<string>, 3: string}>
     */
    private const MANUAL_CLASS_RULES = [
        ['card-footer', ['panel-footer'], [], '%s becomes .wsu-card__footer, but only if the enclosing panel became a card'],
        ['card-title', ['panel-heading', 'panel-title'], [], '%s becomes .wsu-card__title; the heading wrapper drops'],
        ['card', ['panel', 'well', 'thumbnail'], ['panel-'], '%s becomes .wsu-card; the panel-body structure does not carry over'],
        ['app-bar', ['navbar', 'navbar-default', 'navbar-fixed-top'], [], '%s is part of the 1.x navbar; the bar collapses into one .wsu-app-bar, so rebuild it by hand'],
        ['navbar-toggle', ['navbar-toggle'], [], '%s plus data-toggle drives a jQuery drawer; 2.x uses .wsu-app-bar__toggle--nav with data-wsu-drawer-toggle'],
        ['sidebar', ['sidebar', 'nav-sidebar'], [], '%s maps onto .wsu-sidebar with __list and __link children, so restructure it by hand'],
        ['shell', ['wayne-ui-main', 'wayne-ui-with-sidebar'], [], '%s moves to the .wsu-shell element (.wsu-shell--sidebar when the sidebar stays)'],
        ['page-header', ['wayne-ui-page-header', 'page-header'], [], '%s becomes .wsu-page-header with the title as a .wsu-page-header__title child'],
        ['breadcrumb', ['breadcrumb'], [], '%s becomes .wsu-breadcrumbs; add the .wsu-breadcrumbs__list inner list'],
        ['alert', ['alert'], ['alert-'], '%s becomes .wsu-alert (--warning and friends); the new markup adds an icon and a title'],
        ['table', ['table', 'table-striped'], [], '%s becomes .wsu-table (--zebra for stripes) inside a .wsu-table-wrap; insert the wrapper div'],
        ['form-control', ['form-control'], [], '%s becomes .wsu-input, .wsu-select or .wsu-textarea; the choice depends on the element'],
        ['has-error', ['has-error'], [], '%s moves to aria-invalid="true" on the control; the attribute leaves the wrapper'],
        ['help-block', ['help-block'], [], '%s becomes .wsu-field__hint, or .wsu-field__error inside a has-error wrapper'],
        ['pagination', ['pagination'], [], '%s becomes .wsu-pagination; add the .wsu-pagination__list inner list'],
        ['tabs', ['nav-tabs'], [], '%s becomes .wsu-tabs__list; add the tab ARIA roles'],
        ['tab-panel', ['tab-content'], [], '%s becomes .wsu-tabs__panel; add the tab ARIA roles'],
        ['dialog', ['modal'], ['modal-'], '%s becomes a native <dialog class="wsu-dialog">; move the div to the element by hand'],
        ['non-production', ['non-production'], [], '%s becomes a .wsu-env-ribbon element; the body class moves to a ribbon'],
        ['container', ['container', 'container-fluid'], [], '%s becomes .wsu-container or a shell width; the choice depends on the layout'],
        ['grid', ['row'], ['col-'], '%s has no 2.x counterpart; use CSS grid or flexbox'],
        ['utility', ['pull-left', 'pull-right', 'center-block'], [], '%s has no 2.x counterpart; use a Tailwind utility or own CSS'],
        ['visibility', ['hidden-xs', 'visible-xs'], [], '%s has no 2.x counterpart; use a container query or media query'],
        ['colour-text', ['text-danger', 'bg-success'], [], '%s becomes a Tailwind utility over semantic tokens; the exact syntax depends on the Tailwind setup'],
        ['footer', ['footer'], [], '%s becomes .wsu-footer; the name is generic, so check the app CSS for its own .footer first'],
        ['selectpicker', ['selectpicker'], [], '%s plus bootstrap-select becomes a native <select>; drop the plugin classes and JS'],
    ];

    /** Bootstrap data hooks and the note each occurrence carries (%s is the hook). */
    private const DATA_HOOKS = [
        'data-toggle' => '%s does nothing in 2.x; it becomes data-wsu-drawer-toggle where it drives a drawer, else remove it',
        'data-target' => '%s does nothing in 2.x; point the 2.x equivalent at the element, or remove it',
        'data-dismiss' => '%s does nothing in 2.x; it becomes data-wsu-drawer-close where it closes a drawer, else remove it',
        'data-ride' => '%s starts a Bootstrap carousel; 2.x has no carousel, so remove it',
    ];

    /**
     * Rewrites the mechanical rows of the 1.x to 2.x mapping across the app.
     * A dry run reports what would change and writes nothing.
     */
    public function migrate(string $appPath, bool $dryRun): WayneUiMigrationResult
    {
        $appPath = str_replace('\\', '/', rtrim($appPath, '/\\'));
        $changes = [];
        $files = [];
        $entries = [];
        $rewrites = 0;

        foreach ($this->files($appPath) as $relative => $absolute) {
            $content = (string) file_get_contents($absolute);
            $stats = ['classes' => [], 'icons' => [], 'colours' => []];
            $new = $content;

            if (self::isBlade($relative)) {
                $new = $this->icons($new, $relative, $entries, $stats);
                $new = $this->classes($new, $relative, $entries, $stats);
                $this->dataHooks($new, $relative, $entries);
                $new = $this->bladeColours($new, $relative, $entries, $stats);
            } elseif (self::isCss($relative)) {
                $new = $this->hexes($new, $relative, 0, $entries, $stats);
            } elseif (self::isSass($relative)) {
                $this->sass($new, $relative, $entries);
            }

            if ($new === $content) {
                continue;
            }

            if (!$dryRun) {
                file_put_contents($absolute, $new);
            }

            $rewrites += count($stats['classes']) + count($stats['icons']) + count($stats['colours']);
            $parts = [];
            $diff = [];
            if ($stats['classes'] !== []) {
                $parts[] = self::plural(count($stats['classes']), 'class token');
                $diff[] = 'classes: ' . implode(', ', $stats['classes']);
            }
            if ($stats['icons'] !== []) {
                $parts[] = self::plural(count($stats['icons']), 'icon');
                $diff[] = 'icons: ' . implode(', ', $stats['icons']);
            }
            if ($stats['colours'] !== []) {
                $parts[] = self::plural(count($stats['colours']), 'colour');
                $diff[] = 'colours: ' . implode(', ', $stats['colours']);
            }

            $changes[] = ($dryRun ? 'Dry run: would rewrite ' : '') . $relative . ': ' . implode(', ', $parts) . '.';
            $files[] = ['file' => $relative, 'diff' => implode('; ', $diff)];
        }

        return new WayneUiMigrationResult($changes, $files, self::capManual($entries), $rewrites);
    }

    /**
     * Every file under the app root that step one reads, relative path to
     * absolute path, sorted by relative path. Skipped directories are
     * pruned whole, so vendor and node_modules cost nothing.
     *
     * @return array<string, string>
     */
    private function files(string $appPath): array
    {
        $out = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($appPath, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );
        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $absolute = str_replace('\\', '/', $file->getPathname());
            $relative = substr($absolute, strlen($appPath) + 1);
            foreach (self::SKIP_PREFIXES as $prefix) {
                if (str_starts_with($relative, $prefix)) {
                    continue 2;
                }
            }
            if (self::isBlade($relative) || self::isCss($relative) || self::isSass($relative)) {
                $out[$relative] = $absolute;
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * Replaces empty <i> Font Awesome tags with x-wsu::icon and notes the
     * shapes a person must finish (unknown names, extra tokens, inner text,
     * Glyphicons). Runs before the class pass so replaced tags do not echo
     * into it.
     *
     * @param list<array{0: string, 1: string}> $entries
     * @param array{classes: list<string>, icons: list<string>, colours: list<string>} $stats
     */
    private function icons(string $content, string $rel, array &$entries, array &$stats): string
    {
        $count = preg_match_all('/<i\b[^>]*>[^<]*<\/i>/i', $content, $ms, PREG_OFFSET_CAPTURE);
        if ($count === false || $count === 0) {
            return $content;
        }
        for ($i = $count - 1; $i >= 0; $i--) {
            $full = (string) $ms[0][$i][0];
            $offset = (int) $ms[0][$i][1];
            $line = self::lineAt($content, $offset);
            [$tokens, $inner] = self::iconParts($full);
            $lower = array_map('strtolower', $tokens);
            $replacement = null;
            $note = null;

            $glyph = null;
            foreach ($lower as $t) {
                if (str_starts_with($t, 'glyphicon-')) {
                    $glyph = $t;
                    break;
                }
            }
            if ($glyph !== null) {
                $note = ['glyphicons', $rel . ':' . $line . ': ' . $glyph . ' has no mapping table; map it to a Lucide icon by hand'];
            } elseif (!in_array('fa', $lower, true)) {
                // Not a Font Awesome element; leave it alone.
            } else {
                $sorted = $lower;
                sort($sorted);
                if ($sorted === ['fa', 'fa-spin', 'fa-spinner'] && $inner === '') {
                    $replacement = '<x-wsu::icon name="loader-circle" />';
                    $stats['icons'][] = 'fa-spinner fa-spin to loader-circle';
                } else {
                    $fas = array_values(array_filter($lower, static fn(string $t): bool => str_starts_with($t, 'fa-')));
                    if (count($fas) === 1 && count($lower) === 2) {
                        $name = $fas[0];
                        if ($name === 'fa-spinner') {
                            $note = ['icons-pair', $rel . ':' . $line . ': fa fa-spinner maps to loader-circle only as the fa-spinner fa-spin pair; check the element by hand'];
                        } elseif (($target = self::ICONS[$name] ?? null) !== null && $inner === '') {
                            $replacement = '<x-wsu::icon name="' . $target . '" />';
                            $stats['icons'][] = $name . ' to ' . $target;
                        } elseif ($inner !== '') {
                            $note = ['icons-text', $rel . ':' . $line . ': ' . implode(' ', $lower) . ' carries inner text; move it to x-wsu::icon by hand'];
                        } elseif ($name === 'fa-heart') {
                            $note = ['icons-heart', $rel . ':' . $line . ': fa-heart is not in the 2.x icon subset; adding it means editing the icon table, so pick another icon'];
                        } else {
                            $note = ['icons-unknown', $rel . ':' . $line . ': ' . $name . ' has no row in the WayneUI 2.x icon table; choose the Lucide icon by hand'];
                        }
                    } elseif ($fas === []) {
                        $note = ['icons-extra', $rel . ':' . $line . ': ' . implode(' ', $lower) . ' names no icon; check the element by hand'];
                    } else {
                        $extras = array_values(array_diff($lower, ['fa']));
                        $sizes = array_values(array_filter($extras, static fn(string $t): bool => in_array($t, self::ICON_SIZE_MODIFIERS, true)));
                        if ($extras === $sizes) {
                            $note = ['icons-size', $rel . ':' . $line . ': ' . implode(' ', $lower) . ' carries size modifiers with no 2.x row; move it to x-wsu::icon and set the size by hand'];
                        } else {
                            $note = ['icons-extra', $rel . ':' . $line . ': ' . implode(' ', $lower) . ' cannot be mapped automatically; move it to x-wsu::icon by hand'];
                        }
                    }
                }
            }

            if ($replacement !== null) {
                $content = substr($content, 0, $offset) . $replacement . substr($content, $offset + strlen($full));
            } elseif ($note !== null) {
                $entries[] = $note;
            }
        }

        return $content;
    }

    /**
     * Class tokens of one <i> tag and its inner text, lowercased later by
     * the caller.
     *
     * @return array{0: list<string>, 1: string}
     */
    private static function iconParts(string $tag): array
    {
        $gt = strpos($tag, '>');
        $inner = trim(substr($tag, $gt === false ? strlen($tag) : $gt + 1, -4));
        if (preg_match('/class\s*=\s*(["\'])((?:(?!\1)[^\n])*)\1/i', $tag, $c) !== 1) {
            return [[], $inner];
        }
        $tokens = array_values(array_filter(
            array_map('trim', explode(' ', (string) $c[2])),
            static fn(string $t): bool => $t !== '',
        ));

        return [$tokens, $inner];
    }

    /**
     * Rewrites the whole tokens of every class attribute and notes the
     * tokens that need a person. Blade expressions survive byte for byte.
     *
     * @param list<array{0: string, 1: string}> $entries
     * @param array{classes: list<string>, icons: list<string>, colours: list<string>} $stats
     */
    private function classes(string $content, string $rel, array &$entries, array &$stats): string
    {
        $count = preg_match_all('/class\s*=\s*(["\'])((?:(?!\1)[^\n])*)\1/', $content, $ms, PREG_OFFSET_CAPTURE);
        if ($count === false || $count === 0) {
            return $content;
        }
        for ($i = $count - 1; $i >= 0; $i--) {
            $full = (string) $ms[0][$i][0];
            $offset = (int) $ms[0][$i][1];
            $quoteStart = (int) $ms[1][$i][1];
            $value = (string) $ms[2][$i][0];
            $line = self::lineAt($content, $offset);
            [$newValue, $replaced, $hits] = self::rewriteClassValue($value);

            foreach ($hits as [$rule, $token, $advice]) {
                $entries[] = [$rule, $rel . ':' . $line . ': ' . sprintf($advice, $token)];
            }
            if ($replaced !== []) {
                $stats['classes'] = array_merge($stats['classes'], $replaced);
            }
            if ($newValue !== $value) {
                $head = substr($full, 0, $quoteStart - $offset + 1);
                $quote = (string) $ms[1][$i][0];
                $content = substr($content, 0, $offset) . $head . $newValue . $quote . substr($content, $offset + strlen($full));
            }
        }

        return $content;
    }

    /**
     * Rewrites one class attribute value. Returns the new value (the
     * original when nothing was rewritten), the source tokens replaced and
     * the manual hits as [rule, token, advice]. Mapped targets are
     * deduplicated within the attribute; Blade expressions are masked first
     * and restored untouched.
     *
     * @return array{0: string, 1: list<string>, 2: list<array{0: string, 1: string, 2: string}>}
     */
    private static function rewriteClassValue(string $value): array
    {
        $placeholders = [];
        $masked = (string) preg_replace_callback(
            '/(\{\{.*?\}\})/',
            static function (array $m) use (&$placeholders): string {
                $placeholders[] = $m[0];

                return "\x00" . (\count($placeholders) - 1) . "\x00";
            },
            $value,
        );

        $out = [];
        $seen = [];
        $replaced = [];
        $hits = [];
        foreach (explode(' ', $masked) as $token) {
            if ($token === '' || preg_match('/^\x00\d+\x00$/', $token) === 1) {
                $out[] = $token;
                continue;
            }
            $target = self::CLASS_REWRITES[$token] ?? null;
            if ($target !== null) {
                $replaced[] = $token;
                if (!in_array($target, $seen, true)) {
                    $out[] = $target;
                    $seen[] = $target;
                }
                continue;
            }
            $rule = self::manualClassRule($token);
            if ($rule !== null) {
                $hits[] = [$rule[0], $token, $rule[1]];
            }
            $out[] = $token;
            $seen[] = $token;
        }
        if ($replaced === []) {
            return [$value, [], $hits];
        }
        $new = implode(' ', $out);
        foreach ($placeholders as $i => $ph) {
            $new = str_replace("\x00" . $i . "\x00", $ph, $new);
        }

        return [$new, $replaced, $hits];
    }

    /**
     * The manual rule a class token falls under, or null.
     *
     * @return array{0: string, 1: string}|null rule name and advice template
     */
    private static function manualClassRule(string $token): ?array
    {
        foreach (self::MANUAL_CLASS_RULES as [$name, $exact, $prefixes, $advice]) {
            if (in_array($token, $exact, true)) {
                return [$name, $advice];
            }
            foreach ($prefixes as $prefix) {
                if (str_starts_with($token, $prefix)) {
                    return [$name, $advice];
                }
            }
        }

        return null;
    }

    /**
     * Notes the Bootstrap data hooks and the jQuery and Bootstrap script
     * tags, which do nothing once the 1.x assets go away.
     *
     * @param list<array{0: string, 1: string}> $entries
     */
    private function dataHooks(string $content, string $rel, array &$entries): void
    {
        foreach (self::DATA_HOOKS as $attr => $advice) {
            $count = preg_match_all('/' . $attr . '\s*=/i', $content, $ms, PREG_OFFSET_CAPTURE);
            if ($count === false) {
                continue;
            }
            foreach ($ms[0] as [$match, $offset]) {
                $entries[] = [$attr, $rel . ':' . self::lineAt($content, (int) $offset) . ': ' . sprintf($advice, $attr)];
            }
        }

        $count = preg_match_all('/<script\b[^>]*>/i', $content, $ms, PREG_OFFSET_CAPTURE);
        if ($count === false) {
            return;
        }
        foreach ($ms[0] as [$tag, $offset]) {
            if (preg_match('/src\s*=\s*(["\'])((?:(?!\1)[^\n])*)\1/i', (string) $tag, $s) !== 1) {
                continue;
            }
            $src = strtolower((string) $s[2]);
            if (str_contains($src, 'jquery')) {
                $entries[] = ['scripts', $rel . ':' . self::lineAt($content, (int) $offset) . ': the jQuery script tag does nothing in 2.x; there is no jQuery, so drop it'];
            } elseif (str_contains($src, 'bootstrap')) {
                $entries[] = ['scripts', $rel . ':' . self::lineAt($content, (int) $offset) . ': the Bootstrap script tag does nothing in 2.x; drop it'];
            }
        }
    }

    /**
     * Rewrites the mapped brand hexes inside <style> blocks and style
     * attributes of a Blade file. Spans are spliced in reverse so earlier
     * offsets stay valid.
     *
     * @param list<array{0: string, 1: string}> $entries
     * @param array{classes: list<string>, icons: list<string>, colours: list<string>} $stats
     */
    private function bladeColours(string $content, string $rel, array &$entries, array &$stats): string
    {
        $spans = [];
        $count = preg_match_all('/<style\b[^>]*>([\s\S]*?)<\/style>/i', $content, $ms, PREG_OFFSET_CAPTURE);
        if ($count !== false) {
            foreach ($ms[1] as [$text, $offset]) {
                $spans[] = [(int) $offset, (string) $text];
            }
        }
        $count = preg_match_all('/style\s*=\s*(["\'])((?:(?!\1)[^\n])*)\1/i', $content, $ms, PREG_OFFSET_CAPTURE);
        if ($count !== false) {
            foreach ($ms[2] as [$text, $offset]) {
                $spans[] = [(int) $offset, (string) $text];
            }
        }
        usort($spans, static fn(array $a, array $b): int => $b[0] <=> $a[0]);
        foreach ($spans as [$offset, $text]) {
            $lineBase = substr_count(substr($content, 0, $offset), "\n");
            $new = $this->hexes($text, $rel, $lineBase, $entries, $stats);
            if ($new !== $text) {
                $content = substr($content, 0, $offset) . $new . substr($content, $offset + strlen($text));
            }
        }

        return $content;
    }

    /**
     * Rewrites the mapped brand hexes in a span of CSS and notes the hexes
     * the mapping leaves to a person. $lineBase is the number of newlines
     * before the span, so manual lines keep their real line numbers.
     *
     * @param list<array{0: string, 1: string}> $entries
     * @param array{classes: list<string>, icons: list<string>, colours: list<string>} $stats
     */
    private function hexes(string $text, string $rel, int $lineBase, array &$entries, array &$stats): string
    {
        $count = preg_match_all('/#([0-9a-fA-F]{3,8})(?![0-9a-fA-F])/', $text, $ms, PREG_OFFSET_CAPTURE);
        if ($count === false || $count === 0) {
            return $text;
        }
        $out = '';
        $last = 0;
        foreach ($ms[0] as $index => [$full, $offset]) {
            $key = strtolower((string) $ms[1][$index][0]);
            $var = self::COLOURS[$key] ?? null;
            $note = self::COLOUR_MANUAL[$key] ?? null;
            if ($var === null && $note === null) {
                continue;
            }
            $line = $lineBase + substr_count(substr($text, 0, (int) $offset), "\n") + 1;
            if ($var !== null) {
                $out .= substr($text, $last, (int) $offset - $last) . $var;
                $last = (int) $offset + strlen($full);
                $stats['colours'][] = '#' . $key . ' to ' . $var;
            } else {
                $entries[] = ['colour', $rel . ':' . $line . ': ' . $note];
            }
        }
        if ($last === 0) {
            return $text;
        }

        return $out . substr($text, $last);
    }

    /**
     * Sass gets no rewrites (a function around a colour would break); a
     * mapped or NEEDS-HUMAN hex only earns a manual line.
     *
     * @param list<array{0: string, 1: string}> $entries
     */
    private function sass(string $content, string $rel, array &$entries): void
    {
        $count = preg_match_all('/#([0-9a-fA-F]{3,8})(?![0-9a-fA-F])/', $content, $ms, PREG_OFFSET_CAPTURE);
        if ($count === false) {
            return;
        }
        foreach ($ms[0] as $index => [$full, $offset]) {
            $key = strtolower((string) $ms[1][$index][0]);
            $var = self::COLOURS[$key] ?? null;
            $note = self::COLOUR_MANUAL[$key] ?? null;
            if ($var === null && $note === null) {
                continue;
            }
            $what = $var !== null ? '#' . $key . ' becomes ' . $var : $note;
            $entries[] = ['colour', $rel . ':' . self::lineAt($content, (int) $offset) . ': ' . $what . '; Booster does not rewrite Sass, so change it by hand'];
        }
    }

    /**
     * Caps the manual lines at 25 per rule; the rest collapse into one
     * "<rule>: and N more" line, appended after all kept lines in rule
     * order.
     *
     * @param list<array{0: string, 1: string}> $entries rule and full line
     * @return list<string>
     */
    private static function capManual(array $entries): array
    {
        $out = [];
        $counts = [];
        $suppressed = [];
        foreach ($entries as [$rule, $line]) {
            $counts[$rule] = ($counts[$rule] ?? 0) + 1;
            if ($counts[$rule] <= self::MANUAL_CAP) {
                $out[] = $line;
            } else {
                $suppressed[$rule] = ($suppressed[$rule] ?? 0) + 1;
            }
        }
        ksort($suppressed);
        foreach ($suppressed as $rule => $n) {
            $out[] = $rule . ': and ' . $n . ' more';
        }

        return $out;
    }

    /** 1-based line number of an offset into a file. */
    private static function lineAt(string $content, int $offset): int
    {
        return substr_count(substr($content, 0, $offset), "\n") + 1;
    }

    /** "1 icon", "3 icons". */
    private static function plural(int $n, string $word): string
    {
        return $n . ' ' . $word . ($n === 1 ? '' : 's');
    }

    private static function isBlade(string $relative): bool
    {
        return str_starts_with($relative, 'resources/views/') && str_ends_with($relative, '.blade.php');
    }

    private static function isCss(string $relative): bool
    {
        return str_starts_with($relative, 'resources/') && str_ends_with($relative, '.css');
    }

    private static function isSass(string $relative): bool
    {
        if (!str_starts_with($relative, 'resources/')) {
            return false;
        }

        return str_ends_with($relative, '.scss') || str_ends_with($relative, '.sass') || str_ends_with($relative, '.less');
    }
}
