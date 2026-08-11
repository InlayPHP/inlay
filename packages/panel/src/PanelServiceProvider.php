<?php

declare(strict_types=1);

namespace Inlay;

use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Inlay\Routing\PanelRegistrar;
use Inlay\Auth\LoginPipeline;
use InvalidArgumentException;

final class PanelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/inlay-panels.php', 'inlay-panels');
        $this->app->singleton(PanelRegistry::class, fn (): PanelRegistry => new PanelRegistry);
        $this->app->singleton(LoginPipeline::class);
        $this->app->singleton(PanelRegistrar::class);

        foreach ((array) $this->app['config']->get('inlay-panels.providers', []) as $provider) {
            if (! is_string($provider) || ! is_subclass_of($provider, PanelProvider::class)) {
                throw new InvalidArgumentException('Configured panel providers must extend '.PanelProvider::class.'.');
            }

            $this->app->register($provider);
        }
    }

    public function boot(PanelRegistrar $registrar): void
    {
        $registrar->registerConfiguredPanels();

        // Make multi-panel navigation opt-in at the renderer, not at every
        // application middleware. The closure is evaluated per request so a
        // PanelUser decision and tenant/session state are never cached globally.
        Inertia::share('inlayPanels', fn (): array => $this->app->make(PanelRegistry::class)
            ->directoryFor(request()->user()));

        $this->publishes([
            __DIR__.'/../config/inlay-panels.php' => config_path('inlay-panels.php'),
        ], 'inlay-panels-config');
    }
}
