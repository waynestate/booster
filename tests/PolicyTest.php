<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use DateTimeImmutable;
use Waynestate\Booster\Policy\JsonFilePolicySource;
use Waynestate\Booster\Policy\PolicyException;
use Waynestate\Booster\Policy\PolicyLocator;
use Waynestate\Booster\Policy\State;

final class PolicyTest extends TestCase
{
    public function test_states_follow_the_dates(): void
    {
        $p = $this->policy();

        self::assertSame(State::ACTIVE, $p->stateOf('php', '8.4', new DateTimeImmutable('2026-12-31')));
        self::assertSame(State::SECURITY, $p->stateOf('php', '8.4', new DateTimeImmutable('2027-01-01')));
        self::assertSame(State::EOL, $p->stateOf('php', '8.1', new DateTimeImmutable('2026-01-15')));
        self::assertSame(State::EOL_GRACE_EXPIRED, $p->stateOf('php', '8.1', new DateTimeImmutable('2026-10-28')));
    }

    public function test_supported_lists_only_active_and_security_versions_in_order(): void
    {
        $p = $this->policy();

        self::assertSame(['8.2', '8.3', '8.4', '8.5'], $p->supported('php', $this->today()));
        self::assertSame(['12', '13'], $p->supported('laravel', $this->today()));
    }

    public function test_unknown_version_and_component_are_errors(): void
    {
        $this->expectException(PolicyException::class);
        $this->policy()->stateOf('php', '9.9', $this->today());
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
        new JsonFilePolicySource('/nonexistent/policy.json');
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
                new JsonFilePolicySource($file);
                self::fail("Policy case '{$name}' should have been rejected.");
            } catch (PolicyException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
