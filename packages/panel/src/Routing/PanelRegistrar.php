<?php

declare(strict_types=1);

namespace Inlay\Routing;

use Illuminate\Contracts\Container\Container;
use Illuminate\Routing\Router;
use Inlay\Authorization\AbilityRegistry;
use Inlay\Http\Controllers\AccountSettingsController;
use Inlay\Http\Controllers\AuthenticationController;
use Inlay\Http\Controllers\DashboardController;
use Inlay\NavigationItem;
use Inlay\Panel;
use Inlay\PanelRegistry;
use Inlay\Resources\Resource;
use Inlay\Resources\Http\Controllers\GlobalSearchController;
use Inlay\Resources\Http\Middleware\ResolveTenant;
use Inlay\Resources\Routing\ResourceRegistrar;
use LogicException;

final class PanelRegistrar
{
    public function __construct(
        private readonly Router $router,
        private readonly PanelRegistry $panels,
        private readonly AbilityRegistry $abilities,
        private readonly Container $container,
    ) {}

    public function registerConfiguredPanels(): void
    {
        foreach ($this->panels->all() as $panel) {
            $this->register($panel);
        }
    }

    public function register(Panel $panel): void
    {
        $panel->bootPlugins();

        $prefix = trim($panel->pathValue(), '/');
        $name = 'inlay.'.$panel->id().'.';
        $publicMiddleware = $panel->middlewareList();
        $protectedMiddleware = [...$publicMiddleware, ...$panel->authMiddlewareList()];
        $defaults = ['inlayPanel' => $panel->id()];

        // A tenant panel resolves its tenant on every protected route, so no
        // page or resource query can precede it. Login stays outside it.
        $tenant = $panel->tenantConfiguration();
        if ($tenant !== null) {
            $defaults = [
                ...$defaults,
                'inlayTenantModel' => $tenant['model'],
                'inlayTenantParameter' => $tenant['parameter'],
                'inlayTenantRouteKey' => $tenant['routeKey'],
            ];
            $protectedMiddleware = [...$protectedMiddleware, ResolveTenant::class];
        }
        $tenantPrefix = $tenant === null ? $prefix : trim('{'.$tenant['parameter'].'}/'.$prefix, '/');

        $this->route('get', $prefix.'/login', [AuthenticationController::class, 'create'], $name.'login', $publicMiddleware, $defaults);
        $this->route('post', $prefix.'/login', [AuthenticationController::class, 'store'], $name.'authenticate', [...$publicMiddleware, 'throttle:6,1'], $defaults);
        $this->route('post', $prefix.'/logout', [AuthenticationController::class, 'destroy'], $name.'logout', $protectedMiddleware, $defaults);
        $this->route('get', $tenantPrefix, DashboardController::class, $name.'dashboard', $protectedMiddleware, $defaults);

        if ($panel->hasAccountSettings()) {
            $this->route('get', $tenantPrefix.'/settings/account', [AccountSettingsController::class, 'edit'], $name.'account.edit', $protectedMiddleware, $defaults);
            $this->route('patch', $tenantPrefix.'/settings/profile', [AccountSettingsController::class, 'updateProfile'], $name.'account.profile', $protectedMiddleware, $defaults);
            $this->route('put', $tenantPrefix.'/settings/password', [AccountSettingsController::class, 'updatePassword'], $name.'account.password', [...$protectedMiddleware, 'throttle:6,1'], $defaults);
            $panel->userMenuItem(
                NavigationItem::make('account-settings')
                    ->label('Account settings')
                    ->url($panel->pathValue().'/settings/account')
                    ->icon('user-circle')
                    ->sort(90),
            );
        }

        foreach ($panel->getRoutes() as $route) {
            $middleware = $route->requiresAuthentication() ? $protectedMiddleware : $publicMiddleware;
            $this->route(
                strtolower($route->method()),
                $prefix.'/'.$route->uri(),
                $route->action(),
                $name.$route->name(),
                [...$middleware, ...$route->middlewareList()],
                $defaults,
            );
        }

        foreach ($panel->getAbilities() as $contribution) {
            $this->abilities->register($contribution['definition'], $contribution['owner']);
        }

        $panel->navigationItem(
            NavigationItem::make('dashboard')
                ->label('Dashboard')
                ->url($panel->pathValue())
                ->icon('home')
                ->activeWhen('page.type', 'dashboard')
                ->sort(-100),
        );

        $resources = $panel->getResources();
        if ($resources === []) {
            return;
        }

        if (! class_exists(Resource::class) || ! class_exists(ResourceRegistrar::class)) {
            throw new LogicException('Panel resources require the [inlayphp/resources] package.');
        }

        foreach ($resources as $resource) {
            if (! is_subclass_of($resource, Resource::class)) {
                throw new \InvalidArgumentException("Panel resource [{$resource}] must extend ".Resource::class.'.');
            }

            foreach ($resource::abilityDefinitions() as $ability) {
                $this->abilities->register($ability, $resource);
            }

            if ($resource::parentResource() !== null) {
                continue;
            }

            $metadata = $resource::metadata($prefix);
            $item = NavigationItem::make('resource-'.$metadata->slug)
                ->label($metadata->pluralLabel)
                ->url('/'.trim($prefix.'/'.$metadata->slug, '/'))
                ->group('resources')
                ->activeWhen('resource.slug', $metadata->slug);
            if ($metadata->navigationIcon !== null) {
                $item->icon($metadata->navigationIcon);
            }
            $panel->navigationItem($item);
        }

        if ($panel->globalSearchEnabled()) {
            $searchPath = '/'.trim($prefix.'/_inlay/global-search', '/');
            $this->route(
                'get',
                $prefix.'/_inlay/global-search',
                [GlobalSearchController::class, 'index'],
                $name.'global-search',
                $protectedMiddleware,
                $defaults,
            );
            $panel->globalSearchEndpoint($searchPath);
        }

        $this->container->make(ResourceRegistrar::class)->routes($resources, [
            ...($tenant === null ? [] : ['tenant' => $tenant]),
            'prefix' => $prefix,
            'name' => $name,
            'middleware' => $protectedMiddleware,
            'mutationMiddleware' => $panel->resourceMutationMiddlewareList(),
            'defaults' => $defaults,
        ]);
    }

    /**
     * @param  array{class-string, string}|class-string|\Closure  $action
     * @param  list<string>  $middleware
     * @param  array<string, string>  $defaults
     */
    private function route(string $method, string $uri, array|string|\Closure $action, string $name, array $middleware, array $defaults): void
    {
        $route = $this->router->{$method}($uri, $action)->middleware($middleware)->name($name);
        foreach ($defaults as $key => $value) {
            $route->defaults($key, $value);
        }
    }
}
