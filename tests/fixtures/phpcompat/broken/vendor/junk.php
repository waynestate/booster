<?php

// Lives under vendor/ on purpose: the scan must ignore it entirely.

function vendored_each(array $rows): array
{
    while (each($rows) !== false) {
        // Should never be reported.
    }

    return $rows;
}
