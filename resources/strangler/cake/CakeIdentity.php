<?php

namespace App\Http\Controllers\Legacy\Cake;

/**
 * Written by Booster's strangler (cakephp-to-laravel): the signed-in user
 * as a copied Policy sees it (Authorization's IdentityInterface):
 * getIdentifier() is the Laravel user's identifier, get() a field of it.
 */
final class CakeIdentity implements \ArrayAccess
{
    public function __construct(private readonly mixed $user) {}

    public function getIdentifier(): mixed
    {
        return is_object($this->user) && method_exists($this->user, 'getAuthIdentifier') ? $this->user->getAuthIdentifier() : null;
    }

    public function get(string $field): mixed
    {
        return data_get($this->user, $field);
    }

    public function getOriginalData(): mixed
    {
        return $this->user;
    }

    public function __get(string $field): mixed
    {
        return data_get($this->user, $field);
    }

    public function offsetExists(mixed $offset): bool
    {
        return data_get($this->user, (string) $offset) !== null;
    }

    public function offsetGet(mixed $offset): mixed
    {
        return data_get($this->user, (string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('The signed-in user is read-only.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('The signed-in user is read-only.');
    }
}
