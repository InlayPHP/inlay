<?php

declare(strict_types=1);

use Inlay\MediaSpatie\Support\CatalogAwareFileRemover;
use Inlay\MediaSpatie\Support\CatalogAwarePathGenerator;

return [
    'reference_mode' => true,
    'reference_resolver' => true,
    'idempotent_attachments' => true,
    'generate_conversions' => true,
    'default_visibility' => 'private',
    'conversions_directory' => 'inlay-media-library',
    'path_generator' => CatalogAwarePathGenerator::class,
    'file_remover' => CatalogAwareFileRemover::class,
    // Null captures the corresponding Media Library configuration before it is wrapped.
    'fallback_path_generator' => null,
    'fallback_path_generators' => [],
    'fallback_file_remover' => null,
];
