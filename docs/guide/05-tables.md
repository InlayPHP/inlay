# Tables

Tables are server-driven schemas for search, sorting, filters, pagination,
selection, actions, exports, and reordering. The browser sends query state; PHP
allow-lists and applies it before Eloquent or an external data source runs.

## Install

```bash
composer require inlayphp/tables inlayphp/actions
npm install @inlayphp/tables-react @inlayphp/actions-react
# Vue: @inlayphp/tables-vue @inlayphp/actions-vue
```

Resources and the full panel preset already install the PHP dependencies.

## Build and query a Table

```php
use App\Models\User;
use Inlay\Actions\Action;
use Inlay\Actions\BulkAction;
use Inlay\Tables\Columns\BadgeColumn;
use Inlay\Tables\Columns\BooleanColumn;
use Inlay\Tables\Columns\TextColumn;
use Inlay\Tables\Filters\SelectFilter;
use Inlay\Tables\Table;

$table = Table::make('users')
    ->searchPlaceholder('Search users')
    ->columns([
        TextColumn::make('name')->searchable()->sortable(),
        TextColumn::make('email')->searchable(),
        BadgeColumn::make('status')
            ->labels(['active' => 'Active', 'invited' => 'Invited'])
            ->colors(['active' => 'success', 'invited' => 'warning']),
        BooleanColumn::make('verified')->alignment('center'),
    ])
    ->filters([
        SelectFilter::make('status')->options([
            'active' => 'Active',
            'invited' => 'Invited',
        ]),
    ])
    ->actions([
        Action::make('edit')
            ->label('Edit')
            ->url('/admin/users/{id}/edit')
            ->method('get'),
        Action::make('delete')
            ->url('/admin/users/{id}')
            ->method('delete')
            ->requiresConfirmation()
            ->color('danger'),
    ])
    ->bulkActions([
        BulkAction::make('archive')
            ->url('/admin/users/archive')
            ->method('post'),
    ])
    ->recordUrl('/admin/users/{id}')
    ->query(User::query(), request()->query(), perPage: 25);

return inertia('users/index', ['table' => $table]);
```

Render it with the matching adapter:

```tsx
import { Table } from '@inlayphp/tables-react';

export default function UsersIndex({ table, errors }) {
    return <Table resource={table} errors={errors} />;
}
```

```vue
<script setup lang="ts">
import { Table } from '@inlayphp/tables-vue';

defineProps<{ table: TableResource }>();
</script>

<template>
    <Table :resource="table" />
</template>
```

## Server-side query safety

Only declared operations can affect the query:

```php
$table
    ->columns([
        TextColumn::make('name')->searchable()->sortable(),
        TextColumn::make('email')->searchable(),
        TextColumn::make('created_at')->sortable(),
    ])
    ->filters([
        SelectFilter::make('status')->options(Status::labels()),
    ]);
```

The browser may request `users_sort=secret`, but the server discards it because
`secret` is not a declared sortable column. The same rule applies to search,
filters, grouping, per-page values, and table views.

Keep the table query scoped:

```php
$table->query(
    User::query()->where('workspace_id', auth()->user()->workspace_id),
    request()->query(),
    perPage: 25,
);
```

The Resource layer is preferable when the table belongs to CRUD because it
reuses the Resource's scoped query and policy.

## Search

Global search waits 500ms by default. Configure timing and behavior in PHP:

```php
Table::make('users')
    ->searchDebounce('750ms')
    ->searchOnBlur();
```

Search multiple model attributes with one displayed column:

```php
TextColumn::make('full_name')
    ->searchable(['first_name', 'last_name']);
```

Add server-only targets or callbacks:

```php
$table->searchable([
    'email',
    fn (Builder $query, string $search): Builder => is_numeric($search)
        ? $query->whereYear('created_at', $search)
        : $query,
]);
```

Individual column search is opt-in:

```php
TextColumn::make('reference')
    ->searchable(isIndividual: true, isGlobal: false);
```

The query parameter is namespaced by table name. Do not parse these values
manually unless you are implementing a custom data source.

## Columns

Common columns:

| Column | Useful methods |
| --- | --- |
| `TextColumn` | `searchable`, `sortable`, `formatStateUsing`, `copyable`, `url`, `dateTime` |
| `BadgeColumn` | `colors`, `labels`, `icons` |
| `BooleanColumn` | `trueLabel`, `falseLabel`, `alignment` |
| `IconColumn` | `icons`, `colors`, `boolean` |
| `ImageColumn` | `circular`, `size`, `stacked` |
| `ColorColumn` | `copyable` |
| `SelectColumn` | `options`, `disabled` |
| `ToggleColumn` | `url`, `authorizeUsing` |
| `TextInputColumn` | `rules`, `url`, `authorizeUsing` |
| `CheckboxColumn` | `url`, `authorizeUsing` |

