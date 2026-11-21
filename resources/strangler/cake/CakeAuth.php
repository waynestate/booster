<?php

namespace App\Http\Controllers\Legacy\Cake;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Written by Booster's strangler (cakephp-to-laravel): the Authorization
 * plugin's component for moved actions. authorize() asks the copied
 * Policy for the entity (OrmResolver's naming: Entity\Article to
 * Policy\ArticlePolicy, `can` plus the action), and a refusal ends in the
 * legacy app's error page as ForbiddenException did. ensure() is the
 * AuthorizationMiddleware check: an action that neither authorized nor
 * skipped authorization ends in the legacy error page, as before.
 */
final class CakeAuth
{
    private const CHECKED = 'booster.cake.authorized';

    public static function authorize(Request $request, mixed $resource, string $action): void
    {
        $request->attributes->set(self::CHECKED, true);
        if (!$resource instanceof Model) {
            throw new CakeHttpException(500, 'Booster: authorize() of something that is not an entity is not covered.', false);
        }
        $short = substr((string) strrchr('\\' . $resource::class, '\\'), 1);
        $policy = __NAMESPACE__ . '\\Policy\\' . $short . 'Policy';
        if (!class_exists($policy)) {
            throw new CakeHttpException(500, 'Policy for `' . $resource::class . '` has not been defined.', false);
        }
        $method = 'can' . ucfirst($action);
        if (!method_exists($policy, $method)) {
            throw new CakeHttpException(500, 'Method `' . $method . '` for invoking action `' . $action . '` has not been defined in `' . $policy . '`.', false);
        }
        $user = $request->user();
        $result = $user === null ? false : (new $policy())->{$method}(new CakeIdentity($user), $resource);
        if (is_object($result) && method_exists($result, 'getStatus')) {
            $result = $result->getStatus();
        }
        if ($result !== true) {
            throw new CakeHttpException(403, 'Identity is not authorized to perform `' . $action . '` on `' . $resource::class . '`.', false);
        }
    }

    public static function skip(Request $request): void
    {
        $request->attributes->set(self::CHECKED, true);
    }

    public static function ensure(Request $request): void
    {
        if ($request->attributes->get(self::CHECKED) !== true) {
            throw new CakeHttpException(500, 'The request to `' . $request->getRequestUri() . '` did not apply any authorization checks.', false);
        }
    }
}
