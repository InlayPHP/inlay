# Resources and CRUD

A Resource is the recommended way to build model-centric CRUD. It keeps the
query, table, form, validation class, authorization, page routes, and
persistence lifecycle in one PHP class while leaving the frontend renderer
replaceable.

## Generate a Resource

```bash
php artisan make:inlay-resource User
```

The generator creates:

```text
app/Inlay/Resources/UserResource.php
app/Inlay/Resources/ListUsers.php
app/Inlay/Resources/CreateUser.php
app/Inlay/Resources/EditUser.php
app/Validation/UserRules.php
```

Useful options:

```bash
php artisan make:inlay-resource User --generate
php artisan make:inlay-resource User --view
php artisan make:inlay-resource User --soft-deletes
php artisan make:inlay-resource User --simple
```

`--generate` reads the model table once and creates an editable starting point.
It does not re-derive code on every request. Review generated rules and field
types before shipping them.

## Define the Resource

```php
<?php

namespace App\Inlay\Resources;

use App\Models\User;
use App\Validation\UserRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Inlay\Actions\Action;
use Inlay\Forms\Fields\Select;
use Inlay\Forms\Fields\TextInput;
use Inlay\Forms\Fields\Toggle;
use Inlay\Forms\Form;
use Inlay\Resources\Resource;
use Inlay\Resources\ResourceOperation;
use Inlay\Tables\Columns\BadgeColumn;
use Inlay\Tables\Columns\TextColumn;
use Inlay\Tables\Filters\SelectFilter;
use Inlay\Tables\Table;

final class UserResource extends Resource
{
    protected static string $model = User::class;

    protected static ?string $navigationIcon = 'users';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable(),
                BadgeColumn::make('status')->colors([
                    'active' => 'success',
                    'suspended' => 'danger',
                ]),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'active' => 'Active',
                    'suspended' => 'Suspended',
                ]),
            ])
            ->actions([
                Action::make('edit')->url('/admin/users/{id}/edit'),
                Action::make('delete')
                    ->url('/admin/users/{id}')
                    ->method('delete')
                    ->color('danger')
                    ->requiresConfirmation(),
            ]);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->columns(2)
            ->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('email')->email()->required(),
                Select::make('role')->options([
                    'admin' => 'Administrator',
                    'member' => 'Member',
                ]),
                Toggle::make('active')->default(true),
            ]);
    }

    public static function validation(): string
    {
        return UserRules::class;
    }

    protected static function modifyEloquentQuery(Builder $query): Builder
    {
        return $query->where('is_archived', false);
    }

    protected static function canAccess(
        ResourceOperation $operation,
        ?Model $record,
        mixed $user,
    ): bool {
        return $user?->can($operation->policyAbility(), $record ?? User::class) ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
```

The frontend receives the Table and Form contracts from the page props. It
does not query Eloquent or decide which rows are visible.

## Page classes

Page classes connect a Resource to an Inertia component:

```php
namespace App\Inlay\Resources;

use Inlay\Resources\Pages\ListRecords;

final class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;
    protected static string $component = 'users/index';
}
```

The generated create and edit classes select `users/form` and pass the
operation and record into the Form contract. You may override page methods for
tabs, header actions, widgets, custom breadcrumbs, or a different component.

## Register Resource routes

Inside a panel, register Resources on the provider:

```php
return $panel
    ->path('/admin')
    ->authMiddleware(['auth'])
    ->resources([
        UserResource::class,
        OrderResource::class,
    ]);
```

The panel supplies URL prefixes, route names, middleware, panel props, and
navigation. Outside a panel, use the Resource facade:

```php
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Inlay\Resources\Facades\InlayResources;

InlayResources::routes([UserResource::class], [
    'middleware' => ['web', 'auth'],
    'mutationMiddleware' => [HandlePrecognitiveRequests::class],
    'prefix' => 'admin',
    'name' => 'admin.inlay.',
]);
```

The normal generated route set is:

| Method | URI | Operation |
| --- | --- | --- |
| `GET` | `/users` | list records and Table props |
| `GET` | `/users/create` | create form |
| `GET` | `/users/{record}/edit` | edit form |
| `POST` | `/users` | create record |
| `PATCH` | `/users/{record}` | update record |
| `DELETE` | `/users/{record}` | delete record |

## Authorization and scoping