Presentation callbacks execute in PHP and publish resolved values, not
closures:

```php
TextColumn::make('name')
    ->label('Customer')
    ->formatStateUsing(fn (mixed $state): string => Str::upper((string) $state))
    ->tooltip(fn (mixed $state): string => (string) $state)
    ->copyable();
```

Relationship columns are allow-listed and eager-loaded:

```php
TextColumn::make('author.name')->searchable()->sortable();
```

Or flatten the name explicitly:

```php
TextColumn::make('author_name')
    ->relationship('author', 'name')
    ->searchable()
    ->sortable();
```

Aggregates are server-side:

```php
TextColumn::make('books_count')->counts('books')->sortable();
BooleanColumn::make('books_exists')->exists('books')->sortable();
TextColumn::make('pages_total')->sums('books', 'pages');
```

## Filters

Use the filter that matches the value type:

```php
use Inlay\Tables\Filters\BooleanFilter;
use Inlay\Tables\Filters\DateFilter;
use Inlay\Tables\Filters\NumericFilter;
use Inlay\Tables\Filters\SelectFilter;
use Inlay\Tables\Filters\TernaryFilter;
use Inlay\Tables\Filters\TextFilter;

$table->filters([
    SelectFilter::make('role')->options(Role::labels()),
    BooleanFilter::make('enabled'),
    TernaryFilter::make('verified'),
    TextFilter::make('email_domain'),
    DateFilter::make('created_at'),
    NumericFilter::make('age'),
]);
```

For domain-specific query behavior, attach a server-only callback:

```php
SelectFilter::make('scope')
    ->options([
        'mine' => 'Assigned to me',
        'unassigned' => 'Unassigned',
    ])
    ->query(function (Builder $query, string $value): void {
        $query->when(
            $value === 'mine',
            fn (Builder $query) => $query->whereBelongsTo(auth()->user()),
            fn (Builder $query) => $query->whereNull('user_id'),
        );
    });
```

Use `TrashedFilter::make()` for Laravel `SoftDeletes`. Unknown or malformed
filter rules are ignored or rejected before they can become SQL.

For complex forms, use `schema()` on a filter and keep the query callback
server-side. The renderer receives control metadata and never receives the
query closure.

## Sorting, defaults, and stable pagination

```php
$table
    ->defaultSort('created_at', 'desc')
    ->primaryKey('uuid')
    ->defaultKeySort(true);
```

A valid browser-selected sort overrides the default. Inlay adds the primary key
as a final tie-breaker so equal timestamps do not jump between pages. Use
`defaultKeySort(false)` for a remote view that has no stable key.

## Pagination and table sizing

```php
$table
    ->query(User::query(), request()->query(), perPage: 25)
    ->cursorPagination();
```

Use length-aware pagination when users need page counts. Use cursor pagination
for large, append-heavy datasets. The renderer displays the pagination metadata
returned by PHP.

The default layout is intrinsically sized and horizontally scrollable on narrow
screens. Set explicit dimensions when a column needs a stable width:

```php
TextColumn::make('email')
    ->columnWidth('14rem')
    ->minWidth('10rem')
    ->maxWidth('18rem');
```

Use `striped()` for alternating rows. `recordClasses()` can add resolved row
classes without sending closures to the browser:

```php
$table->recordClasses(fn (User $record): ?string =>
    $record->is_featured ? 'bg-(--inlay-warning-surface)' : null
);
```

## Row, header, and bulk actions

```php
use Inlay\Actions\Action;
use Inlay\Actions\BulkAction;

$table
    ->headerActions([
        Action::make('create')->url('/admin/users/create'),
    ])
    ->actions([
        Action::make('edit')->url('/admin/users/{id}/edit'),
        Action::make('delete')
            ->url('/admin/users/{id}')
            ->method('delete')
            ->requiresConfirmation(),
    ])
    ->bulkActions([
        BulkAction::make('archive')
            ->url('/admin/users/archive')
            ->method('post')
            ->requiresConfirmation()
            ->minimumSelection(1)
            ->maximumSelection(500),
    ]);
```

Bulk actions automatically enable selection. Lifecycle actions may have a
server-side `form()`, `authorizeUsing()`, validation class, before/after hooks,
and a transaction. The server re-queries selected records through the scoped
table query; never trust a primary-key list from the browser.

Use `onAction` in React or `actionExecutor` in Vue only when the application
owns a custom transport. An explicit URL is safer for ordinary Laravel routes.

## Reordering

The persisted column must exist in the table migration:

