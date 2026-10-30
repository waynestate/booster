<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Waynestate\Booster\Run\GateImage;
use Waynestate\Booster\ToolError;

final class GateImageTest extends TestCase
{
    public function test_null_and_empty_mean_not_given(): void
    {
        self::assertNull(GateImage::fromOption(null));
        self::assertNull(GateImage::fromOption(''));
    }

    public function test_a_plain_name_serves_every_php_version(): void
    {
        $image = GateImage::fromOption('myapp/gate');
        self::assertNotNull($image);

        self::assertSame('myapp/gate', $image->forPhp('7.1'));
        self::assertSame('myapp/gate', $image->forPhp('8.5'));
    }

    public function test_a_template_replaces_the_php_placeholder(): void
    {
        $image = GateImage::fromOption('registry/php-apache:{php}');
        self::assertNotNull($image);

        self::assertSame('registry/php-apache:8.1', $image->forPhp('8.1'));
    }

    public function test_a_map_resolves_the_named_versions(): void
    {
        $image = GateImage::fromOption('8.0=foo:1, 8.1=bar:2');
        self::assertNotNull($image);

        self::assertSame('foo:1', $image->forPhp('8.0'));
        self::assertSame('bar:2', $image->forPhp('8.1'));
    }

    public function test_a_map_without_the_version_names_the_fix(): void
    {
        $image = GateImage::fromOption('8.0=foo:1');
        self::assertNotNull($image);

        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('--image has no image for PHP 8.2; add 8.2=<image> or use a {php} template.');
        $image->forPhp('8.2');
    }

    /** @return array<string, array{string}> */
    public static function malformedMaps(): array
    {
        return [
            'empty image' => ['8.0='],
            'missing version' => ['=foo:1'],
            'bad version' => ['8.a=foo:1'],
            'second entry invalid' => ['8.0=a,b'],
        ];
    }

    #[DataProvider('malformedMaps')]
    public function test_a_malformed_map_entry_is_a_tool_error(string $raw): void
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('Invalid --image map entry');
        GateImage::fromOption($raw);
    }

    public function test_a_template_wins_over_a_map_when_both_markers_are_present(): void
    {
        $image = GateImage::fromOption('8.0=foo:{php}');
        self::assertNotNull($image);

        self::assertSame('8.0=foo:8.1', $image->forPhp('8.1'));
    }
}
