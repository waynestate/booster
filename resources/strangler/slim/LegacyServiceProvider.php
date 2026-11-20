<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Controllers\Legacy\Support\LegacyContainer;
use App\Http\Controllers\Legacy\Support\SlimAdapter;
use Illuminate\Support\ServiceProvider;

/**
 * The legacy Slim app's services for the moved handlers (Booster's
 * strangler, design section 10.2). App-owned: Booster wrote it on the first
 * Slim move; add a binding here for a service Booster could not rebuild.
 *
 * - `legacy.container`: the legacy container, rebuilt from the same
 *   definition files the legacy front controller loads (null when it had none);
 * - `legacy.<key>`: each key those definitions declare, resolved through it;
 * - `legacy`: an ArrayAccess shim, so `$c['x']` resolves `legacy.x`.
 */
final class LegacyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('legacy.container', static fn() => SlimAdapter::buildContainer(SlimAdapter::manifest()));
        $this->app->singleton('legacy', static fn($app) => new LegacyContainer($app));
        foreach ((array) (SlimAdapter::manifest()['keys'] ?? []) as $key) {
            $this->app->singleton('legacy.' . $key, static fn($app) => $app->make('legacy.container')?->get($key));
        }
    }
}
