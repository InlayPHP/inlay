<?php

declare(strict_types=1);

namespace Inlay\MediaSpatie;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Database\Eloquent\Model;
use Inlay\Media\Contracts\MediaAssetContract;
use Inlay\Media\Enums\MediaVisibility;
use Inlay\Media\Models\MediaAsset;
use Inlay\Media\Models\MediaFolder;
use Inlay\MediaSpatie\Contracts\ConversionGenerator;
use Inlay\MediaSpatie\Support\CatalogAwarePathGenerator;
use Inlay\MediaSpatie\Support\MediaMetadataMapper;
use InvalidArgumentException;
use RuntimeException;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

final readonly class MediaBridge
{
    public const ASSET_ID = 'inlay_media_asset_id';

    public const ASSET_DISK = 'inlay_media_asset_disk';

    public function __construct(
        private Filesystems $filesystems,
        private Config $config,
        private ConversionGenerator $conversions,
        private MediaMetadataMapper $metadata,
    ) {}

    /**
     * Attach a catalog asset to a Spatie collection by reference. The original blob is not copied.
     *
     * @param  array<string, mixed>  $customProperties
     * @param  array<string, mixed>  $manipulations
     */
    public function attach(
        HasMedia $subject,
        MediaAssetContract $asset,
        string $collection = 'default',
        array $customProperties = [],
        array $manipulations = [],
        ?string $conversionsDisk = null,
        ?bool $generateConversions = null,
    ): Media {
        if (! $subject instanceof Model) {
            throw new InvalidArgumentException('A Spatie media subject must be an Eloquent model.');
        }
        if (! (bool) $this->config->get('media-spatie.reference_mode', true)) {
            throw new RuntimeException('The zero-copy media bridge requires reference mode.');
        }
        if ($asset->key() === null) {
            throw new InvalidArgumentException('The Inlay media asset must be persisted before attachment.');
        }

        $path = $this->safePath($asset->path());
        if (! $this->filesystems->disk($asset->disk())->exists($path)) {
            throw new RuntimeException('The Inlay media blob does not exist on its configured disk.');
        }

        $collection = trim($collection);
        if ($collection === '') {
            throw new InvalidArgumentException('A Spatie media collection name is required.');
        }

        if ((bool) $this->config->get('media-spatie.idempotent_attachments', true)) {
            $existing = $subject->media()->where('collection_name', $collection)->get()->first(fn (mixed $media): bool => $media instanceof Media
                && (string) $media->getCustomProperty(self::ASSET_ID) === (string) $asset->key()
                && $media->getCustomProperty(self::ASSET_DISK) === $asset->disk());
            if ($existing instanceof Media) {
                return $existing;
            }
        }

        $mediaClass = $subject->getMediaModel();
        if (! is_string($mediaClass) || ! is_a($mediaClass, Media::class, true)) {
            throw new InvalidArgumentException('The subject media model must extend Spatie Media.');
        }

        $properties = array_merge($customProperties, [
            self::ASSET_ID => $asset->key(),
            self::ASSET_DISK => $asset->disk(),
            CatalogAwarePathGenerator::ASSET_PATH => $path,
            'inlay_media_metadata' => $asset->metadata(),
        ]);
        $displayName = $asset->metadata()['name'] ?? $asset->metadata()['original_name'] ?? pathinfo($path, PATHINFO_FILENAME);
        $mediaCollection = $subject->getMediaCollection($collection);
        $collectionConversionsDisk = is_object($mediaCollection) && property_exists($mediaCollection, 'conversionsDiskName')
            ? (string) $mediaCollection->conversionsDiskName
            : '';
        $conversionsDisk = $conversionsDisk ?: ($collectionConversionsDisk !== '' ? $collectionConversionsDisk : $asset->disk());

        /** @var Media $media */
        $media = $subject->media()->create([
            'name' => (string) $displayName,
            'file_name' => basename($path),
            'disk' => $asset->disk(),
            'conversions_disk' => $conversionsDisk,
            'collection_name' => $collection,
            'mime_type' => $asset->mimeType(),
            'size' => $asset->size(),
            'manipulations' => $manipulations,
            'custom_properties' => $properties,
            'generated_conversions' => [],
            'responsive_images' => [],
        ]);

        try {
            if ($generateConversions ?? (bool) $this->config->get('media-spatie.generate_conversions', true)) {
                $this->conversions->generate($media);
            }
        } catch (Throwable $exception) {
            $media->delete();
            throw $exception;
        }

        return $media;
    }

    /**
     * Add a Spatie original to the Inlay catalog by reference. The blob is not copied.
     */
    public function ingest(
        Media $media,
        ?MediaFolder $folder = null,
        ?MediaVisibility $visibility = null,
    ): MediaAssetContract {
        if (! $media->exists) {
            throw new InvalidArgumentException('Spatie media must be persisted before ingestion.');
        }

        $disk = (string) $media->getAttribute('disk');
        $linkedPath = $media->getCustomProperty(CatalogAwarePathGenerator::ASSET_PATH);
        $path = $this->safePath(is_string($linkedPath) ? $linkedPath : $media->getPathRelativeToRoot());
        $filesystem = $this->filesystems->disk($disk);
        if (! $filesystem->exists($path)) {
            throw new RuntimeException('The Spatie media blob does not exist on its configured disk.');
        }

        $visibility ??= $this->visibility($disk, $path);
        $model = $this->assetModel();

        /** @var MediaAsset $asset */
        $asset = $model::withTrashed()->firstOrNew(['disk' => $disk, 'path' => $path]);
        $metadata = (array) ($asset->getAttribute('metadata') ?? []);
        $metadata['spatie'] = $this->metadata->map($media);
        $asset->fill([
            'folder_id' => $folder?->getKey() ?? $asset->getAttribute('folder_id'),
            'file_name' => (string) $media->getAttribute('file_name'),
            'mime_type' => (string) $media->getAttribute('mime_type'),
            'extension' => strtolower(pathinfo((string) $media->getAttribute('file_name'), PATHINFO_EXTENSION)),
            'size' => (int) $media->getAttribute('size'),
            'visibility' => $visibility->value,
            'metadata' => $metadata,
        ]);
        $asset->save();
        if (method_exists($asset, 'trashed') && $asset->trashed()) {
            $asset->restore();
        }

        return $asset;
    }

    private function safePath(string $path): string
    {
        if (trim($path, '/') === '' || str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, '\\')) {
            throw new InvalidArgumentException('The media path is unsafe.');
        }

        return trim($path, '/');
    }

    private function visibility(string $disk, string $path): MediaVisibility
    {
        try {
            return MediaVisibility::from($this->filesystems->disk($disk)->getVisibility($path));
        } catch (Throwable) {
            return MediaVisibility::from((string) $this->config->get('media-spatie.default_visibility', 'private'));
        }
    }

    /** @return class-string<MediaAsset> */
    private function assetModel(): string
    {
        $model = $this->config->get('media.models.asset', MediaAsset::class);
        if (! is_string($model) || ! is_a($model, MediaAsset::class, true)) {
            throw new InvalidArgumentException('The configured Inlay media asset model is invalid.');
        }

        return $model;
    }
}
