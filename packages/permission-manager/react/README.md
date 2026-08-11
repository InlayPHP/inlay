# Inlay Permission Manager for React

[![npm](https://img.shields.io/npm/v/@inlayphp/permission-manager-react?style=flat-square)](https://www.npmjs.com/package/@inlayphp/permission-manager-react)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../../LICENSE)

**React role and permission management screens for Inlay panels**

`@inlayphp/permission-manager-react` is the official optional React 19 renderer for the standalone `inlayphp/permission-manager` panel plugin. The Laravel package owns authorization, routing, validation, Resources, forms, and tables; this package supplies the matching Inertia pages without copying them into every application.

## Optional package boundary

This renderer is not part of the lean `inlayphp/inlay` frontend installation. Add it only when both conditions are true:

1. the Laravel application installed and registered `inlayphp/permission-manager`;
2. the target Inlay panel uses React.

Installing the npm package does not install the PHP plugin, create Spatie tables, register routes, or grant access. Conversely, Vue applications can keep the PHP plugin and provide Vue/community pages without installing this React package. Authorization always remains on the Laravel side.

## Install

```bash
pnpm add \
  @inlayphp/permission-manager-react \
  @inlayphp/forms-react \
  @inlayphp/tables-react \
  @inertiajs/react react react-dom
```

The standalone PHP plugin must also be installed and registered. It already depends on `inlayphp/authorization-spatie`, which must have its Spatie migrations and user model integration configured.

## Resolve all package pages

```tsx
import { createInertiaApp } from '@inertiajs/react'
import {
  permissionManagerPages,
  resolvePermissionManagerPage,
} from '@inlayphp/permission-manager-react'

createInertiaApp({
  resolve(name) {
    return resolvePermissionManagerPage(name)
      ?? import.meta.glob('./Pages/**/*.tsx', { eager: true })[`./Pages/${name}.tsx`]
  },
})
```

The resolver map includes:

- `inlay-permission-manager/roles/index` → `RoleIndexPage`
- `inlay-permission-manager/roles/form` → `RoleFormPage`
- `inlay-permission-manager/permissions/index` → `PermissionIndexPage`
- `inlay-permission-manager/permissions/form` → `PermissionFormPage`
- `inlay-permission-manager/users/index` → `UserAccessIndexPage`
- `inlay-permission-manager/users/form` → `UserAccessFormPage`
- `inlay-permission-manager/audit/index` → `AccessAuditPage`

If the application applies a shared panel layout in its resolver, wrap package components the same way as local pages.

## Page contracts

Role and permission list pages accept:

```ts
type ListPageProps = {
  resource: ResourceMetadata // contract: inlay.resources.v1
  table: TableResource
  flash?: { success?: string | null }
  theme?: PermissionManagerTheme
  className?: string
  classNames?: PermissionManagerClassNames
  tableClassNames?: TableClassNames
}
```

Form pages accept `resource`, `form`, optional `record`, validation `errors`, optional ability metadata, and theme. User access list/form pages receive their serialized table or form plus `{ baseUrl, label }`. Audit pages receive `inlay.permission-manager.audit.v1`, including registered/synced/missing/stale totals and per-ability owner/role coverage.

```tsx
import { RoleFormPage } from '@inlayphp/permission-manager-react'

export default function RoleForm(props: FormPageProps) {
  return <RoleFormPage {...props} theme={{
    accent: '#4f46e5',
    radius: '0.75rem',
    surface: '#ffffff',
    surfaceMuted: '#f4f4f5',
    foreground: '#18181b',
    muted: '#71717a',
    border: 'rgb(24 24 27 / 0.12)',
    danger: '#dc2626',
  }} />
}
```

All resource lists and audit datasets render through `@inlayphp/tables-react`; all editable pages render through `@inlayphp/forms-react`. The permission matrix is the only field override because group selection and ability metadata are specific to this plugin.

## Permission matrix

The role form replaces the serialized `checkbox-list` with `PermissionMatrix`. It supports search, grouping, group-select-all, selected counts, descriptions, disabled state, dark mode, and responsive columns. Destructive operations such as delete, force-delete, and restore are highlighted even when explicit metadata is absent.

Ability metadata improves labels and grouping:

```tsx
<RoleFormPage
  {...props}
  abilities={[
    {
      name: 'orders.refund',
      label: 'Refund orders',
      group: 'Orders',
      description: 'Return captured funds to the customer.',
      dangerous: true,
    },
  ]}
/>
```

`PermissionMatrix` and `PermissionMatrixProps` are exported for custom forms. It follows the `SchemaComponentRendererProps` contract from `@inlayphp/forms-react`, so it can also be registered directly as a `checkbox-list` renderer.

## Styling and accessibility

The pages use inherited `--inlay-*` panel variables. `PermissionManagerTheme` can override accent, radius, surfaces, foreground, muted text, border, danger, control height, and page shadow. Compatible values are forwarded to the shared Form and Table components, keeping plugin controls consistent with the panel.

Use `className` for the page root, `classNames` for plugin chrome, and `tableClassNames` for the shared table slots:

```tsx
<RoleIndexPage
  {...props}
  className="py-10"
  classNames={{
    header: 'border-b pb-6',
    surface: 'shadow-none',
    table: 'text-sm',
  }}
  tableClassNames={{ toolbar: 'bg-zinc-50 px-3' }}
/>
```

`PermissionManagerClassNames` supports `root`, `header`, `surface`, `flash`, `form`, `table`, `auditSummary`, and `auditTable`. The permission matrix uses fieldsets, labelled checkboxes, searchable content, status text, and dangerous-operation color cues; audit rows use the same responsive, semantic Table renderer as other resources.

The package contains Tailwind utility markup rather than a compiled stylesheet. Tailwind v4 applications should add an `@source` entry for `node_modules/@inlayphp/permission-manager-react/src`, adjusted relative to the application stylesheet.

## Authorization and security

This renderer never decides access. Laravel Gate protects every PHP route and Resource operation. Hiding a button or altering serialized props cannot grant a permission. Validation errors come from Laravel, and role changes are persisted only by the backend's validated `syncRoles()`/`syncPermissions()` workflows.

## Verify

```bash
pnpm --filter @inlayphp/permission-manager-react test -- --run
pnpm --filter @inlayphp/permission-manager-react typecheck
pnpm --filter @inlayphp/permission-manager-react build
```

Related official packages: lean `inlayphp/inlay` provides the panel foundation; optional `inlayphp/permission-manager` provides the backend plugin; optional `inlayphp/authorization-spatie` persists role bundles; `@inlayphp/forms-react` and `@inlayphp/tables-react` render its standard contracts.
