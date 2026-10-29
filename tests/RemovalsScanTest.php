<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

use Waynestate\Booster\Scan\RemovalsScan;
use Waynestate\Booster\ToolError;

final class RemovalsScanTest extends TestCase
{
    private function appWith(string $name, string $content): string
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/' . $name, $content);

        return $dir;
    }

    public function test_a_removed_function_call_is_found_with_file_and_line(): void
    {
        $app = $this->appWith('legacy.php', "<?php\n\$db = mysql_connect('h', 'u', 'p');\n");
        $result = (new RemovalsScan())->scan($app, '5.6', '7.0');

        self::assertSame(1, $result->total);
        self::assertCount(1, $result->findings);
        self::assertSame('legacy.php', $result->findings[0]->file);
        self::assertSame(2, $result->findings[0]->line);
        self::assertSame('mysql-functions', $result->findings[0]->id);
        self::assertSame('mysqli or PDO', $result->findings[0]->replacement);
    }

    public function test_a_method_call_with_the_same_name_is_not_found(): void
    {
        $app = $this->appWith('model.php', "<?php\n\$row = \$db->mysql_fetch_assoc(\$result);\n\$other = Foo::each();\n");
        $result = (new RemovalsScan())->scan($app, '5.6', '8.0');

        self::assertSame(0, $result->total);
    }

    public function test_strings_and_comments_are_not_found(): void
    {
        $app = $this->appWith('notes.php', "<?php\n// each(\$row) used to work here\n\$label = 'each()';\n\$other = \"create_function was fun\";\n");
        $result = (new RemovalsScan())->scan($app, '7.4', '8.0');

        self::assertSame(0, $result->total);
    }

    public function test_prefix_entries_match_any_call_that_starts_with_the_prefix(): void
    {
        $app = $this->appWith('crypto.php', "<?php\n\$k = mcrypt_encrypt('des', \$d, \$k, 'cbc', \$iv);\n\$r = ereg_replace('[^a-z]', '', \$s);\n");
        $result = (new RemovalsScan())->scan($app, '5.6', '7.2');

        self::assertSame(2, $result->total);
        $ids = array_map(static fn($f): string => $f->id, $result->findings);
        self::assertContains('mcrypt-functions', $ids);
        self::assertContains('ereg-functions', $ids);
    }

    public function test_the_hop_range_filters_by_removed_in(): void
    {
        $app = $this->appWith('legacy.php', "<?php\nmysql_query('SELECT 1');\n");
        $scan = new RemovalsScan();

        self::assertSame(1, $scan->scan($app, '5.6', '7.0')->total);
        self::assertSame(0, $scan->scan($app, '7.0', '7.1')->total);
        self::assertSame(0, $scan->scan($app, '7.1', '7.2')->total);
    }

    public function test_new_by_reference_is_found_and_plain_new_is_not(): void
    {
        $app = $this->appWith('ref.php', "<?php\n\$a = &new Foo();\n\$b = new Foo();\n\$c = &\$a;\n");
        $result = (new RemovalsScan())->scan($app, '5.6', '7.0');

        self::assertSame(1, $result->total);
        self::assertSame('new-by-reference', $result->findings[0]->id);
        self::assertSame(2, $result->findings[0]->line);
    }

    public function test_vendor_node_modules_storage_and_git_are_skipped(): void
    {
        $app = $this->tempDir();
        foreach ([
            'vendor/pkg/old.php' => "<?php mysql_query('x');\n",
            'node_modules/lib/a.php' => "<?php mysql_query('x');\n",
            'storage/logs/b.php' => "<?php mysql_query('x');\n",
            '.git/hooks/c.php' => "<?php mysql_query('x');\n",
        ] as $name => $content) {
            mkdir(dirname($app . '/' . $name), 0775, true);
            file_put_contents($app . '/' . $name, $content);
        }
        $result = (new RemovalsScan())->scan($app, '5.6', '7.0');

        self::assertSame(0, $result->total);
        self::assertSame(0, $result->filesScanned);
    }

    public function test_findings_are_capped_but_the_total_survives(): void
    {
        $lines = ["<?php\n"];
        for ($i = 0; $i < 210; $i++) {
            $lines[] = "each([]);\n";
        }
        $app = $this->appWith('many.php', implode('', $lines));
        $result = (new RemovalsScan())->scan($app, '7.4', '8.0');

        self::assertSame(210, $result->total);
        self::assertCount(RemovalsScan::MAX_FINDINGS, $result->findings);
        self::assertTrue($result->toArray()['truncated']);
    }

    public function test_an_unreadable_file_is_skipped_with_a_count(): void
    {
        $app = $this->tempDir();
        mkdir($app . '/broken.php');
        file_put_contents($app . '/ok.php', "<?php mysql_query('x');\n");
        $result = (new RemovalsScan())->scan($app, '5.6', '7.0');

        self::assertSame(1, $result->total);
        self::assertSame(1, $result->filesScanned);
        self::assertSame(1, $result->filesSkipped);
    }

    public function test_the_seeded_entries_cover_the_documented_removals(): void
    {
        $scan = new RemovalsScan();

        $sevenZero = array_column($scan->entriesForHop('5.6', '7.0'), 'id');
        foreach (['mysql-functions', 'ereg-functions', 'split', 'spliti', 'sql_regcase', 'set_magic_quotes_runtime', 'call_user_method', 'call_user_method_array', 'define_syslog_variables', 'new-by-reference'] as $id) {
            self::assertContains($id, $sevenZero);
        }

        self::assertSame([], $scan->entriesForHop('7.0', '7.1'));

        self::assertEqualsCanonicalizing(['mcrypt-functions', 'mdecrypt_generic'], array_column($scan->entriesForHop('7.1', '7.2'), 'id'));

        $eightZero = array_column($scan->entriesForHop('7.4', '8.0'), 'id');
        foreach (['each', 'create_function', 'money_format', 'get_magic_quotes_gpc', 'get_magic_quotes_runtime', 'is_real', 'fgetss', 'gzgetss', 'image2wbmp', 'jpeg2wbmp', 'png2wbmp', 'hebrevc', 'convert_cyr_string', 'ezmlm_hash', 'restore_include_path'] as $id) {
            self::assertContains($id, $eightZero);
        }
    }

    public function test_the_data_file_drives_the_scan(): void
    {
        $data = $this->tempDir() . '/removals.json';
        file_put_contents($data, json_encode([
            'schema_version' => 1,
            'removals' => [
                ['id' => 'acme-widget', 'removed_in' => '7.3', 'kind' => 'function', 'match' => 'acme_widget', 'label' => 'acme_widget()', 'replacement' => 'nothing'],
            ],
        ]));
        $app = $this->appWith('w.php', "<?php acme_widget();\n");

        self::assertSame(1, (new RemovalsScan($data))->scan($app, '7.2', '7.3')->total);
        self::assertSame(0, (new RemovalsScan($data))->scan($app, '7.3', '7.4')->total);
    }

    public function test_a_broken_data_file_is_a_tool_error(): void
    {
        $data = $this->tempDir() . '/removals.json';
        file_put_contents($data, '{"schema_version": 1, "removals": [{"id": "x"}]}');

        $this->expectException(ToolError::class);
        new RemovalsScan($data);
    }
}
