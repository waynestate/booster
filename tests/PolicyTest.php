<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use DateTimeImmutable;
use Waynestate\Booster\Creed\State;
use Waynestate\Booster\Policy\PolicyException;
use Waynestate\Booster\Policy\PolicyLocator;

final class PolicyTest extends TestCase
{
    public function test_states_follow_the_dates(): void
    {
        $p = $this->policy();

        self::assertSame(State::Active, $p->stateOf('php', '8.4', new DateTimeImmutable('2026-12-31')));
        self::assertSame(State::Security, $p->stateOf('php', '8.4', new DateTimeImmutable('2027-01-01')));
        self::assertSame(State::Eol, $p->stateOf('php', '8.1', new DateTimeImmutable('2026-01-15')));
        self::assertSame(State::EolGraceExpired, $p->stateOf('php', '8.1', new DateTimeImmutable('2026-10-28')));
    }

    public function test_supported_lists_only_active_and_security_versions_in_order(): void
    {
        $p = $this->policy();

        self::assertSame(['8.2', '8.3', '8.4', '8.5'], $p->supported('php', $this->today()));
        self::assertSame(['12', '13'], $p->supported('laravel', $this->today()));
    }

    public function test_a_version_above_every_listed_line_is_active(): void
    {
        $p = $this->policy();

        self::assertSame(State::Active, $p->stateOf('php', '9.9', $this->today()));
        self::assertTrue($p->isAheadOfPolicy('php', '9.9'));
    }

    public function test_a_version_below_the_listed_lines_is_an_error(): void
    {
        $this->expectException(PolicyException::class);
        $this->policy()->stateOf('php', '8.0', $this->today());
    }

    public function test_a_version_between_listed_lines_is_an_error(): void
    {
        $file = $this->tempDir() . '/gap.json';
        file_put_contents($file, (string) json_encode([
            'schema_version' => 1,
            'components' => [
                'php' => [
                    'versions' => [
                        '8.1' => ['active_until' => '2023-11-23', 'security_until' => '2024-12-31'],
                        '8.9' => ['active_until' => '2029-11-21', 'security_until' => '2030-12-31'],
                    ],
                ],
            ],
            'rules' => ['eol_grace_days' => 90],
        ]));
        $p = PolicyLocator::locate($file, null);

        $this->expectException(PolicyException::class);
        $p->stateOf('php', '8.5', $this->today());
    }

    public function test_unknown_component_is_an_error(): void
    {
        $this->expectException(PolicyException::class);
        $this->policy()->supported('cobol', $this->today());
    }

    public function test_locator_prefers_option_then_env_then_bundled(): void
    {
        $bundled = PolicyLocator::locate(null, null);
        self::assertSame('bundled snapshot', $bundled->origin());

        $file = $this->tempDir() . '/p.json';
        copy(PolicyLocator::bundledPath(), $file);
        self::assertSame($file, PolicyLocator::locate($file, '/nonexistent.json')->origin());
        self::assertSame($file, PolicyLocator::locate(null, $file)->origin());
    }

    public function test_missing_file_fails_closed(): void
    {
        $this->expectException(PolicyException::class);
        PolicyLocator::locate('/nonexistent/policy.json', null);
    }

    public function test_malformed_policies_are_rejected(): void
    {
        $dir = $this->tempDir();
        $cases = [
            'not json' => '{nope',
            'no php' => '{"components":{"laravel":{"versions":{}}}}',
            'bad date' => '{"components":{"php":{"versions":{"8.4":{"security_until":"soon"}}}}}',
            'impossible date' => '{"components":{"php":{"versions":{"8.4":{"security_until":"2026-02-31"}}}}}',
            'bad active' => '{"components":{"php":{"versions":{"8.4":{"active_until":"x","security_until":"2028-01-01"}}}}}',
        ];

        foreach ($cases as $name => $json) {
            $file = $dir . '/' . md5($name) . '.json';
            file_put_contents($file, $json);
            try {
                PolicyLocator::locate($file, null);
                self::fail("Policy case '{$name}' should have been rejected.");
            } catch (PolicyException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_fixture_policy_is_read_through_the_creed_copy(): void
    {
        $file = __DIR__ . '/fixtures/policy.json';
        $p = PolicyLocator::locate($file, null);

        self::assertSame($file, $p->origin());
        self::assertSame(State::EolGraceExpired, $p->stateOf('php', '8.1', $this->today()));
        self::assertSame(State::Security, $p->stateOf('php', '8.2', $this->today()));
        self::assertSame(State::Active, $p->stateOf('php', '8.3', $this->today()));
        self::assertSame(['8.2', '8.3', '8.4'], $p->supported('php', $this->today()));
    }
}
