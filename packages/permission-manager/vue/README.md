# Inlay Permission Manager for Vue

[![npm](https://img.shields.io/npm/v/@inlayphp/permission-manager-vue?style=flat-square)](https://www.npmjs.com/package/@inlayphp/permission-manager-vue)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../../LICENSE)

**Vue role and permission management screens for Inlay panels**

`@inlayphp/permission-manager-vue` renders the Inertia pages published by
`inlayphp/permission-manager`. It is the Vue counterpart of
`@inlayphp/permission-manager-react` and answers the same page names with the same
props, so a panel can switch renderers without the PHP plugin changing.

## Optional package boundary

This renderer is not part of the lean `inlayphp/inlay` frontend installation. Add it
only when both conditions are true:

1. the Laravel application installed and registered `inlayphp/permission-manager`;
2. the target Inlay panel uses Vue.

Installing the npm package does not install the PHP plugin, create Spatie tables,
register routes, or grant access. Authorization always remains on the Laravel side —
these pages render what the server already decided the visitor may see.

## Install

```bash
pnpm add \
  @inlayphp/permission-manager-vue \
  @inlayphp/forms-vue \
  @inlayphp/tables-vue \
  @inertiajs/vue3 vue
```

The standalone PHP plugin must also be installed and registered. It depends on
`inlayphp/authorization-spatie`, which needs its Spatie migrations and user model
integration configured.

## Pages

Register these against the page names the plugin publishes:

| Component | Page |
| --- | --- |
| `RoleIndexPage` | `inlay-permission-manager/roles/index` |
| `RoleFormPage` | `inlay-permission-manager/roles/form` |
| `PermissionIndexPage` | `inlay-permission-manager/permissions/index` |
| `PermissionFormPage` | `inlay-permission-manager/permissions/form` |
| `UserAccessFormPage` | `inlay-permission-manager/users/access` |
| `AccessAuditPage` | `inlay-permission-manager/audit` |

```ts
import {
  AccessAuditPage,
  PermissionFormPage,
  PermissionIndexPage,
  RoleFormPage,
  RoleIndexPage,
  UserAccessFormPage,
} from '@inlayphp/permission-manager-vue'
```

`PageShell`, `Surface`, `Flash`, `PrimaryLink`, `BackLink`, and `PermissionMatrix` are
exported as well, for an application that wants to compose its own screens from the
same parts rather than mount the pages as published.

## Styling

The pages read the `--inlay-*` custom properties the surrounding panel declares and
name their elements with the shared `data-slot` vocabulary, so a stylesheet written
against the React pages selects the same elements here. The permission matrix's
controls come from `@inlayphp/ui`, which is where the shared control class lives so the
two renderers cannot drift apart.
