<?php

declare(strict_types=1);

namespace Waynestate\Booster\Run\Slim;

/**
 * A small line-based unified diff (three lines of context), so the Slim hop
 * can show what it changed in each file without a runtime diff dependency.
 * The common head and tail are trimmed first; the middle is diffed through
 * a longest common subsequence, or shown as one replaced block when it is
 * too large for that.
 *
 * Diffs are like reunion photos: everyone looks the same except for the
 * three lines that clearly had work done.
 */
final class UnifiedDiff
{
    private const CONTEXT = 3;

    /** Above this many cells the middle is shown as one replaced block. */
    private const MAX_CELLS = 1_000_000;

    /**
     * The unified diff from $old to $new for $file (relative to the app), or
     * an empty string when they are the same. An empty $old is a new file.
     */
    public static function of(string $file, string $old, string $new): string
    {
        if ($old === $new) {
            return '';
        }
        $a = self::lines($old);
        $b = self::lines($new);
        $ops = self::ops($a, $b);

        $changed = [];
        foreach ($ops as $index => [$type]) {
            if ($type !== ' ') {
                $changed[] = $index;
            }
        }
        if ($changed === []) {
            return '';
        }

        $groups = [];
        $first = $changed[0];
        $last = $changed[0];
        foreach (array_slice($changed, 1) as $index) {
            if ($index - $last - 1 > 2 * self::CONTEXT) {
                $groups[] = [$first, $last];
                $first = $index;
            }
            $last = $index;
        }
        $groups[] = [$first, $last];

        $out = [
            $old === '' ? '--- /dev/null' : '--- a/' . $file,
            '+++ b/' . $file,
        ];
        foreach ($groups as [$from, $to]) {
            $start = max(0, $from - self::CONTEXT);
            $end = min(count($ops) - 1, $to + self::CONTEXT);
            $oldBefore = 0;
            $newBefore = 0;
            for ($i = 0; $i < $start; $i++) {
                $oldBefore += $ops[$i][0] !== '+' ? 1 : 0;
                $newBefore += $ops[$i][0] !== '-' ? 1 : 0;
            }
            $oldCount = 0;
            $newCount = 0;
            $body = [];
            for ($i = $start; $i <= $end; $i++) {
                [$type, $line] = $ops[$i];
                $oldCount += $type !== '+' ? 1 : 0;
                $newCount += $type !== '-' ? 1 : 0;
                $body[] = $type . $line;
            }
            $out[] = sprintf(
                '@@ -%d,%d +%d,%d @@',
                $oldCount === 0 ? $oldBefore : $oldBefore + 1,
                $oldCount,
                $newCount === 0 ? $newBefore : $newBefore + 1,
                $newCount,
            );
            array_push($out, ...$body);
        }

        return implode("\n", $out);
    }

    /**
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        if ($text === '') {
            return [];
        }
        if (str_ends_with($text, "\n")) {
            $text = substr($text, 0, -1);
        }

        return explode("\n", $text);
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     *
     * @return list<array{string, string}> each line with ' ', '-' or '+'
     */
    private static function ops(array $a, array $b): array
    {
        $countA = count($a);
        $countB = count($b);
        $head = 0;
        while ($head < $countA && $head < $countB && $a[$head] === $b[$head]) {
            $head++;
        }
        $endA = $countA;
        $endB = $countB;
        while ($endA > $head && $endB > $head && $a[$endA - 1] === $b[$endB - 1]) {
            $endA--;
            $endB--;
        }

        $ops = [];
        for ($i = 0; $i < $head; $i++) {
            $ops[] = [' ', $a[$i]];
        }
        $midA = array_slice($a, $head, $endA - $head);
        $midB = array_slice($b, $head, $endB - $head);
        array_push($ops, ...self::middle($midA, $midB));
        for ($i = $endA; $i < $countA; $i++) {
            $ops[] = [' ', $a[$i]];
        }

        return $ops;
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     *
     * @return list<array{string, string}>
     */
    private static function middle(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        if ($n * $m > self::MAX_CELLS) {
            $ops = [];
            foreach ($a as $line) {
                $ops[] = ['-', $line];
            }
            foreach ($b as $line) {
                $ops[] = ['+', $line];
            }

            return $ops;
        }

        // $length[$i][$j]: the longest common subsequence of $a[$i..] and $b[$j..].
        $length = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $length[$i][$j] = $a[$i] === $b[$j]
                    ? $length[$i + 1][$j + 1] + 1
                    : max($length[$i + 1][$j], $length[$i][$j + 1]);
            }
        }

        $ops = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $ops[] = [' ', $a[$i]];
                $i++;
                $j++;
            } elseif ($length[$i + 1][$j] >= $length[$i][$j + 1]) {
                $ops[] = ['-', $a[$i]];
                $i++;
            } else {
                $ops[] = ['+', $b[$j]];
                $j++;
            }
        }
        for (; $i < $n; $i++) {
            $ops[] = ['-', $a[$i]];
        }
        for (; $j < $m; $j++) {
            $ops[] = ['+', $b[$j]];
        }

        return $ops;
    }
}
