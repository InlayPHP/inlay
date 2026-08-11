# Inlay Permission Manager

[![Packagist](https://img.shields.io/packagist/v/inlayphp/permission-manager?style=flat-square&label=packagist)](https://packagist.org/packages/inlayphp/permission-manager)
[![PHP](https://img.shields.io/packagist/dependency-v/inlayphp/permission-manager/php?style=flat-square)](https://packagist.org/packages/inlayphp/permission-manager)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../LICENSE)

**Dcat-inspired role and permission administration plugin for Inlay panels**

`inlayphp/permission-manager` is the official optional access-administration plugin for Inlay panels. It provides Dcat-inspired role and permission resources, user-role assignment, and an ability audit while keeping Laravel Gate/Policies authoritative. Spatie roles are reusable bundles of atomic abilities, not a competing authorization layer.

## Optional package boundary

The lean `inlayphp/inlay` installation intentionally does not bundle permission storage or administration screens. Install this package only when an application needs administrators to manage roles and permission bundles from an Inlay panel.

This is a standalone Composer plugin package:

- it is versioned and installed independently from `inlayphp/inlay`;
- Composer brings in `inlayphp/authorization-spatie` and the required Inlay form/table/resource contracts;
- Laravel package discovery registers its service provider;
- adding `PermissionManagerPlugin` to a panel is still explicit, so installation alone does not expose routes or navigation;
- the React page package is optional and separate from the PHP plugin.

Applications that only use Laravel Policies/Gates do not need this package. Applications that need Spatie-backed role bundles but provide their own UI can install `inlayphp/authorization-spatie` alone.

## Install

```bash
composer require inlayphp/permission-manager
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan migrate
php artisan inlay:permissions:sync
```

`inlayphp/permission-manager` already requires the official Spatie adapter; it does not need to be listed twice in the Composer command. Publish Spatie's configuration/migrations according to the installed Spatie version before migrating. The adapter's own configuration can be published with `--tag=inlay-authorization-spatie-config`.

For the supplied React pages:

```bash
pnpm add @inlayphp/permission-manager-react
```

The authenticatable user model must use Spatie's trait:

```php
use Spatie\Permission\Traits\HasRoles;

final class User extends Authenticatable
{
    use HasRoles;
}
```

By default, the package reads `auth.providers.users.model`. Override it with `INLAY_PERMISSION_USER_MODEL` or publish configuration:

```bash
php artisan vendor:publish --tag=inlay-permission-manager-config
```

## Register the plugin

```php
use Inlay\PermissionManager\PermissionManagerPlugin;

return $panel
    ->resources([
        UserResource::class,
        OrderResource::class,
    ])
    ->plugin(PermissionManagerPlugin::make());
```

Register application resources before the plugin so the ability registry is complete when role forms and audits render. The plugin adds:

- role CRUD at `/roles` with a searchable permission matrix;
- permission CRUD at `/permissions`;
- user-role listing/editing at `/access/users`;
- ability synchronization audit at `/access/audit`;
- Access navigation items and owned ability definitions.

Disable user assignment when accounts are managed elsewhere:

```php
PermissionManagerPlugin::make()->manageUsers(false)
```

This leaves roles, permissions, and audit enabled.

## Authorization model

Resource operations use normal namespaced Gate abilities such as `roles.viewAny`, `roles.create`, `roles.update`, `roles.delete`, and their `permissions.*` counterparts. Additional plugin abilities are:

- `access-users.viewAny` and `access-users.update`;
- `access-audit.view`.

All controller and Resource checks flow through `AuthorizationManager` to Laravel Gate. A configured super-admin role receives the adapter's Gate-before behavior; the permission manager also prevents that role from being renamed or deleted. Define Policies for contextual restrictions and use roles only to grant the underlying ability names.

After Resources or plugins change, synchronize stored Spatie permissions:

```bash
php artisan inlay:permissions:sync
php artisan inlay:permissions:sync --dry-run
php artisan inlay:permissions:sync --prune --force
```

Missing and stale permissions are reported. Stale rows are never pruned without both explicit flags. See `inlayphp/authorization-spatie` for guard selection, super-admin configuration, cache reset, and request-scoped teams.

## Role, permission, and user workflows

Role forms serialize `name`, `guard_name`, and a `permissions` checkbox list sourced from `AbilityRegistry`. Saving calls Spatie `syncPermissions()`. Role lists include permission counts. Permission forms manage atomic `name` and `guard_name` values.

User access pages require a model with `roles()` and `syncRoles()`. The list is searchable, shows role counts, and exposes a Manage roles action. The edit form loads roles for `inlay-authorization-spatie.default_guard`; updates validate selected role names before calling `syncRoles()`.

The audit page uses contract `inlay.permission-manager.audit.v1`:

```json
{
  "contract": "inlay.permission-manager.audit.v1",
  "guard": "web",
  "summary": { "registered": 20, "synced": 19, "missing": 1, "stale": 2 },
  "abilities": [{
    "name": "orders.update",
    "label": "Update",
    "group": "Orders",
    "owner": "App\\Inlay\\Resources\\OrderResource",
    "synced": true,
    "roles": ["manager"],
    "roleCount": 1
  }],
  "stale": []
}
```

This is diagnostic data; synchronization remains an explicit console operation.

## Inertia components

The PHP package renders seven component keys:

- `inlay-permission-manager/roles/index`
- `inlay-permission-manager/roles/form`
- `inlay-permission-manager/permissions/index`
- `inlay-permission-manager/permissions/form`
- `inlay-permission-manager/users/index`
- `inlay-permission-manager/users/form`
- `inlay-permission-manager/audit/index`

Merge `permissionManagerPages` from `@inlayphp/permission-manager-react` into the app resolver. The list/form pages consume the standard `inlay.resources.v1`, forms, and tables contracts. See the renderer README for exact props and theming.

## Theming

The React and Vue pages inherit the panel's semantic theme variables for
inputs, buttons, tables, permission matrices, status notices, and dialogs. A
page can also receive a local token map when it is rendered outside a Panel:

```tsx
<RoleIndexPage
  {...props}
  theme={{
    accent: '#7c3aed',
    controlBorder: '#d8b4fe',
    buttonHeight: '2.75rem',
    successSurface: 'rgb(34 197 94 / 0.1)',
    'table-row-hover': '#faf5ff',
  }}
 />
```

Use semantic names (`surface`, `muted`, `dangerSurface`, `controlHeight`, and
similar) instead of palette classes. The same token map is forwarded to the
Form and Table renderers, and custom names are scoped to the page so a second
panel cannot be restyled accidentally. Dark-mode values should be supplied by
the parent Panel's PHP `Theme::darkTokens()` contract; status surfaces and
focus rings then switch together with the rest of the admin UI.

## Security guidance

- Protect the panel with authentication middleware before registering the plugin.
- Do not authorize based on client page visibility or submitted role names.
- Prefer role assignment over direct per-user permissions to keep audits understandable.
- Keep guards consistent; roles and permissions from another guard are not interchangeable.
- Limit audit and permission CRUD to trusted administrators.
- Treat `--prune --force` as destructive and review dry-run output first.

## Testing

```bash
vendor/bin/pest tests/AuthorizationSpatieTest.php
(cd playground/laravel-react && php artisan test tests/Feature/PermissionManagerTest.php)
pnpm --filter @inlayphp/permission-manager-react test -- --run
pnpm --filter @inlayphp/permission-manager-react typecheck
pnpm --filter @inlayphp/permission-manager-react build
```

Related official packages: the lean `inlayphp/inlay` distribution supplies the panel foundation; `inlayphp/authorization` owns Gate integration and ability metadata; optional `inlayphp/authorization-spatie` stores role bundles; `inlayphp/resources`, `inlayphp/forms`, and `inlayphp/tables` provide the PHP contracts rendered by this plugin.
