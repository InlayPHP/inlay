<?php

declare(strict_types=1);

namespace Inlay\MediaSpatie\Support;

use Inlay\Media\Contracts\MediaAssetContract;
use Inlay\Media\Contracts\MediaReference;
use Inlay\Media\Contracts\MediaReferenceResolver;
use Inlay\MediaSpatie\MediaBridge;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

final readonly class SpatieReferenceResolver implements MediaReferenceResolver
{
    /** @param class-string<Media>|null $mediaModel */
    public function __construct(private ?string $mediaModel = null) {}

    public function resolve(MediaAssetContract $asset): iterable
    {
        if ($asset->key() === null) {
            return;
        }

        $model = $this->mediaModel ?? config('media-library.media_model', Media::class);
        if (! is_string($model) || ! is_a($model, Media::class, true)) {
            return;
        }

        try {
            $media = $model::query()
                ->whereJsonContains('custom_properties->'.MediaBridge::ASSET_ID, $asset->key())
                ->limit(50)
                ->get();
        } catch (Throwable) {
            // Drivers without JSON containment can still use the resolver;
            // filter decoded custom properties in PHP as a safe fallback.
            $media = $model::query()->limit(500)->get()->filter(
                static fn (Media $item): bool => (string) $item->getCustomProperty(MediaBridge::ASSET_ID) === (string) $asset->key(),
            )->take(50);
        }

        foreach ($media as $item) {
            $type = (string) $item->getAttribute('model_type');
            $id = (string) $item->getAttribute('model_id');
            $collection = (string) $item->getAttribute('collection_name');

            yield new MediaReference(
                'spatie',
                trim($type.' #'.$id.' · '.$collection),
                meta: ['media_id' => $item->getKey(), 'model_type' => $type, 'model_id' => $id, 'collection' => $collection],
            );
        }
    }
}
