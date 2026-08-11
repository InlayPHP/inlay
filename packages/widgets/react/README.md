# Inlay Widgets for React

[![npm](https://img.shields.io/npm/v/@inlayphp/widgets-react?style=flat-square)](https://www.npmjs.com/package/@inlayphp/widgets-react)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../../LICENSE)

**React renderer for Inlay dashboard widgets**

`@inlayphp/widgets-react` renders the PHP `inlay.widget-dashboard.v1` contract in React 19. It includes stats with sparklines, lightweight charts, nested Inlay tables, responsive spans, icon replacement, custom widget renderers, and empty-state composition.

## Install

```bash
pnpm add @inlayphp/widgets-react @inlayphp/core @inlayphp/tables-react @inlayphp/actions-react @inlayphp/forms-react react react-dom
```

Generate the `inlayWidgets` prop with `inlayphp/widgets` in Laravel.

## Render a dashboard

```tsx
import { WidgetDashboard } from '@inlayphp/widgets-react'
import type { WidgetDashboardResource } from '@inlayphp/widgets-react'

export function Dashboard({ inlayWidgets }: { inlayWidgets: WidgetDashboardResource }) {
  return (
    <WidgetDashboard
      resource={inlayWidgets}
      theme={{ accent: '#4f46e5', radius: '0.75rem' }}
      classNames={{ grid: 'gap-8', widget: 'scroll-mt-6' }}
      empty={<p>No widgets are available for this account.</p>}
    />
  )
}
```

`WidgetDashboardResource` contains `contract: 'inlay.widget-dashboard.v1'`, a column count, and a discriminated `widgets` union. Each widget uses `contract: 'inlay.widgets.v1'` and one of `stats-overview`, `chart`, or `table`.

The renderer filters `visible: false`, maps `columnSpan` onto a responsive 12-column grid, renders safe stat URLs through `@inlayphp/core`, applies each stat's semantic `color` token, and delegates table payloads to `@inlayphp/tables-react`. With `onRefresh`, lazy widgets reveal when they enter the viewport and polling widgets refresh on their declared interval while visible. Without that callback, no network request is made.

## Widget actions

PHP widget `headerActions()` render beside a chart/table heading, while `footerActions()` render below the surface. Stats-overview widgets place the same actions above and below their stat grid. They use the shared action runtime, so confirmation modals, action forms, lifecycle endpoints, and custom executors work exactly like resource and table actions:

```tsx
<WidgetDashboard
  resource={inlayWidgets}
  actionExecutor={(context) => myActionExecutor(context)}
  actionInput={{ parameters: { tenant: 'acme' } }}
/>
```

If `actionExecutor` is omitted, safe non-lifecycle URLs use an Inertia visit and lifecycle actions use `executeActionEndpoint()`.

## Icons

Pass components or React nodes keyed by the PHP stat icon name. `fallback` handles unknown names:

```tsx
<WidgetDashboard
  resource={inlayWidgets}
  icons={{
    users: ({ className }) => <UsersIcon className={className} />,
    fallback: <GenericMetricIcon aria-hidden="true" />,
  }}
/>
```

When no icon is registered, the default UI displays the first letter of the icon name.

## Custom widget renderers

Replace any widget type without changing the PHP contract:

```tsx
import type { WidgetRendererProps } from '@inlayphp/widgets-react'

function ProductionChart({ widget, theme }: WidgetRendererProps) {
  if (widget.type !== 'chart') return null
  return <EChart labels={widget.labels} datasets={widget.datasets} accent={theme?.accent} />
}

<WidgetDashboard
  resource={inlayWidgets}
  renderers={{ chart: ProductionChart }}
/>
```

Custom renderers receive `{ widget, theme }`. This is the intended extension point for doughnut/line charts, live widgets, maps, third-party packages, or domain-specific payloads that retain one of the current type keys.

## Theme and structural customization

`theme` supports accent, surface, muted surface, foreground, muted, border, danger, success, warning, info, radius, and shadow tokens. The built-in values fall back to the panel's `--inlay-*` variables.

`classNames` targets `root`, `grid`, `widget`, `stats`, `stat`, `chart`, and `table`. Stable hooks include `data-slot="widget-dashboard"`, `widget-grid`, `widget`, `widget-surface`, `widget-actions`, `stats-overview`, `stat`, `chart-widget`, and `widget-table`; each wrapper also has `data-widget={name}`.

The package contains Tailwind utility markup, not compiled CSS. Tailwind v4 applications should add an `@source` entry for `node_modules/@inlayphp/widgets-react/src`, adjusted relative to the app stylesheet.

## Accessibility and security

The dashboard is a labelled section, chart comparisons expose an image role/label and titled values, decorative sparklines/icons are hidden, and linked stat cards retain keyboard focus styling. PHP validates stat URLs, while the renderer calls `isSafeUrl()` again before producing anchors.

Frontend visibility is not authorization. Providers must scope dashboard data to the authenticated user before serialization.

## Verify

```bash
pnpm --filter @inlayphp/widgets-react test -- --run
pnpm --filter @inlayphp/widgets-react typecheck
pnpm --filter @inlayphp/widgets-react build
```

Related packages: `inlayphp/widgets`, `@inlayphp/tables-react`, `@inlayphp/core`, and `@inlayphp/widgets-vue`.
