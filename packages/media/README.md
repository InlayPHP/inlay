# Inlay Media

[![Packagist](https://img.shields.io/packagist/v/inlayphp/media?style=flat-square&label=packagist)](https://packagist.org/packages/inlayphp/media)
[![PHP](https://img.shields.io/packagist/dependency-v/inlayphp/media/php?style=flat-square)](https://packagist.org/packages/inlayphp/media)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../LICENSE)

**Secure, framework-grade media catalog and upload domain for Inlay**

`inlayphp/media` is an official optional Inlay package and a storage-agnostic media catalog for Laravel applications. It owns uploaded objects, database metadata, logical folders, visibility, trash/restore, and transformation hooks. It does not provide an admin UI; install `inlayphp/media-manager` when you need a panel browser and picker.

## Package boundary

Media is deliberately outside the Inlay core. A clean Inlay installation does not create media tables, choose a filesystem disk, or add upload behavior until this package is installed. Conversely, `inlayphp/media` can be used in a Laravel application without installing Inlay Panels, React, Vue, or the media manager.

This package owns:

- the `MediaAsset`, `MediaFolder`, and `MediaCollection` catalog models and migrations;
- secure upload validation and physical object creation;
- folder moves, visibility, trash, restore, and permanent deletion;
- reusable named collections (albums) that can contain the same asset across
  multiple editorial groupings without moving its canonical folder;
- arbitrary metadata, including the optional image `focal_point` value (`x` and
  `y` percentages) used by delivery or transformation layers;
- an opt-in reference resolver registry so integrations can report where an
  asset is used without coupling the catalog to application models;
- the stable `MediaAssetContract` and post-upload transformer registry.

It intentionally does not own HTTP routes, authorization policy, an Inertia payload, a browser/picker UI, or Spatie collections. Those boundaries belong to the host application, `inlayphp/media-manager`, and `inlayphp/media-spatie` respectively.

## Install

```bash
composer require inlayphp/media
php artisan vendor:publish --tag=inlay-media-migrations
php artisan migrate
```

The published asset migration uses 50-character disk names and 500-character
object paths so the composite uniqueness key remains valid on MySQL with
`utf8mb4`, including Laravel Cloud's default database configuration.

That is the complete clean-core installation. No panel plugin registration or JavaScript package is required for service-level use. Laravel package discovery registers `MediaServiceProvider`; applications which disable discovery may register `Inlay\Media\MediaServiceProvider` manually.

Laravel discovers `Inlay\Media\MediaServiceProvider`. Publish configuration only when the defaults need changing:

```bash
php artisan vendor:publish --tag=inlay-media-config
```

Important environment settings are `INLAY_MEDIA_DISK`, `INLAY_MEDIA_DIRECTORY`, `INLAY_MEDIA_VISIBILITY`, and `INLAY_MEDIA_MAX_SIZE_KB`. The default catalog uses the `local` disk, stores below `media/YYYY/MM`, permits files up to 10 MB, and creates private assets. Allowed MIME types and extensions are independent allow-lists in `config/media.php`.

## Upload files

`MediaUploader` accepts Laravel's trusted `UploadedFile`, an optional logical folder, arbitrary JSON metadata, and an optional visibility override:

```php
use Illuminate\Http\Request;
use Inlay\Media\Enums\MediaVisibility;
use Inlay\Media\Models\MediaFolder;
use Inlay\Media\Services\MediaUploader;

final class UploadController
{
    public function __invoke(Request $request, MediaUploader $uploader)
    {
        $folder = MediaFolder::query()->find($request->integer('folder_id'));

        $asset = $uploader->upload(
            file: $request->file('attachment'),
            folder: $folder,
            metadata: [
                'alt' => $request->string('alt')->toString(),
                'caption' => $request->string('caption')->toString(),
            ],
            visibility: MediaVisibility::Private,
        );

        return ['id' => $asset->key()];
    }
}
```

The service detects MIME type and extension from file contents, enforces both allow-lists and the size limit, strips path/control characters from the display name, and writes to a UUID object key. The client filename is never used as a storage path. If catalog persistence fails, the newly written object is removed.

## Catalog models and contract

`MediaAsset` implements `MediaAssetContract` and exposes `key()`, `disk()`, `path()`, `mimeType()`, `size()`, `visibility()`, and `metadata()`. It belongs to an optional `MediaFolder`; folders support parent, children, and assets relationships. Both models use soft deletes.

Applications can extend either model:

```php
return [
    'models' => [
        'asset' => App\Models\Asset::class,
        'collection' => App\Models\AssetCollection::class,
        'folder' => App\Models\AssetFolder::class,
    ],
];
```

Custom models should extend `Inlay\Media\Models\MediaAsset` and `Inlay\Media\Models\MediaFolder` respectively so the configured relationships and services retain their model contracts. Packages which only read catalog assets should type against `MediaAssetContract`.

## Organize, trash, and restore

Use `MediaLibrary` for stateful operations:

```php
use Inlay\Media\Enums\MediaVisibility;
use Inlay\Media\Services\MediaLibrary;

$library->moveAsset($asset, $archiveFolder);
$library->moveFolder($archiveFolder, $parentFolder);
$collection = $library->createCollection('Homepage');
$library->syncCollections($asset, [$collection->getKey()]);
$library->setVisibility($asset, MediaVisibility::Public);
$library->trash($asset);
$library->restore($asset);
$library->permanentlyDelete($asset);
```

Folders are logical: moving an asset updates `folder_id` without copying its object. Folder moves reject self/descendant cycles. Trash only soft-deletes the catalog record and leaves the object available for restore. Permanent deletion removes the object first and force-deletes the row only after storage reports success. Visibility changes update storage and the row; a database failure attempts to restore the original storage visibility.

## Transformations

Implement `MediaTransformer` for thumbnails, metadata extraction, virus-scan dispatch, or other post-upload work:

```php
use Inlay\Media\Contracts\MediaAssetContract;
use Inlay\Media\Contracts\MediaTransformer;
use Inlay\Media\Support\TransformerRegistry;

final class ExtractImageDimensions implements MediaTransformer
{
    public function supports(MediaAssetContract $asset): bool
    {
        return str_starts_with($asset->mimeType(), 'image/');
    }

    public function transform(MediaAssetContract $asset): void
    {
        // Dispatch expensive work to a queue here.
    }
}

app(TransformerRegistry::class)->register(new ExtractImageDimensions);
```

Transformers run after the object and asset row exist. A transformer owns its own retry/compensation behavior. They run synchronously by default, or can be queued without changing the transformer contract:

```dotenv
INLAY_MEDIA_QUEUE_TRANSFORMATIONS=true
INLAY_MEDIA_TRANSFORMATIONS_CONNECTION=redis
INLAY_MEDIA_TRANSFORMATIONS_QUEUE=media-transforms
```

When enabled, `MediaUploader` dispatches `Inlay\Media\Jobs\TransformMediaAsset` after catalog persistence. The serialized job contains only the configured asset model class and primary key; the worker resolves the current asset and the shared `TransformerRegistry`. Generated variants may be recorded in metadata or managed by `inlayphp/media-spatie`.

## Usage references

The catalog cannot infer arbitrary application relationships. Integrations can
register a resolver that returns safe, server-authored `MediaReference` values:

```php
use Inlay\Media\Contracts\{MediaAssetContract, MediaReference, MediaReferenceResolver};
use Inlay\Media\Support\MediaReferenceRegistry;

app(MediaReferenceRegistry::class)->register('pages', new class implements MediaReferenceResolver {
    public function resolve(MediaAssetContract $asset): iterable
    {
        if ($asset->key() === null) {
            return;
        }

        yield new MediaReference('page', 'Homepage hero', '/admin/pages/1');
    }
});
```

The manager caps the payload with `media-manager.max_references_per_asset`
(50 by default). References are advisory UI links, not authorization
boundaries—application routes must still authorize access.

## Security

- Private visibility is the default; authorize delivery in the application or use Media Manager's signed delivery endpoint.
- SVG is excluded from the default allow-list because inline user SVG can contain active content.
- The configured directory rejects empty, parent-traversal, and backslash paths.
- MIME/extension checks are server-side and do not trust browser `accept` attributes.
- Do not expose `disk` and `path` directly to untrusted clients or permanently delete objects while another model still references them.

## Testing

The monorepo package suite uses Pest:

```bash
vendor/bin/pest tests/MediaTest.php
```

For application tests, use `Storage::fake()`, `UploadedFile::fake()`, and assert both the catalog row and physical object. Related packages are `inlayphp/media-manager` for panel UI and `inlayphp/media-spatie` for zero-copy Spatie Media Library integration.
