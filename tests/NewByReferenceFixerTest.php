<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Prepass\NewByReferenceFixer;

final class NewByReferenceFixerTest extends TestCase
{
    private function fixer(): NewByReferenceFixer
    {
        return new NewByReferenceFixer();
    }

    public function test_it_rewrites_new_by_reference_to_plain_new(): void
    {
        [$out, $rewrites] = $this->fixer()->fix(<<<'PHP'
        <?php
        $a = &new Foo();
        PHP);

        self::assertSame(1, $rewrites);
        self::assertStringContainsString('$a = new Foo();', $out);
        self::assertStringNotContainsString('&', $out);
    }

    public function test_it_rewrites_without_spaces(): void
    {
        [$out, $rewrites] = $this->fixer()->fix('<?php $a =& new Foo($b);');

        self::assertSame(1, $rewrites);
        self::assertSame('<?php $a = new Foo($b);', $out);
    }

    public function test_it_rewrites_across_newlines_and_indent(): void
    {
        [$out, $rewrites] = $this->fixer()->fix(<<<'PHP'
        <?php
        $a = &
            new Foo();
        PHP);

        self::assertSame(1, $rewrites);
        self::assertStringContainsString('$a = new Foo();', $out);
    }

    public function test_it_leaves_ampersand_new_inside_strings_alone(): void
    {
        $src = <<<'PHP'
        <?php
        $s = 'x = &new Foo();';
        $t = "y = & new Foo();";
        PHP;

        [$out, $rewrites] = $this->fixer()->fix($src);

        self::assertSame(0, $rewrites);
        self::assertSame($src, $out);
    }

    public function test_it_leaves_ampersand_new_inside_comments_alone(): void
    {
        $src = <<<'PHP'
        <?php
        // $a = &new Foo();
        /* $b = & new Foo(); */
        $c = new Foo();
        PHP;

        [$out, $rewrites] = $this->fixer()->fix($src);

        self::assertSame(0, $rewrites);
        self::assertSame($src, $out);
    }

    public function test_it_leaves_reference_assignment_alone(): void
    {
        $src = '<?php $a = &$b;';

        [$out, $rewrites] = $this->fixer()->fix($src);

        self::assertSame(0, $rewrites);
        self::assertSame($src, $out);
    }

    public function test_it_leaves_reference_arguments_alone(): void
    {
        $src = '<?php foo(&$x); bar($y);';

        [$out, $rewrites] = $this->fixer()->fix($src);

        self::assertSame(0, $rewrites);
        self::assertSame($src, $out);
    }

    public function test_it_counts_every_rewrite_in_a_file(): void
    {
        [$out, $rewrites] = $this->fixer()->fix("<?php \$a = &new Foo(); \$b = & new Bar();");

        self::assertSame(2, $rewrites);
        self::assertSame('<?php $a = new Foo(); $b = new Bar();', $out);
    }

    public function test_it_is_idempotent(): void
    {
        $src = "<?php \$a = &new Foo();\n\$b = & new Bar();\n";
        [$once, $firstPass] = $this->fixer()->fix($src);
        [$twice, $secondPass] = $this->fixer()->fix($once);

        self::assertSame(2, $firstPass);
        self::assertSame(0, $secondPass);
        self::assertSame($once, $twice);
    }

    public function test_it_leaves_source_the_lexer_cannot_handle_alone(): void
    {
        $src = '<?php $x = ;';

        [$out, $rewrites] = $this->fixer()->fix($src);

        self::assertSame(0, $rewrites);
        self::assertSame($src, $out);
    }

    public function test_fix_tree_skips_directories_and_other_extensions(): void
    {
        $dir = $this->tempDir();
        $snippet = "<?php \$a = &new Foo();\n";
        foreach (['src', 'vendor/acme', 'node_modules/pkg', 'storage/logs', '.git'] as $sub) {
            mkdir($dir . '/' . $sub, 0775, true);
        }
        foreach ([
            'src/A.php' => $snippet,
            'src/B.inc' => $snippet,
            'src/C.phtml' => $snippet,
            'src/plain.txt' => $snippet,
            'vendor/acme/V.php' => $snippet,
            'node_modules/pkg/N.php' => $snippet,
            'storage/logs/L.php' => $snippet,
            '.git/H.php' => $snippet,
        ] as $relative => $content) {
            file_put_contents($dir . '/' . $relative, $content);
        }

        $result = $this->fixer()->fixTree($dir, true);

        self::assertSame(['src/A.php', 'src/B.inc', 'src/C.phtml'], array_column($result->files(), 'file'));
        self::assertSame(3, $result->changedCount());
        self::assertStringContainsString('$a = new Foo();', (string) file_get_contents($dir . '/src/A.php'));
        self::assertSame($snippet, (string) file_get_contents($dir . '/vendor/acme/V.php'));
        self::assertSame($snippet, (string) file_get_contents($dir . '/node_modules/pkg/N.php'));
        self::assertSame($snippet, (string) file_get_contents($dir . '/storage/logs/L.php'));
        self::assertSame($snippet, (string) file_get_contents($dir . '/.git/H.php'));
        self::assertSame($snippet, (string) file_get_contents($dir . '/src/plain.txt'));
    }

    public function test_fix_tree_without_write_only_reports(): void
    {
        $dir = $this->tempDir();
        $snippet = "<?php \$a = &new Foo();\n";
        file_put_contents($dir . '/A.php', $snippet);

        $result = $this->fixer()->fixTree($dir, false);

        self::assertSame([['file' => 'A.php', 'rewrites' => 1]], $result->files());
        self::assertSame($snippet, (string) file_get_contents($dir . '/A.php'));
    }

    public function test_restore_puts_original_bytes_back(): void
    {
        $dir = $this->tempDir();
        $snippet = "<?php \$a = &new Foo();\n";
        file_put_contents($dir . '/A.php', $snippet);

        $result = $this->fixer()->fixTree($dir, true);
        self::assertStringContainsString('$a = new Foo();', (string) file_get_contents($dir . '/A.php'));

        $result->restore();

        self::assertSame($snippet, (string) file_get_contents($dir . '/A.php'));
    }
}
