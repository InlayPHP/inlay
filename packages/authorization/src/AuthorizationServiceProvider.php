<?php

declare(strict_types=1);

namespace Inlay\Authorization;

use Illuminate\Support\ServiceProvider;

final class AuthorizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AbilityRegistry::class);
        $this->app->singleton(AuthorizationManager::class);
    }
}
