# Inlay Media — Spatie Adapter

[![Packagist](https://img.shields.io/packagist/v/inlayphp/media-spatie?style=flat-square&label=packagist)](https://packagist.org/packages/inlayphp/media-spatie)
[![PHP](https://img.shields.io/packagist/dependency-v/inlayphp/media-spatie/php?style=flat-square)](https://packagist.org/packages/inlayphp/media-spatie)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../LICENSE)

**Zero-copy Spatie Media Library bridge for the Inlay media catalog**

`inlayphp/media-spatie` is the official optional Inlay integration for `spatie/laravel-medialibrary` 11. It bridges the central `inlayphp/media` catalog and Spatie collections. In reference mode, a Spatie attachment points to the existing catalog original while conversions and responsive images remain isolated. It can also ingest an existing Spatie original into the Inlay catalog without copying the blob.

## Package boundary

This adapter is not required by Inlay core, `inlayphp/media`, or `inlayphp/media-manager`. Install it only when the application already uses—or intentionally adopts—Spatie Media Library. It adds no panel routes and no React/Vue UI.

Ownership remains explicit:

- `inlayphp/media` owns catalog rows and original blobs;
- Spatie owns model collections, conversions, responsive images, and their derived files;
- this adapter owns the safe zero-copy reference and metadata mapping between them;
- this adapter registers a bounded usage resolver so Media Manager can show
  which Spatie subject and collection uses a catalog asset;
- the application owns authorization and decides when attaching, ingesting, or permanently deleting is allowed.

## Install and setup

```bash
composer require inlayphp/media-spatie
php artisan vendor:publish --tag=inlay-media-spatie-config
```

For a clean Inlay installation, install and migrate `inlayphp/media` first, then install and migrate Spatie Media Library according to its official setup. Composer installs both libraries as dependencies, but each package's database migrations still need to run. Laravel package discovery registers `MediaSpatieServiceProvider`; no Inlay panel plugin registration is needed.

Models keep the normal Spatie contract:

```php
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

final class Post extends Model implements HasMedia
{
    use InteractsWithMedia;
}
```

The provider binds `ConversionGenerator` to `SpatieConversionGenerator`. When `media-spatie.reference_mode` is enabled, it wraps Spatie's configured path generator and file remover with catalog-aware implementations while retaining the previous classes as fallbacks for ordinary, non-catalog media.

## Attach a catalog asset

```php
use Inlay\MediaSpatie\MediaBridge;

$media = app(MediaBridge::class)->attach(
    subject: $post,
    asset: $asset,
    collection: 'hero',
    customProperties: ['alt' => 'Mountain at sunrise'],
    manipulations: ['thumb' => ['width' => 480]],
    conversionsDisk: 'public',
    generateConversions: true,
);
```

`attach()` validates that the subject is a persisted Eloquent `HasMedia` model, the asset is persisted, the catalog path is safe, and the original exists. It creates a Spatie `media` row but does not copy the original. Custom properties include the Inlay asset ID, disk, safe catalog path, and catalog metadata.

Attachments are idempotent for the same subject, collection, asset ID, and asset disk when `idempotent_attachments` is true. Disable that setting only when duplicate rows inside the same collection are intentional. A conversion failure deletes the newly created Spatie row and rethrows the error.

## Ingest existing Spatie media

```php
use Inlay\Media\Enums\MediaVisibility;

$asset = app(MediaBridge::class)->ingest(
    media: $spatieMedia,
    folder: $optionalFolder,
    visibility: MediaVisibility::Private,
);
```

`ingest()` references Spatie's current original path, then creates or updates the catalog row identified by disk and path. Re-ingestion is idempotent and restores a matching soft-deleted asset. When visibility is omitted, the bridge asks the filesystem and falls back to `media-spatie.default_visibility` if the driver cannot report it.

The catalog's `metadata.spatie` mapping contains Spatie identifiers, owner, collection, ordering, custom properties, manipulation settings, generated conversions, responsive images, and conversion disk. The blob is never downloaded and rewritten.

## Paths, conversions, and deletion ownership

In reference mode:

- catalog-linked originals resolve to the Inlay asset path;
- conversions resolve below `inlay-media-library/{media-uuid}/` by default;
- deleting a linked Spatie row removes derived files but not the catalog-owned original;
- non-catalog media delegates to the captured Spatie path generator and file remover;
- model-specific `media-library.custom_path_generators` are wrapped and preserved as fallbacks.

The Inlay catalog owns linked originals. Permanently delete them only through `Inlay\Media\Services\MediaLibrary` after checking model references. Spatie deletion must never be treated as permission to erase the shared catalog object.

## Configuration

`config/media-spatie.php` provides:

- `reference_mode`: enables zero-copy wrapping; `MediaBridge::attach()` requires it.
- `idempotent_attachments`: reuses matching attachments.
- `generate_conversions`: global conversion-generation default.
- `default_visibility`: fallback used during ingestion.
- `conversions_directory`: root for derived files.
- `path_generator` and `file_remover`: catalog-aware Spatie strategies.
- `fallback_path_generator`, `fallback_path_generators`, and `fallback_file_remover`: explicit delegates for non-catalog media.

Prefer configuration over changing Spatie's static registries at runtime, because the provider captures the configured fallback graph during boot. Replace `ConversionGenerator` in the container to queue conversion generation or integrate a dedicated worker.

## Security and testing

The bridge rejects absolute paths, backslashes, parent traversal, empty collections, missing originals, invalid model classes, and unpersisted records. It does not authorize who may attach or ingest media; call it only after application Policy/Gate checks.

```bash
vendor/bin/pest tests/MediaSpatieTest.php
```

Application integration tests should cover both catalog-linked and ordinary Spatie media, deletion behavior, conversion disks, custom path generators, and idempotency. Related packages are `inlayphp/media` (required catalog) and `inlayphp/media-manager` (authorized browser/picker).
