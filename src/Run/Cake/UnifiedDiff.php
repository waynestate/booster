<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run\Cake;

/**
 * A small unified diff (`--- a/x`, `+++ b/x`, `@@ -1,3 +1,4 @@`, three lines
 * of context) for the files the CakePHP upgrade engine changed in its copy
 * of the app. Myers' algorithm on the lines between the common head and
 * tail; a file whose edits are too many for that falls back to one hunk that
 * replaces the middle, which is still a correct diff, just a blunt one.
 * Booster's runtime has no diff library (sebastian/diff only comes with
 * PHPUnit), and this is all the engine needs. (Spot the difference, the
 * grown-up edition.)
 */
final class UnifiedDiff
{
    private const CONTEXT = 3;

    /** Above this many edits the line-by-line search stops and the middle is replaced whole. */
    private const MAX_EDITS = 1000;

    /**
     * The unified diff from $old to $new for the file at $path (relative to
     * the app). Null on one side means the file is new or was deleted.
     */
    public static function of(string $path, ?string $old, ?string $new): string
    {
        if (self::binary($old) || self::binary($new)) {
            return sprintf('Binary file %s changed.', $path);
        }

        $header = sprintf("--- %s\n+++ %s", $old === null ? '/dev/null' : 'a/' . $path, $new === null ? '/dev/null' : 'b/' . $path);
        $hunks = self::hunks(self::ops(self::lines($old), self::lines($new)));
        if ($hunks === []) {
            return $header . "\n(only whitespace at the end of the file changed)";
        }

        return $header . "\n" . implode("\n", $hunks);
    }

    private static function binary(?string $text): bool
    {
        return $text !== null && (str_contains($text, "\0") || preg_match('//u', $text) !== 1);
    }

    /**
     * @return list<string>
     */
    private static function lines(?string $text): array
    {
        if ($text === null || $text === '') {
            return [];
        }
        $lines = explode("\n", $text);
        if (end($lines) === '') {
            array_pop($lines);
        }

        return array_map(static fn(string $line): string => rtrim($line, "\r"), $lines);
    }

    /**
     * The edit script: each entry is ' ', '-' or '+' with its line.
     *
     * @param list<string> $a
     * @param list<string> $b
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function ops(array $a, array $b): array
    {
        $head = 0;
        $max = min(count($a), count($b));
        while ($head < $max && $a[$head] === $b[$head]) {
            $head++;
        }
        $tail = 0;
        while ($tail < $max - $head && $a[count($a) - 1 - $tail] === $b[count($b) - 1 - $tail]) {
            $tail++;
        }

        $ops = [];
        foreach (array_slice($a, 0, $head) as $line) {
            $ops[] = [' ', $line];
        }
        $midA = array_slice($a, $head, count($a) - $head - $tail);
        $midB = array_slice($b, $head, count($b) - $head - $tail);
        $middle = self::myers($midA, $midB);
        if ($middle === null) {
            $middle = [];
            foreach ($midA as $line) {
                $middle[] = ['-', $line];
            }
            foreach ($midB as $line) {
                $middle[] = ['+', $line];
            }
        }
        $ops = [...$ops, ...$middle];
        foreach (array_slice($a, count($a) - $tail) as $line) {
            $ops[] = [' ', $line];
        }

        return $ops;
    }

    /**
     * Myers' shortest edit script, or null when it needs more than MAX_EDITS.
     *
     * @param list<string> $a
     * @param list<string> $b
     *
     * @return list<array{0: string, 1: string}>|null
     */
    private static function myers(array $a, array $b): ?array
    {
        $n = count($a);
        $m = count($b);
        $limit = min($n + $m, self::MAX_EDITS);
        /** @var array<int, int> $v */
        $v = [1 => 0];
        /** @var list<array<int, int>> $trace */
        $trace = [];
        for ($d = 0; $d <= $limit; $d++) {
            $trace[] = $v;
            for ($k = -$d; $k <= $d; $k += 2) {
                $down = $k === -$d || ($k !== $d && ($v[$k - 1] ?? -1) < ($v[$k + 1] ?? -1));
                $x = $down ? ($v[$k + 1] ?? 0) : ($v[$k - 1] ?? 0) + 1;
                $y = $x - $k;
                while ($x < $n && $y < $m && $a[$x] === $b[$y]) {
                    $x++;
                    $y++;
                }
                $v[$k] = $x;
                if ($x >= $n && $y >= $m) {
                    return self::backtrack($trace, $a, $b);
                }
            }
        }

        return null;
    }

    /**
     * @param list<array<int, int>> $trace
     * @param list<string> $a
     * @param list<string> $b
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function backtrack(array $trace, array $a, array $b): array
    {
        $x = count($a);
        $y = count($b);
        $ops = [];
        for ($d = count($trace) - 1; $d >= 0; $d--) {
            $v = $trace[$d];
            $k = $x - $y;
            $down = $k === -$d || ($k !== $d && ($v[$k - 1] ?? -1) < ($v[$k + 1] ?? -1));
            $prevK = $down ? $k + 1 : $k - 1;
            $prevX = $v[$prevK] ?? 0;
            $prevY = $prevX - $prevK;
            while ($x > $prevX && $y > $prevY) {
                $ops[] = [' ', $a[$x - 1]];
                $x--;
                $y--;
            }
            if ($d > 0) {
                if ($x === $prevX) {
                    $ops[] = ['+', $b[$y - 1]];
                } else {
                    $ops[] = ['-', $a[$x - 1]];
                }
            }
            $x = $prevX;
            $y = $prevY;
        }

        return array_reverse($ops);
    }

    /**
     * @param list<array{0: string, 1: string}> $ops
     *
     * @return list<string>
     */
    private static function hunks(array $ops): array
    {
        $count = count($ops);
        $oldNo = [];
        $newNo = [];
        $changed = [];
        $o = 1;
        $n = 1;
        foreach ($ops as $i => [$op]) {
            $oldNo[$i] = $o;
            $newNo[$i] = $n;
            if ($op !== '+') {
                $o++;
            }
            if ($op !== '-') {
                $n++;
            }
            if ($op !== ' ') {
                $changed[] = $i;
            }
        }

        $hunks = [];
        $g = 0;
        $groups = count($changed);
        while ($g < $groups) {
            $start = max(0, $changed[$g] - self::CONTEXT);
            $last = $changed[$g];
            while ($g + 1 < $groups && $changed[$g + 1] - $last - 1 <= 2 * self::CONTEXT) {
                $g++;
                $last = $changed[$g];
            }
            $end = min($count - 1, $last + self::CONTEXT);

            $body = [];
            $oldLen = 0;
            $newLen = 0;
            for ($i = $start; $i <= $end; $i++) {
                [$op, $line] = $ops[$i];
                $body[] = $op . $line;
                $oldLen += $op !== '+' ? 1 : 0;
                $newLen += $op !== '-' ? 1 : 0;
            }
            $hunks[] = sprintf(
                '@@ -%d,%d +%d,%d @@',
                $oldLen === 0 ? $oldNo[$start] - 1 : $oldNo[$start],
                $oldLen,
                $newLen === 0 ? $newNo[$start] - 1 : $newNo[$start],
                $newLen,
            ) . "\n" . implode("\n", $body);
            $g++;
        }

        return $hunks;
    }
}
