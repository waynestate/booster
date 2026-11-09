<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Skill\SkillRenderer;

final class SkillRendererTest extends TestCase
{
    private const BOOST_OPEN = 'Only if the app has Laravel Boost (`booster detect` says so):';

    private const ADVISOR_OPEN = 'Optional: ADVISOR gives the agent read-only answers about the WSU stack';

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
        self::assertStringContainsString("Use `database-schema` to look at the tables, and check the database server's version against the minimum Laravel 11 needs, before you apply the hop.", $text);
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

    public function test_every_hop_playbook_says_what_to_do_when_a_gate_fails(): void
    {
        $registry = new HopRegistry();
        $files = (new SkillRenderer())->render($registry);
        $stop = 'Stop after 3 tries at the same failure, or sooner when a try changes nothing. Show the person the last output and what you tried.';
        $never = 'Never delete, skip or loosen a test to turn a gate green, and never add `--skip-gate`, `--allow-failing-baseline` or `--skip` unless the person asks for it.';

        self::assertSame(3, SkillRenderer::MAX_TRIES);
        $manualSeen = false;
        $automaticSeen = false;
        foreach ($registry->all() as $hop) {
            $path = 'booster-' . $hop->id . '/SKILL.md';
            $text = $files[$path];

            self::assertStringContainsString($stop, $text, $path);
            self::assertStringContainsString($never, $text, $path);
            self::assertStringContainsString('the files it names, their tests and the notes below, not from a summary of them.', $text, $path);

            // The section sits after the how-to steps and before the optional Boost help.
            $failure = strpos($text, 'When a gate fails');
            self::assertIsInt($failure, $path);
            self::assertLessThan(strpos($text, self::BOOST_OPEN), $failure, $path);
            self::assertSame(1, substr_count($text, 'When a gate fails'), $path);

            if ($hop->isManual()) {
                $manualSeen = true;
                self::assertStringContainsString('When a gate fails (for this hop, the tests you run after your change):', $text, $path);
                self::assertStringContainsString("2. Fix one cause, then run the tests again.\n3. " . $stop, $text, $path);
                self::assertStringNotContainsString('Finished hops are not repeated', $text, $path);
            } else {
                $automaticSeen = true;
                self::assertStringContainsString("When a gate fails, the run stops and leaves this hop's changes in place:", $text, $path);
                self::assertStringContainsString('3. Commit the fix on its own, then run `booster run --path=<app>` again. Booster plans from the versions the app is on now, so finished hops are not repeated.', $text, $path);
                self::assertStringContainsString("\n4. " . $stop, $text, $path);
            }
        }

        // Both shapes are in the registry, so both branches above really ran.
        self::assertTrue($manualSeen, 'the registry has no manual hop');
        self::assertTrue($automaticSeen, 'the registry has no automatic hop');
    }

    public function test_the_index_points_at_the_failure_steps_and_the_cap(): void
    {
        $text = $this->files()['booster/SKILL.md'];

        self::assertStringContainsString("4. When a gate fails, follow the playbook's \"When a gate fails\" steps: work from the real output, and after 3 tries at one failure, stop and hand it to the person.\n5. Stop when the plan is done.", $text);
    }

    public function test_the_index_stays_clean_of_the_boost_section(): void
    {
        self::assertStringNotContainsString(self::BOOST_OPEN, $this->files()['booster/SKILL.md']);
    }

    public function test_the_index_carries_the_optional_advisor_pointer(): void
    {
        $text = $this->files()['booster/SKILL.md'];

        self::assertStringContainsString(self::ADVISOR_OPEN, $text);
        self::assertStringContainsString('- `composer require --dev waynestate/advisor`', $text);
        self::assertStringContainsString('- `php vendor/bin/advisor install --client=claude --write`', $text);
        self::assertStringContainsString('Booster works without ADVISOR.', $text);
    }

    public function test_the_hop_playbooks_stay_clean_of_the_advisor_pointer(): void
    {
        foreach (array_filter(array_keys($this->files()), static fn(string $path): bool => str_starts_with($path, 'booster-')) as $path) {
            self::assertStringNotContainsString('waynestate/advisor', $this->files()[$path], $path);
        }
    }
}
