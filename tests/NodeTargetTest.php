<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use DateTimeImmutable;
use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Node\NodeTarget;
use Waynestate\Booster\Policy\Policy;
use Waynestate\Booster\Policy\PolicyLocator;
use Waynestate\Booster\ToolError;

/**
 * The Node pin floors an app at the Node its deploy image ships, unless
 * --node says otherwise. Found on a real Laravel app: the hop pinned >=26
 * while Citadel's php-apache:8.5r3 ships Node 22, so every npm ci on the
 * server warned EBADENGINE, and there was no way to ask for 22.
 */
final class NodeTargetTest extends TestCase
{
    private const KIT = "ARG BASE_IMAGE=docker.io/library/php:8.5-apache\nARG NODE_IMAGE=docker.io/library/node:22.23.4-trixie-slim@sha256:0000\nFROM \${NODE_IMAGE} AS node\n";

    private function fixturePolicy(): Policy
    {
        return PolicyLocator::locate(self::POLICY_FIXTURE, null);
    }

    private function day(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::TODAY);
    }

    private function appWithKit(string $kit = self::KIT): string
    {
        $dir = $this->tempDir();
        mkdir($dir . '/.squire', 0775, true);
        file_put_contents($dir . '/' . NodeTarget::KIT_FILE, $kit);

        return $dir;
    }

    public function test_the_kit_node_image_sets_the_floor_and_says_so(): void
    {
        $target = NodeTarget::resolve(null, $this->appWithKit(), $this->fixturePolicy(), $this->day(), '26');

        self::assertSame('22', $target->major);
        self::assertStringStartsWith("Node 22, the Node the app's Citadel image ships (NODE_IMAGE in .squire/base-fallback.Dockerfile).", $target->reason);
    }

    public function test_the_option_wins_over_the_kit(): void
    {
        $target = NodeTarget::resolve('24', $this->appWithKit(), $this->fixturePolicy(), $this->day(), '26');

        self::assertSame('24', $target->major);
        self::assertSame('Node 24, as --node asked.', $target->reason);
    }

    public function test_no_kit_means_the_newest_supported_node_and_a_hint(): void
    {
        $target = NodeTarget::resolve(null, $this->tempDir(), $this->fixturePolicy(), $this->day(), '26');

        self::assertSame('26', $target->major);
        self::assertStringContainsString('Booster cannot see which Node the app\'s deploy image ships', $target->reason);
        self::assertStringContainsString('pass --node=<major>', $target->reason);
    }

    public function test_a_kit_node_the_policy_dropped_falls_back_to_the_data_target(): void
    {
        $dir = $this->appWithKit("ARG NODE_IMAGE=node:16-alpine\n");
        $target = NodeTarget::resolve(null, $dir, $this->fixturePolicy(), $this->day(), '26');

        self::assertSame('26', $target->major);
        self::assertStringContainsString('ships Node 16', $target->reason);
    }

    public function test_bad_or_unsupported_values_are_tool_errors(): void
    {
        foreach (['lts', '22.1', '18'] as $bad) {
            try {
                NodeTarget::resolve($bad, $this->tempDir(), $this->fixturePolicy(), $this->day(), '26');
                self::fail('no error for ' . $bad);
            } catch (ToolError $e) {
                self::assertStringContainsString('--node', $e->getMessage(), $bad);
            }
        }
    }

    public function test_the_kit_line_parses_with_or_without_a_registry_or_digest(): void
    {
        self::assertSame('22', NodeTarget::kitNodeMajor($this->appWithKit()));
        self::assertSame('24', NodeTarget::kitNodeMajor($this->appWithKit("arg NODE_IMAGE = node:24\n")));
        self::assertNull(NodeTarget::kitNodeMajor($this->appWithKit("ARG NODE_IMAGE=node:lts\n")));
        self::assertNull(NodeTarget::kitNodeMajor($this->tempDir()));
    }

    public function test_the_registry_override_moves_the_pin_and_carries_the_reason(): void
    {
        $registry = new HopRegistry(null, '22', 'Node 22, as --node asked.');
        $hop = $registry->get(HopRegistry::NODE_PIN_ID);

        self::assertSame('22', $registry->nodePinTarget());
        self::assertSame('22', $hop->to);
        self::assertSame(['engines.node' => '>=22'], $hop->npmSet);
        self::assertSame('Pin the app to Node 22: set engines.node in package.json and write .nvmrc. Node 22, as --node asked.', $hop->summary);
        self::assertSame('Pin the app to Node 26: set engines.node in package.json and write .nvmrc.', (new HopRegistry())->get(HopRegistry::NODE_PIN_ID)->summary);
        self::assertNull((new HopRegistry())->nodePinReason());
    }
}
