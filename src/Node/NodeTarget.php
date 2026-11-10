<?php

declare(strict_types=1);

namespace Waynestate\Booster\Node;

use DateTimeImmutable;
use Waynestate\Booster\Policy\Policy;
use Waynestate\Booster\Policy\PolicyException;
use Waynestate\Booster\ToolError;

/**
 * The Node major the node-pin-24 hop floors the app at, and why.
 *
 * A floor above the Node the app's deploy image ships makes every npm ci on
 * that server warn (EBADENGINE), and a hop gate that never builds on the new
 * Node cannot prove the pin either. So, in order:
 *
 * 1. --node=<major>, when the policy still supports that major;
 * 2. the Node the app's Citadel image ships, read from the Squire kit's
 *    stand-in for it (NODE_IMAGE in .squire/base-fallback.Dockerfile);
 * 3. the hop data's target: the newest Node the policy supports.
 *
 * The reason is printed with the plan, so the number never arrives without
 * its source. Choosing a Node by rumor is how you end up with a node_modules
 * the size of a small moon.
 */
final readonly class NodeTarget
{
    /** The Squire kit file whose NODE_IMAGE mirrors the Node in Citadel's image. */
    public const KIT_FILE = '.squire/base-fallback.Dockerfile';

    public function __construct(
        public string $major,
        public string $reason,
    ) {}

    /**
     * @throws ToolError when --node is not a whole number or names a major the policy does not support today
     */
    public static function resolve(?string $option, string $appPath, Policy $policy, DateTimeImmutable $today, string $dataTarget): self
    {
        try {
            $supported = $policy->supported('node', $today);
        } catch (PolicyException) {
            $supported = [];
        }
        if ($option !== null) {
            if (preg_match('/^\d+$/', $option) !== 1) {
                throw new ToolError(sprintf('Invalid --node value "%s". Expected a Node major, such as %s.', $option, self::join($supported !== [] ? $supported : ['22', '24', '26'], 'or')));
            }
            if ($supported !== [] && !in_array($option, $supported, true)) {
                throw new ToolError(sprintf('--node=%s: the policy supports Node %s today. Pick one of those.', $option, self::join($supported, 'and')));
            }

            return new self($option, sprintf('Node %s, as --node asked.', $option));
        }

        $image = self::kitNodeMajor($appPath);
        if ($image !== null && ($supported === [] || in_array($image, $supported, true))) {
            return new self($image, sprintf(
                'Node %s, the Node the app\'s Citadel image ships (NODE_IMAGE in %s). Pass --node=<major> for a newer floor.',
                $image,
                self::KIT_FILE,
            ));
        }
        if ($image !== null) {
            return new self($dataTarget, sprintf(
                'Node %s, the newest the policy supports: the app\'s image ships Node %s (NODE_IMAGE in %s), which the policy no longer supports. Move the image to a supported Node, or pass --node=<major>.',
                $dataTarget,
                $image,
                self::KIT_FILE,
            ));
        }

        return new self($dataTarget, sprintf(
            'Node %s, the newest the policy supports. Booster cannot see which Node the app\'s deploy image ships (no %s); if it ships an older one, pass --node=<major> to match it.',
            $dataTarget,
            self::KIT_FILE,
        ));
    }

    /**
     * The Node major in the kit's NODE_IMAGE line, such as 22 for
     * ARG NODE_IMAGE=docker.io/library/node:22.23.4-trixie-slim@sha256:..., or
     * null when the app has no kit or the line names no version.
     */
    public static function kitNodeMajor(string $appPath): ?string
    {
        $file = $appPath . '/' . self::KIT_FILE;
        $raw = is_file($file) ? file_get_contents($file) : false;
        if ($raw === false || preg_match('/^\s*ARG\s+NODE_IMAGE\s*=\s*\S*?\bnode:(\d+)/mi', $raw, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    /** @param list<string> $items */
    private static function join(array $items, string $word): string
    {
        if (count($items) < 2) {
            return implode('', $items);
        }
        $last = array_pop($items);

        return implode(', ', $items) . ' ' . $word . ' ' . $last;
    }
}
