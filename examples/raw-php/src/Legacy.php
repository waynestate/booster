<?php

namespace Example;

class Legacy
{
    /** @var string */
    private $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function mentionsAdmin(string $text): bool
    {
        return strpos($text, 'admin') !== false;
    }

    public function className($object): string
    {
        return get_class($object);
    }

    public function label(string $label = null): string
    {
        return $label ?? $this->name;
    }
}
