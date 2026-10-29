<?php

// A small modern app used to verify the scan stays quiet on current PHP.

final class Greeting
{
    public function __construct(
        private readonly string $name,
    ) {
    }

    public function render(): string
    {
        return match (true) {
            $this->name === '' => 'Hello stranger!',
            default => "Hello {$this->name}!",
        };
    }
}
