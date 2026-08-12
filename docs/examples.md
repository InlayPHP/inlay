# Usage examples

## Laravel form definition

```php
final class UserForm
{
    public static function make(?User $user = null): Form
    {
        return Form::make('user')
            ->action($user ? route('users.update', $user) : route('users.store'))
            ->method($user ? 'put' : 'post')
            ->columns(2)
            ->validation(UserRules::class, $user ? 'update' : 'create')
            ->precognitive(mode: 'blur', debounce: 350)
            ->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('email')->email()->required(),
                Select::make('account_type')
                    ->options(['personal' => 'Personal', 'company' => 'Company'])
                    ->default('personal')
                    ->live(),
                TextInput::make('company_name')
                    ->visibleWhen('account_type', 'company')
                    ->requiredWhen('account_type', 'company'),
                Select::make('role')->options(Role::labels())->searchable(),
                Toggle::make('active')->default(true),
                Repeater::make('addresses')->schema([
                    TextInput::make('street')->required(),
                    TextInput::make('city')->required(),
                ])->columns(2),
            ])
            ->data($user?->toArray() ?? []);
    }
}
```

## Reactive form conditions

Conditions are evaluated immediately by both adapters and remain serializable across the Inertia boundary:

```php
use Inlay\Support\Condition;

TextInput::make('company_name')
    ->visibleWhen('account_type', 'company')
    ->requiredWhen('account_type', 'company')
    ->disabledWhen(Condition::falsy('enabled'));

TextInput::make('tax_id')
    ->hiddenWhen(Condition::blank('country'))
    ->live(onBlur: true, debounce: 350);
```

Available operators are `equals`, `not-equals`, `in`, `not-in`, `truthy`, `falsy`, `filled`, and `blank`. An equality-based `requiredWhen()` also produces the matching Laravel `required_if` validation rule.

Client adapters can observe fields marked `live()` without replacing their ordinary change handlers:

```tsx
<Form
  resource={userForm}
  onLiveChange={({ path, value, data }) => {
    console.log(path, value, data)
  }}
/>
```

```vue
<Form
  :resource="userForm"
  @live-change="({ path, value, data }) => console.log(path, value, data)"
/>
```

Immediate, per-field debounce, and blur modes are supported. The live event is an integration hook; it does not automatically make a server request.

## Laravel table definition

```php
final class UsersTable
{
    public static function make(LengthAwarePaginator $users): Table
    {
        return Table::make('users')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable(),
                BadgeColumn::make('status')->colors([
                    'active' => 'success',
                    'disabled' => 'danger',
                ]),
            ])
            ->filters([
                SelectFilter::make('status')->options(Status::labels()),
            ])
            ->actions([
                Action::make('edit')->url('/users/{id}/edit'),
                Action::make('delete')->method('delete')->color('danger')->requiresConfirmation(),
            ])
            ->rows($users->items())
            ->pagination([
                'currentPage' => $users->currentPage(),
                'lastPage' => $users->lastPage(),
                'total' => $users->total(),
            ]);
    }
}
```

Buildable adapter examples live in `examples/react` and `examples/vue`.

## React import wizard

```tsx
import { ImportWizard } from '@inlayphp/imports-react'

<ImportWizard
  resource={userImport}
  onUpload={({ file }) => imports.upload(file)}
  onPreview={(request) => imports.preview(request)}
  onStart={(request) => imports.start(request)}
  onPoll={({ job }) => imports.status(job.id)}
/>
```

The Vue adapter exposes the same four transport callbacks through `@inlayphp/imports-vue`. Both adapters support PHP-preloaded previews, per-step overrides, class maps, and semantic theme tokens.

## Laravel infolist

```php
use Inlay\Infolists\Entries\IconEntry;
use Inlay\Infolists\Entries\TextEntry;
use Inlay\Infolists\Infolist;
use Inlay\Schemas\Components\Section;

$details = Infolist::make('user-details')
    ->schema([
        Section::make('Account')->columns(2)->schema([
            TextEntry::make('name')->copyable(),
            TextEntry::make('email')->url('mailto:{state}'),
            IconEntry::make('active')->boolean(),
        ]),
    ])
    ->data($user->toArray());
```

Render the resource with `Infolist` from `@inlayphp/infolists-react` or `@inlayphp/infolists-vue`.

## PHP-defined admin panel

```php
use Inlay\NavigationGroup;
use Inlay\NavigationItem;
use Inlay\Panel;
use Inlay\PanelProvider;

final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->path('/admin')
            ->brandName('Inlay Admin')
            ->collapsible()
            ->navigationGroups([
                NavigationGroup::make('management')->items([
                    NavigationItem::make('users')->url('/admin/users'),
                ]),
            ]);
    }
}
```

The application frontend mounts one `Panel` from `@inlayphp/panels-react` or `@inlayphp/panels-vue`; navigation, branding, layout mode, and theme come from PHP.

## React theming and CSS customization

The React adapters accept lightweight theme tokens and root class names. Table also exposes typed per-slot class overrides:

When using Tailwind CSS 4, include the renderer-neutral class vocabulary in the
application stylesheet so control, select, and button tokens are generated:

```css
@source '../../node_modules/@inlayphp/*/src/**/*.{ts,tsx,vue}';
```

```tsx
const theme = {
  accent: '#0f766e',
  radius: '0.75rem',
}

<Form className="my-form" resource={userForm} theme={theme} />
<Table
  className="my-table"
  classNames={{ filtersPanel: 'my-filter-panel', applyButton: 'my-apply-button' }}
  resource={usersTable}
  theme={{ ...theme, controlHeight: '2.5rem' }}
/>
```

Stable `data-slot` attributes expose structural elements without depending on internal Tailwind classes. Form fields also expose `data-field`, while filters expose `data-filter`:

```css
.my-form [data-field='email'] {
  grid-column: 1 / -1;
}

.my-table [data-slot='toolbar'] {
  align-items: end;
}

.my-table [data-filter='status'] {
  min-width: 11rem;
}
```

Theme tokens cover fast brand changes. Root classes and data slots cover layout or product-specific styling without changing the PHP resource contract.
