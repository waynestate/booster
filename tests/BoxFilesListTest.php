<?php

declare(strict_types=1);

namespace Waynestate\Booster\Tests;

/**
 * Box auto-discovery only picks up autoloaded paths (bin/, src/, vendor/).
 * The non-autoloaded runtime files (config/, data/, docker/, resources/) must
 * be listed explicitly in box.json.dist "files", or the phar silently ships
 * without them and commands fail at runtime. This test fails if any file
 * under those directories is missing from the list.
 */
final class BoxFilesListTest extends TestCase
{
    /** @var list<string> */
    private const DIRECTORIES = ['config', 'data', 'docker', 'resources'];

    public function test_every_runtime_file_is_listed_in_box_files(): void
    {
        $root = dirname(__DIR__);
        $boxPath = $root . '/box.json.dist';
        self::assertFileExists($boxPath);

        $box = json_decode((string) file_get_contents($boxPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($box);
        self::assertArrayHasKey('files', $box);
        self::assertIsArray($box['files']);

        $listed = array_map(static fn($path): string => str_replace('\\', '/', (string) $path), $box['files']);

        $missing = [];
        foreach (self::DIRECTORIES as $dir) {
            foreach ($this->filesIn($root . '/' . $dir) as $file) {
                $relative = str_replace('\\', '/', substr($file, strlen($root) + 1));
                if (! in_array($relative, $listed, true)) {
                    $missing[] = $relative;
                }
            }
        }

        self::assertSame([], $missing, 'Files missing from box.json.dist "files": ' . implode(', ', $missing));
    }

    /**
     * @return list<string> absolute paths of every file under $dir
     */
    private function filesIn(string $dir): array
    {
        $files = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $files = array_merge($files, $this->filesIn($path));
            } else {
                $files[] = $path;
            }
        }

        return $files;
    }
}
