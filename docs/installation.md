# Installation and deployment

## Complete React panel

Start from any Laravel 13 application, including a brand-new application from
`laravel new`:

```bash
composer require inlayphp/inlay:"^0.3"
php artisan inlay:install --panels
```

The installer detects a plain Laravel application and scaffolds the missing
Inertia 3 React entrypoint, root Blade view, `HandleInertiaRequests` middleware,
and Vite/React dependencies. It then creates an application-owned panel
provider, User CRUD, panel authentication and account settings, the Media
Manager and migrations, React page wrappers, npm dependencies, and Tailwind
source discovery. The original Laravel `resources/js/app.js` entrypoint is
kept in Vite's inputs so the default welcome route remains valid. It prints the
remaining commands for the package manager detected from the application's
lockfile. A typical pnpm installation finishes with:

```bash
php artisan migrate
php artisan inlay:make-user
pnpm run build
php artisan inlay:doctor --production
```

Open `/admin` after the doctor reports `Inlay is ready.`

## Tailwind CSS 4

Inlay renderers ship utility markup rather than a second compiled stylesheet.
The consuming application therefore compiles one consistent theme and can
override shared tokens once. The installer adds this rule to
`resources/css/app.css`:

```css
@source '../../node_modules/@inlayphp/*/src/**/*.{ts,tsx,vue}';
```

The path is relative to `resources/css/app.css`. Keep the rule even when the
application is developed inside a monorepo: production and CI normally install
the renderers under `node_modules` without sibling package source directories.

## Installation diagnostics

Run the lightweight checks before building:

```bash
php artisan inlay:doctor
```

After Vite builds the production manifest and stylesheet, require compiled CSS
verification:

```bash
php artisan inlay:doctor --production
```

The production check fails when the manifest, stylesheet, or representative
Inlay form utilities are missing. This prevents a deployment from succeeding
with functional JavaScript but unstyled Forms, Tables, or panel controls.

## Safe reruns and custom installations

`inlay:install` is safe to rerun after a package-manager or deployment failure.
Existing application-owned panel providers, Resources, validation classes, and
page wrappers are preserved. Missing supporting files are restored. Pass
`--force` only to regenerate those files intentionally.

Useful smaller presets include:

```bash
php artisan inlay:install --panels --without-media
php artisan inlay:install --panels --without-users
php artisan inlay:install --panels --no-frontend
php artisan inlay:install --renderer=none
```

Use `--no-npm` when CI or a container build installs frontend dependencies in a
separate phase. The installer still updates `package.json`, writes page wrappers,
and configures Tailwind.

## Standalone Forms and Tables

Applications that do not need a panel can install only their PHP builders and
official renderer packages:

```bash
composer require inlayphp/forms inlayphp/tables inlayphp/actions inlayphp/validation
pnpm add @inlayphp/forms-react @inlayphp/tables-react @inlayphp/actions-react
```

Add the same universal Tailwind source rule, build the application, and use
`Form` or `Table` directly on ordinary Inertia pages. Panel registration is not
required.

## Laravel Cloud and other clean deployments

Commit `composer.lock` and the frontend lockfile. The deployment must install
both ecosystems before building Vite assets:

```bash
composer install --no-interaction --prefer-dist --optimize-autoloader
pnpm install --frozen-lockfile
pnpm run build
php artisan migrate --force
php artisan inlay:doctor --production
```

Use the equivalent npm, Yarn, or Bun commands when that lockfile is committed.
Do not rely on sibling monorepo paths in `@source`; Cloud checks out the
standalone repository and installs published packages under `node_modules`.

For Media Manager uploads, configure `INLAY_MEDIA_DISK` for persistent or
object storage in production. An ephemeral application filesystem is suitable
for build artifacts but not durable user uploads.

The public [Inlay demo](https://github.com/InlayPHP/demo) is the clean-consumer
reference: it has no Composer path repositories or local npm links, and its CI
asserts that the production stylesheet contains Inlay utilities.
