# Inlay Widgets for Vue

[![npm](https://img.shields.io/npm/v/@inlayphp/widgets-vue?style=flat-square)](https://www.npmjs.com/package/@inlayphp/widgets-vue)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../../LICENSE)

**Vue renderer for Inlay dashboard widgets**

`@inlayphp/widgets-vue` is the Vue 3 renderer for the PHP `inlay.widget-dashboard.v1` contract. It supports stats and sparklines, lightweight charts, Inlay table widgets, responsive column spans, icon registries, custom widget components, and an empty slot.

## Install

```bash
pnpm add @inlayphp/widgets-vue @inlayphp/core @inlayphp/tables-vue @inlayphp/actions-vue @inlayphp/forms-vue @inlayphp/infolists-vue vue
```

Create the serialized `inlayWidgets` prop with `inlayphp/widgets` in Laravel.

## Render

```vue
<script setup lang="ts">
import { WidgetDashboard } from '@inlayphp/widgets-vue'
import type { WidgetDashboardResource } from '@inlayphp/widgets-vue'

defineProps<{ inlayWidgets: WidgetDashboardResource }>()
</script>

<template>
  <WidgetDashboard
    :resource="inlayWidgets"
    :theme="{ accent: '#4f46e5', radius: '0.75rem' }"
    :class-names="{ grid: 'gap-8', widget: 'scroll-mt-6' }"
  >
    <template #empty>
      <p>No widgets are available for this account.</p>
    </template>
  </WidgetDashboard>
</template>
```

The component accepts `resource`, `theme`, `className`, `classNames`, `icons`, and `renderers`. It renders PHP-owned dashboard headings and tabs, filters invisible widgets, maps PHP spans and column starts to a responsive 12-column grid, validates stat links through `@inlayphp/core`, applies each stat's semantic `color` token, and passes table, form, and infolist contracts to the corresponding Inlay Vue packages. With an `@refresh` listener, lazy widgets reveal when they enter the viewport and polling widgets emit refresh events on their declared interval while visible. Without a listener, no network request is made.

## Widget actions

PHP widget `headerActions()` render beside a chart/table heading, while `footerActions()` render below the surface. Stats-overview widgets place the same actions above and below their stat grid. Actions use the shared `@inlayphp/actions-vue` runtime and `@inlayphp/forms-vue` renderer, so confirmation modals, action forms, lifecycle endpoints, and custom executors are consistent with resources and tables:

```vue
<WidgetDashboard
  :resource="inlayWidgets"
  :action-executor="(context) => myActionExecutor(context)"
  :action-input="{ parameters: { tenant: 'acme' } }"
/>
```

If `actionExecutor` is omitted, safe non-lifecycle URLs use an Inertia visit and lifecycle actions use `executeActionEndpoint()`.

## Icons and custom renderers

Icon registry keys match `Stat::icon()` names. A `fallback` component handles unknown icons:

```vue
<WidgetDashboard
  :resource="inlayWidgets"
  :icons="{ users: UsersIcon, fallback: MetricIcon }"
/>
```

Replace a widget type with a Vue component:

```vue
<WidgetDashboard
  :resource="inlayWidgets"
  :renderers="{ chart: ProductionChart }"
/>
```

Custom components receive `widget` and `theme` props. Narrow `widget.type` in TypeScript before reading type-specific fields. This is the extension point for full line/doughnut charts, polling wrappers, maps, or community widget implementations.

## Payload and types

`WidgetDashboardResource` contains PHP-owned heading metadata, optional tabs, `columns`, and `widgets`. The exported `WidgetResource` union distinguishes:

- `StatsOverviewWidget` with `columns` and typed `stats`;
- `ChartWidget` with `chartType`, labels, and datasets;
- `TableWidget` with a serialized table.
- `FormWidget` with a serialized form.
- `InfolistWidget` with a serialized infolist.

All share the `inlay.widgets.v1` base contract: name, label, description, span, sort, visibility, polling interval, and lazy state. The default chart is an accessible comparative bar surface regardless of `chartType`; supply a custom renderer when type-specific chart geometry matters.

## Theme and classes

`theme` supports accent, surface, muted surface, foreground, muted, border, danger, success, warning, info, radius, and shadow. Defaults inherit panel `--inlay-*` variables.

`classNames` targets `root`, `grid`, `widget`, `stats`, `stat`, `chart`, and `table`. Stable `data-slot` hooks mirror the React renderer, including `widget-actions`, and widget wrappers expose `data-widget`.

The package ships Tailwind utility markup rather than compiled CSS. Tailwind v4
applications should scan all installed Inlay renderers from the application
stylesheet:

```css
@source '../../node_modules/@inlayphp/*/src/**/*.{ts,tsx,vue}';
```

## Accessibility and security

The dashboard is labelled, decorative icons/sparklines are hidden, safe linked stats remain keyboard accessible, and chart regions expose labels and titled values. The application must still authorize and scope every PHP query; hiding a widget in Vue cannot protect sensitive data already serialized.

## Verify

```bash
pnpm --filter @inlayphp/widgets-vue test -- --run
pnpm --filter @inlayphp/widgets-vue typecheck
pnpm --filter @inlayphp/widgets-vue build
```

Related packages: `inlayphp/widgets`, `@inlayphp/tables-vue`, `@inlayphp/core`, and `@inlayphp/widgets-react`.
