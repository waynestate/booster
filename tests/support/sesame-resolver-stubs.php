<?php

declare(strict_types=1);

/*
 * Test doubles for the SESAME and Laravel types the strangler's resolvers
 * implement, so StranglerSignInTest can load the legacy half's and the Laravel
 * half's resolvers side by side without SESAME or Laravel installed. The
 * signatures are the real ones (SESAME 0.10, Laravel 13).
 */

namespace Waynestate\Sesame {
    if (!class_exists(Identity::class, false)) {
        final class Identity
        {
            /** @param array<string, mixed> $attributes */
            public function __construct(
                public string $username,
                public array $attributes = [],
            ) {}
        }
    }

    if (!interface_exists(UserResolver::class, false)) {
        interface UserResolver
        {
            public function findOrImportUser(Identity $identity): mixed;
        }
    }

    if (!interface_exists(RefreshesUser::class, false)) {
        interface RefreshesUser
        {
            public function refreshUser(mixed $user): mixed;
        }
    }
}

namespace Illuminate\Contracts\Auth {
    if (!interface_exists(Authenticatable::class, false)) {
        interface Authenticatable
        {
            public function getAuthIdentifierName();

            public function getAuthIdentifier();

            public function getAuthPasswordName();

            public function getAuthPassword();

            public function getRememberToken();

            public function setRememberToken($value);

            public function getRememberTokenName();
        }
    }
}
