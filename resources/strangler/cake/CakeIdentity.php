<?php

namespace App\Http\Controllers\Legacy\Cake;

/**
 * Written by Booster's strangler (cakephp-to-laravel): the signed-in user
 * as a copied Policy sees it (Authorization's IdentityInterface):
 * getIdentifier() is the user data's `id` field, as Authentication's
 * Identity reads it (its fieldMap default), and get() a field of it.
 */
final class CakeIdentity implements \ArrayAccess
{
    public function __construct(private readonly mixed $user) {}

    /** The `id` field of a signed-in user (Identity::getIdentifier()); null when the user data has none. */
    public static function idOf(mixed $user): mixed
    {
        $data = is_object($user) && method_exists($user, 'toArray') ? $user->toArray() : $user;

        return data_get($data, 'id');
    }

    public function getIdentifier(): mixed
    {
        return self::idOf($this->user);
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
