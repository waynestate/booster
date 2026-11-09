<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Report\TextWriter;
use Waynestate\Booster\Scan\DynamicPropertiesScan;
use Waynestate\Booster\Scan\DynamicPropertyFinding;

/**
 * The PHP 8.2 dynamic properties check: a 2012 app's User class set six
 * properties it never declared, and the 8.2 hop said "none found" because
 * nothing looked. Each case is a pocket class, fake names only.
 */
final class DynamicPropertiesScanTest extends TestCase
{
    /** @param array<string, string> $files */
    private function app(array $files): string
    {
        $dir = $this->tempDir();
        foreach ($files as $name => $contents) {
            if (!is_dir(dirname($dir . '/' . $name))) {
                mkdir(dirname($dir . '/' . $name), 0775, true);
            }
            file_put_contents($dir . '/' . $name, $contents);
        }

        return $dir;
    }

    /** @return list<string> */
    private static function where(string $dir): array
    {
        return array_map(
            static fn(DynamicPropertyFinding $f): string => sprintf('%s:%d %s::$%s', $f->file, $f->line, $f->class, $f->property),
            (new DynamicPropertiesScan())->scan($dir)->findings,
        );
    }

    public function test_it_names_each_undeclared_property_once_where_it_is_first_set(): void
    {
        $dir = $this->app(['lib/User.php' => <<<'PHP'
            <?php
            class User
            {
                public $id;
                var $legacy;

                public function __construct($row)
                {
                    $this->id = $row['id'];
                    $this->fullName = $row['first'] . ' ' . $row['last'];
                    $this->roles[] = 'reader';
                    $this->legacy = true;
                    $this->fullName = trim($this->fullName);
                    $this->cache ??= [];
                    $this->id->value = 1;
                    $this->label .= '!';
                }
            }
            PHP]);

        self::assertSame([
            'lib/User.php:10 User::$fullName',
            'lib/User.php:11 User::$roles',
            'lib/User.php:14 User::$cache',
            'lib/User.php:16 User::$label',
        ], self::where($dir));
    }

    public function test_declared_promoted_inherited_and_allowed_properties_are_fine(): void
    {
        $dir = $this->app([
            'src/Base.php' => "<?php\nnamespace App;\nabstract class Base\n{\n    protected array \$seen = [];\n}\n",
            'src/Stamps.php' => "<?php\nnamespace App;\ntrait Stamps\n{\n    private \$stamped;\n}\n",
            'src/Note.php' => <<<'PHP'
                <?php
                namespace App\Models;

                use App\Base;
                use App\Stamps as Stamped;

                final class Note extends Base
                {
                    use Stamped;

                    public function __construct(private readonly string $title, int $plain = 0)
                    {
                        $this->seen = [$title];
                        $this->stamped = time();
                        $other = new class {
                            public function x(): void { $this->anything = 1; }
                        };
                        $fn = function () { return $this->title; };
                    }
                }
                PHP,
            'src/Bag.php' => "<?php\n#[\\AllowDynamicProperties]\nclass Bag\n{\n    public function put(\$k): void { \$this->items = \$k; }\n}\n",
            'src/Magic.php' => "<?php\nclass Magic\n{\n    public function __set(\$n, \$v): void {}\n    public function go(): void { \$this->whatever = 1; }\n}\n",
            'src/Plain.php' => "<?php\nclass Plain extends \\stdClass\n{\n    public function go(): void { \$this->whatever = 1; }\n}\n",
        ]);

        self::assertSame([], self::where($dir));
        self::assertSame([], (new DynamicPropertiesScan())->scan($dir)->unchecked);
    }

    public function test_a_php_parent_is_read_by_reflection(): void
    {
        $dir = $this->app(['src/Oops.php' => "<?php\nclass Oops extends Exception\n{\n    public function __construct(\$m)\n    {\n        \$this->message = \$m;\n        \$this->hint = 'retry';\n    }\n}\n"]);

        self::assertSame(['src/Oops.php:7 Oops::$hint'], self::where($dir));
    }

    public function test_a_parent_outside_the_app_is_not_checked_rather_than_cleared(): void
    {
        $dir = $this->app([
            'lib/SmartyView.php' => "<?php\nclass App_SmartyView extends Slim_View\n{\n    public function render(\$t) { \$this->engine = 1; }\n}\n",
            'vendor/Slim/View.php' => "<?php\nclass Slim_View\n{\n}\n",
        ]);

        $result = (new DynamicPropertiesScan())->scan($dir);

        self::assertSame([], $result->findings);
        self::assertSame(["App_SmartyView (Slim_View, which is not in the app's own code)"], $result->unchecked);
        self::assertSame(
            ["Dynamic properties (deprecated in PHP 8.2) not checked in 1 class, because a parent or a trait is outside the app's own code: App_SmartyView (Slim_View, which is not in the app's own code). Check it by hand."],
            (new TextWriter())->dynamicProperties($result),
        );
    }

    public function test_the_plan_and_run_lines(): void
    {
        $dir = $this->app(['lib/User.php' => "<?php\nclass User\n{\n    public function load(): void\n    {\n        \$this->email = 'someone@example.invalid';\n    }\n}\n", 'lib/Clean.php' => "<?php\nclass Clean {}\n"]);
        $result = (new DynamicPropertiesScan())->scan($dir);
        $writer = new TextWriter();

        self::assertSame([
            'Dynamic properties (deprecated in PHP 8.2): 1 found. Booster does not change them; declare each one in its class, or mark the class #[\AllowDynamicProperties]:',
            '  lib/User.php:6 User::$email',
        ], $writer->dynamicProperties($result));
        self::assertSame(
            ['MANUAL: lib/User.php:6 User::$email is a dynamic property, deprecated in PHP 8.2: declare it in the class, or mark the class #[\AllowDynamicProperties].'],
            $writer->dynamicProperties($result, true),
        );
        $clean = (new DynamicPropertiesScan())->scan($this->app(['lib/Clean.php' => "<?php\nclass Clean {}\n"]));
        self::assertSame(['Dynamic properties (deprecated in PHP 8.2): none found in the 1 class Booster read.'], $writer->dynamicProperties($clean));
        self::assertSame([], $writer->dynamicProperties($clean, true));
    }

    public function test_only_the_hop_that_crosses_8_2_asks(): void
    {
        self::assertTrue(DynamicPropertiesScan::appliesTo('8.1', '8.2'));
        self::assertTrue(DynamicPropertiesScan::appliesTo('7.4', '8.4'));
        self::assertFalse(DynamicPropertiesScan::appliesTo('8.0', '8.1'));
        self::assertFalse(DynamicPropertiesScan::appliesTo('8.2', '8.3'));
        self::assertFalse(DynamicPropertiesScan::appliesTo(null, '8.2'));
    }
}
