<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Hop\HopRegistry;
use Waynestate\Booster\Run\RectorRunner;
use Waynestate\Booster\Run\SymfonyProcessRunner;

/**
 * Runs the real Rector, from Booster's own vendor, on a tiny app. Every other
 * Rector test uses the fake runner, which is why 1.8.0 and 1.8.1 shipped with
 * Rector 2.8.1 next to PHPStan 2.3.4: the two load different copies of
 * phpdoc-parser in one process, and Rector died on the first closure it met
 * ("CallableTypeNode::__construct(): Argument #1 ($identifier) must be of type
 * IdentifierTypeNode, FullyQualifiedNameNode given"). If a dependency update
 * splits the pair again, this test fails before a release does.
 *
 * Booster boosts the app, and this test boosts Booster's confidence.
 */
final class RectorRealProcessTest extends TestCase
{
    public function test_real_rector_rewrites_a_tiny_app_with_closures_and_reports_no_errors(): void
    {
        $app = $this->tempDir();
        file_put_contents($app . '/composer.json', '{"name": "fixture/tiny", "require": {"php": "^7.4"}}' . "\n");
        mkdir($app . '/src');
        // A closure passed to array_map is the shape that tripped the phpdoc-parser
        // clash; strpos() !== false is something the PHP 8.0 set really rewrites.
        $source = <<<'PHP'
            <?php

            function shout(array $names): array
            {
                return array_map(function ($name) {
                    return strtoupper($name);
                }, $names);
            }

            function hasAt(string $email): bool
            {
                return strpos($email, '@') !== false;
            }

            PHP;
        file_put_contents($app . '/src/helpers.php', $source);

        $result = (new RectorRunner(new SymfonyProcessRunner()))
            ->run((new HopRegistry())->get('php-74-to-80'), $app, true, '8.0');

        self::assertSame([], $result->errors, 'Real Rector reported errors: ' . implode("\n", $result->errors));
        self::assertSame(['src/helpers.php'], array_column($result->files, 'file'));
        self::assertStringContainsString('str_contains($email, \'@\')', $result->files[0]['diff']);
        self::assertSame($source, file_get_contents($app . '/src/helpers.php'), 'A dry run must not write the file.');
    }
}
