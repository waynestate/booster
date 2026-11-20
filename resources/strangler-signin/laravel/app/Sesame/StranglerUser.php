<?php

declare(strict_types=1);

namespace App\Sesame;

use ArrayAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use JsonSerializable;

/**
 * The signed-in user on the Laravel half while the strangler runs, written by
 * Booster. App-owned: edit it with the legacy half's resolver.
 *
 * It holds exactly the array the legacy half's resolver
 * (legacy/.../Sesame/UserResolver.php or LegacySignIn) keeps, so a route moved
 * from the old app reads the same keys: `$user['username']` works as it did
 * there, `toArray()` is equal to the legacy value, and SESAME's guard sees an
 * Authenticatable for `auth()->user()`. Nobody signs in with a password here.
 *
 * @implements ArrayAccess<string, mixed>
 */
final class StranglerUser implements ArrayAccess, Authenticatable, JsonSerializable
{
    /**
     * @param array<string, mixed> $attributes the legacy half's user array
     */
    public function __construct(
        private readonly array $attributes,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->attributes;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->attributes;
    }

    public function __get(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->attributes[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->attributes[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('The signed-in user is read-only.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('The signed-in user is read-only.');
    }

    public function getAuthIdentifierName(): string
    {
        return 'username';
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->attributes['username'] ?? null;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void {}

    public function getRememberTokenName(): string
    {
        return '';
    }
}
