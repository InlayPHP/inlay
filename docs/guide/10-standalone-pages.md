# Standalone Forms and Tables

Panels and Resources are convenient, but they are not required. Forms and
Tables can be mounted on ordinary Inertia routes using the same PHP contracts.
This is useful for public checkout flows, reports, settings pages, and
applications that already have their own shell.

## Standalone Form page

Generate a page:

```bash
php artisan make:inlay-form-page Billing/CreateInvoice --model=Invoice
```

The generator prints a route macro. Register it in `routes/web.php`:

```php
use App\Inlay\Forms\CreateInvoice;
use Illuminate\Support\Facades\Route;

Route::inlayForm('/billing/invoices/create', CreateInvoice::class)
    ->middleware(['web', 'auth'])
    ->name('billing.invoices.create');
```

Define the page:

```php
namespace App\Inlay\Forms;

use App\Models\Invoice;
use App\Validation\InvoiceRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inlay\Forms\Fields\TextInput;
use Inlay\Forms\Form;
use Inlay\Forms\FormPage;

final class CreateInvoice extends FormPage
{
    protected static string $component = 'billing/create-invoice';

    protected function form(Form $form): Form
    {
        return $form
            ->submitLabel('Create invoice')
            ->validation(InvoiceRules::class, operation: 'create')
            ->precognitive()
            ->schema([
                TextInput::make('reference')->required(),
                TextInput::make('amount')->numeric()->required(),
            ]);
    }

    protected function submit(array $data, Request $request): RedirectResponse
    {
        Invoice::create([
            ...$data,
            'user_id' => $request->user()->getAuthIdentifier(),
        ]);

        return back()->with('success', 'Invoice created.');
    }
}
```

`FormPage` supplies the current URL as the form action. The route macro handles
display and mutation methods on one URL and attaches Precognition middleware.
The form method determines whether the browser submits `POST`, `PUT`, `PATCH`,
or `DELETE`.

Override these methods when needed:

```php
protected function data(Request $request): array
{
    return ['currency' => $request->user()->currency];
}

protected function props(Request $request): array
{
    return ['currencies' => Currency::options()];
}

protected function rules(): array
{
    return ['reference' => ['required', 'alpha_dash']];
}
```

Use `HasForms` and `InteractsWithForms` when inheritance is not appropriate:

```php
final class CheckoutFormProvider implements HasForms
{
    use InteractsWithForms;

    protected function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('email')->email()->required(),
        ]);
    }
}
```

## Standalone Table page

Generate a page:

```bash
php artisan make:inlay-table-page Reports/ListInvoices --model=Invoice
```

Register it:

```php
use App\Inlay\Tables\ListInvoices;

Route::inlayTable('/reports/invoices', ListInvoices::class)
    ->middleware(['web', 'auth'])
    ->name('reports.invoices');
```

Define the table and query:

```php
namespace App\Inlay\Tables;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inlay\Tables\Columns\BadgeColumn;
use Inlay\Tables\Columns\TextColumn;
use Inlay\Tables\Filters\SelectFilter;
use Inlay\Tables\Table;
use Inlay\Tables\TablePage;

final class ListInvoices extends TablePage
{
    protected static string $component = 'reports/list-invoices';

    protected function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')->searchable()->sortable(),
                TextColumn::make('customer.name')->searchable(),
                BadgeColumn::make('status'),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'draft' => 'Draft',
                    'paid' => 'Paid',
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    protected function query(Request $request): Builder
    {
        return Invoice::query()
            ->where('workspace_id', $request->user()->workspace_id);
    }

    protected function perPage(): int
    {
        return 25;
    }
}
```

The `TablePage` controller supplies the current query string automatically.
Search, filters, sorting, and pagination remain server-driven. The React page
is intentionally small:

```tsx
import { Table } from '@inlayphp/tables-react';

export default function ListInvoices({ table }) {
    return <Table resource={table} />;
}
```

Use `HasTables` and `InteractsWithTables` for a widget or plugin page that does
not extend `TablePage`.

## Multiple forms or tables on one URL

Named forms use a selector such as `_inlay_form=password`, with separate error
bags. Named tables namespace query state:

```text
recent_users_search=Ada
recent_users_page=2
failed_imports_filters[type]=csv
failed_imports_page=1
```

This lets a dashboard render two independent tables without one search box
changing the other table.

## Direct controller construction

The macros are convenience APIs, not a replacement for Laravel routes:

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

Use this style when a host application already has a controller pipeline or a
custom Inertia response. The same contract, renderer, theme, and tests apply.

## Test standalone pages

Use the package testers in feature tests:

```php
use Inlay\Forms\Testing\FormPageTester;
use Inlay\Tables\Testing\TableTester;

FormPageTester::make(CreateInvoice::class)
    ->assertFormFieldExists('reference');

TableTester::make($resolvedTable)
    ->assertTableColumnExists('reference')
    ->assertTableFilterExists('status');
```

Also send real GET and mutation requests through the route so middleware,
authorization, Precognition, and redirects are covered.
