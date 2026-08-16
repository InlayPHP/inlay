# Inlay user guide

This is the practical guide for building Laravel administration screens with
Inlay. It is written for a developer who is starting with a clean Laravel
application and wants to understand the complete path from a PHP class to a
working browser page.

Inlay keeps the application code in Laravel. You define panels, resources,
forms, tables, validation, actions, and widgets in PHP. Inertia transports a
versioned data contract to the frontend, and the official React or Vue
renderer turns that contract into accessible controls.

```text
PHP configuration
    │
    ├── Form / Table / Resource / Panel / Widget
    │
    ▼
Inertia props (versioned Inlay contract)
    │
    ├── React renderer
    └── Vue renderer
```

The guide is intentionally progressive. Start with the first two chapters,
then read only the feature chapter you need. The package READMEs remain the
complete API reference; this guide explains which pieces belong together and
shows the recommended application structure.

## Choose a starting point

| I want to… | Read |
| --- | --- |
| Install a panel in a new Laravel application | [Getting started](01-getting-started.md) |
| Understand panel login, navigation, and middleware | [Panels](02-panels.md) |
| Build CRUD without writing controllers | [Resources](03-resources.md) |
| Build a form in PHP | [Forms](04-forms.md) |
| Build a searchable, filterable table | [Tables](05-tables.md) |
| Present a read-only record detail view | [Schemas and Infolists](12-schemas-and-infolists.md) |
| Keep rules in one reusable class | [Validation](06-validation.md) |
| Add actions, notifications, and dashboard widgets | [Actions and widgets](07-actions-and-widgets.md) |
| Change the entire UI with one theme | [Themes](08-themes.md) |
| Install permissions, media, or another extension | [Plugins](09-plugins.md) |
| Use Forms or Tables without a panel | [Standalone pages](10-standalone-pages.md) |
| Test and deploy safely | [Testing and deployment](11-testing-and-deployment.md) |

## The five-minute mental model

An Inlay screen normally has five layers:

1. **Model and policy** — Laravel owns data, authorization, scopes, and
   persistence.
2. **PHP contract** — a `Form`, `Table`, `Resource`, or `Panel` describes what
   the screen can do.
3. **Route or page class** — Laravel decides where the screen is delivered and
   which mutation endpoint receives data.
4. **Inertia transport** — Inlay serializes the PHP object as a named,
   versioned contract.
5. **Renderer** — React or Vue renders the contract and reports interaction
   back to the server.

Business rules stay in layers 1–3. The renderer should not become a second
place where authorization or validation is implemented.

## Recommended application layout

The installer creates this shape. You can rename directories, but keeping the
boundaries makes a project easy to navigate:

```text
app/
  Inlay/
    Resources/
      UserResource.php
      ListUsers.php
      CreateUser.php
      EditUser.php
    Themes/
    Widgets/
  Providers/Inlay/
    AdminPanelProvider.php
  Validation/
    UserRules.php
resources/
  js/
    layouts/inlay-panel-layout.tsx   # or .vue
    pages/inlay/
      auth/login.tsx
      dashboard.tsx
    pages/users/
      index.tsx
      form.tsx
  css/app.css
```

Application-owned files are deliberately generated into `app/` and
`resources/`. You can edit them, commit them, and review them like any other
Laravel code. Inlay packages provide the runtime and renderer primitives; they
do not hide your application in a vendor directory.

## Conventions used in the examples

The examples use:

- Laravel 12 or 13;
- PHP 8.3 or newer;
- Inertia Laravel 3;
- React 19 unless a Vue example is shown;
- an `App\Models\User` model with the normal Laravel authentication contract.

Replace `User` and the field names with your own model. Every route, policy,
column, field, and validation rule should be reviewed for the data your
application actually owns.

## Package reference

| Need | Composer package | React package | Vue package |
| --- | --- | --- | --- |
| Core contracts and plugins | `inlayphp/core` | `@inlayphp/core` | `@inlayphp/core` |
| Layouts and schema containers | `inlayphp/schemas` | Rendered by the Forms/Infolists adapter | Rendered by the Forms/Infolists adapter |
| Forms | `inlayphp/forms` | `@inlayphp/forms-react` | `@inlayphp/forms-vue` |
| Tables | `inlayphp/tables` | `@inlayphp/tables-react` | `@inlayphp/tables-vue` |
| CRUD Resources | `inlayphp/resources` | `@inlayphp/resources-react` | `@inlayphp/resources-vue` |
| Admin panels | `inlayphp/panels` | `@inlayphp/panels-react` | `@inlayphp/panels-vue` |
| Validation | `inlayphp/validation` | — | — |
| Actions | `inlayphp/actions` | `@inlayphp/actions-react` | `@inlayphp/actions-vue` |
| Infolists | `inlayphp/infolists` | `@inlayphp/infolists-react` | `@inlayphp/infolists-vue` |
| Notifications | `inlayphp/notifications` | `@inlayphp/notifications-react` | `@inlayphp/notifications-vue` |
| Widgets | `inlayphp/widgets` | `@inlayphp/widgets-react` | `@inlayphp/widgets-vue` |
| Theme generation | `inlayphp/design` | `@inlayphp/design` | `@inlayphp/design` |
| Media catalog | `inlayphp/media` | — | — |
| Media panel | `inlayphp/media-manager` | `@inlayphp/media-manager-react` | `@inlayphp/media-manager-vue` |
| Permissions | `inlayphp/permission-manager` | `@inlayphp/permission-manager-react` | `@inlayphp/permission-manager-vue` |

The `inlayphp/inlay` meta-package installs the clean panel foundation. Media,
roles, imports, two-factor authentication, and other larger features remain
explicit plugins so a first installation stays understandable.

## Source of truth and compatibility

The package READMEs contain exhaustive method-level details:

- [Forms API](../../packages/form/README.md)
- [Tables API](../../packages/table/README.md)
- [Resources API](../../packages/resources/README.md)
- [Panels API](../../packages/panel/README.md)
- [Schemas API](../../packages/schemas/README.md)
- [Actions API](../../packages/actions/README.md)
- [Validation API](../../packages/validation/README.md)
- [Infolists API](../../packages/infolist/README.md)
- [Schemas API](../../packages/schemas/README.md)

Every top-level object carries a versioned contract such as
`inlay.forms.v1` or `inlay.tables.v1`. Adding optional keys is compatible;
changing the meaning or shape of an existing key requires a new contract
version. This rule lets applications upgrade package and renderer releases
without silently changing a screen.

## Before opening an issue

Run the smallest useful checks first:

```bash
php artisan inlay:doctor
php artisan route:list | grep admin
npm run build
php artisan inlay:doctor --production
```

For a page that is blank in the browser, inspect the browser console and the
Inertia response first. A blank React or Vue page is usually an exception in a
page resolver, a missing compiled asset, or a missing `inlayPanel` prop—not a
database query problem.
