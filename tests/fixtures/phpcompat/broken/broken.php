<?php

// A deliberately broken legacy app for the PHPCompatibility scan tests.
// each() and create_function() were removed in PHP 8.0; the ${name} embed
// was deprecated in PHP 8.2, so it shows up only when scanning at 8.2 or newer.

function legacy_each(array $rows): array
{
    // each() was deprecated in PHP 7.2 and removed in PHP 8.0.
    while (each($rows) !== false) {
        // Intentionally empty body; the sniff cares about the call.
    }

    return $rows;
}

function legacy_create_function(): callable
{
    // create_function() was deprecated in PHP 7.2 and removed in PHP 8.0.
    return create_function('', 'return 42;');
}

function legacy_string_embed(string $name): string
{
    // ${name} embedding was deprecated in PHP 8.2.
    return "Hello ${name}!";
}
