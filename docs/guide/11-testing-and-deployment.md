# Testing and deployment

Inlay has two contracts to protect: the PHP contract that builds and secures a
screen, and the React/Vue renderer that presents it. Test both layers.

## PHP tests

Run the package suite from the monorepo:

```bash
composer lint
vendor/bin/pest
```

For an application, prefer feature tests that authenticate a real model and
exercise the route:

```php
it('shows the protected user Resource', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/users')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('users/index')
            ->has('table')
            ->where('inlayPanel.id', 'admin'));
});
```

Cover these boundaries for every Resource or plugin:

- guest redirect to panel login;
- authenticated access;
- policy denial for each operation;
- tenant or ownership scope;
- invalid validation data;
- successful create/update/delete;
- action confirmation and lifecycle hooks;
- expected Inertia contract and component name;
- migration on the database engines used in deployment.

## Contract tests

Forms:

```php
FormTester::make($form)
    ->assertFormFieldExists('email')
    ->assertFormFieldRequired('email')
    ->assertFormFieldDoesNotExist('internal_token');
```

Tables:

```php
TableTester::make($table)
    ->assertTableColumnExists('email')
    ->assertTableFilterExists('status')
    ->assertTableActionExists('edit')
    ->assertCanSeeTableRecords($visibleUsers)
    ->assertCanNotSeeTableRecords($hiddenUsers);
```

These testers inspect the same serialized payload consumed by React and Vue.
They are not a replacement for HTTP authorization tests.

## Frontend tests

Run the adapter checks for the renderer you ship:

```bash
npm test
npm run typecheck
npm run build
```

In a monorepo, the package-specific commands are available through pnpm:

```bash
pnpm test:frontend
pnpm typecheck
pnpm build
```

Add browser tests for the important user paths:

1. login and logout;
2. open dashboard and navigation;
3. search and filter a table;
4. open create/edit form and display validation errors;
5. confirm a destructive action;
6. switch light/dark mode;
7. open the mobile navigation drawer;
8. use keyboard focus and submit controls.

The frontend should never be the only test of authorization. A hidden button is
not a security boundary.

## Installation checks

Every clean Laravel application should pass:

```bash
php artisan inlay:doctor
npm run build
php artisan inlay:doctor --production
```

`inlay:doctor --production` catches the most common deployment failure: the
JavaScript loads but Tailwind did not scan the installed `@inlayphp` packages,
leaving forms and tables unstyled.

## CI checklist

A package or application workflow should run:

```text
Composer install with the committed lock
PHP lint and Pest
Frontend install with the committed lock
Frontend tests and typecheck
Production frontend build
Laravel integration tests
Migration tests
```

Run the build before typecheck/test when a clean checkout needs generated
frontend declaration files. Keep PHP 8.3 as the lowest supported runtime and
test the highest supported PHP version as well.

## Standalone deployment

Commit both lockfiles and build inside the deployment repository:

```bash
composer install --no-interaction --prefer-dist --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan inlay:doctor --production
```

Use `pnpm install --frozen-lockfile` or the equivalent command when that is the
committed package manager.

Do not use Composer `path` repositories or npm `link:` dependencies in a clean
deployment repository. Publish Composer packages to Packagist (or configure a
private Composer repository) and publish renderer packages to npm. A standalone
Laravel Cloud checkout cannot see sibling monorepo directories.

## Laravel Cloud and object storage

Laravel Cloud's local filesystem should not be treated as durable media
storage. Configure an attached S3-compatible disk:

```dotenv
FILESYSTEM_DISK=s3
INLAY_MEDIA_DISK=s3
```

Install the adapter required by the disk:

```bash
composer require league/flysystem-aws-s3-v3
```

Run media migrations before registering the Media Manager. If a migration was
partially applied, repair the database state deliberately; do not hide a
duplicate table by blindly changing every migration to `createIfNotExists()`.
The current media migration guards the known folder-table recovery case and
uses MySQL-safe disk/path lengths.

## Environment and security

- never commit `.env`, passwords, or package tokens;
- use a real password reset or deployment secret for the first administrator;
- enable HTTPS and secure cookies;
- configure queue workers for imports, transformations, and queued exports;
- set storage lifecycle/retention for trashed media;
- keep policy checks on protected routes and actions;
- rate-limit login, password changes, uploads, and expensive exports;
- clear application caches after changing panel providers or themes.

## Debugging a blank or unstyled page

Use this order:

1. `php artisan route:list | grep admin` — confirm the route exists;
2. browser console — find a page resolver or JavaScript exception;
3. Inertia response — confirm `inlayPanel`, `form`, `table`, or `inlayWidgets`
   exists for the page;
4. `public/build/manifest.json` — confirm the entrypoint exists;
5. compiled CSS — confirm an Inlay class and `--inlay-*` variable is present;
6. `php artisan optimize:clear` — remove stale config/view/routes;
7. `php artisan inlay:doctor --production` — repeat the automated check.

If `/admin/users` has a table in the response but the page is blank, the
frontend page resolver likely did not map `users/index`, or the page was wrapped
in a second starter-kit layout. If the page is unstyled, fix Tailwind source
discovery before changing component markup.
