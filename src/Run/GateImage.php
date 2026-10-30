<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run;

use Waynestate\Booster\ToolError;

/**
 * The --image option, in its three forms: one image name used for every PHP
 * version, a template whose {php} is replaced with the PHP version of that
 * baseline or gate, or a map of PHP version to image. Resolution happens per
 * call, so a PHP hop can gate on a different image from the baseline.
 */
final readonly class GateImage
{
    /** @param array<string, string> $map PHP version to image, when the option was a map */
    private function __construct(
        private ?string $name = null,
        private ?string $template = null,
        private array $map = [],
    ) {}

    /**
     * Parses and validates the raw --image value. Null means "not given",
     * which is not an error: the gate builds booster-target as usual. A value
     * with {php} is a template even when it also contains =, because the
     * placeholder is the more specific marker.
     */
    public static function fromOption(?string $raw): ?self
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (str_contains($raw, '{php}')) {
            return new self(template: $raw);
        }
        if (str_contains($raw, '=')) {
            $map = [];
            foreach (explode(',', $raw) as $part) {
                $part = trim($part);
                if (preg_match('/^(\d+\.\d+)=(.+)$/', $part, $m) !== 1) {
                    throw new ToolError(sprintf('Invalid --image map entry "%s". Expected version=image, for example 8.0=foo:1,8.1=bar:2.', $part));
                }
                $map[$m[1]] = $m[2];
            }

            return new self(map: $map);
        }

        return new self(name: $raw);
    }

    /**
     * The image for one PHP version. A map without the version is a ToolError
     * that names the fix.
     */
    public function forPhp(string $php): string
    {
        if ($this->name !== null) {
            return $this->name;
        }
        if ($this->template !== null) {
            return str_replace('{php}', $php, $this->template);
        }
        if (isset($this->map[$php])) {
            return $this->map[$php];
        }

        throw new ToolError(sprintf('--image has no image for PHP %s; add %s=<image> or use a {php} template.', $php, $php));
    }
}
