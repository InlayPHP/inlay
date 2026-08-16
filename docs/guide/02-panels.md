# Panels

A panel is the PHP-owned application shell: its URL, authentication, theme,
navigation, Resources, widgets, plugins, and custom routes. A project may have
one panel or several panels with different guards and policies.

## The generated provider

The installer creates an application-owned provider similar to this:

```php
<?php

namespace App\Providers\Inlay;

use App\Inlay\Resources\UserResource;
use Inlay\Panel;
use Inlay\PanelProvider;
use Inlay\Theme\Theme;

final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->path('/admin')
            ->brandName((string) config('app.name', 'Inlay'))
            ->theme(Theme::default())
            ->sidebarNavigation()
            ->collapsible()
            ->breadcrumbs()
            ->topbar()
            ->middleware(['web'])
            ->authMiddleware(['auth'])
            ->loginComponent('inlay/auth/login')
            ->dashboardComponent('inlay/dashboard')
            ->accountSettings()
            ->resources([UserResource::class])
            ->globalSearch()
            ->spa()
            ->renderComponent('InlayPanelLayout');
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

Register the provider in `config/inlay-panels.php`:

```php
return [
    'providers' => [
        App\Providers\Inlay\AdminPanelProvider::class,
    ],
];
```

Do not register the provider a second time in `bootstrap/providers.php`.
`Inlay\PanelServiceProvider` is discovered by Laravel and loads the configured
application providers.

## URL and panel identity

`panelId()` is the stable identity used by route names and panel directories.
`path()` is the URL prefix. They must be unique across the application:

```php
final class SupportPanelProvider extends PanelProvider
{
    protected function panelId(): string
    {
        return 'support';
    }

    protected function isDefaultPanel(): bool
    {
        return false;
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->path('/support')
            ->brandName('Support desk')
            ->authMiddleware(['auth', 'can:access-support-panel'])
            ->dashboardComponent('support/dashboard');
    }
}
```

Route names are predictable. For an `admin` panel, common names are:

```text
inlay.admin.login
inlay.admin.authenticate
inlay.admin.logout
inlay.admin.dashboard
inlay.admin.account.edit
inlay.admin.account.profile
inlay.admin.account.password
```

Use route names in PHP rather than hard-coding panel paths in links whenever a
destination is owned by the panel:

```php
route('inlay.admin.dashboard');
route('inlay.admin.login');
```

## Authentication

Panels use Laravel's configured authentication guard and middleware. The login
page is a normal application-owned Inertia page at
`resources/js/pages/inlay/auth/login.tsx` or `.vue`; the authentication
controller remains in the package.

Protect the panel with the normal `auth` middleware and add a domain-specific
middleware when needed:

```php
return $panel
    ->middleware(['web'])
    ->authMiddleware([
        'auth',
        'verified',
        EnsureUserIsAdmin::class,
    ]);
```

For an explicit per-user access decision, implement `PanelUser`:

```php
use Illuminate\Foundation\Auth\User as Authenticatable;
use Inlay\Contracts\PanelUser;
use Inlay\Panel;

final class User extends Authenticatable implements PanelUser
{
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active
            && $this->can('panels.'.$panel->id().'.access');
    }
}
```

This is intentionally separate from `PanelAccount`. `PanelUser` answers
“may this actor enter this panel?”; `PanelAccount` enables the profile and
password settings forms.

## Account settings

Implement `PanelAccount` and add `accountSettings()`:

```php
use Inlay\Concerns\InteractsWithPanelAccount;
use Inlay\Contracts\PanelAccount;

final class User extends Authenticatable implements PanelAccount
{
    use InteractsWithPanelAccount;
}
```

The generated account page uses the same Forms contract as every application
form. It includes profile name/email and password change forms, applies current
password and confirmation rules, regenerates the session after a password
change, and clears email verification when a verified email changes.

Override the `PanelAccount` methods when your model uses a different profile
shape. Keep authorization and password policy in Laravel code; do not add a
second client-only password check.

## Navigation

Navigation can be declared directly or generated from Resources:

```php
use Inlay\NavigationGroup;
use Inlay\NavigationItem;

