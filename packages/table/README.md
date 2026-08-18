# Inlay Tables

[![Packagist](https://img.shields.io/packagist/v/inlayphp/tables?style=flat-square&label=packagist)](https://packagist.org/packages/inlayphp/tables)
[![PHP](https://img.shields.io/packagist/dependency-v/inlayphp/tables/php?style=flat-square)](https://packagist.org/packages/inlayphp/tables)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../LICENSE)

**Schema-driven tables for Laravel and Inertia**

`inlayphp/tables` builds renderer-neutral, server-driven table definitions for Laravel and Inertia. Search, sort and filter inputs are allow-listed from the declared schema before Eloquent pagination; React and Vue consume the resulting `inlay.tables.v1` contract.

## Install

```bash
composer require inlayphp/tables
pnpm add @inlayphp/tables-react
# or: pnpm add @inlayphp/tables-vue
```

Tables depends on `inlayphp/actions` and Laravel Eloquent/pagination. It can be used directly in a controller or through `inlayphp/resources`.

### Striped rows

Opt into a quiet alternating row surface with the same theme token used by the rest of the table:

```php
$table->striped();
```

Striping is presentation-only. The React and Vue renderers keep their normal hover and focus-within states on top of it, so an application theme can change `--inlay-surface-muted` without changing the table schema.

### Deterministic table sizing

Standard tables use a fluid intrinsic layout in both renderers, so labels and
email addresses receive natural room instead of being squeezed into equal-width
columns. The table keeps its intrinsic width (`w-max`) inside an
`overflow-x-auto` shell, so narrow screens scroll the complete row rather than
compressing email, badge, or action content into neighboring columns. Text cells
truncate by default and remain readable through their optional tooltip. The
surrounding scroll region handles intentional overflow,
and applications can opt into fixed proportions with declared dimensions:

```php
TextColumn::make('email')
    ->columnWidth('14rem')
    ->minWidth('10rem')
    ->maxWidth('18rem');
```

Card, stacked, grid, and custom column layouts keep their own responsive layout
rules.

When any visible column publishes one of these dimensions, both renderers use a
fixed table layout so those widths and bounds are deterministic. The table
remains horizontally scrollable on small screens; tables without explicit
dimensions keep the natural fluid layout. This keeps the same sizing contract
in React and Vue, including standalone `TablePage` screens.

Conditional row classes stay server-owned:

```php
$table->recordClasses(fn (User $record): ?string => $record->is_featured
    ? 'bg-(--inlay-warning-surface)'
    : null);
```

The callback is evaluated for each loaded record and the contract publishes only a primary-key-to-class map. It may return a class string, a list of strings, a `class => bool` map, or `null`.

## Global table configuration

Tables and their component families expose the same deterministic `configureUsing()` API as Schemas and Forms:

```php
Table::configureUsing(fn (Table $table) => $table
    ->deferFilters()
    ->persistQueryInSession());

Column::configureUsing(fn (Column $column) => $column->toggleable());
Filter::configureUsing(fn (Filter $filter) => $filter->default(null));
Group::configureUsing(fn (Group $group) => $group->collapsible());
Summarizer::configureUsing(fn (Summarizer $summary) => $summary->numeric(2));
```

Configuration is available on `Table`, `Column`, `Filter`, column layouts, summarizers, groups, and query-builder constraints. Registering against a concrete subclass affects only that type. Broad defaults run before specific defaults, `isImportant: true` runs after normal defaults, and fluent calls following `make()` remain authoritative. A `$during` callback provides exception-safe temporary scoping; `flushConfiguration()` removes registrations for exactly the called class.

Packages can append to an application's existing definition without replacing it:

```php
$table
    ->columns([TextColumn::make('name')])
    ->pushColumns([TextColumn::make('created_at')])
    ->filters([SelectFilter::make('status')])
    ->pushFilters([TrashedFilter::make()]);
```

The append path reruns the same nested-layout, column-group, duplicate-name, and
filter validation as the original definition.

## Standalone table pages

Scaffold one with Artisan:

```bash
php artisan make:inlay-table-page Reports/ListInvoices --model=Invoice
```

The generator derives the Inertia component name (`reports/list-invoices`) and the query-string prefix from the class, dropping a leading verb so the URL reads `invoices_search` rather than `list_invoices_search`. It prints the `Route::inlayTable()` line to register and refuses to overwrite an existing file without `--force`.

Use `TablePage` for a server-driven table on an ordinary Inertia page without an Inlay Panel or Resource:

```php
use App\Inlay\Tables\ListUsers;
use Illuminate\Support\Facades\Route;

Route::inlayTable('/users', ListUsers::class)
    ->middleware('auth')
    ->name('users.index');
```

```php
namespace App\Inlay\Tables;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inlay\Tables\Columns\TextColumn;
use Inlay\Tables\Filters\SelectFilter;
use Inlay\Tables\Table;
use Inlay\Tables\TablePage;

final class ListUsers extends TablePage
{
    protected static string $component = 'users/index';

    protected function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'active' => 'Active',
                    'suspended' => 'Suspended',
                ]),
            ]);
    }

    protected function query(Request $request): Builder
    {
        return User::query();
    }

    protected function perPage(): int
    {
        return 25;
    }
}
```

`TablePage` supplies the current query string to the table automatically, so search, sorting, filters, and pagination work without request plumbing in application code. Override `name()` to control the query-string prefix, `perPage()` for page size, and `props()` for additional Inertia data.

When records are reorderable, pagination is hidden during the browser’s reorder session so the visitor can move through the complete result set. Opt into keeping it visible when the application intentionally reorders one page at a time:

```php
$table->reorderable('position')->paginatedWhileReordering();
```

Reordering also exposes application hooks. Both receive the normalized primary-key order (and may optionally type or name the current request/table); hooks must return `null`:

```php
$table
    ->beforeReordering(fn (array $order) => audit('reorder.started', $order))
    ->afterReordering(fn (array $order) => cache()->forget('users.order'));
```

Customize the reorder trigger through the shared Actions contract:

```php
$table->reorderRecordsTriggerAction(
    Action::make('arrange')->label('Arrange users')->icon('arrows-up-down'),
);
```

Use the optional `direction` argument when the persisted ordering column is
queried in descending order. The first row in the reorder UI receives the
highest value, so the next table load keeps the same visual order:

```php
$table->reorderable(
    column: 'position',
    authorizeUsing: fn (): bool => auth()->user()->can('reorder-users'),
    direction: 'desc',
);
```

The contract publishes `reordering.direction` (`asc` or `desc`). Eloquent
tables assign contiguous values in that direction; custom `TableDataSource`
adapters receive the same value as `TableDataRequest::$reorderDirection`.

The React page remains minimal:

```tsx
export default function ListUsers({ table }) {
    return <Table resource={table} />;
}
```

## Reuse tables without extending TablePage

`TablePage` implements `HasTables` and uses `InteractsWithTables`. Widgets, plugin pages, reports, and other application classes can use the same table resolution lifecycle without inheriting from the page class:

```php
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inlay\Tables\Concerns\InteractsWithTables;
use Inlay\Tables\Contracts\HasTables;

final class RecentUsersTableProvider implements HasTables
{
    use InteractsWithTables;

    protected function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable(),
            TextColumn::make('created_at')->dateTime(),
        ]);
    }

    /** @return Builder<User> */
    protected function query(Request $request): Builder
    {
        return User::query()->latest();
    }
}
```

The concern owns PHP-side table construction and server query resolution only. React or Vue continues to own interactive selection and draft-filter state.

### Multiple named tables

Override `tables()` for dashboards or reports containing more than one independent table:

```php
use Closure;

/** @return array<string, Closure(Table): Table> */
protected function tables(Request $request): array
{
    return [
        'recent_users' => fn (Table $table): Table => $this->usersTable($table),
        'failed_imports' => fn (Table $table): Table => $this->importsTable($table),
    ];
}

protected function tableQuery(string $name, Request $request): Builder
{
    return match ($name) {
        'recent_users' => User::query()->latest(),
        'failed_imports' => Import::query()->where('status', 'failed'),
    };
}

protected function tablePerPage(string $name, Request $request): int
{
    return $name === 'recent_users' ? 10 : 25;
}
```

Every table uses its name as its query-string prefix:

```text
recent_users_search=Ada
recent_users_page=2
failed_imports_filters[type]=csv
failed_imports_page=1
```

Search, sorting, filtering, and pagination therefore remain isolated even though both tables use the same route.

`TablePage` shares both props:

- `table`: the first table, retained for single-table page compatibility;
- `tables`: every table keyed by its registered name.

```tsx
export default function Dashboard({ tables }) {
    return (
        <>
            <Table resource={tables.recent_users} />
            <Table resource={tables.failed_imports} />
        </>
    );
}
```

### Low-level controllers remain supported

The macro is optional. Existing direct construction and explicit Laravel routes continue to work:

```php
Route::get('/reports/users', [UserReportController::class, 'index']);

public function index(Request $request): Response
{
    return Inertia::render('reports/users', [
        'table' => Table::make('users')
            ->columns([...])
            ->query(User::query(), $request->query()),
    ]);
}
```

## Build and query a table directly

```php
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
        Action::make('edit')->label('Edit')->url('/admin/users/{id}/edit')->method('get'),
    ])
    ->recordUrl('/admin/users/{id}')
    ->poll('15s')
    ->deferLoading()
    ->cursorPagination()
    ->bulkActions([
        BulkAction::make('archive')->url('/admin/users/archive')->method('post'),
    ])
    ->emptyState('No users', 'Create a user or change the filters.')
    ->query(User::query(), request()->all(), perPage: 25);

return inertia('Users/Index', ['usersTable' => $table]);
```

Like the documented contract, Inlay waits `500ms` before sending search input by default. Customize
the shared timing for global and individual column search in PHP:

```php
Table::make('users')
    ->searchDebounce('750ms') // integers such as 300 also work
    ->searchOnBlur();       // or wait for blur or Enter
```

The typed value stays visible while the request waits, so the field never appears to lag
behind what was typed.

Query keys are namespaced by table name:

```text
users_search
users_sort
users_direction
users_page
users_filters[status]
users_loaded
users_group
users_group_direction
```

Only declared search targets participate in the grouped `LIKE` search. Only an exact declared `sortable()` column is ordered. Only declared filters are applied. Direction is normalized to `asc` or `desc`, and page is clamped to at least one.

Global search splits input into words by default. Every word must match at least
one declared target, though different words may match different columns:

```php
TextColumn::make('full_name')
    ->searchable(['first_name', 'last_name']);

// Search columns that do not need to be displayed:
$table->searchable(['id', 'email', 'author.email']);

// Or add server-only search behavior:
$table->searchable([
    'email',
    fn (Builder $query, string $search): Builder => is_numeric($search)
        ? $query->whereYear('created_at', $search)
        : $query,
]);
```

Use `->splitSearchTerms(false)` when an expensive dataset should search the
whole submitted phrase instead. Search paths are validated while the table is
built, and callbacks never cross the serialized browser contract. Calling
`->searchable()` on the table without arguments also displays the search input
for an external data source that owns its search implementation.

### Custom query filters and soft deletes

Every filter can own a server-only Eloquent callback. Named and typed
parameters are injected without serializing the closure:

```php
use Illuminate\Database\Eloquent\Builder;
use Inlay\Tables\Filters\SelectFilter;

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

For models using Laravel `SoftDeletes`, use the built-in three-state filter:

```php
use Inlay\Tables\Filters\TrashedFilter;

$table->filters([
    TrashedFilter::make(), // Without, with, or only trashed records
]);
```

`TrashedFilter` is standalone and does not require Panels or Resources.

Relationship columns are automatic. A dotted column name eager-loads its relationship and applies search with `whereHas()`; sorting uses an allow-listed relationship aggregate instead of treating user input as SQL:

```php
TextColumn::make('author.name')->searchable()->sortable();
```

When the browser-facing field name should be flat, declare the relationship explicitly. Inlay selects the related value into the declared alias:

```php
TextColumn::make('author_name')
    ->relationship('author', 'name')
    ->searchable()
    ->sortable();
```

Relationship and attribute identifiers are validated when the table is built. Requested search and sort values still operate only on declared columns.

Aggregate the related records instead of reading one of their columns:

```php
TextColumn::make('books_count')->counts('books')->sortable();
BooleanColumn::make('books_exists')->exists('books')->sortable();
TextColumn::make('pages_total')->sums('books', 'pages');
TextColumn::make('pages_average')->averages('books', 'pages');
TextColumn::make('longest_book')->maximum('books', 'pages');
```

The aggregate is computed in SQL and exposed under the column's own name, so formatting,
summaries, and sorting treat it like any other value — sorting by a book count needs no
extra configuration. A column cannot both read a related column and aggregate one: the two
would fight over the same alias, so it is refused when the table is built.

Give an Eloquent or external-data table a deterministic initial order with
`defaultSort()`:

```php
$table->defaultSort('created_at', 'desc');

$table->defaultSort(
    fn (Builder $query): Builder => $query
        ->orderByDesc('priority')
        ->orderBy('name'),
);
```

A valid browser-selected sortable column overrides the default. Invalid submitted
sort names never reach SQL; the server-owned default is restored. String defaults
also travel to `TableDataRequest` for external adapters, while closure defaults
remain Eloquent-only.

For stable pagination, Inlay appends the configured primary key as a final
tie-breaker using the active sort direction. This matches the API's default
primary-key sorting and prevents records with equal `rank` or `created_at`
values from moving between pages:

```php
$table
    ->primaryKey('uuid')
    ->defaultSort('created_at', 'desc');
```

Views or remote records without a stable key can opt out explicitly:

```php
$table->defaultKeySort(false);
```

The setting is server-authoritative and is included in the table contract for
renderer and adapter awareness. It does not expose a new browser sort option.

### Individual column search

Place a search field in a column header with the same named arguments as
the documented contract:

```php
TextColumn::make('name')
    ->searchable(isIndividual: true);

TextColumn::make('reference')
    ->searchable(
        isIndividual: true,
        isGlobal: false,
    );
```

The first column participates in both global and individual search. The second
can only be searched through its own header control. Individual values travel as
`<table>_column_searches[column]`, combine with each other and with global search
using `AND`, and reuse relationship or custom search callbacks. PHP discards
undeclared column names and caps each term before Eloquent or an external
`TableDataRequest` can observe it. React and Vue render the same accessible
`Search <column label>` control, apply the table's debounce or blur timing, and
persist it with search state when requested.

The control uses the shared `@inlayphp/ui` `controlClass` in both adapters.
That keeps border rings, focus rings, radius, placeholder, disabled, and theme
tokens aligned with the global table search and filter controls; adapter CSS
should extend that class instead of rebuilding the control shell.

Single-value filters, query-builder values, saved views, grouping, and page-size
controls use the matching React/Vue `Select` primitive. Multi-value filters stay
native listboxes where the browser's modifier-key selection is useful. All table
buttons use the shared semantic height tokens, so changing `button-height` or a
small/large variant in the panel theme updates pagination, filters, and actions
together.

### Custom trigger actions

The filter and column-manager controls use the same serialized Action
presentation contract as the rest of Inlay. Customize them from PHP while their
click behavior remains owned by the table:

```php
use Inlay\Actions\Action;

$table
    ->filtersTriggerAction(
        fn (Action $action): Action => $action
            ->label('Refine users')
            ->icon('adjustments')
            ->color('primary'),
    )
    ->columnManagerTriggerAction(
        fn (Action $action): Action => $action
            ->label('Display fields')
            ->icon('columns'),
    );
```

You may pass a preconfigured `Action` directly as well. Closures run on the
server with both the default action and table available for dependency
injection. React and Vue consume only the resolved label, icon, and semantic
color; a trigger cannot smuggle an unrelated browser-side mutation into these
table controls.

## Selection and grouped bulk actions

Selection policy is defined in PHP and serialized per row. This keeps records that the current user cannot act on disabled before they reach a bulk-action dialog, while the mutation endpoint remains responsible for the final policy check:

```php
use Inlay\Actions\ActionGroup;
use Inlay\Actions\BulkAction;

Table::make('orders')
    // Prefer an ability flag eager-loaded into each row; avoid an N+1 policy query here.
    ->recordSelectableUsing(fn (array $record): bool => $record['can_update'] === true)
    ->maxSelectableRecords(50)
    ->bulkActions([
        ActionGroup::make('status', [
            BulkAction::make('approve')
                ->url('/orders/bulk-approve')
                ->method('post')
                ->minimumSelection(2)
                ->maximumSelection(50)
                ->deselectRecordsAfterCompletion(),
            BulkAction::make('reject')
                ->url('/orders/bulk-reject')
                ->method('post')
                ->color('danger')
                ->requiresConfirmation(),
        ])->label('Change status')->icon('chevron-down'),
    ]);
```

React and Vue provide the same behavior:

- non-selectable records have disabled checkboxes with an explanation;
- the page checkbox uses its native indeterminate state for partial selection;
- the configured maximum is enforced for row and page selection;
- action-specific minimum/maximum requirements disable actions with a reason;
- action groups use a keyboard-focusable native disclosure;
- selection count changes are announced to assistive technology;
- `deselectRecordsAfterCompletion()` clears selection only after successful execution;
- “Clear selection” remains available independently of action execution.

For backward compatibility, a regular `Action` placed in `bulkActions()` is still accepted and is normalized to `bulk: true`. Laravel must re-query the submitted IDs through an authorized query, validate action-specific limits again, and perform the mutation in a transaction. Client-side disabled state is never authorization.

### Selecting all matching records across pages

Current-page selection remains the backward-compatible default. Opt into compact query-wide selection explicitly:

```php
return $table
    ->columns([...])
    ->filters([...])
    ->bulkActions([...])
    ->selectAllMatchingRecords();

// Equivalent fluent inverse:
$table->selectCurrentPageOnly(false);
```

After every selectable record on the page is checked, React and Vue offer “Select all N matching records.” The browser does not download every primary key. It sends this bounded descriptor with the bulk action instead:

```json
{
  "selection": {
    "mode": "query",
    "excluded": [42, 91],
    "query": {
      "search": "priority",
      "filters": { "status": "active" }
    }
  }
}
```

Unchecked records become exclusions, selection counts remain accurate, and changing search, filters, sort, or grouping clears the selection. React and Vue also clear it when a parent or an Inertia response replaces the server query directly, so a saved-view redirect or externally-triggered refresh cannot carry a stale selection into a later bulk action. Existing current-page actions still receive the original `records: [1, 2]` payload without the new descriptor.

On an authorized bulk-action endpoint, reuse the `TablePage` definition and process the scoped selection without loading the entire result set:

```php
$this->authorize('archiveAny', Order::class);

$processed = $page->processTableSelection(
    request: $request,
    name: 'orders',
    selection: $request->array('selection'),
    queryState: $request->array('selection.query'),
    callback: function (Collection $orders): void {
        Order::query()->whereKey($orders->modelKeys())->update(['archived' => true]);
    },
    chunkSize: 250,
);
```

The resolver reconstructs only declared searchable columns and filters against `tableQuery()`, validates the descriptor and exclusion list, and uses `chunkById()`. Authorization must happen before calling it. Query mode is rejected unless the PHP table opted in, page mode requires explicit IDs, descriptors allow at most 5,000 exclusions/IDs, and chunk sizes are limited to 1–1,000.

A queued bulk action can act on a query-wide selection larger than the inline record cap:

```php
BulkAction::make('export')->queueUsing(ExportOrders::class);
```

An inline action is capped at 500 records because it holds the request open. A queued
action only needs record keys, so the selection is resolved as keys rather than models and
dispatched in chunks. It is bounded by its own limit (default 10,000) and refused above it
rather than silently truncated.

## Grouping and summaries

Grouping follows the API's familiar PHP API while remaining renderer-neutral:

```php
use Inlay\Tables\Columns\Summarizers\Average;
use Inlay\Tables\Columns\Summarizers\Count;
use Inlay\Tables\Columns\Summarizers\Range;
use Inlay\Tables\Columns\Summarizers\Sum;
use Inlay\Tables\Grouping\Group;

Table::make('orders')
    ->columns([
        TextColumn::make('status'),
        TextColumn::make('revenue')->summarize([
            Sum::make()->label('Revenue')->money('USD'),
            Average::make()->label('Average order')->money('USD'),
            Range::make()->label('Range'),
        ]),
        TextColumn::make('id')->summarize(
            Count::make()->all()->label('Orders'),
        ),
    ])
    ->groups([
        Group::make('status')
            ->label('Order status')
            ->collapsible(),
        Group::make('created_at')
            ->label('Order date')
            ->date()
            ->collapsible(),
        // Dotted relationship grouping eager-loads, orders, and scopes summaries.
        Group::make('customer.name'),
        // A flat row key can map explicitly to a relationship title.
        Group::make('customer_name')->relationship('customer', 'name'),
    ])
    ->defaultGroup('status')
    ->collapsedGroupsByDefault()
    ->query(Order::query(), request()->query());
```

The serialized contract keeps three meanings separate:

- `summaries.page` is calculated from the rows on the current page;
- `summaries.query` is calculated from the complete filtered Eloquent query before pagination;
- each group bucket contains its own complete-query summaries when the group can be scoped in SQL.

React and Vue render group headings, descriptions, collapse controls, group totals, and a summary footer. The footer shows the filtered-query result first and the current-page result underneath, matched by summarizer type and label rather than position. Currency and decimal formatting are serialized, so both adapters display the same values.

Match the API's summary-row controls when a report only needs one scope. The
page condition controls the summary for the currently loaded page; the
all-table condition controls the aggregate over the complete filtered query:

```php
$table
    ->summaries(
        pageCondition: false,
        allTableCondition: true,
    );
```

Set both conditions to `false` for grouped reports that should show only their
group headings and totals. Hidden rows are omitted from the Inertia payload and
their aggregate query is not executed. Group bucket summaries remain available
so `groupsOnly()` reports keep their per-group totals. The serialized contract
publishes `summaries.pageVisible` and `summaries.queryVisible`; React and Vue
default missing flags to `true` for backwards-compatible hand-written payloads.

### Aggregate widgets

A column summary answers a question about one column. `aggregateWidgets()` answers one
about the table, publishing several aggregates side by side above it:

```php
Table::make('orders')
    ->columns([...])
    ->aggregateWidgets([
        'revenue' => Sum::make()->column('amount')->label('Revenue')->money('USD'),
        'items' => Sum::make()->column('items')->label('Items'),
        'orders' => Count::make()->column('id')->label('Orders'),
    ]);
```

Widgets run over the whole filtered query rather than the loaded page, so the answer does
not change with pagination. `column()` names what to aggregate, since a widget has no
owning column to inherit from, and the name is validated in PHP. React and Vue render them
as a card strip with the same formatter column summaries use, behind
`data-slot="aggregates"`.

### Scoped and custom aggregates

A summarizer can narrow its own aggregate, or replace it entirely:

```php
TextColumn::make('total')->summarize([
    Sum::make()->label('All'),

    // Same column, narrower aggregate — the table's own query is untouched.
    Sum::make()
        ->label('Paid')
        ->query(fn (Builder $query): Builder => $query->where('status', 'paid')),

    // A completely custom aggregate, with its matching page value.
    Count::make()
        ->label('Distinct statuses')
        ->using(fn (Builder $query): int => (int) $query->distinct()->count('status'))
        ->usingRows(fn (array $rows): int => count(array_unique(array_column($rows, 'status')))),
]);
```

`query()` receives a clone of the summary query, so scoping one aggregate never leaks into the listed records or the other summarizers, and the callback must return that builder or `null`. `using()` replaces the query aggregate; `usingRows()` supplies the page aggregate, which runs over the loaded rows rather than SQL. A `using()` summarizer without `usingRows()` publishes **no** page summary — the footer simply shows the query value alone. Note that `query()` applies to query and group summaries only; page summaries are derived from the rows already on the page.

Use `groupsOnly()` for a summary report that hides detail records. Use `groupingSettingsHidden()` for a fixed grouping, and `groupingDirectionSettingHidden()` when users should not reverse it.

### Relationship and custom grouping behavior

Dot paths such as `customer.name` automatically eager-load the relationship, order by its title attribute, and scope complete-query group summaries with `whereHas()`. Use `Group::make('customer_name')->relationship('customer', 'name')` when the serialized row should expose a flat `customer_name` key. Relationship names and attributes are identifier-validated before they reach Eloquent.

For computed groups or keys that differ from the displayed relationship value, override the query behavior explicitly:

```php
Group::make('customer.name')
    ->getKeyFromRecordUsing(fn (array $record): string => (string) $record['customer']['id'])
    ->getTitleFromRecordUsing(fn (array $record): string => $record['customer']['name'])
    ->getDescriptionFromRecordUsing(fn (array $record): string => $record['customer']['email'])
    ->orderQueryUsing(fn (Builder $query, string $direction) =>
        $query->orderBy(
            Customer::select('name')->whereColumn('customers.id', 'orders.customer_id'),
            $direction,
        )
    )
    ->scopeQueryByKeyUsing(fn (Builder $query, string $customerId) =>
        $query->where('customer_id', $customerId)
    );
```

Group resolver callbacks receive normalized row arrays, never Eloquent objects. This keeps direct rows, Eloquent results, React, and Vue on the same stable contract. Requested group names are allow-listed against `groups()`; forged query-string group names are ignored.

## Record URLs, polling, and deferred loading

Make the entire row an accessible record link with a safe URL template or callback:

```php
Table::make('users')
    ->recordUrl('/admin/users/{id}')
    ->openRecordUrlInNewTab();

// Row arrays are passed to callbacks after query normalization.
Table::make('users')
    ->recordUrl(fn (array $record): ?string => $record['active']
        ? route('users.show', $record['id'])
        : null);
```

Template values, including dotted paths, are URL encoded on the server. Every resolved URL passes Inlay's safe-URL policy before serialization. React and Vue give linked rows keyboard focus and Enter/Space activation. Clicking a button, input, select, label, or nested link does not trigger row navigation.

Record callbacks support utility injection by parameter name or object type. Use `$record` or `$row` for the normalized row and `$table` for the table instance, in any order. Selection, record URL, reorder authorization, and grouping callbacks retain their original positional signatures. Group resolvers additionally expose `$value` and `$group`; group query callbacks expose `$query`, `$direction`, and `$key` where applicable.

Use `poll()` for fresh server data and `deferLoading()` to avoid running the initial Eloquent query until the adapter mounts:

```php
return $table
    ->poll('10s')       // also accepts milliseconds, with a 250 ms minimum
    ->deferLoading();
```

The default adapters issue an Inertia reload for polling and add `<table>_loaded=1` for deferred loading. A host that wants to own the request handles it instead: pass `onRefresh` in React, or listen for `refresh` in Vue. In both renderers a listening host means the table does *not* also reload — Vue's `manual` prop still opts out of the built-in transport entirely. Polling pauses while the tab is hidden and resumes on the next tick, so a table left open in a background tab stops asking. Timer cleanup happens on unmount or configuration changes. The first deferred response contains no rows or pagination and performs no database query; normal search, filters, sorting, and pagination apply when the loaded request arrives.

## Pagination modes

Length-aware pagination remains the default and includes total records and numbered pages. Large datasets can avoid the count query with simple or cursor pagination:

```php
// Previous/next controls, no count query.
$table->simplePagination();

// Stable opaque cursors, no page offsets.
$table->cursorPagination();

// Return the complete filtered result set.
$table->paginated(false);

// Equivalent explicit API:
$table->paginationMode('length-aware'); // simple, cursor, or none
```

Cursor tokens are length-limited and structurally validated before reaching Laravel's paginator. Search, sort, and filter changes clear the active cursor. React and Vue render mode-appropriate controls: numbered pages for length-aware results, previous/next for simple results, and opaque previous/next cursor navigation for cursor results. Page sizes outside 1–500 are rejected.

### Per-page chooser

Declare the page sizes a visitor may pick. Nothing else is accepted from the request, so `?users_per_page=100000` can never widen a page:

```php
$table->paginationPageOptions([10, 25, 50, 'all']);
```

The chooser arrives as `pagination.perPageOptions`, the active size as `pagination.perPage`, and the visitor's choice travels in `{table}_per_page`. An unknown, out-of-range, or non-declared value silently falls back to the page size the page itself passed to `query()`.

`'all'` returns the complete filtered result set: `pagination.mode` becomes `none`, `perPage` becomes `'all'`, and the payload still reports `total`, `from`, and `to`. React and Vue keep the chooser visible in that state and drop the page controls. Options are validated once at build time — integers between 1 and 500, or `'all'` — and duplicates collapse.

## Table chrome

```php
Table::make('orders')
    ->heading('Recent orders', 'Everything placed this week.')
    ->emptyState('No orders yet', 'Import some to get started.')
    ->emptyStateActions([
        Action::make('import')->url('/orders/import'),
    ]);
```

Headings and descriptions accept closures resolved once per build. Empty-state actions join
the `header` scope, so they are resolved and authorized by the same boundary as header
actions rather than a separate one. A table that declares neither serializes a null heading
and an empty action list.

## Columns

Every column supports `label`, `sortable`, `searchable`, `toggleable`, `visible`, `hidden`, `alignment`, `state`, `default`, `placeholder`, `description`, `tooltip`, `copyable`, `url`, and `openUrlInNewTab`. URLs are checked by `inlayphp/support` and may interpolate encoded row paths such as `{user.id}` in the frontend. Both `url()` and `openUrlInNewTab()` accept closures, so links can be authorized or targeted per record without leaking PHP callbacks into the React/Vue contract:

```php
TextColumn::make('name')
    ->url(fn (User $record): ?string => $record->active
        ? route('users.show', $record)
        : null)
    ->openUrlInNewTab(fn (User $record): bool => $record->role === 'admin');
```

Horizontal alignment uses `left`, `center`, and `right`, with compatible
`alignStart()`, `alignCenter()`, and `alignEnd()` aliases. Vertical alignment uses
`start`, `center`, and `end`, with compatible fluent aliases:

```php
TextColumn::make('name')->alignStart();
TextColumn::make('amount')->alignEnd();
TextColumn::make('name')->verticallyAlignStart();
TextColumn::make('details')->verticalAlignment(VerticalAlignment::End);
```

The value is resolved on the server and rendered as the same `align-*` class in
React and Vue, including stacked and responsive table layouts.

Use `disabledClick()` when a record has a URL but a particular column should not
activate it. The cell remains available for its own links, buttons, and actions,
while the rest of the row keeps its normal record navigation:

```php
TextColumn::make('name')->disabledClick();
```

### Text formatting

```php
TextColumn::make('created_at')->since();
TextColumn::make('published_at')->since('UTC');
TextColumn::make('created_at')->date('Y/m/d');
TextColumn::make('total')->numeric(2, locale: 'en_US');
TextColumn::make('cents')->money('USD', divideBy: 100);
TextColumn::make('name')->formatStateUsing(
    fn (string $state): string => strtoupper($state),
);
TextColumn::make('total')->money('EUR')->prefix('~')->suffix(' incl. VAT');
TextColumn::make('body')->html();
TextColumn::make('summary')->markdown();
TextColumn::make('notes')->words(20);
TextColumn::make('skills')
    ->listWithLineBreaks(fn (array $state): bool => count($state) > 1)
    ->limitList(fn (array $record): int => $record['featured'] ? 3 : 1)
    ->expandableLimitedList(fn (array $record): bool => $record['featured']);
```

`since()` accepts an optional IANA timezone (`since('UTC')`) or a closure resolved against
the column. The selected timezone is published as `sinceTimezone`, while the renderer keeps
the live browser-relative-time behavior for payloads that provide a client clock. Use
`since(false)` when a column conditionally disables relative time. `words()` truncates on
word boundaries instead of mid-word (1–200), and both `words()` and `limit()` accept a custom
ending such as `->words(20, '… more')`, including a per-row closure. `prefix()` and `suffix()`
decorate the formatted value and deliberately leave an empty cell alone, so a placeholder is
never wrapped in stray affixes.

`date()`, `dateTime()`, `time()`, and the `isoDate*()` aliases format concrete row values in
PHP using the declared format, then publish the result through the same row presentation
payload. Each accepts an optional IANA timezone as its second argument; a closure timezone can
inspect the row or column. Invalid or empty format strings and timezones are rejected before a
response is built.

The matching `dateTooltip()`, `dateTimeTooltip()`, `timeTooltip()`, `isoDate*Tooltip()`, and
`sinceTooltip()` helpers keep the visible state unchanged while publishing a server-resolved
tooltip. They accept the same format and timezone arguments, including row-aware closures.
For shared configuration, `timezone('America/Los_Angeles')` can be called before or after a
date formatter and applies to the next serialized row values.

`numeric()` and `money()` use the same server-owned formatter. Numeric columns accept optional
fixed decimal places, decimal/thousands separators, a maximum decimal-place cap, and a locale;
all of these may be closures resolved per row. Money columns accept a currency code (or backed
enum/closure), an optional `divideBy` for minor-unit values, a locale, and decimal places. The
raw value remains in the row for editing, links, and copy actions, while the localized display
string is emitted as `formattedState`, so React, Vue, API consumers, and SSR agree on the
result. Non-numeric values pass through unchanged. `numeric(false)` keeps the legacy opt-out
form and clears numeric formatting.

Image columns accept `alt()` and `defaultImageUrl()` callbacks. They resolve per record on the
server and are emitted in the row presentation payload, so accessible alt text and a safe
fallback URL stay consistent in React and Vue. Static values are also included in the column
contract. Unsafe fallback protocols and overlong alt text are rejected before serialization.

`html()` sanitizes presentation HTML with Inlay's shared allow-list, while `markdown()` converts
GitHub-flavored Markdown and sanitizes the generated HTML. Both are server-only transforms: the
raw value remains available for copy and editing, and only sanitized `formattedState` is rendered
as markup by React and Vue. Do not use these methods as an authorization boundary for untrusted
content; the sanitizer removes scripts, unsafe URLs, and event attributes.

`formatStateUsing()` runs in PHP for each row and publishes the formatted value in the
row presentation payload. Its callback may receive `state`, `record`/`row`, and `column`;
return scalar values, arrays of scalar values, backed enums, stringable objects, or null.
The raw row value remains available for editable controls, URLs, and copy actions.

Use `TextColumn::make('position')->rowIndex()` for a server-owned one-based index, or
`rowIndex(true)` when the display should start at zero. The index is calculated from the
resolved page offset, so paginated Eloquent tables continue numbering across pages while
standalone `rows()` tables start at zero (or one when the default is used).

List presentation methods (`badge()`, `bulleted()`, `listWithLineBreaks()`, `limitList()`,
and `expandableLimitedList()`) also accept closures. They are evaluated per row and merged
into the same presentation payload, so one table can use compact text for ordinary records
and an expanded, badge-like list for a featured record.

Copy controls are closure-aware as well. `copyable()`, `copyMessage()`, and
`copyMessageDuration()` can resolve per record, while `copyableState()` lets the copied value
be different from the displayed value. The raw state remains the fallback when no custom copy
state is declared.

### Column actions

Turn a cell into an action trigger instead of adding another button to the row-actions column:

```php
TextColumn::make('name')
    ->action(
        Action::make('promote')
            ->modal(ActionModal::make('Promote this user?')->submitLabel('Promote'))
            ->authorizeUsing(fn (Request $request, User $record): bool => $request->user() !== null)
            ->action(fn (User $record): array => ['id' => $record->getKey()])
            ->successNotificationTitle('User promoted.'),
    );
```

A column action is not a separate transport. It joins the table's `row` scope, receives the same default `…&_inlay_action_scope=row&record={id}` URL as any row action, and is resolved by the same lookup — so default-deny authorization, Laravel validation, hosted action forms, transactions, and halt/cancel results behave identically. Actions must declare a lifecycle handler or a URL; a decorative action throws at build time.

React and Vue wrap the cell contents in a `data-slot="column-action"` button and stop the click from reaching a record URL, so a clickable row and a clickable cell can coexist.

Offer several actions in one cell with `actions()`:

```php
TextColumn::make('name')->actions([
    Action::make('impersonate')->action(fn (User $record) => auth()->login($record)),
    Action::make('profile')->url(fn (Request $request): string => $request->is('admin/*')
        ? '/admin/users/{id}'
        : '/users/{id}'),
]);
```

The cell becomes a menu trigger and each entry runs through the same row boundary, so the
group is a presentation choice rather than a second execution path. URL closures resolve
on the server; `{id}` is still interpolated for the active row by React or Vue. Entries are
validated in PHP, so an action with neither a handler nor a URL is refused at build time.

### Header and cell attributes

Decorate a column's header, content wrapper, or body cells with plain HTML attributes. Cell and content attributes accept a closure that resolves per record:

```php
BadgeColumn::make('status')
    ->extraHeaderAttributes(['data-testid' => 'status-header'])
    ->extraAttributes(fn (array $record): array => ['data-status' => $record['status']])
    ->extraCellAttributes(fn (array $record): array => $record['status'] === 'suspended'
        ? ['data-state' => 'suspended', 'title' => 'This account is suspended']
        : []);
```

`extraAttributes()` styles the column content wrapper, while `extraCellAttributes()` styles the surrounding `<td>`. Static attributes travel on the column as `extraHeaderAttributes`/`extraAttributes`/`extraCellAttributes`; closure results arrive per row inside the `__inlay.columns.<name>.attributes` or `.cellAttributes` presentation payload, so no callback is serialized. Pass `merge: true` to `extraAttributes()` when adding to previously configured content attributes.

Attribute names must be simple HTML attribute names, and event handlers (`on*`), `style`, and URL-bearing attributes (`href`, `src`, `formaction`, `action`, `srcdoc`) are rejected in PHP. React and Vue filter the payload again before spreading it, so a hand-written contract still cannot inject executable content.

### Custom search and sort clauses

`searchable()` and `sortable()` accept a `query` callback when the default `like` clause or `orderBy` is not what the column means:

```php
TextColumn::make('full_name')
    ->searchable(query: fn (Builder $query, string $search): Builder => $query
        ->whereRaw("first_name || ' ' || last_name like ?", ['%'.$search.'%']))
    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
        ->orderBy('last_name', $direction)
        ->orderBy('first_name', $direction));
```

Search callbacks run inside the table's OR group, so a custom clause joins the other searchable columns without escaping the base query's own constraints. Sort callbacks replace the generated `orderBy` entirely, which is how a status column can order by lifecycle priority rather than alphabetically. Both receive the Eloquent builder plus `$search` or `$direction`, resolve their arguments by name or position like every other Inlay callback, and must return the supplied builder or `null` — returning a different builder throws.

Presentation splits into two kinds of callback, and choosing the right one matters:

- **Build-time**: `label()`, `visible()`, `hidden()`, `alignment()`, `placeholder()`, `headerTooltip()`, `wrapHeader()`, `columnWidth()`, `extraHeaderAttributes()`, and `grow()` accept a closure resolved **once per table build**. They cannot see a record. Use them for tenant-, role-, or config-dependent presentation.
- **Per-row**: `state()`, `default()`, `description()`, `tooltip()`, `color()`, `icon()`, and `extraCellAttributes()` run for every row.

```php
TextColumn::make('salary')
    ->label(fn (): string => auth()->user()->prefersGross() ? 'Gross salary' : 'Net salary')
    ->visible(fn (): bool => auth()->user()->can('viewSalaries'))
    ->alignment(fn (): string => 'right')
    ->placeholder(fn (): string => 'Not disclosed');

$table
    ->searchPlaceholder(fn (): string => 'Search '.User::query()->count().' users…')
    ->emptyState(fn (): string => 'Nothing here yet', fn (): string => 'Adjust the filters above.');
```

Resolved values are validated: a label or heading must be a non-empty string, `visible()` and `hidden()` a boolean, and `alignment()` one of `left`, `center`, or `right`. A callback returning the wrong shape throws instead of serializing an invalid contract.

Table structure is closure-backed too:

```php
$table
    ->selectable(fn (): bool => auth()->user()->can('bulkEdit', User::class))
    ->stackedOnMobile(fn (): bool => auth()->user()->prefersCards())
    ->contentGrid(fn (): array => ['default' => 1, 'lg' => 3])
    ->paginationPageOptions(fn (): array => auth()->user()->isStaff() ? [10, 25, 100, 'all'] : [10, 25]);
```

Each resolved value passes the same checks an eager one does: an out-of-range grid, a
non-boolean, or a page size above 500 throws exactly as it would if written literally. An
empty option list still means no per-page chooser rather than an error.

Per-row presentation callbacks execute in PHP for each row and serialize only their results:

```php
TextColumn::make('email')
    ->state(fn (array $record): string => strtolower($record['email']))
    ->default('unknown@example.com')
    ->placeholder('No email')
    ->description(
        fn (string $state, array $record): string => ucfirst($record['status']).' account',
        position: 'below',
    )
    ->tooltip(fn (array $record): string => "Copy {$record['name']}'s email")
    ->copyable(message: 'Email copied', messageDuration: 2000);
```

Callbacks receive injectable `$state`, `$record` / `$row`, and the typed `$column`. The renderer-neutral row metadata contains resolved state, description, and tooltip values, never closures. React and Vue render equivalent muted placeholders, above/below descriptions, cell tooltips, accessible copy buttons, clipboard failure handling, and polite success feedback. `default()` replaces only `null`; `placeholder()` is display-only and is not copied as data.

Cell and header presentation are independent. This prevents a record-value tooltip from leaking onto a heading and keeps table sizing in PHP:

```php
TextColumn::make('description')
    ->tooltip(fn (array $record): string => "Description for {$record['name']}")
    ->headerTooltip('The public description shown to customers')
    ->wrapHeader()
    ->width(240) // compatible alias; integers become pixels.
    ->minWidth('12rem')
    ->maxWidth('40ch');
```

Width values accept bounded `px`, `rem`, `em`, `ch`, and `%` lengths. Arbitrary CSS expressions are rejected before serialization. `columnWidth()` remains available as the explicit Inlay name, while `width()` is the compatible alias. Image sizing uses `imageWidth()` / `imageHeight()` on `ImageColumn`. Header attributes are sanitized before they cross the transport boundary, including closure results.

Built-in columns:

- `TextColumn`: server-resolved presentation, semantic colors, badges, named icons, typography, wrapping, line clamps, character limits, date/date-time, numeric and money formatting, and expandable array lists;
- `BadgeColumn`: value-to-label and value-to-color maps;
- `BooleanColumn` and `IconColumn`;
- `ImageColumn`: safe single images or limited/wrapped/overlapping stacks with independent dimensions, square/circular crops, rings, overlap, and remaining counts;
- `ColorColumn`;
- editable `SelectColumn`, `TextInputColumn`, `ToggleColumn`, and `CheckboxColumn`.

### Editable columns

Editable columns use the same PHP-first lifecycle as the rest of the table. On a standalone `TablePage`, `Route::inlayTable()` automatically exposes a PATCH endpoint. The endpoint re-resolves the record through the page's scoped `query()`, locks it in a transaction, authorizes the update, validates the submitted state with Laravel, persists it, and returns a versioned contract:

```php
use Illuminate\Http\Request;
use Inlay\Tables\Columns\SelectColumn;
use Inlay\Tables\Columns\TextInputColumn;
use Inlay\Tables\Columns\ToggleColumn;

protected function table(Table $table): Table
{
    return $table->columns([
        TextInputColumn::make('name')
            ->rules(['required', 'string', 'max:120'])
            ->authorizeUpdateUsing(
                fn (User $record, Request $request): bool =>
                    $request->user()?->can('update', $record) === true,
            )
            ->beforeStateUpdated(fn (User $record, string $state) => audit('renaming', $record, $state))
            ->afterStateUpdated(fn (User $record, string $state) => UserRenamed::dispatch($record, $state)),

        SelectColumn::make('status')
            ->options([
                'active' => 'Active',
                'suspended' => 'Suspended',
            ])
            ->rules(['required', 'string']),

        ToggleColumn::make('is_admin')
            ->rules(['required', 'boolean'])
            ->updateStateUsing(function (User $record, bool $state): bool {
                $record->forceFill(['is_admin' => $state])->save();

                return $record->is_admin;
            }),
    ]);
}

protected function query(Request $request): Builder
{
    // Forged record keys outside this scope are rejected.
    return User::query()->whereBelongsTo($request->user()->team);
}
```

When the table belongs to an Inlay Resource, the resource's Edit authorization is applied first and the mutation endpoint is registered automatically. An explicit `authorizeUpdateUsing()` callback still runs and may impose a stricter per-column rule. Standalone tables default to Laravel's `update` model policy when no callback is supplied.

`SelectColumn` values are checked against the PHP option allow-list after Laravel validation. Dotted column names require `updateStateUsing()` because automatic persistence deliberately writes direct model attributes only.

React and Vue update the cell optimistically, disable it while the request is active, accept the state returned by Laravel, and roll back on failure while rendering the validation message beside the cell. `onCellChange` / `cell-change` fires after a successful server response. Applications may replace the transport with React's `columnUpdater` prop or Vue's `column-updater` prop; tables without an endpoint retain the earlier event-only behavior.

### Rich text presentation

`TextColumn` presentation can be static or resolved in PHP for each record. Application code does not need renderer-specific conditionals:

```php
TextColumn::make('status')
    ->badge()
    ->color(fn (string $state): string => match ($state) {
        'active' => 'success',
        'suspended' => 'danger',
        default => 'gray',
    })
    ->icon(fn (string $state): string => match ($state) {
        'active' => 'check-circle',
        'suspended' => 'x-circle',
        default => 'clock',
    })
    ->iconColor(fn (string $state): string => $state === 'active' ? 'success' : 'gray')
    ->iconPosition('before')
    ->size('small')
    ->weight('semibold')
    ->fontFamily('sans')
    ->lineClamp(2);
```

`color()`, `icon()`, and `iconColor()` accept either a static string or a closure. Closures receive injectable `$state`, `$record` / `$row`, and the typed `$column`; Inlay resolves them on the server and serializes only the safe result. Built-in semantic colors are `primary`, `danger`, `info`, `success`, `warning`, and `gray`. Icon names are renderer-neutral identifiers rather than executable markup.

Use `size('small'|'medium'|'large')`, `weight('light'|'normal'|'medium'|'semibold'|'bold')`, and `fontFamily('sans'|'serif'|'mono')` for typography. `wrap()` enables ordinary text and list-item wrapping. `lineClamp(1..6)` enables wrapping automatically and applies an equivalent multi-line clamp in React and Vue.

Theme packages may add semantic colors without changing a table definition. A custom token such as `brand` reads `--inlay-color-brand` for text and icons, and `--inlay-color-brand-soft` for badge backgrounds:

```css
:root {
    --inlay-color-brand: #6d28d9;
    --inlay-color-brand-soft: #ede9fe;
}
```

Semantic tokens and icon identifiers are validated before transport. Inlay deliberately does not force Heroicons, Lucide, or another frontend dependency. Applications and community packages provide an exact-name map or one wildcard adapter; unresolved names retain the compact built-in fallback.

The table's row hover is also a semantic token. Set `table-row-hover` in the
application theme (or pass it to the standalone `theme` prop) to change every
row hover and focus-within surface at once; the renderer falls back to the
shared `hover` token when it is not supplied.

React:

```tsx
import { CheckCircle2, Circle, UserRound } from 'lucide-react';
import type { IconRendererProps } from '@inlayphp/tables-react';

const icons = {
    'heroicon-o-user': UserRound,
    'heroicon-o-check-circle': CheckCircle2,
};

function TableIcon({ name }: IconRendererProps) {
    const Icon = icons[name] ?? Circle;

    return <Icon aria-hidden className="size-4" />;
}

<Table resource={table} renderers={{ icon: { '*': TableIcon } }} />;
```

Vue:

```ts
import { h } from 'vue';
import { Circle, UserRound } from 'lucide-vue-next';

const icons = { 'heroicon-o-user': UserRound };
const TableIcon = (props: { name: string }) => h(icons[props.name] ?? Circle, {
    'aria-hidden': true,
    class: 'size-4',
});
```

```vue
<Table :resource="table" :renderers="{ icon: { '*': TableIcon } }" />
```

Use `renderers.icon[name]` for exact overrides. For a package-owned dynamic registry, provide `registries.icon.get(name)` instead. Exact names win over the wildcard, and the local `renderers` map wins over a registry. The same resolver is used by `TextColumn`, `IconColumn`, row actions, bulk actions, and action groups, including columns nested in Split/Stack/Panel layouts.

Array state can be presented as a readable list without formatting it in JavaScript:

```php
TextColumn::make('skills')
    ->bulleted() // Also enables line breaks.
    ->limitList(3)
    ->expandableLimitedList();

TextColumn::make('contact_methods')
    ->listWithLineBreaks();
```

React and Vue use semantic lists and an accessible `aria-expanded` control. `limitList()` requires at least one item; without `expandableLimitedList()`, the renderer shows a compact remaining count.

`ImageColumn` accepts either one safe URL or an array of safe URLs. This supports avatar groups and compact galleries while preserving the existing `size()` and `fallbackUrl()` methods:

```php
ImageColumn::make('team.avatar')
    ->imageWidth(48)
    ->imageHeight(48)
    ->circular()
    ->stacked()
    ->ring(2)
    ->overlap(3)
    ->limit(4)
    ->limitedRemainingText()
    ->wrap()
    ->defaultImageUrl('/images/avatar-placeholder.png');
```

Use `imageSize()` when width and height are equal, or `square()` to derive width from the configured height. Dimensions are bounded to 1–2048 pixels; ring and overlap values are bounded to 0–8. Default URLs are validated in PHP, and every record-provided URL is checked again by React/Vue before an `<img>` is emitted. Images are lazy-loaded and receive indexed alternative text when a cell contains more than one image.

### Grouped column headers

Group related columns under an accessible two-row header while keeping the query engine's column list flat:

```php
use Inlay\Tables\Columns\ColumnGroup;

Table::make('users')->columns([
    TextColumn::make('name')->sortable(),
    ColumnGroup::make('Account', [
        TextColumn::make('email')->searchable(),
        BadgeColumn::make('role'),
        BadgeColumn::make('status'),
    ])
        ->alignment('center')
        ->wrapHeader()
        ->tooltip('Contact and access status'),
    BooleanColumn::make('active'),
]);
```

React and Vue emit `scope="colgroup"`, the correct dynamic `colspan`, and `rowspan="2"` for ungrouped headers and utility columns. Hidden columns are removed from the group span. If the column manager moves grouped columns apart, the renderer repeats the group heading for each contiguous segment instead of producing an invalid table grid. Search, sort, summaries, responsive visibility, and column management continue to operate on `resource.columns` exactly as before.

`ColumnGroup` accepts only leaf columns. It cannot be mixed with `Split`, `Stack`, or `Panel` in the same table because those components intentionally replace the semantic table header with a per-record custom layout.

### Responsive rows and record grids

For an ordinary table that becomes readable cards on narrow screens, keep the same columns and enable mobile stacking:

```php
Table::make('customers')
    ->columns([
        ImageColumn::make('avatar')->circular()->grow(false),
        TextColumn::make('name'),
        TextColumn::make('email')->visibleFrom('md'),
        TextColumn::make('internal_note')->hiddenFrom('xl'),
    ])
    ->stackedOnMobile();
```

React and Vue hide the header below `sm`, render each record as a labelled card, and restore table rows on larger screens. `visibleFrom()` and `hiddenFrom()` accept `sm`, `md`, `lg`, `xl`, or `2xl`; their classes apply to both header and data cells.

For card-oriented content, arrange records into a responsive grid:

```php
Table::make('products')
    ->columns([...])
    ->contentGrid([
        'md' => 2,
        'xl' => 3,
        '2xl' => 4,
    ]);
```

Grid values are validated from 1 through 12. The default remains one card per row until the first configured breakpoint. Call `contentGrid(null)` or `stackedOnMobile(false)` to disable either mode.

For full control, nest `Split`, `Stack`, and `Panel` components directly in `columns()`:

```php
use Inlay\Tables\Columns\Layout\Panel;
use Inlay\Tables\Columns\Layout\Split;
use Inlay\Tables\Columns\Layout\Stack;

Table::make('customers')->columns([
    Split::make([
        ImageColumn::make('avatar')->circular()->grow(false),
        Stack::make([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('email'),
        ])->space(2),
    ])->from('md'),
    Panel::make([
        Stack::make([
            TextColumn::make('phone'),
            TextColumn::make('notes')->wrap(),
        ])->visibleFrom('md'),
    ])->collapsible()->collapsed(),
]);
```

Layouts may nest recursively. `Split::from()` controls when horizontal alignment begins; `Stack::alignment()` and `space()` control vertical placement; `Panel` supplies per-record accessible collapse state. The contract also retains a flat leaf-column list, so searching, sorting, summaries, column renderers, and older consumers remain compatible.

### Column manager

Toggleable columns automatically receive a column manager in new PHP-generated contracts. Hide infrequently used columns initially with the fluent named argument:

```php
TextColumn::make('internal_id')
    ->toggleable(isToggledHiddenByDefault: true);

TextColumn::make('name')->toggleable(false); // fixed visibility
```

Column changes are deferred until “Apply columns” by default and persist for the browser tab/session under a table-name-scoped key. Both behaviors are configurable:

```php
return $table
    ->reorderableColumns()
    ->deferColumnManager(false)
    ->persistColumnsInSession(false);
```

The manager can use a fluent modal, lay out controls in one to six
columns, and place its reset action in the header or footer:

```php
use Inlay\Tables\Enums\ColumnManagerLayout;
use Inlay\Tables\Enums\ColumnManagerResetActionPosition;

return $table
    ->columnManagerLayout(ColumnManagerLayout::Modal)
    ->columnManagerColumns(2)
    ->columnManagerResetActionPosition(
        ColumnManagerResetActionPosition::Footer,
    );
```

`Reset columns` restores the visibility and order declared by PHP. With the
default deferred manager, the reset remains a draft until the visitor chooses
`Apply columns`; a live manager applies it immediately. Closing a manager
without applying discards its uncommitted draft.

When `reorderableColumns()` is enabled, the same manager provides keyboard-accessible move-up and move-down controls and persists the resulting order. Stored data is treated as untrusted: React and Vue remove duplicate or unknown names, append newly declared columns, and only restore boolean visibility values for currently declared, toggleable columns. Non-toggleable columns always retain their PHP-defined visibility. Older visibility-only storage and `inlay.tables.v1` payloads without `columnManager` remain compatible.

### Reordering records

Record ordering is separate from column ordering. On a standalone `TablePage`, declare the persisted integer column and an explicit authorization callback:

```php
protected function table(Table $table): Table
{
    return $table
        ->columns([
            TextColumn::make('title'),
        ])
        ->reorderable(
            column: 'position',
            authorizeUsing: fn (Request $request): bool =>
                $request->user()?->can('reorder', Post::class) === true,
            direction: 'desc',
        );
}

protected function query(Request $request): Builder
{
    // This scope is also authoritative for reorder mutations.
    return Post::query()->whereBelongsTo($request->user()->team);
}
```

`Route::inlayTable()` accepts both GET and PATCH on the same URI. The GET controller injects that URI into the table contract; React and Vue submit the table name, ordered primary keys, and the current page's absolute start position. Laravel then:

1. runs the authorization callback;
2. validates 2–500 unique scalar keys;
3. re-resolves every key through `tableQuery()`;
4. locks those records in a transaction;
5. refuses the save when the stored order no longer matches the order the browser saw; and
6. writes contiguous integer positions using quiet model saves, honoring the
   configured `direction` (`asc` by default, or `desc` for descending lists).

Before the transaction, the endpoint also verifies that the configured reorder
column exists. If a project has an older database, the request returns a normal
validation error naming the missing column and table instead of leaking a raw
SQL `no such column` exception. Add the column in a migration (for example,
`$table->unsignedInteger('position')->default(0)->index()`) before calling
`->reorderable('position')`.

This supports keyboard and pointer users with draggable row handles plus Move up, Move down, Save order, and Cancel controls. Dropping a handle on another row moves it to that row's position and announces the result through an `aria-live` status. The drag affordance is progressive enhancement: the keyboard buttons use the same local order and submit the same authorized v1 contract, so reordering does not depend on HTML drag-and-drop support. Grouped views disable the reorder trigger because a grouped order is ambiguous. Paginated pages preserve their absolute position using `pagination.from`; applications may disable pagination when users need to reorder the complete collection.

If the PATCH returns a validation error (for example, an older database is
missing the configured position column), both renderers keep reorder mode open
and show the first server message in an inline dismissible alert. The visitor
can correct the migration or stale-order conflict and retry without losing the
local order they arranged.

Each page publishes a `reordering.version` fingerprint of the order it rendered, and both
renderers send it back. The server recomputes that fingerprint from the locked records
inside the same transaction that writes the new positions, so a concurrent reorder cannot
be silently overwritten and the check cannot race the write it guards. A client that sends
no version still works.

The callback defaults to deny. Client state is not authorization, and forged IDs outside the scoped query receive a validation error without revealing which record was inaccessible. For a manually supplied endpoint, call `->reorderUrl('/projects/reorder')`; React can instead own persistence with `onReorder`, while Vue emits `reorder`.

## Filters

Built-in filters are `TextFilter`, `NumericFilter`, `DateFilter`, `BooleanFilter`, `SelectFilter`, and `TernaryFilter`. `SelectFilter` supports multiple values; `DateFilter` serializes its range setting; `TernaryFilter` customizes true/false labels.

`SelectFilter` can also filter through a relationship instead of a column:

```php
SelectFilter::make('author')
    ->relationship('author', 'name', fn (Builder $query): Builder => $query->where('active', true))
    ->multiple();
```

Options are read from the related model through the owner's own query, so a scoped table
cannot offer a record the visitor may not see, and the modifier narrows them further. Add
`searchable()` when the relationship holds more records than a list can show:

```php
SelectFilter::make('author')
    ->relationship('author', 'name')
    ->searchable()
    ->preload();
```

A searchable filter ships no options until the visitor types, fetching them through the
same authorized query and modifier. `preload()` loads the first page up front. Selected
values remain resolvable, so a chosen option keeps its label. React and Vue debounce the
search and replace the list in place. The
filter applies with `whereHas`, and its indicator names the related record rather than its
key. `optionsLimit()` bounds how many are loaded (1–500, default 50).

### Arbitrary schema filters

When a filter needs controls the built-in types do not cover, `SchemaFilter` renders any Forms schema inside the filter panel:

```php
use Inlay\Tables\Filters\SchemaFilter;

SchemaFilter::make('signup')
    ->label('Signup window')
    ->formColumns(2)
    ->columnSpan(2)
    ->schema([
        TextInput::make('name_starts_with')->label('Name starts with'),
        Select::make('account_role')->label('Account role')->options([
            'admin' => 'Admin',
            'member' => 'Member',
        ]),
    ])
    ->query(function (Builder $query, mixed $value): Builder {
        if (is_string($value['name_starts_with'] ?? null) && $value['name_starts_with'] !== '') {
            $query->where('name', 'like', $value['name_starts_with'].'%');
        }

        return $query;
    });
```

The submitted value is one associative array keyed by field name, so a schema filter never maps onto a column automatically — it must declare both a schema and a `query()` callback, and `filters()` throws at build time if either is missing. Each filled field publishes its own removable indicator (`signup.account_role`), so a visitor can drop one input without clearing the rest.

`inlayphp/tables` does not depend on `inlayphp/forms` in PHP: the schema travels as plain serialized components, and React and Vue render it with the `SchemaRenderer` the Forms adapters already export.

Filters are deferred by default: the frontend maintains draft values until “Apply filters.” Use `deferFilters(false)` for immediate query updates. Defaults are serialized and restored by reset controls.

### Filter form layout

The filter form sits behind a toggle by default. Keep it open above the table, choose the grid width, and let one filter span several columns:

```php
$table
    ->filtersLayout('above-content') // or 'chips', 'below-content', 'above-content-collapsible', 'modal', or 'dropdown'
    ->filtersFormColumns(2)
    ->filters([
        SelectFilter::make('role')->options([...]),
        SelectFilter::make('status')->options([...]),
        QueryBuilder::make('advanced')->columnSpan(2)->constraints([...]),
    ]);
```

`above-content` and `below-content` keep the form open before or after the table; `dropdown` keeps it behind a toggle. Columns and spans are validated in PHP (1–6) and travel as `filtersLayout`, `filtersFormColumns`, and each filter's `columnSpan`. React and Vue apply them through CSS custom properties, so the form stays single-column on small screens and only adopts the declared grid from the `sm` breakpoint up. A span wider than the grid is clamped to it.

Use `chips` for a compact status/filter toolbar. Select filters with serialized
options render as an “All” chip plus one chip per option; choosing a chip updates
the query immediately and hides the full filter form. This is useful for the
default dashboard table style and remains fully PHP-defined:

```php
$table
    ->deferFilters(false)
    ->filtersLayout('chips')
    ->filters([
        SelectFilter::make('status')->options([
            'paid' => 'Paid',
            'pending' => 'Pending',
        ]),
    ]);
```

`above-content-collapsible` keeps the panel in the same position as `above-content`, but
adds the normal Filters toggle so a page can reclaim vertical space when the controls
are not needed.

Use `modal` when filters deserve a focused surface instead of taking space in the table
flow. The trigger opens an accessible dialog with a backdrop, Close action, Escape-key
dismissal, and the same Apply/Reset lifecycle as the other layouts:

```php
$table->filtersLayout('modal');
```

For a long filter schema, cap the open panel and let its contents scroll. The value is
validated as a safe CSS length and integer pixels are accepted as a convenience:

```php
$table
    ->filtersFormMaxHeight('28rem') // also accepts 448 for 448px
    ->hiddenFilterIndicators();
```

`hiddenFilterIndicators()` removes the chips from the presentation while leaving the
filter values and server-authored `filterIndicators` payload intact. This is useful when
the filter form itself already explains the active state, or when a compact table header
is more important than quick chip removal. React and Vue apply the max height to both
`above-content` and `below-content` panels with a vertical overflow region.

The reset control is in the filter header by default, matching the compact form
layout. Move it next to the deferred Apply button when a footer is easier to scan:

```php
$table->filtersResetActionPosition('footer');
```

Use `'header'` or `'footer'`; the value is serialized once and both renderers place one
accessible reset button in the selected region.

### Filter indicators

Every active filter publishes a removable chip above the table. The text is resolved in PHP, so select chips show option labels, ternary chips show their true/false labels, and query-builder chips count their conditions:

```php
SelectFilter::make('status')
    ->options(['active' => 'Active', 'suspended' => 'Suspended'])
    ->indicateUsing(fn (string $value): ?string => $value === '' ? null : 'Only '.strtolower($value).' accounts');
```

Return `null` to hide the chip. Return an array to publish one removable chip per sub-field, which is how a range filter drops only its lower bound:

```php
DateFilter::make('created_on')
    ->range()
    ->indicateUsing(fn (array $value): array => array_filter([
        'from' => ($value['from'] ?? null) ? 'From '.$value['from'] : null,
        'to' => ($value['to'] ?? null) ? 'Until '.$value['to'] : null,
    ]));
```

The payload arrives as `filterIndicators`, each entry carrying `filter`, the `field` its remove button clears (`created_on.from`), and the resolved `label`. React and Vue render the chips with an accessible `Remove <label>` control that clears exactly that field and re-applies the query.

### Nested query builder

`QueryBuilder` gives users nested AND/OR groups while keeping every database column and operator allow-listed in PHP:

```php
use Inlay\Tables\Filters\QueryBuilder;
use Inlay\Tables\Filters\QueryBuilder\BooleanConstraint;
use Inlay\Tables\Filters\QueryBuilder\DateConstraint;
use Inlay\Tables\Filters\QueryBuilder\NumberConstraint;
use Inlay\Tables\Filters\QueryBuilder\RelationshipConstraint;
use Inlay\Tables\Filters\QueryBuilder\SelectConstraint;
use Inlay\Tables\Filters\QueryBuilder\TextConstraint;

QueryBuilder::make('advanced')
    ->label('Advanced filters')
    ->constraints([
        TextConstraint::make('name')->nullable(),
        NumberConstraint::make('total')->integer(),
        DateConstraint::make('created_at'),
        BooleanConstraint::make('verified'),
        RelationshipConstraint::make('posts')
            ->multiple()
            ->selectable([1 => 'Welcome post', 2 => 'Release notes']),
        SelectConstraint::make('status')->options([
            'draft' => 'Draft',
            'published' => 'Published',
        ]),
    ])
    ->limits(maxDepth: 5, maxRules: 50);
```

React and Vue provide equivalent recursive editors for adding, removing, and nesting rules, choosing all/any matching, and rendering text, number, date, boolean, select, and relationship values. The submitted renderer-neutral AST looks like this:

All query-builder selects and value controls use the shared UI control contract
in both adapters, so filter panels retain the same border ring, focus ring,
radius, disabled state, and theme tokens as the table search field. Adapter
themes can extend the shared class without replacing its accessibility states.

```php
[
    'boolean' => 'and',
    'children' => [
        ['constraint' => 'status', 'operator' => 'is', 'value' => 'published'],
        [
            'boolean' => 'or',
            'children' => [
                ['constraint' => 'total', 'operator' => 'minimum', 'value' => 100],
                ['constraint' => 'name', 'operator' => 'contains', 'value' => 'priority'],
            ],
        ],
    ],
]
```

`RelationshipConstraint` supports relationship-count comparisons, `has` / `does_not_have`, and allow-listed `is_related_to` / `is_not_related_to` rules. Static options keep submitted identifiers server-authorized. For large related models, let the standalone table route provide authenticated, scoped remote search:

```php
RelationshipConstraint::make('author')
    ->relationship('author', 'name')
    ->searchable()
    ->preload()
    ->searchDebounce(300)
    ->optionsLimit(50)
    ->modifyOptionsQueryUsing(
        fn (Builder $query, Request $request): Builder =>
            $query->where('tenant_id', $request->user()->tenant_id),
    );
```

`Route::inlayTable()` uses the same authenticated page route for option requests, so no public lookup endpoint is added. Filter and constraint names are allow-listed from the PHP table definition, search and selected-ID requests are bounded, selected identifiers are checked against the authoritative related query again when the filter executes, and React/Vue retain labels for selected records across searches. Use `preload()` only for small initial sets; search remains remote either way.

The server rejects undeclared constraints, unsupported operators, invalid select or relationship values, non-numeric values, excessive nesting, and excessive rule counts before building the Eloquent query. A relationship constraint may use a friendly public name (for example, `assigned_role`); saved state from an older renderer that still contains the configured relationship path (for example, `roles`) is accepted as a declared alias, never as arbitrary request input.

Add a reusable operator to any built-in constraint without creating a new constraint or frontend component:

```php
use Inlay\Tables\Filters\QueryBuilder\Operator;

TextConstraint::make('name')->withOperators([
    Operator::make('length_is_multiple_of')
        ->label('Length is divisible by')
        ->valueType('number')
        ->query(
            fn (Builder $query, int|float $value): Builder =>
                $query->whereRaw('char_length(name) % ? = 0', [$value]),
        ),
]);
```

Every operator is described in the payload, built-in ones included: label, value type,
whether it accepts a list, and its options. React and Vue read that metadata rather than
inferring behavior from operator names, so a constraint's controls cannot drift between the
two renderers or away from PHP. A custom constraint can refine the defaults by overriding
`operatorValueType()`, `operatorAcceptsMany()`, or `describeOperator()`.

Operator value types are `text`, `number`, `date`, `boolean`, `select`, and `none`. `options([...], multiple: true)` creates an allow-listed multi-select. Inlay serializes the label and control metadata for React and Vue, normalizes the submitted value, rejects undeclared options or invalid types, then invokes the server-only query callback. Community packages can therefore publish ordinary `Operator` factories. They may still add a completely new constraint family by extending `QueryBuilder\Constraint` when an operator alone is insufficient.

### Persisting query state

URL query parameters remain authoritative and persistence is opt-in. Enable only the state families appropriate for a table:

```php
return $table
    ->persistSearchInSession()
    ->persistSortInSession()
    ->persistFiltersInSession();

// Convenience switch for all three:
$table->persistQueryInSession();
```

React and Vue restore the enabled values from table-scoped session storage and immediately issue the normal query-change request. Restored sort columns must still be declared `sortable()`, filters are reduced to declared filter names, directions are normalized, and search text is capped. Pagination and cursor state are deliberately not persisted, so restoration starts from the first result page. Passing `false` to any method disables that state family.

## Actions and selection

Use `Inlay\Actions\Action` and `Inlay\Actions\BulkAction`; the legacy classes under `Inlay\Tables\Actions` are deprecated aliases. Tables serialize row, header and bulk actions. Adding bulk actions automatically enables row selection.

The React and Vue action adapters handle safe URL interpolation, confirmations and modal metadata. Bulk URL actions send selected primary keys as `records`. Applications can take ownership through React's `onAction` or Vue's `actionExecutor`.

Lifecycle actions can keep authorization, Laravel validation, transactions, and hooks beside the table definition. `Route::inlayTable()` hosts them on the table URI and assigns the endpoint URL automatically:

```php
use App\Models\Order;
use Illuminate\Http\Request;
use Inlay\Actions\Action;
use Inlay\Forms\Fields\TextInput;

protected function table(Table $table): Table
{
    return $table->actions([
        Action::make('mark-paid')
            ->form(fn (Order $record): array => [
                TextInput::make('reference')
                    ->label('Payment reference')
                    ->rules('nullable', 'string', 'max:100'),
            ])
            ->fillForm(fn (Order $record): array => [
                'reference' => $record->payment_reference,
            ])
            ->authorizeUsing(
                fn (Request $request, Order $record): bool =>
                    $request->user()?->can('markPaid', $record) === true,
            )
            ->databaseTransaction()
            ->action(function (Order $record, array $data): void {
                $record->update([
                    'paid_at' => now(),
                    'payment_reference' => $data['reference'] ?? null,
                ]);
            })
            ->successNotificationTitle('Order marked as paid.'),
    ]);
}
```

The endpoint re-resolves row and bulk records through the table's scoped Eloquent query, rejects forged or invisible IDs, enforces action authorization and validation, and returns the versioned `inlay.actions.result.v1` contract. React and Vue keep halted or invalid actions open, close successful/cancelled actions, optionally clear selection after success, and refresh the table after a successful lifecycle mutation. Query-wide bulk selections are resolved with their bounded exclusions and are limited to 500 records per lifecycle request.

When `form()` is present, the table also hosts a scoped form-mount request. It
authorizes before resolving record-aware fields and defaults, returns the
versioned Form resource, and submits through the same lifecycle endpoint.
Laravel validation errors remain visible in the React or Vue modal until the
user corrects and retries the action.

### CSV exports

Use `ExportAction` for a streamed, query-wide CSV download. It belongs in
`headerActions()` so the current table search, filters, and sort are applied;
the browser receives an ordinary attachment response instead of an Inertia
visit:

```php
use Inlay\Tables\Actions\ExportAction;
use Inlay\Tables\Exports\ExportColumn;

$table->headerActions([
    ExportAction::make('export-users')
        ->label('Export CSV')
        ->filename('users.csv')
        ->columns([
            ExportColumn::make('name')->label('Name'),
            ExportColumn::make('email')->label('Email'),
            ExportColumn::make('status')->label('Status'),
        ])
        ->maximumRows(50_000)
        ->authorizeUsing(fn (Request $request): bool => $request->user()?->can('export', User::class) === true),
]);
```

If `columns()` is omitted, every declared table column is exported. A column
can transform its value with `stateUsing(fn (mixed $state, array $row) => ...)`;
arrays become JSON, dates use ISO-8601, and scalar values remain spreadsheet
friendly. Filenames are restricted to safe names with a declared extension.
Exports currently target Eloquent-backed tables and enforce a hard row limit.

The same action can be placed in `bulkActions()` for a selection-aware POST
download. The table automatically changes the transport to POST and the React
and Vue adapters send only the selected keys, or the bounded query-wide
descriptor after “Select all matching records”:

```php
ExportAction::make('export-selected')
    ->label('Export selected')
    ->filename('selected-users.csv')
    ->columns([
        ExportColumn::make('name'),
        ExportColumn::make('email'),
    ])
    ->maximumSelection(10_000)
    ->authorizeUsing(fn (Request $request): bool => $request->user()?->can('export', User::class) === true),

// inside Table::bulkActions([...])
```

The server re-resolves the selection through the table's authorized base
query, reapplies only declared search, filters, and sorting, enforces the
minimum/maximum selection and row limits, and authorizes the action again.
Nothing trusts a primary-key list or filter name supplied by the browser.

Formats are driver-backed. CSV ships with the package. Install the official
optional XLSX adapter when you need a real Excel workbook:

```bash
composer require inlayphp/tables-xlsx
```

Then configure its driver without changing the table schema:

```php
use Inlay\Tables\Xlsx\PhpSpreadsheetExportDriver;

ExportAction::make('export-xlsx')
    ->format('xlsx')
    ->driver(PhpSpreadsheetExportDriver::class)
    ->filename('users.xlsx');
```

Drivers own only serialization and response headers; they never resolve
untrusted columns or bypass the table's authorization boundary. The
`inlayphp/tables-xlsx` README documents workbook formatting, explicit string
cells, and the same selection/row-limit behavior as CSV. Community packages can
implement `Inlay\Tables\Contracts\ExportDriver` for PDF, ODS, or other
formats without adding dependencies to core tables. Queued XLSX drivers can use
the same selection descriptor and dispatch their own application-owned job,
while the synchronous CSV contract remains dependency-free.

### Queued exports

Large exports can cross the queue boundary without serializing a Builder,
Request, closure, or Action instance. Register an export in `bulkActions()` and
give it an application-owned job:

```php
use App\Jobs\BuildUsersExport;
use Inlay\Tables\Actions\ExportAction;

ExportAction::make('queue-users')
    ->label('Queue export')
    ->filename('users.csv')
    ->queueUsing(BuildUsersExport::class, queue: 'exports')
    ->authorizeUsing(fn (Request $request): bool => $request->user()?->can('export', User::class) === true),
```

`bulkActions()` automatically selects the POST transport. The job receives one
`Inlay\Tables\Exports\QueuedExport` value object:

```php
final class BuildUsersExport implements ShouldQueue
{
    public function __construct(public readonly QueuedExport $export) {}

    public function handle(): void
    {
        // Re-resolve the table definition, re-authorize the actor, and write a
        // CSV/XLSX file to application storage. Publish a signed download URL
        // through the application's notification system.
    }
}
```

The payload contains the table/action names, format, safe filename, compact
query state, bounded page/query selection, and safe export-column metadata. It
does not contain a query builder or executable PHP. The endpoint returns
`202 application/json` with the `inlay.tables.export.v1` contract, so React and
Vue show the queued message instead of saving the JSON response as a file.
The application-owned job remains responsible for worker-time authorization,
file retention, signed URLs, progress, and notifications; this keeps storage
and queue policy out of the clean table package.

External data-source tables do not receive implicit lifecycle endpoints because their adapter owns authoritative record lookup. Give those actions an explicit URL and execute `ActionRunner` after resolving records through the remote system.

## Manual rows and pagination

### External and custom data sources

Use `dataSource()` when a table is backed by an API, search index, repository, cached projection, or another non-Eloquent system. A standalone `TablePage` using a data source does not need to implement `query()`:

```php
use Inlay\Tables\Data\TableDataRequest;
use Inlay\Tables\Data\TableDataResult;

protected function table(Table $table): Table
{
    return $table
        ->primaryKey('uuid')
        ->columns([
            TextColumn::make('name')->searchable()->sortable(),
            BadgeColumn::make('status'),
        ])
        ->filters([
            SelectFilter::make('status')->options([
                'active' => 'Active',
                'archived' => 'Archived',
            ]),
        ])
        ->dataSource(function (TableDataRequest $request): TableDataResult {
            $response = $this->directory->search(
                search: $request->search,
                status: $request->filters['status'] ?? null,
                sort: $request->sort,
                direction: $request->direction,
                primaryKey: $request->primaryKey,
                defaultKeySort: $request->defaultKeySort,
                page: $request->page,
                perPage: $request->perPage,
            );

            return new TableDataResult(
                rows: $response->items,
                pagination: [
                    'mode' => 'length-aware',
                    'currentPage' => $response->page,
                    'lastPage' => $response->lastPage,
                    'perPage' => $response->perPage,
                    'total' => $response->total,
                ],
                total: $response->total,
            );
        });
}
```

`TableDataRequest` contains a normalized table name, search, allow-listed sortable column, `asc`/`desc` direction, declared filters only, page, cursor, page size, pagination mode, an allow-listed active group/direction, and the table's `primaryKey` plus `defaultKeySort` policy. An external adapter should append that key to its ordering when the policy is enabled, using the active direction, to keep page boundaries stable. Unknown sort, filter, or group names never reach the adapter. `TableDataResult` accepts arrays, JSON-serializable values, or objects and requires explicit pagination metadata whenever pagination is enabled.

Remote systems may return query-wide and group-wide aggregate values without rebuilding Inlay's presentation metadata. Values follow the configured summarizer order; Inlay checks the column and count, then adds labels, money/number formatting, prefixes, and suffixes from the PHP column definition:

```php
TextColumn::make('amount')->summarize([
    Sum::make()->money('USD'),
    Count::make(),
]);

return new TableDataResult(
    rows: $response->items,
    pagination: $response->pagination,
    total: $response->total,
    querySummaryValues: ['amount' => [$response->totalAmount, $response->total]],
    groupSummaryValues: [
        'open' => ['amount' => [$response->openAmount, $response->openCount]],
    ],
);
```

For reusable or community adapters, implement `Inlay\Tables\Contracts\TableDataSource`. Implement `ProcessesTableSelections` when the remote system can process query-wide selections and `ReordersTableRecords` when it can persist an ordered key list. The callback convenience API exposes both optional capabilities:

```php
$table->dataSource(
    source: fn (TableDataRequest $request): TableDataResult => $repository->table($request),
    selectionProcessor: function (
        BulkSelection $selection,
        TableDataRequest $request,
        Closure $consume,
        int $chunkSize,
    ) use ($repository): int {
        return $repository->eachSelectedChunk(
            request: $request,
            selection: $selection,
            chunkSize: $chunkSize,
            callback: $consume,
        );
    },
    recordReorderer: function (
        array $keys,
        int $startPosition,
        TableDataRequest $request,
    ) use ($repository): void {
        $repository->reorder($keys, $startPosition, $request->reorderDirection);
    },
);
```

External reordering uses the same explicit `reorderable(..., authorizeUsing: ...)` declaration, 2–500 unique-key limit, starting-position validation, and standalone PATCH route as Eloquent tables. This is a real extension boundary: adapters own external query execution, cursor semantics, aggregate calculation, and persistence while Inlay owns input allow-listing, authorization, renderer contracts, result validation, and operation limits. Unsupported selection or reordering fails explicitly instead of silently loading or mutating remote records.

### Pre-resolved rows

For non-Eloquent sources, provide normalized rows and pagination explicitly:

```php
Table::make('events')
    ->primaryKey('uuid')
    ->columns([...])
    ->rows($events)
    ->pagination([
        'currentPage' => 1,
        'lastPage' => 4,
        'perPage' => 20,
        'total' => 76,
    ]);
```

Rows may be arrays, `JsonSerializable` objects, or ordinary objects.

## Serialized contract

The payload contains:

```json
{
  "contract": "inlay.tables.v1",
  "type": "table",
  "name": "users",
  "primaryKey": "id",
  "searchPlaceholder": "Search users",
  "columns": [],
  "filters": [],
  "actions": [],
  "headerActions": [],
  "bulkActions": [],
  "rows": [],
  "recordUrls": { "1": "/users/1" },
  "openRecordUrlInNewTab": false,
  "pagination": { "mode": "length-aware", "currentPage": 1, "lastPage": 1 },
  "pollIntervalMs": 10000,
  "deferLoading": false,
  "columnManager": { "deferred": true, "persistInSession": true, "reorderable": false },
  "reordering": { "enabled": true, "url": "/users", "method": "patch", "direction": "asc" },
  "queryPersistence": { "search": false, "sort": false, "filters": false },
  "selectable": true,
  "selection": { "recordKeys": [1, 2], "maximum": null, "selectAllMode": "query", "total": 1240 },
  "deferFilters": true,
  "query": { "search": "", "sort": null, "direction": "asc", "page": 1, "cursor": null, "filters": {}, "loaded": true },
  "emptyState": { "heading": "No users", "description": null }
}
```

Column, filter and action `type` values are frontend renderer keys.

## Named table views

Tables can expose server-authored, allow-listed query presets. A view may set
search, sorting, filters, grouping, and page-size defaults. Browser-supplied
state always wins, and the selected view is available as `{table}_view` in the
query string. Views are always data-only. Personal views are opt-in and use the
same contract:

```php
use Inlay\Tables\Table;
use Inlay\Tables\Views\TableView;

$table = Table::make('users')
    ->columns([
        TextColumn::make('name')->searchable()->sortable(),
    ])
    ->views([
        TableView::make('active')
            ->label('Active users')
            ->description('Accounts that can sign in.')
            ->filters(['status' => 'active'])
            ->sort('name', 'asc')
            ->default(),
        TableView::make('invited')
            ->label('Invited users')
            ->filters(['status' => 'invited']),
    ]);
```

Both `@inlayphp/tables-react` and `@inlayphp/tables-vue` render a Saved view
control when views are present and emit the same `QueryState.view` value.

### Personal views

Standalone `TablePage` routes use a session-scoped store by default. The page
automatically handles the `Save view`, `Edit view`, and `Delete view` transports
when the current visitor is authenticated and the table opts in:

```php
use Inlay\Tables\Contracts\TableViewStore;

return $table
    ->views([TableView::make('active')->filters(['status' => 'active'])])
    ->personalViews(app(TableViewStore::class), auth()->id());
```

The store receives the owner key from PHP; it is never accepted from the
browser. To persist views across sessions and devices, bind the first-party
database driver and publish its migration:

```php
use Inlay\Tables\Contracts\TableViewStore;
use Inlay\Tables\Views\DatabaseTableViewStore;

$this->app->bind(TableViewStore::class, fn ($app) => new DatabaseTableViewStore(
    $app->make('db')->connection(),
));
```

```bash
php artisan vendor:publish --tag=inlay-table-migrations
php artisan migrate
```

Custom stores implement `TableViewStore` when views belong to a team or tenant
instead of an individual user. Stale persisted records are ignored while
loading, and table-defined views cannot be overwritten by a personal view.

## Styling hooks

Both renderers emit the same `data-slot` names, so one stylesheet works against React and Vue:

| Element | `data-slot` |
| --- | --- |
| Table root | `root` |
| Toolbar, search input | `toolbar`, `search` |
| Header actions | `header-actions` |
| Horizontal scroll container | `table-scroll` |
| `<table>`, `<thead>` | `table`, `table-head` |
| Each row, each cell | `table-row` (plus `data-row-key`), `table-cell` |
| Row action group | `row-actions` |
| Bulk action bar | `bulk-actions` |
| Pagination, its page buttons, its per-page control | `pagination`, `pagination-pages`, `pagination-per-page` |

Filters, summaries, grouping, the column manager, and the query builder expose their own
slots too — `filters`, `filter-indicators`, `summaries`, `group-header`, `column-manager`,
`query-builder`, and the rest.

```css
.orders [data-slot='table-row']:hover { background: #fafafa; }
.orders [data-slot='pagination'] { justify-content: center; }
```

## Testing

Use `TableTester` for renderer-neutral structure, record, order, and cell-state assertions:

```php
use Inlay\Tables\Testing\TableTester;

TableTester::make($resolvedTable)
    ->assertTableColumnExists('email', fn (TextColumn $column): bool => $column->name() === 'email')
    ->assertTableColumnDoesNotExist('secret')
    ->assertTableFilterExists('status')
    ->assertTableActionExists('edit')
    ->assertTableHeaderActionExists('create')
    ->assertTableBulkActionExists('archive')
    ->assertCountTableRecords(10)
    ->assertCanSeeTableRecords($visibleUsers)
    ->assertCanNotSeeTableRecords($hiddenUsers)
    ->assertTableColumnStateSet('active', true, $user);
```

Pass `inOrder: true` to `assertCanSeeTableRecords()` when sorting order matters. Records may be Eloquent models, primary-key strings/integers, or serialized row arrays. The tester reads the same `inlay.tables.v1` payload consumed by React and Vue, including server-resolved presentation state.

`Table` also exposes `getAction()`, `getHeaderAction()`, and `getBulkAction()` plus plural variants for package extensions and custom testers. Bulk lookups flatten `ActionGroup` definitions while serialized output preserves the groups. Production lifecycle endpoints enforce `BulkAction::minimumSelection()` and `maximumSelection()` against the re-queried authorized records, not only in the browser.

For search, sort, filters, editable persistence, and Resource mutations, use the Resource tester documented in `inlayphp/resources`; it rebuilds the table through the authoritative Eloquent query for every interaction.

```bash
# monorepo root
composer test
```

Use the adapter-local `pnpm test -- --run`, `pnpm typecheck`, and `pnpm build` commands for frontend verification.

## Related packages

- `inlayphp/actions` and the matching action frontend adapter.
- `inlayphp/resources` for CRUD resource pages.
- `inlayphp/support` for URL policy.
- `@inlayphp/tables-react` and `@inlayphp/tables-vue`.
