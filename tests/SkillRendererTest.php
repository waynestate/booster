<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Skill\SkillRenderer;

final class SkillRendererTest extends TestCase
{
    private const BOOST_OPEN = 'Only if the app has Laravel Boost (`booster detect` says so):';

    /** @return array<string, string> */
    private function files(): array
    {
        return (new SkillRenderer())->render(new HopRegistry());
    }

    public function test_every_hop_playbook_carries_the_boost_section(): void
    {
        $files = $this->files();
        self::assertArrayHasKey('booster/SKILL.md', $files);

        foreach (array_filter(array_keys($files), static fn(string $path): bool => str_starts_with($path, 'booster-')) as $path) {
            self::assertStringContainsString(self::BOOST_OPEN, $files[$path], $path);
            self::assertStringContainsString("Boost is read-only help. Booster's gate stays the authority: never skip a gate because Boost says so.", $files[$path], $path);
        }
    }

    public function test_each_boost_section_lists_three_to_five_prompts(): void
    {
        foreach ($this->files() as $path => $text) {
            if (!str_starts_with($path, 'booster-')) {
                continue;
            }
            $start = strpos($text, self::BOOST_OPEN);
            self::assertIsInt($start, $path);
            $end = strpos($text, 'Boost is read-only help.', $start);
            self::assertIsInt($end, $path);

            $prompts = preg_grep('/^- /m', explode("\n", substr($text, $start, $end - $start))) ?: [];
            self::assertGreaterThanOrEqual(3, count($prompts), $path);
            self::assertLessThanOrEqual(5, count($prompts), $path);
        }
    }

    public function test_the_laravel_section_asks_for_the_upgrade_guide_and_the_app_state(): void
    {
        $text = $this->files()['booster-laravel-10-to-11/SKILL.md'];

        self::assertStringContainsString("Use `search-docs` for the Laravel 11 upgrade guide and list every breaking change that touches this app's `list-routes` and `get-config` output.", $text);
        self::assertStringContainsString('Use `database-schema` to check the tables against the Laravel 11 database floor before you apply the hop.', $text);
        self::assertStringContainsString('Use `list-artisan-commands` and `list-routes` before the hop, then compare the output after it.', $text);
        self::assertStringContainsString('Run `last-error` and `read-log-entries` after the tests.', $text);
    }

    public function test_the_php_section_checks_removed_functions_against_the_changed_files(): void
    {
        $text = $this->files()['booster-php-83-to-84/SKILL.md'];

        self::assertStringContainsString('Use `search-docs` for removed or deprecated functions in PHP 8.4 and check each one against the files Booster changed.', $text);
        self::assertStringContainsString('Use `tinker` to load a changed class and confirm it still loads.', $text);
        self::assertStringContainsString('Use `application-info` to confirm the reported versions after the hop.', $text);
        self::assertStringContainsString('Run `last-error` and `read-log-entries` after the tests.', $text);
    }

    public function test_the_other_kinds_name_their_tools(): void
    {
        $files = $this->files();

        self::assertStringContainsString('Use `search-docs` for the PHPUnit 11 migration notes and list the changes that touch this app\'s tests.', $files['booster-phpunit-10-to-11/SKILL.md']);
        self::assertStringContainsString('Use `get-config` to read the app\'s test-related config before you change phpunit.xml.', $files['booster-phpunit-10-to-11/SKILL.md']);
        self::assertStringContainsString('Use `get-config` to copy the current wayne-cas settings before the swap.', $files['booster-cas-to-sesame/SKILL.md']);
        self::assertStringContainsString('Use `list-routes` to note the sign-in and sign-out routes you will re-test.', $files['booster-cas-to-sesame/SKILL.md']);
        self::assertStringContainsString('Use `search-docs` for the Vue 3 migration guide and list the breaking changes that touch this app\'s components.', $files['booster-vue-2-to-3/SKILL.md']);
        self::assertStringContainsString('Use `browser-logs` after a page load to catch the Vue warnings the console prints.', $files['booster-vue-2-to-3/SKILL.md']);
        self::assertStringContainsString('Use `search-docs` for the Vite front-end docs and check the app\'s build config against them.', $files['booster-mix-to-vite/SKILL.md']);
        self::assertStringContainsString('Run `last-error` and `read-log-entries` after the build and the tests.', $files['booster-node-pin-24/SKILL.md']);
    }

    public function test_the_index_stays_clean_of_the_boost_section(): void
    {
        self::assertStringNotContainsString(self::BOOST_OPEN, $this->files()['booster/SKILL.md']);
    }
}
