<?php

declare(strict_types=1);

namespace App\Sesame;

use Waynestate\Sesame\Identity;
use Waynestate\Sesame\UserResolver as UserResolverContract;

/**
 * Turns the identity SESAME verified into what the session keeps, written by
 * Booster's strangler Step 0. App-owned: edit it freely.
 *
 * It keeps only the username (the AccessID). The app's own sign-in code
 * still builds its profile and roles from that username, as it did from the
 * old cookie. Return null here to refuse someone outright.
 */
final class UserResolver implements UserResolverContract
{
    public function findOrImportUser(Identity $identity): mixed
    {
        return ['username' => $identity->username];
    }
}
