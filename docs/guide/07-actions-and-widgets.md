# Actions, notifications, and widgets

Actions make a button or menu item reusable across Forms, Tables, Resources,
Panels, and Widgets. Widgets make dashboard data reusable without moving the
query into React or Vue. Notifications provide the feedback after a server
operation completes.

## Actions

Install the PHP package when using actions outside the meta-package:

```bash
composer require inlayphp/actions
npm install @inlayphp/actions-react
# Vue: @inlayphp/actions-vue
```

A presentation-only action is just a safe link:

```php
Action::make('docs')
    ->label('Read documentation')
    ->url('/docs')
    ->link();
```

Use `BulkAction` when the renderer must collect selected record keys:

```php
BulkAction::make('delete')
    ->url('/admin/users/bulk-delete')
    ->method('delete')
    ->color('danger')
    ->requiresConfirmation();
```

Action presentation is declared once:

```php
Action::make('edit')
    ->label('Edit customer')
    ->icon('pencil')
    ->outlined()
    ->size(ActionSize::Small)
    ->tooltip('Edit this customer');
```

Use `iconButton()` only when the icon has an accessible label. Use `download()`
for a URL that returns a streamed file; it changes browser transport but does
not grant access.

## Lifecycle actions

For a mutation owned by the action, declare authorization, validation, hooks,
and the handler in PHP:

```php
Action::make('publish')
    ->modal(
        ActionModal::make('Publish this post?')
            ->description('Readers will see this post immediately.')
            ->submitLabel('Publish'),
    )
    ->authorizeUsing(
        fn (Request $request, Post $record): bool =>
            $request->user()?->can('publish', $record) === true,
    )
    ->rules([
        'note' => ['nullable', 'string', 'max:500'],
    ])
    ->before(fn (Post $record) => audit('publish.started', $record))
    ->databaseTransaction()
    ->action(function (Post $record, array $data): array {
        $record->update(['published_at' => now()]);

        return ['id' => $record->getKey()];
    })
    ->after(fn (Post $record) => event(new PostPublished($record)))
    ->successNotificationTitle('Post published.');
```

Lifecycle actions deny authorization by default. The execution order is:

```text
authorize
  → beforeFormValidated
  → validate
  → afterFormValidated
  → mutateFormDataUsing
  → before
  → action
  → after
```

`databaseTransaction()` wraps the mutation. `halt()` keeps a modal open with a
message; `cancel()` closes it without treating the action as successful.

Do not use footer arguments as a replacement for authorization or validation.
They are request input and must be treated as untrusted.

## Table hosting

`Route::inlayTable()` hosts row, header, and bulk lifecycle actions on the same
table endpoint. In a Resource, action records are resolved through the
Resource's scoped query before authorization. For an external data source,
provide explicit URLs and resolve the record in the remote system yourself.

## Notifications

Install the transport:

```bash
composer require inlayphp/notifications
npm install @inlayphp/notifications-react
# Vue: @inlayphp/notifications-vue
```

Send a session-backed toast after a redirect:

```php
use Inlay\Notifications\Notification;

Notification::make('Profile updated.')
    ->body('Your account details are saved.')
    ->success()
    ->action('View profile', route('profile.edit'))
    ->send();
```

Mount one renderer in the application shell:

```tsx
<Notifications notifications={page.props.inlayNotifications} />
```

The contract supports `success`, `info`, `warning`, and `danger`. Use
`persistent()` or `duration(null)` when the visitor must dismiss it explicitly.
URLs are checked by the shared safe URL policy.

For a persistent notification center:

```bash
php artisan vendor:publish --tag=inlay-notifications-migrations
php artisan migrate
```

```php
Notification::make('Import finished')
    ->success()
    ->sendToDatabase($request->user());

$rows = app(NotificationManager::class)
    ->databaseNotifications($request->user(), unreadOnly: true);
```

Authorize read/mark-as-read routes in the application. The manager scopes rows
by both notifiable morph class and key.

## Widgets

Install the widget package and a renderer:

```bash
composer require inlayphp/widgets
npm install @inlayphp/widgets-react
# Vue: @inlayphp/widgets-vue
```

Build a dashboard in PHP:

```php
use Inlay\Actions\Action;
use Inlay\Widgets\ChartWidget;
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
                Action::make('create-order')
                    ->label('New order')
                    ->url('/admin/orders/create'),
            ])
            ->stats([
                Stat::make('Revenue', '$42,180')
                    ->description('12% above yesterday')
                    ->icon('currency-dollar')
                    ->color('success')
                    ->trend('up')
                    ->chart([18, 21, 20, 25, 29, 31, 36]),
                Stat::make('Orders', 284),
            ]),
        ChartWidget::make('signups')
            ->label('New signups')
            ->chartType('bar')
            ->labels(['Mon', 'Tue', 'Wed', 'Thu', 'Fri'])
            ->dataset('Users', [12, 18, 15, 27, 31], '#4f46e5')
            ->columnSpan(8),
        TableWidget::make('latest-users')
            ->label('Latest users')
            ->table($usersTable)
            ->columnSpan(4),
    ]);
```

Widget names are stable lowercase identifiers. Shared methods are
`label()`, `description()`, `columnSpan()`, `sort()`, `visible()`, `poll()`,
`lazy()`, `headerActions()`, and `footerActions()`.

## Request-aware providers

Use `ProvidesWidgets` when a widget depends on the authenticated user or
tenant:

```php
use Illuminate\Http\Request;
use Inlay\Widgets\Contracts\ProvidesWidgets;

final class AdminDashboardWidgets implements ProvidesWidgets
{
    public function widgets(Request $request): iterable
    {
        yield StatsOverviewWidget::make('account')
            ->stats([
                Stat::make(
                    'Open orders',
                    $request->user()->orders()->open()->count(),
                ),
            ]);
    }
}

return $panel
    ->widget(AdminDashboardWidgets::class)
    ->discoverWidgets(
        directories: app_path('Inlay/Widgets'),
        namespace: 'App\\Inlay\\Widgets',
    );
```

The panel resolves providers per request and sends `inlayWidgets` to the
dashboard. Discovery caches only class names, never user-specific data.

## Refreshing and caching

React:

```tsx
<WidgetDashboard
    resource={widgets}
    onRefresh={(name) => router.reload({ only: ['widgets'], data: { widget: name } })}
/>
```

Vue exposes the same callback through `@refresh`. Without a callback, lazy or
polling widgets do not invent a transport.

For expensive providers, implement `CacheableWidgets` and include every input
that changes the result in the cache key:

```php
final class AdminDashboardWidgets implements CacheableWidgets
{
    public function cacheKey(Request $request): string
    {
        return 'dashboard.'.$request->user()->getAuthIdentifier()
            .'.'.$request->getLocale();
    }

    public function cacheTtl(Request $request): int
    {
        return 30;
    }
}
```

Caching never grants authorization. The provider still scopes and authorizes
its query on every request.

## Widget testing

Test provider authorization and values in PHP, then run adapter tests:

```bash
vendor/bin/pest tests/ThemeWidgetTest.php
npm --prefix packages/widgets/react test -- --run
npm --prefix packages/widgets/vue test -- --run
```

Also run typecheck and production build for the renderer you ship.
