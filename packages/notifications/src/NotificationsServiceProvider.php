<?php

declare(strict_types=1);

namespace Inlay\Notifications;

use Illuminate\Support\ServiceProvider;

final class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NotificationManager::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../database/migrations/2026_08_02_000000_create_inlay_notifications_table.php' => database_path('migrations/2026_08_02_000000_create_inlay_notifications_table.php'),
        ], 'inlay-notifications-migrations');

        // Inertia remains optional for the standalone package. When it is
        // installed, the same serialized contract is available to React and
        // Vue without making panel controllers know about the transport.
        if (! class_exists(\Inertia\Inertia::class)) {
            return;
        }

        \Inertia\Inertia::share('inlayNotifications', fn (): array => $this->app
            ->make(NotificationManager::class)
            ->pull());
    }
}
