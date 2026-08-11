<?php

declare(strict_types=1);

namespace Inlay\Actions;

use Illuminate\Support\ServiceProvider;
use Inlay\Actions\Contracts\ActionFormResolver;

final class ActionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ActionFormResolver::class, UnavailableActionFormResolver::class);
        $this->app->singleton(ActionRunner::class);
    }
}
