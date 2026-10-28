<?php

declare(strict_types=1);

namespace Waynestate\Booster\Report;

final class JsonWriter
{
    /**
     * @param array<string, mixed> $data
     */
    public function write(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }
}
