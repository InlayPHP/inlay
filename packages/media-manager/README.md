# Inlay Media Manager

[![Packagist](https://img.shields.io/packagist/v/inlayphp/media-manager?style=flat-square&label=packagist)](https://packagist.org/packages/inlayphp/media-manager)
[![PHP](https://img.shields.io/packagist/dependency-v/inlayphp/media-manager/php?style=flat-square)](https://packagist.org/packages/inlayphp/media-manager)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../LICENSE)

**Authorized media browser and picker backend plugin for Inlay panels**

`inlayphp/media-manager` is the official optional media administration plugin for Inlay Panels. It adds an authorized media browser and picker backend to a panel. The PHP package owns routes, queries, uploads, mutations, signed delivery, and the versioned Inertia/JSON payload. Install a separate React or Vue renderer for the UI.

## Package boundary

Media Manager is not part of Inlay core and is not needed for service-level use of `inlayphp/media`. A clean Inlay panel gains no media navigation or routes until `MediaManagerPlugin` is explicitly registered.

The packages divide responsibility as follows:

- `inlayphp/media` owns catalog persistence, storage, upload validation, and lifecycle services;
- `inlayphp/media-manager` owns panel route contributions, Gate checks, queries, mutations, signed delivery, and the `inlay.media-manager.v1` contract;
- `@inlayphp/media-manager-react` and `@inlayphp/media-manager-vue` render that contract and are alternatives—install the one matching the application;
- `inlayphp/media-spatie` is an independent optional bridge and is not required for the manager.

The plugin does not weaken the panel's authentication, replace Laravel Policies, or make the physical disk/path public.

## Install

```bash
composer require inlayphp/media-manager
php artisan vendor:publish --tag=inlay-media-migrations
php artisan migrate
```

For a clean-core application, this installs the backend and its required official packages through Composer. Publishing/running the media migration creates the catalog. Laravel discovers the service providers, but package discovery alone does not add panel routes; plugin registration below is mandatory.

Choose one renderer:

```bash
pnpm add @inlayphp/media-manager-react
# or
pnpm add @inlayphp/media-manager-vue
```

Do not install both renderers unless the repository intentionally builds both frontend stacks. Neither JavaScript package is needed for a JSON-only custom client.

The package requires `inlayphp/media`, `inlayphp/panels`, `inlayphp/authorization`, and Inertia Laravel. Publish backend options with:

```bash
php artisan vendor:publish --tag=inlay-media-manager-config
```

`config/media-manager.php` selects the component (`inlay-media-manager/index`), default and maximum page sizes, and signed URL lifetime.

## Register the panel plugin

```php
use Inlay\MediaManager\MediaManagerPlugin;
use Inlay\Panel;

public function panel(Panel $panel): Panel
{
    return $panel
        ->resourceMutationMiddleware(['throttle:media'])
        ->plugin(MediaManagerPlugin::make());
}
```

This explicit registration keeps the core panel clean and lets applications enable Media Manager per panel. Register the plugin only on panels that should expose the media library.

The plugin contributes a Media navigation item and protected routes below `{panel}/media`. Mutation routes inherit panel mutation middleware. The download route additionally uses Laravel's `signed` middleware.

## Authorization

The plugin contributes these owned abilities:

- `media.viewAny` and `media.pick`
- `media.upload` and `media.update`
- `media.delete`, `media.restore`, and `media.forceDelete`
- `media.download`
- `media.manageFolders`
- `media.manageCollections`

Every controller calls `AuthorizationManager`, which delegates to Laravel Gate. Define Gate callbacks or model policies as normal; `inlayphp/authorization-spatie` roles can grant abilities, but Gate remains authoritative. Asset-specific operations pass the asset to Gate, and folder operations pass the folder where applicable.

## Inertia and JSON contract

The browser renders `inlay-media-manager/index` with a `media` prop. JSON browser and picker responses use the same contract:

```json
{
  "contract": "inlay.media-manager.v1",
  "assets": {
    "data": [],
    "meta": { "current_page": 1, "last_page": 1, "per_page": 24, "total": 0 },
    "links": { "first": "...", "last": "...", "previous": null, "next": null }
  },
  "folders": [],
  "breadcrumbs": [{ "id": null, "name": "Media" }],
  "currentFolderId": null,
  "view": "grid",
  "endpoints": {},
  "filters": { "search": "", "folder_id": null, "mime": null, "visibility": null, "trash": false },
  "picker": false
}
```

Asset rows contain ID, logical folder, reusable collection memberships, display filename, MIME type, extension, size, visibility, metadata, optional bounded `references`, timestamps, trash state, and a temporary signed `delivery_url`. Physical disk and object path are intentionally omitted. Folder rows form a nested tree and include asset/child counts. Collection rows are named albums with asset counts. `endpoints` supplies templates for upload; folder and collection creation/move/delete; asset update/move/collection-sync/trash/restore/force-delete; and `storageBrowse`. When the media storage service is installed, `storage.browsers` lists the explicitly exposed browser names and disk labels.

Storage browsing is deliberately read-only and bounded. The built-in `filesystem` browser reads configured Laravel disks and returns `inlay.media-storage.v1` objects (`disk`, `path`, `name`, `directory`, and optional file metadata) from `GET storageBrowse?browser=filesystem&disk=local&prefix=imports`. Configure the allow-list rather than exposing every disk:

```php
// config/media-manager.php
'storage_disks' => [
    ['name' => 'local', 'label' => 'Application files'],
    ['name' => 's3', 'label' => 'Object storage'],
],
'storage_browser_limit' => 100,
```

Applications may register additional `MediaStorageBrowser` implementations in `MediaStorageRegistry` for API-backed providers. Browsing never imports or mutates an object; React and Vue expose `onStorageObjectSelect` so the host application can authorize and implement an explicit import flow.

Queries accept `search`, `folder_id` (`root` selects unfiled assets), `collection_id`, MIME types such as `image/*`, `visibility`, `trash`, `view`, `page`, and bounded `per_page`.

## HTTP operations

The backend supports:

- secure multipart upload through `MediaUploader`;
- filename, metadata, and visibility updates;
- logical asset and folder moves;
- collection creation, editing, deletion, and asset membership synchronization;
- folder creation and empty-folder deletion;
- asset trash, restore, and permanent deletion;
- JSON picker results;
- bounded, authorized storage browsing for configured disks;
- signed private file delivery.

Force-delete returns HTTP 409 unless an asset is already in trash. Folder deletion returns HTTP 409 when live or trashed assets/children would be orphaned. Folder moves use the core cycle check. Delivery authorizes the current user before opening storage and responds as an attachment with `nosniff` and `private, no-store` headers.

## Resolve package pages

Both renderer packages export `mediaManagerPages` and `resolveMediaManagerPage()` keyed by `inlay-media-manager/index`:

```ts
import { mediaManagerPages } from '@inlayphp/media-manager-react'

const packagePage = mediaManagerPages[name as keyof typeof mediaManagerPages]
```

Use `@inlayphp/media-manager-vue` in Vue applications. The renderer READMEs document callback/emit overrides, picker composition, theming, and Tailwind source discovery.

## Testing

```bash
vendor/bin/pest tests/MediaTest.php tests/MediaManagerTest.php
pnpm --filter @inlayphp/media-manager-react test -- --run
pnpm --filter @inlayphp/media-manager-vue test -- --run
```

Also run each renderer's typecheck and build scripts. Related packages: `inlayphp/media` owns the catalog; `inlayphp/media-spatie` bridges Spatie collections; `inlayphp/authorization-spatie` synchronizes contributed abilities.
