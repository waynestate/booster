<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Controllers\Legacy\Support\LegacyContainer;
use App\Http\Controllers\Legacy\Support\LegacyTwigExtension;
use App\Http\Controllers\Legacy\Support\SlimAdapter;
use Illuminate\Support\ServiceProvider;

/**
 * The legacy Slim app's services for the moved handlers (Booster's
 * strangler, design section 10.2). App-owned: Booster wrote it on the first
 * Slim move; add a binding here for a service Booster could not rebuild.
 *
 * - `legacy.container`: the legacy container, rebuilt from the same
 *   definition files the legacy front controller loads (null when it had none),
 *   or, when the app builds its own (an app class, Pimple, the Slim 3 to 4
 *   hop's LegacyApp), the one its own setup builds: the copied front
 *   controller runs up to the app's dispatch, once per request;
 * - `legacy.<key>`: each key those definitions declare, resolved through it;
 * - `legacy`: an ArrayAccess shim, so `$c['x']` resolves `legacy.x`.
 */
final class LegacyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('legacy.container', static fn() => SlimAdapter::buildContainer(SlimAdapter::manifest()));
        $this->app->singleton('legacy', static fn($app) => new LegacyContainer($app));
        $manifest = SlimAdapter::manifest();
        $keys = array_merge((array) ($manifest['keys'] ?? []), (array) ($manifest['hosted']['keys'] ?? []));
        foreach (array_unique($keys) as $key) {
            $this->app->bind('legacy.' . $key, static fn() => SlimAdapter::service($key));
        }
    }

    /**
     * Legacy Twig templates rendered by the Laravel half through TwigBridge
     * (rcrowe/twigbridge) find legacy/templates and slim/twig-view's
     * url_for, full_url_for, base_path and is_current_url.
     */
    public function boot(): void
    {
        if (!class_exists('TwigBridge\ServiceProvider')) {
            return;
        }
        $templates = base_path((string) (SlimAdapter::manifest()['twig']['templates'] ?? 'legacy/templates'));
        if (is_dir($templates)) {
            $this->callAfterResolving('view', static fn($view) => $view->addLocation($templates));
        }
        $this->callAfterResolving('twig', static fn($twig) => $twig->addExtension(LegacyTwigExtension::forRequest()));
    }
}
