# Inlay playground

A Laravel 12 / Inertia v3 application running the Inlay packages from source. It serves
the same pages through **both** renderers: `/standalone/*` mounts the React packages and
`/vue/standalone/*` mounts the Vue ones from the same page classes and the same
serialized payload, so anything that differs between them is the renderer rather than
the server. `/vue/panel` mounts the real `panels-vue` shell and `widgets-vue` dashboard
against the same protected PHP dashboard endpoint used by `/admin`.

## Run it

The `/vue/resources/users` route mounts the PHP UserResource through the Vue panel shell,
resource page composition, Form renderer, and Table renderer. The React resource
remains available at `/admin/users`; its create and edit pages use the shared React
`ResourcePage` shell for server-authored breadcrumbs, headings, actions, and widgets.
The `/vue/media` route mounts the same media manager controller through the Vue panel
shell and the package-owned `mediaManagerPages` resolver exported by
`@inlayphp/media-manager-vue`. There is no playground-specific media page wrapper, so
this route exercises the package page exactly as a consuming application would.
The `/vue/access/roles`, `/vue/access/permissions`, `/vue/access/users`, and
`/vue/access/audit` routes mount the permission-manager Vue pages through the same
panel shell and PHP-owned resource contracts.

```bash
composer install
pnpm install
php artisan migrate --seed
pnpm run build
php artisan serve
```

Open the printed URL. `/` lists every demo. Sign in with `test@example.com` /
`password`.

## Deploying

The database is SQLite, and every driver that can be (`session`, `queue`, `cache`) is on
the database, so the application needs no services beyond a writable file.

```bash
composer install --no-dev --optimize-autoloader
pnpm install --frozen-lockfile && pnpm run build
composer run deploy
```

`composer run deploy` creates the SQLite file if it is absent, migrates with `--seed`,
and caches config, routes and views. `public/build` and the SQLite file are both
ignored by git, so both have to be produced by the deploy rather than committed.

Two things to know before deploying this to a platform:

- **The Composer path repositories point at `../../packages`.** This application is not
  self-contained: it resolves the Inlay packages from the monorepo around it through 26
  `path` repositories with symlinks. The whole repository has to be checked out, with the
  application root set to `playground/laravel-react`. Deploying this directory alone will
  fail at `composer install`.
- **SQLite on an ephemeral filesystem resets on every deploy.** That is fine here —
  `deploy` seeds — but it means the playground is a demonstration and not somewhere to
  keep anything.

`APP_KEY` has to be set in the environment; `.env` is not committed.

## Package installation

The application uses Composer path repositories and pnpm file dependencies so edits to the packages in this repository are available locally:

```json
{
    "require": {
        "inlayphp/schemas": "dev-main",
        "inlayphp/forms": "dev-main",
        "inlayphp/tables": "dev-main"
    }
}
```

```json
{
    "dependencies": {
        "@inlayphp/forms-react": "file:../../packages/form/react",
        "@inlayphp/tables-react": "file:../../packages/table/react"
    }
}
```

## Sample code

- PHP builders: `app/Http/Controllers/UserDemoController.php`
- React rendering: `resources/js/pages/users/index.tsx`
- Inertia routes: `routes/web.php`
- Pest integration tests: `tests/Feature/UserDemoTest.php`

Run every playground check with:

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan test
pnpm run format:check
pnpm run lint:check
pnpm run types:check
pnpm run build
```
