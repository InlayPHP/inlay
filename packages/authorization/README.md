# Inlay Authorization

[![Packagist](https://img.shields.io/packagist/v/inlayphp/authorization?style=flat-square&label=packagist)](https://packagist.org/packages/inlayphp/authorization)
[![PHP](https://img.shields.io/packagist/dependency-v/inlayphp/authorization/php?style=flat-square)](https://packagist.org/packages/inlayphp/authorization)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../LICENSE)

**Vendor-neutral Laravel Gate and Policy authorization for Inlay resources and panels**

`inlayphp/authorization` is the vendor-neutral authorization kernel for Inlay. It keeps Laravel Gate and policies authoritative while adding a collision-safe registry of abilities that packages can describe, inspect, audit, and synchronize to an optional permission store.

## Installation

```bash
composer require inlayphp/authorization
```

Laravel package discovery registers `AuthorizationServiceProvider`, which provides singleton `AbilityRegistry` and `AuthorizationManager` instances. The package supports Laravel 12 and PHP 8.3+.

## Define and register abilities

```php
use Inlay\Authorization\AbilityDefinition;
use Inlay\Authorization\AbilityRegistry;

$abilities = app(AbilityRegistry::class);

$abilities->register(
    AbilityDefinition::make('orders.refund')
        ->label('Refund orders')
        ->group('Orders')
        ->description('Return captured funds to the customer.')
        ->dangerous(),
    owner: 'acme.orders',
);
```

Names must contain at least one dot and follow the package/resource-style format accepted by `AbilityDefinition`, for example `orders.viewAny` or `media.forceDelete`. Labels and groups are generated from the name unless overridden. `dangerous` is presentation/audit metadata; it does not grant or deny access.

Register definitions during application/package boot before running synchronization. Registering the same name for the same owner is idempotent. Registering it for a different owner throws, preventing two plugins from silently redefining one permission.

The registry offers `has()`, `get()`, sorted `all()`, and `owner()`. A definition serializes as:

```json
{
  "name": "orders.refund",
  "label": "Refund orders",
  "group": "Orders",
  "description": "Return captured funds to the customer.",
  "dangerous": true
}
```

## Authorize with Laravel Gate

Define Gate abilities or model policies normally. Inlay does not replace them:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('orders.refund', fn (User $user, Order $order) =>
    $user->account_id === $order->account_id && $user->can_refund
);
```

Inject `AuthorizationManager` where framework-neutral callers need a consistent API:

```php
use Inlay\Authorization\AuthorizationManager;

final class RefundOrderController
{
    public function __invoke(Order $order, AuthorizationManager $authorization)
    {
        $authorization->authorize(request()->user(), 'orders.refund', $order);

        // Perform the mutation only after authorization.
    }
}
```

- `allows()` returns `false` for an unauthenticated user.
- `inspect()` returns Laravel's `Response`, including a denial message; unauthenticated calls return `Authentication is required.`
- `authorize()` returns the successful response or throws Laravel's authorization exception. An unauthenticated user is denied.
- `abilities()` exposes the registry for audits and management UIs.

Frontend visibility based on serialized abilities is only a usability aid. Every endpoint must still apply Gate/policy authorization.

## Permission synchronization adapters

The `PermissionSynchronizer` contract permits database adapters without coupling this kernel to a roles package:

```php
$result = app(PermissionSynchronizer::class)->sync(
    guard: 'web',
    dryRun: true,
    prune: false,
);
```

`PermissionSyncResult` reports sorted `created`, `existing`, `stale`, and `deleted` names plus the `dryRun` flag. `inlayphp/authorization-spatie` provides the official Spatie Laravel Permission implementation.

## Extending

Package authors should register their definitions with a stable owner ID and use the same names in policies, actions, resources, and HTTP endpoints. Applications may build audit screens from `AbilityRegistry` or implement another `PermissionSynchronizer` for a different RBAC store.

This package intentionally does not manage users, roles, database permissions, teams, or UI. Those concerns live in adapters and plugins.

## Development

```bash
vendor/bin/pest tests/AuthorizationTest.php
```

Related packages: `inlayphp/authorization-spatie`, `inlayphp/permission-manager`, and `inlayphp/panels`.
