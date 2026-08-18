# Inlay

[![Tests](https://img.shields.io/github/actions/workflow/status/inlayphp/inlay/tests.yml?branch=main&style=flat-square&label=tests)](https://github.com/inlayphp/inlay/actions/workflows/tests.yml)
[![Packagist](https://img.shields.io/packagist/v/inlayphp/inlay?style=flat-square&label=packagist)](https://packagist.org/packages/inlayphp/inlay)
[![PHP](https://img.shields.io/packagist/dependency-v/inlayphp/inlay/php?style=flat-square)](composer.json)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](LICENSE)

**PHP-first, schema-driven UI for Laravel and Inertia — one server contract, rendered by React or Vue.**

Inlay is a PHP-first, schema-driven UI framework for Laravel and Inertia. It brings a fluent developer experience for forms, tables, actions, infolists, resources, and admin panels to applications that use React or Vue.

Laravel defines the interface and owns authorization, validation, querying, and persistence. Inlay serializes that intent into stable, versioned contracts. Small React and Vue packages render those contracts and handle accessible browser interaction.

```text
Laravel Resource / Form / Table
              │
              ▼
   versioned Inertia props
              │
       ┌──────┴──────┐
       ▼             ▼
 React renderer   Vue renderer
```

The main goals are:

- Make ordinary CRUD interfaces fast to write from Laravel PHP.
- Keep business rules, policies, validation, and queries on the server.
- Give React and Vue the same public component contract.
- Allow applications and community packages to add renderers, fields, columns, actions, themes, and plugins without forking Inlay.
- Keep each feature independently installable.

## Status

Inlay is under active pre-release development. The monorepo currently includes:

- Fluent PHP builders and versioned JSON contracts.
- Complete initial Form, Table, Schema, Action, Infolist, Import, and Panel component catalogs.
- PHP-first Resources with automatic Laravel routes and Inertia page delivery.
- Authenticated admin panels with automatic Resource routes and navigation.
- Native Laravel Gate/Policy authorization, optional Spatie role synchronization, and a Dcat-inspired permission manager.
- A secure media catalog core with private-by-default uploads, logical folders, trash/restore, and transformation hooks.
- Authorized and centrally validated create, update, and delete lifecycles.
- React 19 and Vue 3 renderers with matching behavior tests.
- Custom renderer registries, plugin manifests, asset registries, and render hooks.
- Semantic theme tokens, stable `data-slot` hooks, and typed class overrides.
- A shared neutral base theme, polished default admin theme, accessible high-contrast mode, dark mode, and PHP-defined custom themes.
- A public `inlayphp/design` / `@inlayphp/design` façade with `make:inlay-theme` CSS generation for application-owned themes.
- PHP-first stats, chart, and table dashboard widgets with matching React and Vue renderers.
- Opt-in, request-keyed dashboard widget caching through the Laravel cache repository, without weakening authorization or tenant scoping.
- Session- or database-backed, transport-safe notifications with optional action integration and matching React/Vue renderers.
- A Laravel 12, Inertia v3, React 19 playground using the local packages.

The API may still change before the first stable release. See [Roadmap](#roadmap) for the largest remaining compatibility areas.

Official React and Vue renderers share accessible controls through `@inlayphp/ui-react` and `@inlayphp/ui-vue`. This keeps inputs, focus treatment, custom selects, dialogs, menus, and plugin screens visually consistent without moving PHP component definitions into JavaScript.

## Requirements

- PHP 8.3 or newer
- Laravel 12
- Inertia Laravel 3 for Resource page delivery
- React 19 or Vue 3 for the supplied frontend renderers
- Node.js and pnpm for monorepo development

Individual packages may require fewer dependencies. For example, the schema and support packages do not require an Inertia application.

## Package map

### Clean core Composer packages

| Package | Responsibility |
| --- | --- |
| `inlayphp/core` | Plugin lifecycle, extension manifests, frontend assets, registries, and render hooks |
| `inlayphp/support` | Safe URLs, serializable conditions, and shared low-level contracts |
| `inlayphp/schemas` | Layout components such as sections, grids, tabs, wizards, groups, and callouts |
| `inlayphp/validation` | Central Laravel validation classes shared across forms, requests, imports, APIs, actions, and resources |
| `inlayphp/actions` | Reusable actions, bulk actions, confirmation dialogs, modal metadata, and endpoints |
| `inlayphp/forms` | Form schemas, editable fields, reactive conditions, data, actions, and validation metadata |
| `inlayphp/tables` | Columns, filters, searching, sorting, pagination, selection, row actions, and bulk actions |
| `inlayphp/infolists` | Read-only record presentation and nested entry schemas |
| `inlayphp/notifications` | Session- or database-backed, transport-safe notifications for Inertia and Laravel |
| `inlayphp/panels` | Complete panel runtime: registration, authentication, dashboards, routes, navigation, themes, Resources, and plugins |
| `inlayphp/theme` | Shared base/default theme presets and semantic light/dark token contracts |
| `inlayphp/design` | Public design-system façade, CSS variable generation, and application theme generator |
| `inlayphp/widgets` | PHP-first dashboard stats, charts, tables, providers, and panel integration |
| `inlayphp/resources` | Model-centric CRUD orchestration across tables, forms, infolists, pages, policies, and persistence |
| `inlayphp/authorization` | Owned ability registry and Laravel Gate/Policy decision bridge |

These packages are installed by `inlayphp/inlay`. They provide the panel and
component framework without activating a database permission system, media
library, or import workflow in the generated panel.

### Optional official Composer packages

| Package | Responsibility |
| --- | --- |
| `inlayphp/imports` | Column mapping, preview validation, fault-isolated processing, results, and failure downloads |
| `inlayphp/authorization-spatie` | Spatie permission synchronization, super-admin, cache, and team scoping adapter |
| `inlayphp/permission-manager` | Dcat-inspired roles, permissions, user assignment, and ability-sync audit panel plugin |
| `inlayphp/media` | Secure storage-neutral media catalog, uploads, folders, albums, visibility, trash, and transformations |
| `inlayphp/media-manager` | Authorized panel media browser, album filter, and picker plugin with a versioned Inertia contract |
| `inlayphp/media-spatie` | Optional zero-copy bridge between the Inlay catalog and Spatie Media Library |
| `inlayphp/tables-xlsx` | Optional PhpSpreadsheet XLSX export driver with typed cells, filters, selection, row limits, and formula-injection protection |
| `inlayphp/two-factor-authentication` | Optional encrypted TOTP enrollment, recovery codes, and panel login challenge step |

The CMS family is intentionally excluded from this initial public monorepo
bootstrap. It will be released separately after its own package and contract
review, without changing the core panel, form, table, resource, or plugin APIs.

### Frontend packages

Framework-neutral runtime packages:

- `@inlayphp/core`
- `@inlayphp/actions`
- `@inlayphp/theme`
- `@inlayphp/design`
- `@inlayphp/ui`
- `@inlayphp/notifications`

React packages:

- `@inlayphp/ui-react`
- `@inlayphp/actions-react`
- `@inlayphp/forms-react`
- `@inlayphp/tables-react`
- `@inlayphp/infolists-react`
- `@inlayphp/notifications-react`
- `@inlayphp/imports-react`
- `@inlayphp/panels-react`
- `@inlayphp/permission-manager-react`
- `@inlayphp/media-manager-react`
- `@inlayphp/widgets-react`
- `@inlayphp/two-factor-authentication-react`

Vue packages:

- `@inlayphp/ui-vue`
- `@inlayphp/actions-vue`
- `@inlayphp/forms-vue`
- `@inlayphp/tables-vue`
- `@inlayphp/infolists-vue`
- `@inlayphp/notifications-vue`
- `@inlayphp/imports-vue`
- `@inlayphp/panels-vue`
- `@inlayphp/media-manager-vue`
- `@inlayphp/widgets-vue`
- `@inlayphp/two-factor-authentication-vue`

The monorepo keeps PHP builders and their React/Vue adapters near each other so releases can keep both sides of a contract compatible.

## Installation

Install the complete administration framework with two commands:

```bash
composer require inlayphp/inlay:"^0.3"
php artisan inlay:install --panels
```

This works on a plain `laravel new` application too: the installer creates the
missing Inertia entrypoint (`resources/js/app.tsx` for React or
`resources/js/app.ts` for Vue), root Blade view, `HandleInertiaRequests`
middleware, and renderer/Vite dependencies before it creates the panel. It also
creates and registers `AdminPanelProvider`, enables the default theme,
authentication and account settings, generates a working User Resource,
redirects guests to the panel login, scaffolds the official renderer pages, and
configures Tailwind to scan the installed `@inlayphp/*` npm packages. Media is
available as an explicit opt-in with `--media`, which registers the Media
Manager, publishes its migrations, and adds its renderer page. The original
Laravel `app.js`
entrypoint is kept in the Vite inputs when present, so a stock welcome route
continues to work. It detects npm, pnpm, Yarn, or Bun and installs the required
renderer packages. Run the printed migration, user, and frontend build
commands, then open `/admin`.

```bash
php artisan migrate
php artisan inlay:make-user
npm run build
php artisan inlay:doctor --production
```

The installer writes this Tailwind CSS 4 source rule automatically. Keep it in
the application stylesheet when deploying from a standalone repository:

```css
@source '../../node_modules/@inlayphp/*/src/**/*.{ts,tsx,vue}';
```

`inlay:doctor` checks panel registration, the official renderer dependency,
Tailwind source discovery, generated User and Media files, and compiled CSS.
Run it without `--production` before building, or with `--production` after the
Vite build to prove the deployed stylesheet contains Inlay utilities.

`inlay:make-user` uses hidden password and confirmation prompts. Its `--name`,
`--email`, and `--password` options are available for controlled automation, but
putting passwords directly in shell history is not recommended.

Use `--panel=reports` for another panel id, `--media` to install the optional
Media Manager, `--without-media` as a backwards-compatible no-op alias, or
`--without-users` for a smaller preset. Use `--no-npm` when CI manages frontend
installation separately, and `--force` to replace generated application files.
React and Vue are both turnkey renderer presets. Select Vue explicitly when the
application uses the Vue starter kit (or when starting from a plain Laravel
application):

```bash
php artisan inlay:install --panels --renderer=vue
```

The installer preserves existing starter-kit dependencies and application-owned
Vue entrypoints while adding the Inlay page wrappers and Vite/Tailwind wiring.

The installer is repeatable. Running it again preserves application-owned
providers, Resources, pages, and validation files while restoring missing
supporting files. Use `--force` only when you intentionally want to replace the
generated application code.

For a tenant-aware starter panel, provide the Eloquent tenant model while
installing. The generated provider imports the model and calls `tenant()` for
you; tenant membership is still decided by your model's `TenantAccess` and
`HasTenants` implementations:

```bash
php artisan inlay:install \
  --panel=workspace \
  --tenant-model='App\\Models\\Team' \
  --tenant-parameter=team \
  --tenant-route-key=slug
```

This generates routes such as `/{team}/workspace` and keeps the tenant
configuration in application-owned PHP, where it can be reviewed and extended.
Omit `--tenant-route-key` to use the model's normal Laravel route key.

The root package is the recommended all-in-one installation foundation and
includes the storage-neutral media catalog dependencies. Media Manager is
activated only with `php artisan inlay:install --panels --media`; roles,
database permissions, Spatie adapters, imports, two-factor authentication, and
CMS features remain separate plugins so a default panel stays understandable.

Install only the features an application uses. A Form and Table application typically needs:

```bash
composer require inlayphp/forms inlayphp/tables inlayphp/actions inlayphp/validation
```

For React:

```bash
pnpm add @inlayphp/forms-react @inlayphp/tables-react @inlayphp/actions-react
```

For Vue:

```bash
pnpm add @inlayphp/forms-vue @inlayphp/tables-vue @inlayphp/actions-vue
```

Add model-centric CRUD orchestration with:

```bash
composer require inlayphp/resources
pnpm add @inlayphp/resources @inlayphp/resources-react
```

Vue resource pages use `@inlayphp/resources-vue`. Both adapters render owner-scoped Relation Managers from the same PHP-defined Forms and Tables.

Compose Resources into a protected panel with:

```bash
composer require inlayphp/panels inlayphp/resources
pnpm add @inlayphp/panels-react
```

Add Dcat-style access management and the media library as independent panel plugins:

```bash
composer require inlayphp/permission-manager inlayphp/media-manager
pnpm add @inlayphp/permission-manager-react @inlayphp/media-manager-react
```

Their required backend foundations are installed transitively: Permission Manager installs the Spatie authorization adapter, and Media Manager installs the storage-neutral media catalog. Register them through the same panel plugin API used by community extensions:

```php
use Inlay\MediaManager\MediaManagerPlugin;
use Inlay\PermissionManager\PermissionManagerPlugin;

return $panel->plugins([
    PermissionManagerPlugin::make(),
    MediaManagerPlugin::make(),
]);
```

Use `@inlayphp/media-manager-vue` and `@inlayphp/permission-manager-vue` for a Vue panel. Both official plugin renderers expose the same page registries and contracts as their React counterparts. Install `inlayphp/imports` with its React or Vue renderer only when an application needs import workflows.

All public Composer packages are available from Packagist and the official
React/Vue renderers are available from npm. The monorepo uses local workspaces
only for development and release verification.

See [Installation and deployment](docs/installation.md) for clean Laravel,
standalone Forms/Tables, custom renderer, CI, and Laravel Cloud workflows.

## Quick start: a PHP-first Resource

Generate the resource, its list/create/edit pages, and a centralized validation class:

```bash
php artisan make:inlay-resource User
```

Standalone pages outside a panel have their own generators:

```bash
php artisan make:inlay-form-page Billing/CreateInvoice --model=Invoice
php artisan make:inlay-table-page Reports/ListInvoices --model=Invoice
```

Each scaffolds the page class, derives its Inertia component name and query-string prefix from the class name, and prints the `Route::inlayForm()` or `Route::inlayTable()` line to register it. Existing files are never overwritten without `--force`.

The central resource can configure its table and form once:

```php
<?php

namespace App\Inlay\Resources;

use App\Models\User;
use App\Validation\UserRules;
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

    protected static bool $usesLaravelPolicy = true;

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
                Action::make('delete')
                    ->url('/users/{id}')
                    ->method('delete')
                    ->color('danger')
                    ->requiresConfirmation(),
            ]);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->precognitive()
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

Each page selects an Inertia component while remaining connected to the Resource:

```php
use Inlay\Resources\Pages\ListRecords;

final class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected static string $component = 'users/index';
}
```

Register the full CRUD route set without writing a controller:

```php
use App\Inlay\Resources\UserResource;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Inlay\Resources\Facades\InlayResources;

InlayResources::routes([UserResource::class], [
    'middleware' => ['auth'],
    'mutationMiddleware' => [HandlePrecognitiveRequests::class],
]);
```

For this Resource, Inlay registers:

| Method | URI | Purpose |
| --- | --- | --- |
| `GET` | `/users` | List page and Table props |
| `GET` | `/users/create` | Create page and Form props |
| `GET` | `/users/{record}/edit` | Edit page, populated Form, and safe record props |
| `POST` | `/users` | Authorized, validated, transactional creation |
| `PATCH` | `/users/{record}` | Scoped, authorized, validated update |
| `DELETE` | `/users/{record}` | Scoped, authorized, transactional deletion |

The application frontend only renders the supplied contracts.

Reusable resource shells can also consume the PHP page contract directly:
`ResourcePage` renders server-authored breadcrumbs, actions, header/footer
widget dashboards, Forms, Tables, Infolists, and RelationManagers in either
React or Vue. Widget themes and custom renderers remain replaceable through the
same `WidgetDashboard` options used by panel dashboards.

React:

```tsx
import { Form } from '@inlayphp/forms-react'
import { Table } from '@inlayphp/tables-react'

export default function UsersIndex({ form, table, errors }) {
  return (
    <>
      <Form resource={form} errors={errors} />
      <Table resource={table} />
    </>
  )
}
```

Vue:

```vue
<script setup lang="ts">
import { Form } from '@inlayphp/forms-vue'
import { Table } from '@inlayphp/tables-vue'

defineProps<{
  form: FormResource
  table: TableResource
  errors: Record<string, string>
}>()
</script>

<template>
  <Form :resource="form" :errors="errors" />
  <Table :resource="table" />
</template>
```

See the full [Resources documentation](packages/resources/README.md) for routing options, lifecycle hooks, scoped queries, security guarantees, and advanced persistence.

## Forms

Forms support nested layouts, initial data, defaults, validation metadata, conditional visibility, conditional requirements, live change/blur metadata, and Inertia submission.

```php
use Inlay\Forms\Fields\Select;
use Inlay\Forms\Fields\TextInput;
use Inlay\Forms\Fields\Toggle;
use Inlay\Forms\Form;
use Inlay\Schemas\Components\Grid;
use Inlay\Schemas\Components\Section;

$form = Form::make('create-user')
    ->action(route('users.store'))
    ->method('post')
    ->validation(UserRules::class, 'create')
    ->precognitive(mode: 'blur', debounce: 350)
    ->schema([
        Section::make('account')->label('Account')->schema([
            Grid::make(2)->schema([
                TextInput::make('name')->required()->autofocus(),
                TextInput::make('email')->email()->required(),
                Select::make('account_type')
                    ->options([
                        'personal' => 'Personal',
                        'company' => 'Company',
                    ])
                    ->default('personal')
                    ->live(),
                TextInput::make('company_name')
                    ->visibleWhen('account_type', 'company')
                    ->requiredWhen('account_type', 'company'),
                Toggle::make('active')->default(true),
            ]),
        ]),
    ]);

return inertia('users/create', ['form' => $form]);
```

Available initial field types include text, textarea, select, checkbox, checkbox list, radio, toggle, toggle buttons, hidden, color, date/time, upload, slider, tags, key/value, code, Markdown, rich text, repeater, and builder fields.

## Tables

Tables can execute allow-listed Eloquent searching, sorting, filtering, and pagination from request input. Only columns and filters explicitly marked as supporting a query operation can change the query.

```php
$table = Table::make('users')
    ->searchPlaceholder('Search users…')
    ->columns([
        TextColumn::make('name')->searchable()->sortable(),
        TextColumn::make('email')->searchable(),
        BadgeColumn::make('status'),
        BooleanColumn::make('active'),
    ])
    ->filters([
        SelectFilter::make('role')->options(Role::labels()),
        SelectFilter::make('status')->options(Status::labels()),
    ])
    ->actions([
        Action::make('edit')->url('/users/{id}/edit'),
        Action::make('delete')
            ->url('/users/{id}')
            ->method('delete')
            ->requiresConfirmation(),
    ])
    ->query(User::query(), request()->query(), perPage: 15);

return inertia('users/index', ['table' => $table]);
```

Named, server-authored views reuse the same allow-listed query contract in
React and Vue:

```php
use Inlay\Tables\Views\TableView;

$table->views([
    TableView::make('active')
        ->label('Active users')
        ->filters(['status' => 'active'])
        ->sort('name')
        ->default(),
    TableView::make('invited')->filters(['status' => 'invited']),
]);
```

The selected view is transported as {table}_view; explicit search, filter,
sort, grouping, and page-size parameters override its defaults. Standalone
TablePages also expose Save, Edit, and Delete controls when a personal-view
store is enabled. The default store is session-scoped; applications that need
cross-device persistence can bind `TableViewStore` to the database driver and
publish the optional `inlay_table_views` migration.

The initial catalog includes text, badge, boolean, icon, image, color, select, toggle, text-input, and checkbox columns; select, boolean, ternary, text, date, and numeric filters; row/header/bulk actions; selection; loading and empty states; and responsive pagination.

## Centralized validation

Validation rules belong in one reusable Laravel class instead of being copied between a field schema, Form Request, import, and Resource controller.

```php
use Illuminate\Validation\Rule;
use Inlay\Validation\ValidationContext;
use Inlay\Validation\Validation;

final class UserRules extends Validation
{
    public function rules(ValidationContext $context): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                Rule::unique('users', 'email')->ignore($context->record()),
            ],
            'role' => ['required', Rule::in(['admin', 'member'])],
        ];
    }

    public function prepare(array $data, ValidationContext $context): array
    {
        $data['email'] = strtolower(trim((string) ($data['email'] ?? '')));

        return $data;
    }
}
```

Generate application-owned validation classes with `php artisan make:inlay-validation User`, then execute the same validation from forms, imports, actions, or APIs through `Inlay\Validation\ValidationRunner`.

`ValidationContext` provides:

- Operation, such as `create`, `edit`, or an application-specific operation.
- Source, such as Form, Import, API, Action, or bulk processing.
- Prepared input data.
- Current record and authenticated user.
- Consumer-specific options.

Profiles also support custom messages, attribute labels, after-validation callbacks, dependency injection, and stop-on-first-failure behavior.

## Resource persistence lifecycle

Resource mutations follow this order:

```text
resolve scoped record (when required)
  → authorize operation
  → prepare and validate input
  → begin database transaction
  → before hook
  → mutate validated data
  → create/update/delete model
  → after hook
  → commit
  → redirect with success message
```

Applications can customize small lifecycle steps without replacing the controller:

```php
/**
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
protected static function mutateDataBeforeCreate(array $data): array
{
    return [...$data, 'password' => Str::random(32)];
}

protected static function afterCreate(Model $record, array $data): void
{
    UserCreated::dispatch($record);
}
```

Resources are fail-closed until `canAccess()` is implemented. Record lookup always uses the Resource query, so tenant or visibility scopes cannot be bypassed by passing a model identifier directly.

## Infolists, imports, and panels

Infolists render read-only record details with the same shared layouts:

```php
$infolist = Infolist::make('user')
    ->schema([
        Section::make('Account')->columns(2)->schema([
            TextEntry::make('name')->copyable(),
            TextEntry::make('email')->url('mailto:{state}'),
            IconEntry::make('active')->boolean(),
        ]),
    ])
    ->data($user->toArray());
```

Imports provide column mapping, previews, centralized row validation, authorization, casting, isolated failures, persistence callbacks, and matching five-step React/Vue wizards. Queue transport and storage remain application-owned.

Panels define branding, paths, navigation groups, middleware, theme, plugins, and the application shell from PHP. The Admin package adds login/logout, a protected dashboard, automatic Resource CRUD routes, and Resource navigation. React and Vue renderers provide responsive side/top navigation, active states, badges, user menus, breadcrumbs, SPA-link adapters, and slots.

## Theming and layout customization

Inlay supports three customization levels:

1. Theme tokens for product-wide color, radius, and control sizing.
2. Root classes and typed per-slot class maps for page-specific layout changes.
3. Renderer registries for replacing built-in components or adding community components.

Choose the quiet shadcn-style foundation or the polished Inlay admin preset entirely from PHP:

```php
use Inlay\Theme\Theme;

$panel->theme(
    Theme::default()
        ->accent('#7c3aed')
        ->radius('0.875rem')
        ->font('Inter, ui-sans-serif, system-ui')
        ->tokens(['sidebar-width' => '18rem'])
        ->darkTokens(['accent' => '#a78bfa'])
);
```

`Theme::base()` supplies a neutral zinc foundation. Both presets use semantic tokens—background, surface, foreground, muted, border, hover, status colors and status surfaces, overlays, radius, sizing, font, and shadow—so an application theme automatically reaches panels, forms, tables, actions, infolists, imports, media, permission pages, and widgets. New applications should import `Design` from `inlayphp/design`; the lower-level `Theme` and `@inlayphp/theme` APIs remain compatible for existing apps. Panel also forwards application-defined semantic tokens as scoped CSS variables, so community packages can add a token without forking the core theme.

Generate a PHP theme class and matching CSS variables instead of hand-writing the integration:

```bash
php artisan make:inlay-theme Brand
```

This creates `app/Inlay/Themes/BrandTheme.php` and `resources/css/inlay/brand.css`. The stylesheet includes light variables plus OS-preference and `[data-theme="dark"]` overrides. See [`inlayphp/design`](packages/design/README.md) for the complete generator and frontend API.

```tsx
const theme = {
  accent: '#2563eb',
  radius: '0.75rem',
  controlHeight: '2.5rem',
  'table-row-hover': '#eff6ff',
}

<Form className="customer-form" resource={form} theme={theme} />

<Table
  className="customer-table"
  classNames={{
    filtersPanel: 'bg-(--inlay-surface-muted)',
    applyButton: 'shadow-sm',
  }}
  resource={table}
  theme={theme}
/>
```

Stable semantic attributes make CSS overrides independent of internal Tailwind utility classes:

```css
.customer-form [data-field='email'] {
  grid-column: 1 / -1;
}

.customer-table [data-slot='toolbar'] {
  align-items: end;
}

.customer-table [data-filter='status'] {
  min-width: 12rem;
}
```

Plugin and renderer contracts reject duplicate ownership so two extensions cannot silently replace the same component. Plugin registration is atomic: shared registries roll back if registration fails.

## Public wire contracts

Every top-level PHP object serializes a named, versioned contract:

```json
{
  "contract": "inlay.forms.v1",
  "type": "form",
  "name": "create-user",
  "schema": []
}
```

Current top-level contracts include:

- `inlay.forms.v1`
- `inlay.tables.v1`
- `inlay.actions.v1`
- `inlay.infolists.v1`
- `inlay.imports.v1`
- `inlay.panels.v1`
- `inlay.resources.v1`
- `inlay.media-manager.v1`
- `inlay.themes.v1`
- `inlay.widget-dashboard.v1`

Adding optional keys is backward-compatible. Changing the meaning or shape of an existing key requires a new contract version.

Arbitrary PHP closures never cross the Inertia boundary. Dynamic behavior must be resolved on the server or represented as explicit, allow-listed condition/action metadata.

## Security model

The packages enforce several boundaries by default:

- Resource authorization is fail-closed.
- Resource records are resolved through the Resource’s scoped Eloquent query.
- Authorization occurs before mutation validation and persistence.
- Only validated input reaches Resource persistence.
- Eloquent fillable/guarded rules remain active.
- Resource writes and lifecycle hooks are transactional.
- Tables only apply declared searchable, sortable, and filterable query operations.
- URLs are normalized by a shared safe-URL policy that rejects executable and protocol-relative values.
- Conditions and component types use explicit allow lists.
- Page paths reject traversal, malformed encoding, and record-placeholder mismatches.
- Fresh builders are required to prevent mutable state leaking between long-running requests.
- Extension registries reject ownership collisions and roll back failed plugin registration.
- Panel routes declare abilities and enforce Laravel Gate decisions before returning or mutating data.
- Media uploads are private by default, use randomized object keys, and enforce configured size, MIME, and extension allow-lists.
- Media delivery uses short-lived signed URLs, authorization, private cache headers, and `nosniff`; untrusted formats download instead of rendering inline.
- Media deletion is recoverable until an explicit permanent-delete operation, and folder moves prevent cycles and logical orphans.

Application policies, domain-specific validation, database constraints, CSRF protection, authentication middleware, malware scanning, storage lifecycle rules, and rate limiting remain application responsibilities.

## Community extensions

Community packages should be able to provide:

- Custom Form fields and renderers.
- Table columns, filters, and actions.
- Infolist entries.
- Action modal content.
- Panel plugins and navigation.
- Theme presets and semantic CSS.
- Shared PHP and frontend packages that register the same renderer key.

Custom components should publish a stable PHP payload, declare a renderer category, register a unique renderer key, and provide matching React and/or Vue implementations. The Core package supplies version compatibility checks and ownership-safe registries.

The [community schema-view template](examples/community-schema-view/README.md) is a
cloneable, continuously tested example containing a Composer component plus React and Vue
adapters. It demonstrates stable renderer naming, nested schema rendering, deferred data,
registry ownership, package exports, and compatibility checks.

## Repository layout

```text
packages/
  core/          Plugin and extension kernel
  support/       Shared safe contracts
  schemas/       Layout primitives
  validation/    Central Laravel validation
  actions/       Action contracts and adapters
  form/          Forms PHP + React + Vue
  table/         Tables PHP + React + Vue
  infolist/      Infolists PHP + React + Vue
  import/        Imports PHP + React + Vue
  panel/         Panels PHP + React + Vue
  theme/         Shared PHP and frontend theme presets (compatibility layer)
  design/        Public design façade, CSS generator, and frontend recipes
  notifications/ Session/database notification contracts + React/Vue renderers
  widgets/       PHP dashboard widgets + React/Vue renderers
  resources/     Resource CRUD orchestration
  admin/         Authenticated panel composition
  authorization/ Laravel Gate bridge and ability registry
  authorization-spatie/ Roles, teams, super-admin, and permission sync adapter
  permission-manager/ Dcat-style access manager + React pages
  media/         Storage-agnostic secure media catalog
  media-manager/ Panel media browser/picker + React/Vue pages
  media-spatie/  Zero-copy Spatie Media Library adapter
  two-factor-authentication/
                 Optional TOTP/recovery-code manager and panel login step

examples/
  react/         Buildable React package example
  vue/           Buildable Vue package example
  community-schema-view/
                 Cloneable Composer + React + Vue community component template

playground/
  laravel-react/ Laravel 12 + Inertia v3 + React 19 integration app with Vue standalone and panel showcase routes

docs/            Architecture, component matrix, and focused examples
tests/           Pest contract and PHP behavior tests
```

## Run the playground

```bash
cd playground/laravel-react
composer install
pnpm install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
pnpm run build
php artisan serve --port=8013
```

Open [http://127.0.0.1:8013/admin](http://127.0.0.1:8013/admin) and sign in with `test@example.com` / `password`.

The playground demonstrates:

- A Resource-defined Form and Table.
- Authenticated login/logout, a dashboard, and a responsive admin shell.
- PHP-generated Resource navigation and protected CRUD routes.
- Server-side search, filters, sorting, and pagination.
- Conditional fields and Precognition metadata.
- Central validation shared with imports and Resource mutations.
- Transactional create, update, and delete routes.
- Accessible action confirmation.
- Theme tokens, class overrides, and collapsible code examples.
- Roles, permissions, user-role assignment, and an audit comparing registered, missing, synced, and stale abilities.
- A working media browser with seeded assets, folders, search, MIME filtering, uploads, metadata editing, trash/restore, and a reusable picker.

## Monorepo development

Install dependencies from the repository root:

```bash
composer install
pnpm install
```

Run PHP syntax and Pest tests:

```bash
composer lint
composer test
```

Run the complete release-oriented gate sequentially so declaration generation cannot race the export audit:

```bash
pnpm verify
```

The individual frontend commands remain available when iterating on one layer:

```bash
pnpm test:frontend
pnpm typecheck
pnpm build
```

Verify the Laravel playground:

```bash
cd playground/laravel-react
composer lint:check
composer types:check
pnpm run lint:check
pnpm run format:check
pnpm run types:check
php artisan test
pnpm run build
```

The main test suites use Pest for PHP, Vitest with Testing Library for React/Vue, PHPStan/Larastan for Laravel static analysis, TypeScript and `vue-tsc` for adapter contracts, and production builds to verify package declarations and exports.

GitHub Actions runs the same package gates plus a separate PHP 8.4 / Node 22
integration job for `playground/laravel-react`. That job installs the
playground's committed Composer and pnpm lockfiles, runs its full Pest suite,
type checks, lint/format checks, and production build, so demo routes,
migrations, and renderer wiring cannot silently drift away from the packages
they demonstrate.

## Documentation

Start with the [Inlay user guide](docs/guide/README.md). It is organized as a
progressive Laravel walkthrough: clean installation, panels, Resources, Forms,
Tables, schemas/infolists, centralized validation, actions/widgets, themes,
plugins, standalone pages, and deployment. Each chapter links to the package
README for the full method-level API.

The same Markdown sources power the static documentation site in
[`docs-site/`](docs-site/README.md). Run its local dev server while editing the
guides, or let the included GitHub Pages workflow publish the generated site
from `main`.

For contribution workflow, feature proposals, testing expectations, and the
package/renderer boundaries, read [Contributing](CONTRIBUTING.md). Report
security vulnerabilities privately through [Security](SECURITY.md); do not use
public issues for sensitive reports.

- [Release checklist and split-package process](docs/release.md)
- [Architecture and package boundaries](docs/architecture.md)
- [User guide index](docs/guide/README.md)
- [Getting started](docs/guide/01-getting-started.md)
- [Panels](docs/guide/02-panels.md)
- [Resources and CRUD](docs/guide/03-resources.md)
- [Forms](docs/guide/04-forms.md)
- [Tables](docs/guide/05-tables.md)
- [Schemas and Infolists](docs/guide/12-schemas-and-infolists.md)
- [Validation](docs/guide/06-validation.md)
- [Actions, notifications, and widgets](docs/guide/07-actions-and-widgets.md)
- [Themes and UI customization](docs/guide/08-themes.md)
- [Plugins](docs/guide/09-plugins.md)
- [Standalone Forms and Tables](docs/guide/10-standalone-pages.md)
- [Testing and deployment](docs/guide/11-testing-and-deployment.md)
- [Component matrix](docs/components.md)
- [Focused Laravel, React, and Vue examples](docs/examples.md)
- [Resources and CRUD lifecycle](packages/resources/README.md)
- [Forms](packages/form/README.md)
- [Tables](packages/table/README.md)
- [Actions](packages/actions/README.md)
- [Notifications](packages/notifications/README.md)
- [Validation](packages/validation/README.md)
- [Imports](packages/import/README.md)
- [Infolists](packages/infolist/README.md)
- [Panels](packages/panel/README.md)
- [Core extensions and plugins](packages/core/README.md)
- [Authorization](packages/authorization/README.md)
- [Spatie authorization adapter](packages/authorization-spatie/README.md)
- [Permission manager](packages/permission-manager/README.md)
- [Media catalog](packages/media/README.md)
- [Media manager](packages/media-manager/README.md)
- [Spatie media adapter](packages/media-spatie/README.md)
- [Two-factor authentication](packages/two-factor-authentication/README.md)

## Roadmap

The next major milestones are:

1. Deepen resource page and widget composition.
   Parent-scoped nested resource URLs, hosted action-form sub-transports,
   selection-aware bulk action modals, per-record bulk outcome reports,
   an allow-listed per-page chooser, removable filter indicators,
   PHP-declared filter form layout, per-column search and sort callbacks,
   safe header and per-record cell attributes, column actions,
   scoped and custom summarizers, arbitrary schema filters,
   closure-backed column and table presentation,
   fluent Relation Groups, and keyboard-accessible React/Vue tabs are
   available. Resource and Relation Manager soft deletes include scoped query,
   filter, row/bulk action, lifecycle, React/Vue, test DSL, and playground
   support.
   Validated pivot-aware create/attach/edit forms,
   secure searchable attach/detach, and HasMany/MorphMany
   associate/dissociate UI are available.
2. Dashboard widget caching is available through the opt-in `CacheableWidgets`
   provider contract. Resource header actions now use the resource action
   boundary with page authorization and render through the shared React/Vue
   Actions runtime, including confirmation, action forms, lifecycle responses,
   and custom transport hooks. Remaining page-level action UX and resource
   composition hardening are next, and panel widget providers can be discovered
   from an explicit application namespace. The standalone notification contract,
   session/database delivery, action integration, and React/Vue renderers are
   available.
3. An optional two-factor authentication plugin with encrypted TOTP/recovery
   state, challenge and authenticated settings routes, and React/Vue challenge
   and security-settings renderers. Existing Fortify models can reuse their
   encrypted columns through Inlay's dependency-free storage adapter, and QR
   rendering remains an application-edge contract. An opt-in Fortify challenge
   bridge can keep Fortify as the authentication owner while using the shared
   Inertia Form page. Panels exposes the ordered post-credential `LoginStep`
   pipeline required by the native plugin.
4. Async relationship option loading and direct-to-storage upload adapters need
   broader application fixtures and release hardening across both renderers.
5. Responsive layouts, relationship-group automation, and advanced bulk selection. Server-authored named views, owner-scoped personal save/edit/delete persistence, query-wide filtered CSV exports, selection-aware bulk CSV downloads, queued export payloads, and the optional first-party PhpSpreadsheet XLSX driver now reuse the same authorized React/Vue contract. Grouping, summaries, and column management have their first production-tested React/Vue slice.
6. Rich text/code editor integrations are available; deeper schema reactivity
   and long-tail editor extension documentation remain release work.
7. Vue playground parity is available for standalone Form/Table routes, the panel dashboard, a real UserResource CRUD comparison (list, create, and edit), the package-owned media-manager page, permission-manager access pages, and two-factor settings. Package-owned Vue plugin pages resolve through the same panel shell as local pages. Multi-panel discovery is available through `PanelRegistry::directoryFor()` and the React/Vue `PanelSwitcher`; remaining work is the long-tail Vue plugin-page sweep, tenant-aware starter kits, and reusable application starter kits.
8. The media manager now exposes a bounded filesystem storage browser with a stable PHP/React/Vue contract; community S3/API browsers can register through `MediaStorageRegistry`. Media albums/collections, manager filtering, image focal-point metadata editing, bounded usage/reference inspection, and opt-in queued transformations are available.
9. Coordinated package versions, changelogs, upgrade guides, the first signed
   release tag, and community extension documentation. The split and npm
   publishing automation plus common registry metadata are now in place; the
   first public release still needs organization credentials and an explicitly
   approved version line.

The project does not aim to move Laravel business logic into React or Vue. Frontend adapters should stay replaceable; PHP contracts remain the durable public API.

## License

Inlay is intended to be released under the MIT License. See individual package metadata for the current license declaration.
