# Inlay Support

[![Packagist](https://img.shields.io/packagist/v/inlayphp/support?style=flat-square&label=packagist)](https://packagist.org/packages/inlayphp/support)
[![PHP](https://img.shields.io/packagist/dependency-v/inlayphp/support/php?style=flat-square)](https://packagist.org/packages/inlayphp/support)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../LICENSE)

**Shared serializable contracts for Inlay packages**

`inlayphp/support` provides small renderer-neutral value objects shared across Inlay packages. It currently defines the safe conditional-expression contract and the URL allow-list used before values cross an Inertia boundary.

## Install

```bash
composer require inlayphp/support
```

Most applications receive it transitively through Forms, Tables, Infolists or Schemas.

## Conditions

PHP closures cannot be serialized safely to a browser. `Condition` expresses a deliberately small, allow-listed operation instead:

```php
use Inlay\Support\Condition;

Condition::make('account_type', 'company');
Condition::make('role', 'guest', 'not-equals');
Condition::make('country', ['HK', 'SG'], 'in');
Condition::truthy('enabled');
Condition::falsy('archived');
Condition::filled('email');
Condition::blank('company_name');

Condition::all(
    Condition::make('account_type', 'company'),
    Condition::any(
        Condition::truthy('verified'),
        Condition::filled('tax_id'),
    ),
    Condition::not(Condition::truthy('suspended')),
);
```

Supported operators:

| Operator | Meaning |
| --- | --- |
| `equals` / `not-equals` | Strict value comparison in frontend adapters. |
| `in` / `not-in` | Membership in the supplied array; the constructor requires an array value. |
| `truthy` / `falsy` | Boolean truthiness check. |
| `filled` / `blank` | Empty-state check for strings, arrays, null and values. |

Paths must be non-empty and may be dotted, for example `billing.address.country`.

Use `Condition::all(...)`, `Condition::any(...)`, and `Condition::not(...)` to build nested boolean expressions. `all` and `any` require at least one child; `not` accepts exactly one. Groups can contain leaves or other groups, and frontend adapters evaluate the same recursive contract in React and Vue.

Serialized form:

```json
{ "path": "account_type", "operator": "equals", "value": "company" }
```

Composed form:

```json
{
  "logic": "all",
  "conditions": [
    { "path": "account_type", "operator": "equals", "value": "company" },
    {
      "logic": "not",
      "conditions": [{ "path": "suspended", "operator": "truthy", "value": null }]
    }
  ]
}
```

Forms use Conditions for `visibleWhen`, `hiddenWhen`, `requiredWhen` and `disabledWhen`; shared Schema and Infolist components use them for visibility, and Panels use them for navigation visibility and active state. A simple scalar `requiredWhen(..., operator: 'equals')` is also translated to Laravel's `required_if` rule. Composed client-reactive requirements must still be represented in the application's centralized Laravel validation class so the server remains authoritative.

## Safe URLs

```php
use Inlay\Support\SafeUrl;

$url = SafeUrl::from('/admin/users/1');
$url->value();
(string) $url;
json_encode($url);
```

Accepted values:

- relative application paths;
- explicit `http` and `https` URLs;
- `mailto` and `tel` links.

Rejected values include empty strings, control characters, protocol-relative URLs (`//host/path`), and unsupported or executable schemes such as `javascript:` and `data:`.

`SafeUrl` validates a value; it does not authorize a destination, sign a URL, or sanitize remote content. Continue to apply Laravel authorization and signed-route policies where appropriate. React/Vue adapters also validate state-derived URLs before rendering them.

## Testing

```bash
# monorepo root
composer test
```

When extending frontend condition evaluation, keep PHP serialization and both framework adapters covered by the same operator cases.

## Related packages

- `inlayphp/schemas` consumes Conditions.
- `inlayphp/forms` adds reactive/required/disabled conditions.
- `inlayphp/infolists` adds conditional read-only layouts and safe links.
- `inlayphp/tables` uses `SafeUrl` for linked columns.