```php
$table->unsignedInteger('position')->default(0)->index();
```

Configure the table:

```php
$table
    ->reorderable(
        column: 'position',
        authorizeUsing: fn (): bool => auth()->user()->can('reorder-users'),
        direction: 'asc',
    )
    ->beforeReordering(fn (array $order): mixed => audit('reorder.started', $order))
    ->afterReordering(fn (array $order): mixed => cache()->forget('users.order'));
```

Use `direction: 'desc'` when the first visual row should receive the highest
position. Keep pagination hidden during a reorder session unless the app
intentionally reorders only one page.

If a deployment reports `no such column: position`, add and run the migration
before enabling the feature. Do not make the runtime silently write a missing
column.

## Named views and personal views

Server-authored views are safe presets:

```php
use Inlay\Tables\Views\TableView;

$table->views([
    TableView::make('active')
        ->label('Active users')
        ->filters(['status' => 'active'])
        ->sort('name')
        ->default(),
    TableView::make('invited')
        ->label('Invited users')
        ->filters(['status' => 'invited']),
]);
```

Browser search, filters, sorting, grouping, and page-size values override view
defaults. Personal views are opt-in and session-scoped by default:

```php
$table->personalViews(app(TableViewStore::class), auth()->id());
```

Use the database driver and publish `inlay_table_views` when views must follow a
user across devices. A personal view cannot overwrite a server-authored view.

## CSV and XLSX exports

Install the optional XLSX driver only when needed:

```bash
composer require inlayphp/tables-xlsx
```

CSV export:

```php
use Inlay\Tables\Actions\ExportAction;
use Inlay\Tables\Exports\ExportColumn;

ExportAction::make('export-users')
    ->label('Export CSV')
    ->filename('users.csv')
    ->columns([
        ExportColumn::make('name'),
        ExportColumn::make('email'),
        ExportColumn::make('status'),
    ])
    ->maximumRows(50_000)
    ->authorizeUsing(fn (Request $request): bool =>
        $request->user()?->can('export', User::class) === true
    );
```

Exports reuse the current authorized query, search, filters, and sort. They
enforce row/selection limits and reject unsafe filenames. Drivers serialize
values only; they do not resolve records or bypass policy checks.

## External data sources

Use `dataSource()` for APIs, search indexes, repositories, or projections:

```php
use Inlay\Tables\Data\TableDataRequest;
use Inlay\Tables\Data\TableDataResult;

$table->primaryKey('uuid')->dataSource(
    fn (TableDataRequest $request): TableDataResult => new TableDataResult(
        rows: $directory->search(
            search: $request->search,
            filters: $request->filters,
            sort: $request->sort,
            direction: $request->direction,
            page: $request->page,
            perPage: $request->perPage,
        )->items,
        pagination: $response->pagination,
        total: $response->total,
    ),
);
```

The request contains normalized search, declared filters, allow-listed sort,
direction, page/cursor, per-page size, primary-key policy, and reorder policy.
An external adapter owns remote query execution; Inlay still owns input
normalization, contract validation, selection limits, and authorization.

## Standalone Table pages

Generate a page:

```bash
php artisan make:inlay-table-page Reports/ListInvoices --model=Invoice
```

Register its route:

```php
use App\Inlay\Tables\ListInvoices;
use Illuminate\Support\Facades\Route;

Route::inlayTable('/reports/invoices', ListInvoices::class)
    ->middleware(['web', 'auth'])
    ->name('reports.invoices');
```

`TablePage` implements `HasTables` and `InteractsWithTables`. It supplies the
current query string automatically. Use `tables()` for multiple independent
tables on one page; their names namespace search, filters, sorting, and page
parameters.

## Styling hooks

Both adapters publish the same semantic hooks:

```css
.orders [data-slot='table-row']:hover {
    background: var(--inlay-hover);
}

.orders [data-slot='toolbar'] {
    align-items: end;
}

.orders [data-filter='status'] {
    min-width: 12rem;
}
```

Use theme tokens for global changes and `classNames` for a page-specific
override. Avoid copying the renderer's internal utility string into application
CSS.

## Testing

Use the renderer-neutral `TableTester` for contract and row assertions:

```php
TableTester::make($resolvedTable)
    ->assertTableColumnExists('email')
    ->assertTableFilterExists('status')
    ->assertTableActionExists('edit')
    ->assertTableBulkActionExists('archive')
    ->assertCountTableRecords(10)
    ->assertCanSeeTableRecords($visibleUsers)
    ->assertCanNotSeeTableRecords($hiddenUsers);
```

Feature-test the actual HTTP query behavior and policy boundary. Frontend
packages should run their Vitest/Testing Library suite, typecheck, and build.
