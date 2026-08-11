<?php

declare(strict_types=1);

namespace Inlay\PermissionManager;

use Illuminate\Support\ServiceProvider;

final class PermissionManagerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/inlay-permission-manager.php', 'inlay-permission-manager');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/inlay-permission-manager.php' => config_path('inlay-permission-manager.php'),
        ], 'inlay-permission-manager-config');
    }
}
