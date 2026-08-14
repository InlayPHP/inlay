# Inlay Panels

[![Packagist](https://img.shields.io/packagist/v/inlayphp/panels?style=flat-square&label=packagist)](https://packagist.org/packages/inlayphp/panels)
[![PHP](https://img.shields.io/packagist/dependency-v/inlayphp/panels/php?style=flat-square)](https://packagist.org/packages/inlayphp/panels)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../LICENSE)

**PHP-first administration panels, authentication, dashboards, navigation, and routing for Laravel and Inertia**

A complete, renderer-neutral, fluent panel runtime for Laravel and Inertia. It owns panel configuration, authentication, dashboards, protected routes, navigation, Resource registration, widgets, themes, and plugins. Define the application shell in PHP, then render the stable `inlay.panels.v1` browser contract with React or Vue. Middleware and guards remain server-side and are never serialized into that contract.

## Install

```bash
composer require inlayphp/panels
```

When using the full `inlayphp/inlay` package, install the complete default
panel with `php artisan inlay:install --panels`. The root installer writes an
application-owned `PanelProvider`, registers it in `config/inlay-panels.php`,
generates User CRUD, enables account settings, bundles Media Manager, publishes
its migrations, and scaffolds the official React entry points. Installing
`inlayphp/panels` alone remains intentionally renderer-neutral.

After migrating a new Laravel application, run `php artisan inlay:make-user` to
create the first panel login without shipping a public default password.
Run `php artisan inlay:doctor --production` after the frontend build to verify
panel registration, migrations, renderer dependencies, and compiled Inlay CSS.

The installer can also generate a tenant-aware provider, so a new multi-tenant
panel does not need a hand-written first pass:

```bash
php artisan inlay:install \
    --panel=workspace \
    --tenant-model='App\\Models\\Team' \
    --tenant-parameter=team \
    --tenant-route-key=slug
```

It writes `->tenant(Team::class, parameter: 'team', routeKey: 'slug')` into the
provider and prefixes protected routes with `/{team}`. The command validates
the generated identifiers but does not assume how users belong to tenants;
implement `HasTenants` on the authenticated model and `TenantAccess` on the
tenant model as described below.

Laravel discovers `Inlay\PanelServiceProvider` automatically. Publish the configuration when you want to register panel providers from config:

```bash
php artisan vendor:publish --tag=inlay-panels-config
```

Install `inlayphp/resources` alongside Panels when the panel registers CRUD Resources. A frontend shell is optional for custom renderers; official adapters are available as `@inlayphp/panels-react` and `@inlayphp/panels-vue`.

For new themes, also install `inlayphp/design`. It is the public design-system façade over the compatible `Theme` contract and provides `php artisan make:inlay-theme Brand` to generate an application theme class and CSS variables. Existing `Theme::default()` panel definitions remain supported.

## Define a panel

```php
<?php

namespace App\Providers\Inlay;

use App\Http\Middleware\EnsureUserIsAdmin;
use Inlay\NavigationGroup;
use Inlay\NavigationItem;
use Inlay\Panel;
use Inlay\PanelProvider;
use Inlay\Theme\Theme;

final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->path('/admin')
            ->brandName('Acme Admin')
            ->brandLogo('/images/acme.svg')
            ->colors([
                'primary' => '#4f46e5',
                'danger' => '#dc2626',
            ])
            ->theme(
                Theme::default()
                    ->accent('#4f46e5')
                    ->tokens(['sidebar-width' => '18rem'])
                    ->darkTokens(['accent' => '#818cf8'])
            )
            ->sidebarNavigation()
            ->collapsible()
            ->breadcrumbs()
            ->topbar()
            ->middleware(['web'])
            ->authMiddleware(['auth', EnsureUserIsAdmin::class])
            ->resources([
                App\Inlay\Resources\UserResource::class,
            ])
            ->navigationGroups([
                NavigationGroup::make('management')
                    ->label('Management')
                    ->icon('users')
                    ->sort(10)
                    ->items([
                        NavigationItem::make('users')
                            ->label('Users')
                            ->url('/admin/users')
                            ->icon('user-group')
                            ->badge(12),
                    ]),
            ])
            ->navigationItems([
                NavigationItem::make('dashboard')
                    ->url('/admin')
                    ->icon('home')
                    ->sort(1),
                NavigationItem::make('roles')
                    ->url('/admin/roles')
                    ->group('management')
                    ->visibleWhen('permissions.manage_roles'),
            ])
            ->userMenuItems([
                NavigationItem::make('profile')->url('/profile'),
                NavigationItem::make('logout')->url('/logout')->sort(100),
            ])
            ->spa()
            ->renderComponent('AdminShell');
    }

    protected function panelId(): string
    {
        return 'admin';
    }

    protected function isDefaultPanel(): bool
    {
        return true;
    }
}
```

Register one or more providers in `config/inlay-panels.php`:

```php
return [
    'providers' => [
        App\Providers\Inlay\AdminPanelProvider::class,
    ],
];
```

The first registered panel is the implicit default. Mark one provider as the explicit default with `isDefaultPanel()`. Panel IDs and normalized paths must be unique.

### Panel discovery

Applications with more than one panel can build a small, authorization-aware
directory without serializing every panel's navigation into every response:

```php
use Inertia\Inertia;
use Inlay\PanelRegistry;

public function boot(PanelRegistry $panels): void
{
    Inertia::share('inlayPanels', fn () => $panels->directoryFor(auth()->user()));
}
```

`directoryFor()` returns only `id`, `label`, `path`, and `brandLogo`. Guests get
an empty list. If the authenticated model implements `Inlay\Contracts\PanelUser`,
its `canAccessPanel()` decision is applied to every entry; other models retain
the normal Panels default of being allowed into each registered panel. Render
the result with `PanelSwitcher` from either official adapter:

```tsx
<PanelSwitcher panels={page.props.inlayPanels} currentPanelId={panel.id} />
```

```vue
<PanelSwitcher :panels="$page.props.inlayPanels" :current-panel-id="panel.id" />
```

The switcher rejects unsafe paths at the renderer boundary and accepts the same
Inertia link adapter or `onNavigate` callback as `Panel`.

## Routes, authentication, and Resources

Each configured panel automatically registers login, authentication, logout, and dashboard routes. With an `admin` panel at `/admin`, their route names are `inlay.admin.login`, `inlay.admin.authenticate`, `inlay.admin.logout`, and `inlay.admin.dashboard`. Public panel middleware wraps login routes; the combined public and authentication middleware wraps the dashboard, logout, protected custom routes, and Resources.

Login submissions are rate limited by default. Account settings are opt-in and use the shared `inlayphp/forms` contract for both profile and password screens:

```php
use Inlay\Concerns\InteractsWithPanelAccount;
use Inlay\Contracts\PanelAccount;

final class User extends Authenticatable implements PanelAccount
{
    use InteractsWithPanelAccount;
}

// In the panel provider:
return $panel->accountSettings();
```

This registers `inlay.{panel}.account.edit`, `.account.profile`, and `.account.password`, and adds Account settings to the user menu. The trait supplies conventional `name`, unique `email`, current-password, confirmed-password, and Laravel default password rules. Override any `PanelAccount` method when your model or rules differ. Changing an email on a model implementing Laravel's `MustVerifyEmail` clears its verification timestamp and sends a new verification notification. Password writes are rate limited and regenerate the session and CSRF token.

The default authentication controller validates credentials through Laravel, regenerates the session after login, invalidates it on logout, and redirects only to safe local destinations. The authenticated model may implement `Inlay\Contracts\PanelUser` to make panel access an explicit domain decision:

```php
use Inlay\Contracts\PanelUser;
use Inlay\Panel;

final class User extends Authenticatable implements PanelUser
{
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && $this->can('panels.'.$panel->id().'.access');
    }
}
```

### Extending the login pipeline

After credentials and `PanelUser` access have passed, Panels runs the panel's
ordered `LoginStep` list. A step can call `$next` for the normal redirect or
return a response to suspend the attempt, which lets an optional two-factor
plugin add a challenge without replacing the authentication controller:

```php
use Closure;
use Inlay\Auth\LoginAttempt;
use Inlay\Auth\LoginStep;
use Symfony\Component\HttpFoundation\Response;

final class RequireTwoFactor implements LoginStep
{
    public function handle(LoginAttempt $attempt, Closure $next): ?Response
    {
        if ($attempt->user->two_factor_confirmed_at === null) {
            $attempt->request->session()->put('inlay.2fa.pending', [
                'panel' => $attempt->panel->id(),
                'user' => $attempt->user->getAuthIdentifier(),
            ]);

            return redirect($attempt->panel->pathValue().'/two-factor-challenge');
        }

        return $next();
    }
}

$panel->loginStep(RequireTwoFactor::class);
```

Session fixation protection runs before the pipeline. A challenge step owns its
pending marker and must clear it atomically when the challenge succeeds; it
should never put secrets in URLs. Steps are panel-scoped, so a plugin can be
enabled for one guard without changing another panel.

Register Resources directly on the panel. Panels derives navigation and authorization abilities from their metadata, then delegates CRUD route registration to `inlayphp/resources`:

```php
return $panel
    ->resources([
        UserResource::class,
        RoleResource::class,
    ])
    ->resourceMutationMiddleware([
        HandlePrecognitiveRequests::class,
    ]);
```

Resource routes inherit the panel prefix, route-name namespace, middleware, and panel defaults. If `resources()` is used without installing `inlayphp/resources`, startup fails with an actionable exception instead of silently omitting CRUD routes.

### Resource global search

Panels automatically expose a protected global-search endpoint when Resources are
registered. A Resource opts in by declaring searchable attributes:

```php
final class UserResource extends Resource
{
    public static function globallySearchableAttributes(): array
    {
        return ['name', 'email'];
    }
}
```

The panel serializes the endpoint as `globalSearch` in `inlay.panels.v1` and the
official React/Vue shells render the same debounced top-bar search. Results use the
Resource's scoped query, policy authorization, tenant scope, record title, and edit/view
URL. The default endpoint is `GET /{panel-path}/_inlay/global-search?q=...`; set
`->globalSearch(false)` on a panel to omit both the route and the UI. The response is
`inlay.resources.global-search.v1` and is safe for custom renderers to consume.

## Tenancy

Scope a panel to a tenant:

```php
Panel::make('admin')
    ->path('/admin')
    ->tenant(Team::class, parameter: 'team', routeKey: 'slug')
    ->resources([ProjectResource::class]);
```

Every protected route gains the tenant segment — `/{team}/admin` — and resolves the tenant
before the controller runs, resources included. Login stays outside it, because signing in
happens before a tenant exists.

The payload carries the tenant the request belongs to and the ones the visitor may switch
to. Options come from the user model:

```php
final class User extends Authenticatable implements HasTenants
{
    public function inlayTenants(): iterable
    {
        return $this->teams;
    }
}
```

React and Vue render the switcher in the panel header, offering only tenants that are not
current and disabling it for a visitor who belongs to one. Unsafe option URLs are discarded
before rendering. The menu supports Enter/Space and ArrowUp/ArrowDown keyboard navigation,
Escape and outside-click dismissal, and returns focus to its trigger when it closes.
Membership is enforced by `TenantAccess` on the tenant model, not by the URL. Tenancy requires
`inlayphp/resources`; without it the panel behaves exactly as before.

## Custom panel routes and abilities

Plugins and applications can contribute routes and owned authorization abilities without editing a central routes file:

```php
use Inlay\Authorization\AbilityDefinition;
use Inlay\PanelRoute;

return $panel
    ->routes([
        PanelRoute::get('audit.index', 'audit', AuditController::class)
            ->middleware(['can:audit.view']),
    ])
    ->abilities([
        AbilityDefinition::make('audit.view')
            ->label('View audit log')
            ->group('Audit'),
    ]);
```

Ability ownership is tracked by `inlayphp/authorization`, allowing integrations such as `inlayphp/authorization-spatie` and the permission manager to synchronize only the abilities they own.

## Panel plugins

Panel plugins use the shared `inlayphp/core` lifecycle. Registration happens as the panel is configured; booting happens after Laravel has registered every panel provider. Both phases run once in the same order passed to `plugins()`.

```php
use Inlay\Core\Contracts\Plugin;
use Inlay\Core\PluginContext;
use Inlay\Panel;

final class AuditPlugin implements Plugin
{
    public function id(): string
    {
        return 'acme.audit';
    }

    public function register(PluginContext $context): void
    {
        $panel = $context->hostAs(Panel::class);

        // Register extensions, assets, or render hooks through the context.
    }

    public function boot(PluginContext $context): void
    {
        // Resolve application services that are only available after registration.
    }
}

public function panel(Panel $panel): Panel
{
    return $panel->plugins([
        new AuditPlugin,
    ]);
}
```

Plugin IDs must be unique inside a panel. Use `hasPlugin()`, `getPlugin()`, `getPlugins()`, and `pluginContext()` when a panel or another plugin needs to inspect the installed extensions.

## Share with Inertia

The panel service provider automatically shares the active panel as the
`inlayPanel` prop on panel routes. It resolves the route default first, then
the panel route name, then the request path, so Resource and plugin pages keep
the same shell contract even when a custom router removes route defaults. No
application middleware or controller boilerplate is required. Standalone
routes receive no active panel.

If a custom renderer needs to resolve the contract outside Inertia's shared
props, use the same request-aware resolver:

```php
$panel = app(\Inlay\PanelRegistry::class)->resolveForRequest(request());
```

The React and Vue adapters consume the same JSON payload. `colors` and `theme` are intentionally open token maps so applications can map them to CSS custom properties, Tailwind theme values, or a custom renderer without changing PHP schemas.

`Theme::base()` provides a restrained neutral foundation and `Theme::default()` provides Inlay's polished admin preset. Theme tokens are inherited by every official renderer. Local `theme`, `classNames`, stable `data-slot` hooks, and renderer registries remain available when a screen needs to diverge without forking the package.

## Dashboard widgets

Panels may register request-aware widget providers from `inlayphp/widgets`:

```php
$panel
    ->widget(App\Inlay\Widgets\AdminDashboardWidgets::class)
    ->widget(App\Inlay\Widgets\SalesWidgets::class);
```

The panel dashboard resolves these providers into an `inlayWidgets` prop. Render it with `WidgetDashboard` from `@inlayphp/widgets-react` or `@inlayphp/widgets-vue`.

For an application with many providers, use the explicit discovery boundary:

```php
$panel->discoverWidgets(
    directories: app_path('Inlay/Widgets'),
    namespace: 'App\\Inlay\\Widgets',
);
```

Discovery registers only concrete `ProvidesWidgets` classes. It caches provider
class names for the current PHP process, never rendered widget data; providers
are instantiated and authorized again for each dashboard request.

Conditions such as `visibleWhen()` and `activeWhen()` serialize metadata; the frontend evaluates them using the context you provide. Visibility is presentation only. Always enforce authorization through Laravel policies and middleware on the protected routes.

Navigation attributes are restricted to safe renderer hints: `class`, `id`, `title`, `rel`, `target`, `aria-*`, and `data-*`. Event-handler attributes are rejected.

## Navigation behavior

- Items and groups sort deterministically by `sort`, label, then name.
- A top-level item with `->group('management')` is moved into that group.
- A missing group is created automatically from the group name.
- Hidden items remain in the contract with `visible: false`, allowing adapters to apply context consistently.
- Duplicate item names, group names, panel IDs, and panel paths are rejected.

## Testing an application integration

At minimum, feature-test anonymous login access, authenticated dashboard access, denied `PanelUser` access, logout session invalidation, custom routes, panel-directory visibility, and every Resource route. The monorepo playground provides a working Laravel 12 / Inertia v3 / React 19 example:

```bash
cd playground/laravel-react
php artisan test
pnpm run types:check
pnpm run build
```

## Package boundaries

Panels is the single public panel concept; there is no separate Admin package. Internally, authentication, dashboard delivery, registration, and renderer contracts remain small services. CRUD implementation stays in `inlayphp/resources`, so forms, tables, infolists, and Resources can still be installed and evolved independently.
