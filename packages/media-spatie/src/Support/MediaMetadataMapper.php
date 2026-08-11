<?php

declare(strict_types=1);

namespace Inlay\MediaSpatie\Support;

use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class MediaMetadataMapper
{
    /** @return array<string, mixed> */
    public function map(Media $media): array
    {
        return [
            'id' => $media->getKey(),
            'uuid' => $media->getAttribute('uuid'),
            'model_type' => $media->getAttribute('model_type'),
            'model_id' => $media->getAttribute('model_id'),
            'collection' => (string) $media->getAttribute('collection_name'),
            'conversions_disk' => (string) $media->getAttribute('conversions_disk'),
            'order' => $media->getAttribute('order_column'),
            'manipulations' => (array) ($media->getAttribute('manipulations') ?? []),
            'custom_properties' => (array) ($media->getAttribute('custom_properties') ?? []),
            'generated_conversions' => (array) ($media->getAttribute('generated_conversions') ?? []),
            'responsive_images' => (array) ($media->getAttribute('responsive_images') ?? []),
        ];
    }
}