Resources fail closed. Define `canAccess()` or use a model policy. The Resource
query is used for list rows, record lookup, global search, relation managers,
and mutations:

```php
protected static function canAccess(
    ResourceOperation $operation,
    ?Model $record,
    mixed $user,
): bool {
    return $user?->can($operation->policyAbility(), $record ?? static::model()) ?? false;
}

protected static function modifyEloquentQuery(Builder $query): Builder
{
    return $query->where('workspace_id', auth()->user()->workspace_id);
}
```

Do not rely on a hidden navigation item as authorization. Do not use
`Model::findOrFail()` in a page controller when a scoped Resource query is
available; that can bypass tenant or visibility constraints.

## Persistence lifecycle

Resource mutations follow this order:

```text
resolve scoped record
  → authorize operation
  → prepare and validate input
  → begin transaction
  → before hook
  → mutate validated data
  → create / update / delete model
  → after hook
  → commit
  → redirect with a success message
```

Customize small steps instead of replacing the whole controller:

```php
protected static function mutateDataBeforeCreate(array $data): array
{
    return [
        ...$data,
        'created_by' => auth()->id(),
    ];
}

protected static function afterCreate(Model $record, array $data): void
{
    UserCreated::dispatch($record);
}
```

Keep side effects in the after hook or a queued event. The database write and
Resource lifecycle hooks are transactional; external APIs should be designed
to tolerate retries.

## Record titles and breadcrumbs

Declare a title attribute once:

```php
public static function recordTitleAttribute(): ?string
{
    return 'name';
}
```

The title is reused in breadcrumbs, global search, relation links, and action
messages. Override `recordTitle()` when the title combines fields.

Resource pages publish a `breadcrumbs` prop. The official adapters expose
`ResourceBreadcrumbs` and `ResourcePage` so a custom page can keep the same
accessible trail without copying panel markup.

## Global search

Opt a Resource into the panel search:

```php
public static function globallySearchableAttributes(): array
{
    return ['name', 'email'];
}
```

Search is executed through the Resource's scoped query and authorization. Terms
shorter than two characters are ignored. A Resource that returns no attributes
does not appear in global search.

## List tabs and relation pages

Use named tabs when a list has common server-defined subsets:

```php
protected function tabs(): array
{
    return [
        PageTab::make('all')->label('All')->default(),
        PageTab::make('active')
            ->label('Active')
            ->modifyQueryUsing(fn (Builder $query): Builder =>
                $query->where('status', 'active')
            ),
    ];
}
```

The browser sends only the tab key. The server owns the query. Unknown tabs
fall back to the default.

For relations, generate an owner-scoped manager:

```bash
php artisan make:inlay-relation-manager UserResource posts title
```

Register the generated manager on the Resource and add a page such as
`ManageUserPosts`. Relation managers reuse Forms, Tables, validation, policies,
and the parent Resource scope.

## Soft deletes

Generate the soft-delete conventions:

```bash
php artisan make:inlay-resource Invoice --soft-deletes
```

The generated Resource can expose a trashed filter and restore/force-delete
actions. Keep `SoftDeletes` on the model and authorize restore and permanent
delete separately; a user who may edit a record should not automatically be
allowed to destroy it permanently.

## Frontend pages

A minimal React list page can render the contracts directly:

```tsx
import { Form } from '@inlayphp/forms-react';
import { Table } from '@inlayphp/tables-react';
import InlayPanelLayout from '../../layouts/inlay-panel-layout';

export default function UsersIndex({ table, errors }) {
    return (
        <InlayPanelLayout>
            <Table resource={table} errors={errors} />
        </InlayPanelLayout>
    );
}
```

Generated pages are preferable because they already connect the panel shell,
Inertia navigation, errors, and page props. Use a custom page when the layout
needs application-specific composition, not to duplicate authorization logic.

## Testing a Resource

Feature-test each route and policy boundary:

```php
it('keeps users inside the current workspace', function (): void {
    $this->actingAs($admin)
        ->get('/admin/users')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('users/index')
            ->has('table')
            ->where('inlayPanel.id', 'admin'));

    $this->actingAs($admin)
        ->patch('/admin/users/'.$foreignUser->id, [
            'name' => 'Attempted access',
        ])
        ->assertNotFound();
});
```

Also test invalid input, unauthorized create/update/delete, successful
redirects, and the browser renderer's table/form contract tests.
