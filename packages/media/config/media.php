<?php

declare(strict_types=1);
use Inlay\Media\Models\MediaAsset;
use Inlay\Media\Models\MediaCollection;
use Inlay\Media\Models\MediaFolder;

return [
    'disk' => env('INLAY_MEDIA_DISK', 'local'),
    'directory' => env('INLAY_MEDIA_DIRECTORY', 'media'),
    'visibility' => env('INLAY_MEDIA_VISIBILITY', 'private'),
    'max_size_kb' => (int) env('INLAY_MEDIA_MAX_SIZE_KB', 10240),
    'allowed_mime_types' => [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/avif',
        'application/pdf',
        'text/plain',
        'text/csv',
        'application/zip',
    ],
    'allowed_extensions' => [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif',
        'pdf', 'txt', 'csv', 'zip',
    ],
    'transformations' => [
        // Keep uploads fast by default. Set INLAY_MEDIA_QUEUE_TRANSFORMATIONS=true
        // when registered transformers perform expensive work.
        'async' => (bool) env('INLAY_MEDIA_QUEUE_TRANSFORMATIONS', false),
        'connection' => env('INLAY_MEDIA_TRANSFORMATIONS_CONNECTION'),
        'queue' => env('INLAY_MEDIA_TRANSFORMATIONS_QUEUE', 'media'),
    ],
    'models' => [
        'asset' => MediaAsset::class,
        'collection' => MediaCollection::class,
        'folder' => MediaFolder::class,
    ],
];
