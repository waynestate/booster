<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Run\UnusedImports;

final class UnusedImportsTest extends TestCase
{
    public function test_an_unused_import_is_removed_and_used_ones_stay(): void
    {
        $content = implode("\n", [
            '<?php',
            '',
            'namespace App\Http\Controllers;',
            '',
            'use App\Models\Gone;',
            'use App\Models\Post;',
            'use Illuminate\Routing\Controller;',
            '',
            'class ThingController extends Controller',
            '{',
            '    public function index(): Post',
            '    {',
            '        return Post::first();',
            '    }',
            '}',
            '',
        ]);
        [$cleaned, $removed] = UnusedImports::remove($content);

        $this->assertSame(1, $removed);
        $this->assertStringNotContainsString('use App\Models\Gone;', $cleaned);
        $this->assertStringContainsString('use App\Models\Post;', $cleaned);
        $this->assertStringContainsString('use Illuminate\Routing\Controller;', $cleaned);
    }

    public function test_an_alias_is_matched_by_its_alias_name(): void
    {
        $content = implode("\n", [
            '<?php',
            'use App\Models\Post as Entry;',
            '',
            'class Thing',
            '{',
            '    public function index(): Entry',
            '    {',
            '        return Entry::first();',
            '    }',
            '}',
            '',
        ]);
        [$cleaned, $removed] = UnusedImports::remove($content);

        $this->assertSame(0, $removed);
        $this->assertSame($content, $cleaned);
    }

    public function test_an_unused_alias_is_removed(): void
    {
        $content = implode("\n", [
            '<?php',
            'use App\Models\Post as Entry;',
            '',
            'class Thing',
            '{',
            '}',
            '',
        ]);
        [$cleaned, $removed] = UnusedImports::remove($content);

        $this->assertSame(1, $removed);
        $this->assertStringNotContainsString('Entry', $cleaned);
    }

    public function test_a_name_in_another_import_line_does_not_count_as_a_use(): void
    {
        $content = implode("\n", [
            '<?php',
            'use App\Foo;',
            'use App\Foo\Bar;',
            '',
            'class Thing extends Bar',
            '{',
            '}',
            '',
        ]);
        [$cleaned, $removed] = UnusedImports::remove($content);

        $this->assertSame(1, $removed);
        $this->assertStringNotContainsString('use App\Foo;', $cleaned);
        $this->assertStringContainsString('use App\Foo\Bar;', $cleaned);
    }

    public function test_indented_trait_uses_and_function_imports_stay_alone(): void
    {
        $content = implode("\n", [
            '<?php',
            'use function App\support\slug;',
            '',
            'class Thing',
            '{',
            '    use DispatchesJobs;',
            '',
            '    public function go(): string',
            '    {',
            '        return slug("x");',
            '    }',
            '}',
            '',
        ]);
        [$cleaned, $removed] = UnusedImports::remove($content);

        $this->assertSame(0, $removed);
        $this->assertSame($content, $cleaned);
    }

    public function test_a_name_in_a_comment_keeps_its_import(): void
    {
        $content = implode("\n", [
            '<?php',
            'use App\Models\Post;',
            '',
            'class Thing',
            '{',
            '    // Post is handled elsewhere.',
            '}',
            '',
        ]);
        [$cleaned, $removed] = UnusedImports::remove($content);

        $this->assertSame(0, $removed);
        $this->assertSame($content, $cleaned);
    }

    public function test_content_without_imports_is_unchanged(): void
    {
        $content = "<?php\n\nclass Thing\n{\n}\n";
        [$cleaned, $removed] = UnusedImports::remove($content);

        $this->assertSame(0, $removed);
        $this->assertSame($content, $cleaned);
    }
}
