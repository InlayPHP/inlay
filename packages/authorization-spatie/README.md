# Inlay Authorization — Spatie Adapter

[![Packagist](https://img.shields.io/packagist/v/inlayphp/authorization-spatie?style=flat-square&label=packagist)](https://packagist.org/packages/inlayphp/authorization-spatie)
[![PHP](https://img.shields.io/packagist/dependency-v/inlayphp/authorization-spatie/php?style=flat-square)](https://packagist.org/packages/inlayphp/authorization-spatie)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../LICENSE)

**Spatie Laravel Permission synchronization and super-admin integration for Inlay**

`inlayphp/authorization-spatie` is the official optional adapter between the Inlay ability registry and `spatie/laravel-permission`. It supplies permission synchronization, a configurable Gate-level super-admin role, and request-safe Spatie team selection.

## Optional package boundary

The lean `inlayphp/inlay` installation uses native Laravel Gate and Policies and intentionally does not require a permission database. Install this adapter only when an application wants Spatie roles/permissions to persist and bundle registered Inlay abilities.

The adapter is standalone:

- it can be used without `inlayphp/permission-manager` and without any administration UI;
- it depends on `inlayphp/authorization`, not the complete Inlay panel stack;
- Laravel package discovery registers its provider, synchronizer and console command;
- it does not add panel pages or navigation;
- `inlayphp/permission-manager` is a separate optional consumer that provides management screens.

If Policies and application-owned Gates are sufficient, stay with the clean core authorization package and do not install this adapter.

## Installation

Install and configure Spatie Laravel Permission first, including its migrations and `HasRoles` trait, then add the adapter:

```bash
composer require inlayphp/authorization-spatie
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan migrate
php artisan vendor:publish --tag=inlay-authorization-spatie-config
```

Laravel package discovery registers the provider. The adapter supports Spatie Laravel Permission 6–8 and Laravel 12.

The published configuration contains:

```php
return [
    'super_admin_role' => env('INLAY_SUPER_ADMIN_ROLE', 'super-admin'),
    'default_guard' => env('INLAY_PERMISSION_GUARD', 'web'),
    'teams' => [
        'resolver' => NullTeamResolver::class,
        'session_key' => env('INLAY_PERMISSION_TEAM_SESSION_KEY', 'inlay_team_id'),
        'user_relations' => ['roles', 'permissions'],
    ],
];
```

## Synchronize abilities

Register all Inlay abilities during application/package boot, then preview and apply changes:

```bash
php artisan inlay:permissions:sync --dry-run
php artisan inlay:permissions:sync
php artisan inlay:permissions:sync --guard=admin
```

The command creates missing permissions for the selected guard and clears Spatie's permission cache after a write. Existing matches are retained. Stored permissions not declared in the Inlay registry are reported as stale but are not removed by default.

Pruning is deliberately explicit:

```bash
php artisan inlay:permissions:sync --dry-run --prune --force
php artisan inlay:permissions:sync --prune --force
```

`--prune` without `--force` fails. Review the dry-run output before deleting permissions, particularly when non-Inlay features share the same guard.

You can invoke the adapter without a command:

```php
use Inlay\Authorization\Contracts\PermissionSynchronizer;

$result = app(PermissionSynchronizer::class)->sync(
    guard: 'web',
    dryRun: false,
    prune: false,
);

$result->created;
$result->existing;
$result->stale;
$result->deleted;
```

The configured Spatie permission model must be an Eloquent model implementing Spatie's `Permission` contract.

## Super administrator

The service provider registers `Gate::before()`. A user with the configured role receives `true` before normal Gate/policy evaluation:

```env
INLAY_SUPER_ADMIN_ROLE=super-admin
INLAY_PERMISSION_GUARD=web
```

Set `super_admin_role` to an empty/non-string value to disable this shortcut. The user object must implement `hasRole()`, normally through Spatie's `HasRoles` trait. Because this bypass applies to all Laravel Gate checks, protect assignment of the role and test it carefully.

## Team-aware authorization

Spatie teams remain opt-in. Enable `teams` in Spatie's `permission.php`, select a resolver, and add the middleware after authentication and session middleware:

```php
use Inlay\AuthorizationSpatie\Middleware\SetPermissionTeam;
use Inlay\AuthorizationSpatie\TeamResolvers\SessionTeamResolver;

// config/inlay-authorization-spatie.php
'teams' => [
    'resolver' => SessionTeamResolver::class,
    'session_key' => 'current_team_id',
    'user_relations' => ['roles', 'permissions'],
],

// bootstrap/app.php (Laravel 12+)
$middleware->web(append: [SetPermissionTeam::class]);
```

`SessionTeamResolver` accepts an integer, string, or Eloquent model from the configured session key. The default `NullTeamResolver` selects no team.

For domain, tenancy-package, or header-based selection, implement:

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Inlay\AuthorizationSpatie\Contracts\TeamResolver;

final class CurrentTenantResolver implements TeamResolver
{
    public function resolve(Request $request): int|string|Model|null
    {
        return $request->attributes->get('tenant');
    }
}
```

`SetPermissionTeam` is a no-op when Spatie teams are disabled. Otherwise it saves the previous team, sets the request team, clears configured loaded user relations, invokes the next middleware, and restores the previous team in `finally`. This prevents team state leaking between requests in long-running workers.

## Development

```bash
vendor/bin/pest tests/AuthorizationSpatieTest.php
```

Related official packages: lean `inlayphp/inlay` can use native Gate/Policies without this adapter; `inlayphp/authorization` defines the registry/contract; optional `inlayphp/permission-manager` provides role, permission, assignment, and audit UI on top of this adapter.