return $panel
    ->navigationGroups([
        NavigationGroup::make('content')
            ->label('Content')
            ->icon('document')
            ->sort(20),
    ])
    ->navigationItems([
        NavigationItem::make('reports')
            ->label('Reports')
            ->url('/admin/reports')
            ->icon('chart')
            ->group('content')
            ->sort(10),
        NavigationItem::make('billing')
            ->label('Billing')
            ->url('/admin/billing')
            ->visibleWhen('billing.view'),
    ]);
```

Items may define `badge()`, `badgeColor()`, `visibleWhen()`, `activeWhen()`,
`group()`, `sort()`, and safe link attributes. Event handlers and arbitrary
JavaScript are rejected. Use a Laravel ability or middleware for security;
visibility is only a presentation hint.

Resource navigation is generated from the Resource's index page and label. The
current Resource API exposes `navigationIcon`; use the panel's
`navigationGroups()` and `navigationItems()` when the application needs custom
grouping or ordering around Resource links.

## Layout modes

The default shell is responsive. Configure the broad layout in PHP:

```php
return $panel
    ->sidebarNavigation()
    ->collapsible()
    ->topbar()
    ->breadcrumbs()
    ->spa();
```

The generated React/Vue layout receives the active panel contract and should
wrap local pages with the same package shell. Do not wrap Inlay pages in a
second starter-kit layout; that produces duplicate navigation and often
causes the search box to overlap the sidebar.

## Tenant-aware panels

Generate a tenant-aware provider:

```bash
php artisan inlay:install \
  --panel=workspace \
  --tenant-model='App\\Models\\Team' \
  --tenant-parameter=team \
  --tenant-route-key=slug
```

Or configure it manually:

```php
return $panel
    ->path('/workspace')
    ->tenant(Team::class, parameter: 'team', routeKey: 'slug');
```

Protected routes become `/{team}/workspace/...`. Login remains outside the
tenant segment because a visitor authenticates before choosing a tenant.

Implement `HasTenants` on the authenticated model and `TenantAccess` on the
tenant model. The URL is not an authorization mechanism; membership is checked
server-side for every request.

## Custom panel routes

Add application routes through the panel so middleware, navigation, and ability
ownership remain together:

```php
use Inlay\Authorization\AbilityDefinition;
use Inlay\PanelRoute;

return $panel
    ->abilities([
        AbilityDefinition::make('audit.view')
            ->label('View audit log')
            ->group('Audit'),
    ])
    ->routes([
        PanelRoute::get('audit.index', 'audit', AuditController::class)
            ->middleware(['can:audit.view']),
    ]);
```

The permission manager and Spatie adapter can discover these ability
definitions later. A custom route should still enforce a Laravel policy or
ability in addition to hiding its navigation item.

## Global search

Enable search with `globalSearch()`. Resources opt into search by declaring
searchable attributes. The panel adds a protected search endpoint and the
renderer displays the search field in the shell. Disable it for a panel with:

```php
$panel->globalSearch(false);
```

Search results are authorized and scoped through the Resource query. Never add
an unscoped model query to a custom global search callback.

## Multiple panels

Register several providers in the config file and mark exactly one as default.
To build an authorization-aware directory:

```php
use Inertia\Inertia;
use Inlay\PanelRegistry;

public function boot(PanelRegistry $panels): void
{
    Inertia::share('inlayPanels', fn () =>
        $panels->directoryFor(auth()->user())
    );
}
```

React and Vue can render `PanelSwitcher` from the shared directory. The
directory contains only safe identifiers, labels, paths, and logos—not each
panel's private navigation.

## Panel plugins

Plugins are registered in PHP and can contribute routes, abilities, navigation,
widgets, assets, and renderer pages:

```php
return $panel->plugins([
    PermissionManagerPlugin::make(),
    MediaManagerPlugin::make(),
]);
```

Keep package registration in a provider. Keep application-specific policy
grants and seed data in application code. See [Plugins](09-plugins.md) for the
full extension pattern.
