<?php

declare(strict_types=1);

namespace Inlay\AuthorizationSpatie;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Inlay\Authorization\Contracts\PermissionSynchronizer;
use Inlay\AuthorizationSpatie\Commands\SyncPermissionsCommand;
use Inlay\AuthorizationSpatie\Contracts\TeamResolver;
use Inlay\AuthorizationSpatie\TeamResolvers\NullTeamResolver;
use RuntimeException;

final class AuthorizationSpatieServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/inlay-authorization-spatie.php', 'inlay-authorization-spatie');
        $this->app->singleton(PermissionSynchronizer::class, SpatiePermissionSynchronizer::class);
        $this->app->bind(TeamResolver::class, function ($app): TeamResolver {
            $resolver = config('inlay-authorization-spatie.teams.resolver', NullTeamResolver::class);

            if (! is_string($resolver) || $resolver === '' || ! is_a($resolver, TeamResolver::class, true)) {
                throw new RuntimeException('The configured Inlay team resolver must implement '.TeamResolver::class.'.');
            }

            return $app->make($resolver);
        });
    }

    public function boot(): void
    {
        Gate::before(function (mixed $user): ?bool {
            $role = config('inlay-authorization-spatie.super_admin_role');

            return is_string($role)
                && $role !== ''
                && method_exists($user, 'hasRole')
                && $user->hasRole($role)
                    ? true
                    : null;
        });

        if ($this->app->runningInConsole()) {
            $this->commands([SyncPermissionsCommand::class]);
        }

        $this->publishes([
            __DIR__.'/../config/inlay-authorization-spatie.php' => config_path('inlay-authorization-spatie.php'),
        ], 'inlay-authorization-spatie-config');
    }
}
