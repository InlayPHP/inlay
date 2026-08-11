<?php

declare(strict_types=1);

namespace Inlay\MediaSpatie\Contracts;

use Spatie\MediaLibrary\MediaCollections\Models\Media;

interface ConversionGenerator
{
    public function generate(Media $media): void;
}
