<?php

namespace App\Http\Controllers\Legacy\Cake;

/**
 * Written by Booster's strangler (cakephp-to-laravel): the event a copied
 * Table's beforeSave(), afterSave(), beforeDelete() and afterDelete() hooks
 * receive (CakePHP's EventInterface, the parts hooks use).
 */
final class CakeEvent
{
    private bool $stopped = false;

    private mixed $result = null;

    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(private readonly string $name, private readonly object $subject) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getSubject(): object
    {
        return $this->subject;
    }

    public function stopPropagation(): void
    {
        $this->stopped = true;
    }

    public function isStopped(): bool
    {
        return $this->stopped;
    }

    public function getResult(): mixed
    {
        return $this->result;
    }

    public function setResult(mixed $value = null): static
    {
        $this->result = $value;

        return $this;
    }

    public function getData(?string $key = null): mixed
    {
        return $key === null ? $this->data : ($this->data[$key] ?? null);
    }

    public function setData(array|string $key, mixed $value = null): static
    {
        if (is_array($key)) {
            $this->data = $key;
        } else {
            $this->data[$key] = $value;
        }

        return $this;
    }
}
