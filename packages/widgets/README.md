# Inlay Widgets

[![Packagist](https://img.shields.io/packagist/v/inlayphp/widgets?style=flat-square&label=packagist)](https://packagist.org/packages/inlayphp/widgets)
[![PHP](https://img.shields.io/packagist/dependency-v/inlayphp/widgets/php?style=flat-square)](https://packagist.org/packages/inlayphp/widgets)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../LICENSE)

**PHP-first dashboard widgets for Inlay panels and Inertia applications**

`inlayphp/widgets` is the PHP-first dashboard description layer for Inlay panels and Inertia applications. Laravel builds stats, charts, and table widgets; React or Vue renders the versioned payload. Business queries and authorization stay in PHP.

## Install

```bash
composer require inlayphp/widgets
pnpm add @inlayphp/widgets-react
# or: pnpm add @inlayphp/widgets-vue
```

The Composer package depends on `inlayphp/tables` for `TableWidget`. Panel integration is available when `inlayphp/panels` is installed.

## Build a dashboard

```php
use Inlay\Widgets\ChartWidget;
use Inlay\Actions\Action;
use Inlay\Widgets\Stat;
use Inlay\Widgets\StatsOverviewWidget;
use Inlay\Widgets\TableWidget;
use Inlay\Widgets\WidgetDashboard;

$dashboard = WidgetDashboard::make()
    ->columns(12)
    ->widgets([
        StatsOverviewWidget::make('overview')
            ->label('Today')
            ->columns(3)
            ->headerActions([
                Action::make('create-order')->label('New order')->url('/admin/orders/create'),
            ])
            ->footerActions([
                Action::make('view-orders')->label('View all orders')->url('/admin/orders'),
            ])
            ->stats([
                Stat::make('Revenue', '$42,180')
                    ->description('12% above yesterday')
                    ->icon('currency-dollar')
                    ->color('success')
                    ->trend('up')
                    ->url('/admin/orders')
                    ->chart([18, 21, 20, 25, 29, 31, 36]),
                Stat::make('Orders', 284),
            ]),
        ChartWidget::make('signups')
            ->label('New signups')
            ->description('Last five weekdays')
            ->chartType('bar')
            ->labels(['Mon', 'Tue', 'Wed', 'Thu', 'Fri'])
            ->dataset('Users', [12, 18, 15, 27, 31], '#4f46e5')
            ->columnSpan(8)
            ->sort(10),
        TableWidget::make('latest-users')
            ->label('Latest users')
            ->table($usersTable)
            ->columnSpan(4)
            ->sort(20),
    ]);
```

Widget names are stable lowercase identifiers. Shared methods are `label()`, `description()`, `columnSpan()` (`1`–`12` or `full`), `sort()`, `visible()`, `poll()`, `lazy()`, `headerActions()`, and `footerActions()`. Header and footer actions use the same `Inlay\Actions\Action` contract as panel, resource, and table actions. Invisible widgets are removed when the dashboard is assembled; duplicates are rejected; remaining widgets sort by order then name.

## Refreshing, lazy loading, and layout

The dashboard's `columns()` count is what the grid is actually laid out with, and a widget's
`columnSpan()` is narrowed to fit rather than allowed to overflow it — so `columns(6)` with a
widget spanning `8` gives a six-column grid and a full-width widget.

`poll()` and `lazy()` are honoured by both stock renderers. A lazy widget shows a placeholder
until it is scrolled into view, then renders and asks once for fresh data; where nothing can
observe it (server rendering, or no `IntersectionObserver`) it renders immediately rather than
staying blank. A polling widget asks again on its interval, but only while it is shown and the
tab is being looked at, so a dashboard left open in a background tab stops asking.

Both ask through a callback you supply, so the transport stays yours:

```tsx
import { router } from '@inertiajs/react'
import { WidgetDashboard } from '@inlayphp/widgets-react'

<WidgetDashboard
  onRefresh={(name) => router.reload({ only: ['widgets'], data: { widget: name } })}
  resource={widgets}
/>
```

```vue
<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { WidgetDashboard } from '@inlayphp/widgets-vue'

function refresh(name: string) {
  router.reload({ only: ['widgets'], data: { widget: name } })
}
</script>

<template>
  <WidgetDashboard :resource="widgets" @refresh="refresh" />
</template>
```

Without a callback nothing is requested at all. `poll(null)` turns polling off again.

### Cache request-aware widget output

Provider output is request-scoped by default. When a provider performs an
expensive query, it can opt into caching with `CacheableWidgets`:

```php
use Illuminate\Http\Request;
use Inlay\Widgets\Contracts\CacheableWidgets;

final class AdminDashboardWidgets implements CacheableWidgets
{
    public function cacheKey(Request $request): string
    {
        $user = $request->user();

        return 'inlay.dashboard.'.$user->getAuthIdentifier().'.'.$request->getLocale();
    }

    public function cacheTtl(Request $request): int
    {
        return 30;
    }

    public function widgets(Request $request): iterable
    {
        yield StatsOverviewWidget::make('account')
            ->stats([
                Stat::make('Open orders', $request->user()->orders()->open()->count()),
            ]);
    }
}
```

The resolver uses Laravel's bound cache repository and stores the complete
server-built widget objects for the declared TTL. The key is always supplied
by the application: include the authenticated user, tenant, locale, date range,
or any other input that changes the result. Caching never grants authorization
and does not replace the provider's query scope. If no cache repository is
bound, an explicitly cacheable provider fails clearly instead of silently
serving uncached data.

## Request-aware panel providers

Use `ProvidesWidgets` when data depends on the authenticated user or request:

```php
use Illuminate\Http\Request;
use Inlay\Widgets\Contracts\ProvidesWidgets;
use Inlay\Widgets\Stat;
use Inlay\Widgets\StatsOverviewWidget;

final class AdminDashboardWidgets implements ProvidesWidgets
{
    public function widgets(Request $request): iterable
    {
        yield StatsOverviewWidget::make('account')
            ->stats([
                Stat::make('Open orders', $request->user()->orders()->open()->count()),
            ]);
    }
}

$panel
    ->widget(AdminDashboardWidgets::class)
    ->widget(ChartWidget::make('traffic')->labels([]));
```

`Panel::widget()` accepts a Widget, provider instance, or provider class. `Panel::widgets()` accepts an iterable. The dashboard controller resolves providers through the container and sends the resulting dashboard as the `inlayWidgets` Inertia prop.

Outside a panel, inject `WidgetResolver` and call `resolve($sources, $request)`.

### Discover providers

For larger applications, discover provider classes using the same explicit
directory/namespace boundary used by fluent panels:

```php
$panel->discoverWidgets(
    directories: app_path('Inlay/Widgets'),
    namespace: 'App\\Inlay\\Widgets',
);
```

Only concrete classes implementing `ProvidesWidgets` are registered. Discovery
deduplicates and sorts providers, and caches only that class list for the current
PHP process. Provider instances and their data are still resolved for every
request, so authorization and tenant scoping remain request-safe. Call
`WidgetDiscovery::clear()` (or pass `cache: false` to `discover()`) for a
long-running worker or a development reload after adding a provider.

## Serialized contracts

The dashboard contract is `inlay.widget-dashboard.v1`:

```json
{
  "contract": "inlay.widget-dashboard.v1",
  "columns": 12,
  "widgets": []
}
```

Every widget uses `inlay.widgets.v1` and includes `type`, `name`, `label`, `description`, `columnSpan`, `sort`, `visible`, `pollingInterval`, `lazy`, `headerActions`, and `footerActions`.

- `stats-overview` adds `columns` and `stats`; each stat includes label, value, description, icon, semantic color, safe URL, trend, and sparkline data.
- `chart` adds `chartType` (`line`, `bar`, or `doughnut`), labels, and numeric datasets with optional colors.
- `table` adds the serialized `inlayphp/tables` resource.

The built-in frontend chart is a lightweight accessible comparison visualization. `chartType` remains in the contract so a custom renderer can use Chart.js, ECharts, or another chart system.

## Validation and security

PHP builders validate widget identifiers, dashboard/overview column ranges, spans, chart types, numeric datasets, trend names, semantic color tokens, and stat URLs through `SafeUrl`. These checks protect the contract, not data access.

Authorize and scope every query inside the provider. A hidden widget is not an authorization boundary, and serialized dashboard values should contain only data the current user may see. Custom frontend renderers must keep URL validation when rendering links.

## Customization

Both adapters inherit panel CSS variables, accept typed `theme` and `classNames`, expose stable `data-slot` attributes, accept icon registries, and support custom renderers keyed by `stats-overview`, `chart`, or `table`. React accepts an `empty` node; Vue exposes an `empty` slot.

## Testing

```bash
vendor/bin/pest tests/ThemeWidgetTest.php
pnpm --filter @inlayphp/widgets-react test -- --run
pnpm --filter @inlayphp/widgets-vue test -- --run
```

Run each renderer's `typecheck` and `build` scripts as well. Related packages: `inlayphp/panels` supplies dashboard integration, `inlayphp/tables` supplies table payloads, and `inlayphp/support` supplies safe URL handling.
