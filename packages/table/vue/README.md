# Inlay Tables for Vue

[![npm](https://img.shields.io/npm/v/@inlayphp/tables-vue?style=flat-square)](https://www.npmjs.com/package/@inlayphp/tables-vue)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../../LICENSE)

**Vue renderer for Inlay PHP-first tables**

`@inlayphp/tables-vue` renders `inlay.tables.v1` resources with Vue 3. It provides search, sorting, deferred filters, pagination, bulk selection, editable columns, custom renderers and the Inlay Actions dialog/runtime.

When the PHP table is configured with `->striped()`, the renderer applies an alternating `--inlay-surface-muted` row surface while retaining the normal hover and keyboard focus states.

`recordClasses()` is resolved by PHP per record and rendered from the serialized `rowClasses` map. Vue does not receive or execute the callback.

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
pnpm add @inlayphp/tables-vue @inlayphp/actions @inlayphp/actions-vue @inlayphp/core @inlayphp/ui-vue @inertiajs/vue3 vue
composer require inlayphp/tables
```

## Basic use

```vue
<script setup lang="ts">
import { Table } from '@inlayphp/tables-vue'
import type { TableResource } from '@inlayphp/tables-vue'

defineProps<{ usersTable: TableResource }>()
</script>

<template>
  <Table :resource="usersTable" />
</template>
```

The default query transport performs an Inertia GET to the current path using table-prefixed keys. Set `manual` when the parent owns queries:

```vue
<Table
  :resource="usersTable"
  :loading="loading"
  manual
  @query-change="loadUsers"
  @cell-change="persistCell"
  @action="auditAction"
/>
```

The adapter watches `resource.query` and synchronizes its current and draft filter state when a new server payload arrives.

## Actions

Confirmed and modal actions use the accessible `@inlayphp/actions-vue` dialog. By default, a safe resolved URL is visited through Inertia. Bulk actions send selected primary keys as `records`.

For application-owned execution, pass `actionExecutor`:

```vue
<script setup lang="ts">
import type { TableActionExecutor } from '@inlayphp/tables-vue'

const execute: TableActionExecutor = async (action, rows, context) => {
  await api.run(context.url, context.input)
}
</script>

<template>
  <Table :resource="usersTable" :action-executor="execute" />
</template>
```

`manual` controls table queries only; it does not change action ownership. The `action` event is still emitted when an executor is provided.

Header actions with `download: true` render as ordinary browser links, so a
streamed CSV/PDF response is downloaded instead of being treated as an
Inertia page visit. PHP `ExportAction` sets this flag and supplies the URL.

## Custom renderers

```vue
<script setup lang="ts">
import MoneyColumn from './MoneyColumn.vue'

const renderers = {
  column: { 'vendor-money-column': MoneyColumn },
}
</script>

<template>
  <Table
    :resource="usersTable"
    :renderers="renderers"
    :registries="appTableRegistries"
  />
</template>
```

Local `column`, `filter`, and `action` maps take precedence over Core registries and built-ins. Columns and filters resolve by `type`; actions resolve by optional `type`, then `name`. Renderer prop contracts are exported as `ColumnRendererProps`, `FilterRendererProps`, and `ActionRendererProps`.

## Theme and class slots

```vue
<Table
  :resource="usersTable"
  :theme="{
    accent: '#7c3aed',
    radius: '0.75rem',
    surface: '#fff',
    text: '#18181b',
    muted: '#71717a',
    border: 'rgb(24 24 27 / 0.12)',
    danger: '#dc2626',
    controlHeight: '2.5rem',
  }"
  :class-names="{
    filtersPanel: 'bg-violet-50 dark:bg-violet-950/20',
    applyButton: 'shadow-sm',
  }"
/>
```

Available class slots are `root`, `toolbar`, `filtersTrigger`, `filtersPanel`, `filterGroup`, `filterControl`, `filterIndicators`, `filterIndicator`, `filterActions`, `resetButton`, and `applyButton`. Theme values fall back to shared panel variables.

The complete class-slot surface is shared with the React renderer: add
`headerActions`, `bulkActions`, `tableShell`, `table`, `head`, `row`, `cell`,
`rowActions`, and `pagination` when styling the table chrome, selection bar,
rows, or action column. Both renderers also emit the same `data-slot` values,
so a theme stylesheet does not need a Vue-specific selector. Server-side
`->striped()` and `recordClasses()` remain renderer-neutral; the former uses
the muted surface token and the latter publishes a safe per-record class map.

Download actions (`download: true`) render as ordinary browser links so
streamed responses such as a header `ExportAction` are not interpreted as
Inertia page visits. Selection-aware bulk exports render as buttons instead;
they POST the compact selection/query descriptor and save the streamed blob,
showing server validation errors inline.

Tailwind CSS 4 projects may need to source this package, the shared UI vocabulary,
and `actions-vue` explicitly:

```css
@source '../../node_modules/@inlayphp/*/src/**/*.{ts,tsx,vue}';
```

## Contract and exports

The package exports `Table`, all table resource/query types, renderer maps and registries, `TableActionExecutor`, `TableTheme`, and `TableClassNames`.

## Test, typecheck and build

```bash
pnpm test -- --run
pnpm typecheck
pnpm build
```

## Related packages

- `inlayphp/tables` and `@inlayphp/tables-react`.
- `inlayphp/actions`, `@inlayphp/actions-vue`, and `@inlayphp/core`.
