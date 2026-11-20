<?php

declare(strict_types=1);

namespace App\Sesame;

use Waynestate\Sesame\Identity;
use Waynestate\Sesame\RefreshesUser;
use Waynestate\Sesame\UserResolver;

/**
 * The Laravel half's SESAME resolver while the strangler runs, written by
 * Booster. App-owned: edit it together with the legacy half's resolver.
 *
 * It keeps the same array the legacy half's resolver keeps (today only the
 * username), wrapped in StranglerUser so SESAME's guard can hand it to
 * auth()->user(). Change one, change the other: moved routes and the routes
 * still in legacy/ must see the same user. refreshUser() turns the array a
 * JSON session brings back into the same StranglerUser on every request.
 */
final class StranglerUserResolver implements RefreshesUser, UserResolver
{
    /** The user array, the same shape as the legacy half's resolver returns. */
    public static function attributes(Identity $identity): array
    {
        return ['username' => $identity->username];
    }

    public function findOrImportUser(Identity $identity): mixed
    {
        return new StranglerUser(self::attributes($identity));
    }

    public function refreshUser(mixed $user): mixed
    {
        $attributes = match (true) {
            $user instanceof StranglerUser => $user->toArray(),
            is_array($user) => $user,
            default => null,
        };

        if (! is_array($attributes) || ! is_string($attributes['username'] ?? null) || $attributes['username'] === '') {
            return null;
        }

        return new StranglerUser($attributes);
    }
}
