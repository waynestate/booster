<?php

namespace App\Http\Controllers\Legacy\Cake;

/**
 * Written by Booster's strangler (cakephp-to-laravel): the Authentication
 * plugin's DefaultPasswordHasher for ported entities (a User's
 * `_setPassword()`), on PHP's own password functions with the same
 * defaults, so a hash either half writes checks in the other.
 */
final class CakePasswordHasher
{
    /**
     * @param array{hashType?: string|int|null, hashOptions?: array<string, mixed>} $config
     */
    public function __construct(private readonly array $config = []) {}

    public function hash(string $password): string|false
    {
        return password_hash($password, $this->config['hashType'] ?? PASSWORD_DEFAULT, $this->config['hashOptions'] ?? []);
    }

    public function check(string $password, string $hashedPassword): bool
    {
        return password_verify($password, $hashedPassword);
    }

    public function needsRehash(string $password): bool
    {
        return password_needs_rehash($password, $this->config['hashType'] ?? PASSWORD_DEFAULT, $this->config['hashOptions'] ?? []);
    }
}
