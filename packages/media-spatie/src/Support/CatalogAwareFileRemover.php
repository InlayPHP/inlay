<?php

declare(strict_types=1);

namespace Inlay\MediaSpatie\Support;

use Illuminate\Contracts\Filesystem\Factory;
use InvalidArgumentException;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\FileRemover\DefaultFileRemover;
use Spatie\MediaLibrary\Support\FileRemover\FileRemover;

final class CatalogAwareFileRemover implements FileRemover
{
    private readonly DefaultFileRemover $catalogRemover;

    public function __construct(
        private readonly Filesystem $mediaFileSystem,
        private readonly Factory $filesystem,
    ) {
        $this->catalogRemover = new DefaultFileRemover($mediaFileSystem, $filesystem);
    }

    public function removeAllFiles(Media $media): void
    {
        if ($media->getCustomProperty(CatalogAwarePathGenerator::ASSET_PATH) === null) {
            $this->fallback()->removeAllFiles($media);

            return;
        }

        $disks = array_values(array_unique(array_filter([
            (string) $media->getAttribute('disk'),
            (string) $media->getAttribute('conversions_disk'),
        ])));

        foreach ($disks as $disk) {
            $this->catalogRemover->removeFromConversionsDirectory($media, $disk);
            $this->catalogRemover->removeFromResponsiveImagesDirectory($media, $disk);
        }
    }

    public function removeResponsiveImages(Media $media, string $conversionName): void
    {
        $this->fallback()->removeResponsiveImages($media, $conversionName);
    }

    public function removeFile(string $path, string $disk): void
    {
        $this->fallback()->removeFile($path, $disk);
    }

    private function fallback(): FileRemover
    {
        $class = config('media-spatie.fallback_file_remover') ?: DefaultFileRemover::class;
        if (! is_string($class) || ! is_a($class, FileRemover::class, true) || $class === self::class) {
            throw new InvalidArgumentException('The configured fallback file remover is invalid.');
        }

        return new $class($this->mediaFileSystem, $this->filesystem);
    }
}
