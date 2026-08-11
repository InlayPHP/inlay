<?php

declare(strict_types=1);

namespace Inlay\TwoFactorAuthentication;

use Illuminate\Support\ServiceProvider;

final class TwoFactorAuthenticationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/inlay-two-factor.php', 'inlay-two-factor');
        $this->app->singleton(TwoFactorManager::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/inlay-two-factor.php' => config_path('inlay-two-factor.php'),
        ], 'inlay-two-factor-config');
        $this->publishes([
            __DIR__.'/../database/migrations/2026_08_01_000000_add_two_factor_columns_to_users_table.php' => database_path('migrations/2026_08_01_000000_add_two_factor_columns_to_users_table.php'),
        ], 'inlay-two-factor-migrations');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
