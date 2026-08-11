<?php

declare(strict_types=1);

namespace Inlay\MediaSpatie\Support;

use Inlay\MediaSpatie\Contracts\ConversionGenerator;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final readonly class SpatieConversionGenerator implements ConversionGenerator
{
    public function __construct(private FileManipulator $manipulator) {}

    public function generate(Media $media): void
    {
        $this->manipulator->createDerivedFiles($media);
    }
}
