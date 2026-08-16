# Getting started

This chapter takes a clean Laravel application to a working Inlay panel. The
generated code is application-owned, so you can inspect it immediately after
the install.

## Requirements

- PHP 8.3 or newer;
- Laravel 12 or 13;
- Composer;
- Node.js 20 or newer and npm, pnpm, Yarn, or Bun;
- a database supported by your Laravel application.

Inlay's panel uses Inertia Laravel 3. React 19 is the default renderer. Vue 3
is available with the `--renderer=vue` preset.

## Create a clean application

The Laravel installer can create a plain application without a starter kit:

```bash
laravel new inventory
cd inventory
```

Choose SQLite for a quick local installation, or configure MySQL/PostgreSQL
in `.env`. Inlay does not replace Laravel's database or authentication
contracts.

## Install the panel

```bash
composer require inlayphp/inlay:"^0.3"
php artisan inlay:install --panels
```

The installer performs these application changes:

- creates `AdminPanelProvider` under `app/Providers/Inlay`;
- creates the default `UserResource` and list/create/edit page classes;
- creates `app/Validation/UserRules.php`;
- adds the Inertia root Blade view and request middleware when missing;
- adds the official React renderer and Vite/Tailwind source configuration;
- creates the panel login, dashboard, account settings, and Resource page
  wrappers;
- registers the provider in `config/inlay-panels.php`;
- redirects unauthenticated panel visitors to `/admin/login`;
- leaves Media, roles, imports, and two-factor authentication opt-in.

Run the generated next steps:

```bash
php artisan migrate
php artisan inlay:make-user
npm install
npm run build
php artisan inlay:doctor --production
```

The user command prompts for a name, email, password, and confirmation. For a
local demo only, options can be supplied non-interactively:

```bash
php artisan inlay:make-user \
  --name="Demo Admin" \
  --email="admin@example.com" \
  --password="password" \
  --no-interaction
```

Do not put a real production password in a shell command because it can remain
in shell history. Use the interactive prompt or a deployment secret.

Open the panel:

```text
http://localhost/admin/login
```

After authentication, the default panel contains:

- Dashboard;
- Users list, create, edit, and delete screens;
- Account settings for the signed-in user;
- global resource search;
- responsive navigation, dark mode, and the default theme.

## Choose Vue instead of React

Vue is a first-class renderer. On a plain application:

```bash
php artisan inlay:install --panels --renderer=vue
npm install
npm run build
```

On a Laravel starter kit that already owns a Vue entrypoint, the installer
preserves the existing application entrypoint and adds Inlay page wrappers.
The PHP provider and route contract are identical; only the renderer imports
and page file extensions differ.

## Useful installer options

```text
--panels                    Install the panel preset (default).
--panel=reports             Use a different panel id and URL segment.
--renderer=react|vue|none   Select the frontend adapter.
--media                     Register the optional Media Manager.
--without-users             Do not generate UserResource.
--no-frontend               Generate PHP only.
--no-npm                    Update package files without running npm.
--force                     Replace generated application files intentionally.
--tenant-model=...          Generate a tenant-aware panel provider.
```

The installer is safe to rerun. It restores missing generated support files and
does not replace application-owned providers, Resources, validation classes, or
page wrappers unless `--force` is passed. Use `--force` only when you have
reviewed the generated diff.

## Add the optional Media Manager

Media is intentionally not part of the default panel. Add it when the
application needs uploads, folders, albums, or a media picker:

```bash
php artisan inlay:install --panels --media
php artisan migrate
npm run build
```

The command registers the Media Manager plugin, publishes its migrations, and
creates the renderer page. Configure a persistent disk before production
deployment:

```dotenv
INLAY_MEDIA_DISK=public
```

For Laravel Cloud or another ephemeral filesystem, use an S3-compatible disk.
See [Plugins](09-plugins.md) for authorization and storage details.

## Diagnose an installation

Run the lightweight check before a build:

```bash
php artisan inlay:doctor
```

Run the production check after the build:

```bash
php artisan inlay:doctor --production
```

The production check verifies the Vite manifest, compiled CSS, Tailwind source
discovery, panel provider, renderer dependency, generated User Resource, and
optional Media files when Media is installed.

If the browser shows an unstyled page:

1. confirm `npm run build` succeeded;
2. confirm the Vite manifest exists at `public/build/manifest.json`;
3. keep this source rule in `resources/css/app.css`:

   ```css
   @source '../../node_modules/@inlayphp/*/src/**/*.{ts,tsx,vue}';
   ```

4. run `php artisan inlay:doctor --production`;
5. clear cached views/config with `php artisan optimize:clear`;
6. hard-refresh the browser.

If `/admin/users` is blank, inspect the browser console and the Inertia props.
The page must receive an `inlayPanel` prop when rendered inside a panel. A
stale split package or a custom page resolver that drops shared props is a
common cause.

## Add a panel to an existing application

Inlay does not require a new Laravel application. On an existing app:

```bash
composer require inlayphp/inlay:"^0.3"
php artisan inlay:install --panels --no-npm
npm install
npm run build
```

Review the generated provider and `config/inlay-panels.php`. If the application
already has authentication, Inlay reuses the configured Laravel guard. If it
already has a React or Vue entrypoint, keep the application's providers and
merge the generated Inlay page resolver instead of replacing unrelated pages.

## What the installer does not decide

The installer creates a safe starting point, not application policy. You still
decide:

- which users may access the panel;
- which Resource records are visible to each tenant;
- which Laravel policies authorize actions;
- which validation rules apply to your domain;
- whether to use Spatie permissions, the permission plugin, or policies alone;
- which storage disk and retention policy apply to uploaded files;
- which theme tokens represent your brand.

That separation is deliberate: generated code is easy to understand and the
packages remain independently useful.
