# Inlay Tables for React

[![npm](https://img.shields.io/npm/v/@inlayphp/tables-react?style=flat-square)](https://www.npmjs.com/package/@inlayphp/tables-react)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../../LICENSE)

**React renderer for Inlay PHP-first tables**

`@inlayphp/tables-react` renders the server-driven `inlay.tables.v1` contract. It includes query controls, deferred filters, responsive table scrolling, selection, editable cells, pagination and the Inlay Actions runtime.

The default UI uses a compact shared button system, high-contrast control borders and focus rings, a grouped filter surface, readable row density, full-row hover/focus-within feedback, and semantic surface tokens that inherit safely in light or dark panels.

When the PHP table is configured with `->striped()`, the renderer applies an alternating `--inlay-surface-muted` row surface while retaining the normal hover and keyboard focus states.

`recordClasses()` is resolved by PHP per record and rendered from the serialized `rowClasses` map. React does not receive or execute the callback.

Reorder sessions hide pagination by default. Set `->paginatedWhileReordering()` when the resource deliberately wants pagination controls to remain available while rows are being moved.

The PHP builder also accepts `beforeReordering()` and `afterReordering()` hooks. They receive the normalized key order and run immediately before and after the server transaction.

`reorderable(..., direction: 'desc')` is supported for tables whose ordering
column is descending. The server publishes `resource.reordering.direction` and
assigns positions so the visual order remains stable after saving.

If the reorder PATCH returns Laravel validation errors, the component keeps the
draft order and reorder controls open, displays the first message in a
dismissible `role="alert"`, and lets the visitor retry. This includes missing
position-column migrations and stale-order fingerprints.

## Install

```bash
pnpm add @inlayphp/tables-react @inlayphp/actions @inlayphp/actions-react @inlayphp/core @inertiajs/react react react-dom
composer require inlayphp/tables
```

## Basic use

```tsx
import { Table } from '@inlayphp/tables-react'
import type { TableResource } from '@inlayphp/tables-react'

export default function Users({ usersTable }: { usersTable: TableResource }) {
  return <Table resource={usersTable} />
}
```

Search, sort, filters and pagination update local query state. Without `onQueryChange`, the component performs an Inertia GET to the current path using table-prefixed query keys and preserves state/scroll.

```tsx
<Table
  resource={usersTable}
  loading={loading}
  onQueryChange={(query) => loadUsers(query)}
  onCellChange={(row, column, value) => updateCell(row.id, column.name, value)}
/>
```

Providing `onQueryChange` gives the application query ownership. The component resynchronizes when `resource.query` changes.

## Actions

Actions use `@inlayphp/actions` and `@inlayphp/actions-react`. Confirmation or modal metadata opens the accessible action dialog automatically. URL parameters are interpolated from rows and validated before navigation. Bulk URL actions merge declared data with a `records` array of primary keys.

```tsx
<Table
  resource={usersTable}
  onAction={(action, rows) => executeInApplication(action, rows)}
/>
```

Supplying `onAction` transfers execution ownership to the callback. The runtime prevents duplicate execution while an action is active.

Header actions with `download: true` render as ordinary browser links, so a
streamed CSV/PDF response is downloaded instead of being treated as an
Inertia page visit. PHP `ExportAction` sets this flag and supplies the URL.

## Custom renderers

```tsx
import type { ColumnRendererProps } from '@inlayphp/tables-react'

function MoneyColumn({ value }: ColumnRendererProps) {
  return <strong>{String(value)}</strong>
}

<Table
  resource={usersTable}
  renderers={{ column: { 'vendor-money-column': MoneyColumn } }}
  registries={appTableRegistries}
/>
```

Renderer maps have `column`, `filter`, and `action` groups. Local renderers take precedence over app-owned Core registries and built-ins. Columns and filters resolve by `type`; actions resolve by optional `type`, then `name`. Custom renderers receive normalized props and callbacks for cell/filter/action changes.

## Theme and class slots

```tsx
<Table
  resource={usersTable}
  theme={{
    accent: '#7c3aed',
    radius: '0.75rem',
    surface: '#ffffff',
    mutedSurface: '#f7f7f8',
    hover: '#f5f3ff',
    text: '#18181b',
    muted: '#71717a',
    border: 'rgb(24 24 27 / 0.12)',
    controlBorder: '#d4d4d8',
    danger: '#dc2626',
    controlHeight: '2.5rem',
  }}
  classNames={{
    toolbar: 'items-start',
    filtersPanel: 'bg-violet-50 dark:bg-violet-950/20',
    tableShell: 'shadow-md',
    row: 'data-[featured=true]:bg-violet-50',
    applyButton: 'shadow-sm',
  }}
/>
```

Class slots are `root`, `toolbar`, `filtersTrigger`, `filtersPanel`, `filterGroup`, `filterControl`, `filterIndicators`, `filterIndicator`, `filterActions`, `resetButton`, `applyButton`, `headerActions`, `bulkActions`, `tableShell`, `table`, `head`, `row`, `cell`, `rowActions`, and `pagination`. Stable `data-slot` attributes provide lower-level CSS hooks for header actions, the table head, rows/cells, action groups, and pagination pages.

Download actions (`download: true`) render as ordinary browser links so
streamed responses such as a header `ExportAction` are not interpreted as
Inertia page visits. Selection-aware bulk exports render as buttons instead;
they POST the compact selection/query descriptor and save the streamed blob,
showing server validation errors inline.

Tailwind CSS 4 projects may need:

```css
@source '../../vendor/inlayphp/tables/react/src/**/*.{ts,tsx}';
@source '../../vendor/inlayphp/actions/react/src/**/*.{ts,tsx}';
@source '../../node_modules/@inlayphp/ui/src/**/*.{ts,tsx}';
```

## Contract and exports

`TableResource` includes columns, filters, action groups, rows, pagination, selection/deferred-filter flags, query state and empty-state copy. The package exports `Table`, its theme/class/renderer prop types, and every resource/query/column/filter/action type.

## Test, typecheck and build

```bash
pnpm test -- --run
pnpm typecheck
pnpm build
```

## Related packages

- `inlayphp/tables`: PHP builder and Eloquent query allow-list.
- `@inlayphp/tables-vue`: Vue adapter.
- `inlayphp/actions`, `@inlayphp/actions-react`, and `@inlayphp/core`.
