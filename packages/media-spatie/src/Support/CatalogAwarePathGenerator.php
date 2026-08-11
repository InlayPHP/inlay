<?php

declare(strict_types=1);

namespace Inlay\MediaSpatie\Support;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;

final readonly class CatalogAwarePathGenerator implements PathGenerator
{
    public const ASSET_PATH = 'inlay_media_asset_path';

    public function __construct(private Container $container) {}

    public function getPath(Media $media): string
    {
        $path = $this->assetPath($media);

        return $path === null ? $this->fallback($media)->getPath($media) : $this->directory($path);
    }

    public function getPathForConversions(Media $media): string
    {
        if ($this->assetPath($media) === null) {
            return $this->fallback($media)->getPathForConversions($media);
        }

        return $this->derivedDirectory($media, 'conversions');
    }

    public function getPathForResponsiveImages(Media $media): string
    {
        if ($this->assetPath($media) === null) {
            return $this->fallback($media)->getPathForResponsiveImages($media);
        }

        return $this->derivedDirectory($media, 'responsive-images');
    }

    private function assetPath(Media $media): ?string
    {
        $path = $media->getCustomProperty(self::ASSET_PATH);
        if ($path === null) {
            return null;
        }

        if (! is_string($path) || trim($path, '/') === '' || str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, '\\')) {
            throw new InvalidArgumentException('The linked Inlay media path is unsafe.');
        }

        return trim($path, '/');
    }

    private function directory(string $path): string
    {
        $directory = dirname($path);

        return $directory === '.' ? '' : trim($directory, '/').'/';
    }

    private function derivedDirectory(Media $media, string $type): string
    {
        $root = trim((string) config('media-spatie.conversions_directory', 'inlay-media-library'), '/');
        if ($root === '' || str_contains($root, '..') || str_contains($root, '\\')) {
            throw new InvalidArgumentException('The Inlay conversion directory is unsafe.');
        }

        $identifier = $media->getAttribute('uuid') ?: $media->getKey();
        if (! is_int($identifier) && (! is_string($identifier) || $identifier === '')) {
            throw new InvalidArgumentException('Spatie media must be persisted before resolving derived paths.');
        }
        $identifier = (string) $identifier;
        if (str_contains($identifier, '..') || str_contains($identifier, '/') || str_contains($identifier, '\\')) {
            throw new InvalidArgumentException('The Spatie media identifier is unsafe.');
        }

        return "{$root}/{$identifier}/{$type}/";
    }

    private function fallback(Media $media): PathGenerator
    {
        $class = $this->modelFallback($media) ?: config('media-spatie.fallback_path_generator') ?: DefaultPathGenerator::class;
        if (! is_string($class) || ! is_a($class, PathGenerator::class, true) || $class === self::class) {
            throw new InvalidArgumentException('The configured fallback path generator is invalid.');
        }

        return $this->container->make($class);
    }

    /** @return class-string<PathGenerator>|null */
    private function modelFallback(Media $media): ?string
    {
        $type = (string) $media->getAttribute('model_type');
        $model = Relation::getMorphedModel($type) ?: $type;

        foreach ((array) config('media-spatie.fallback_path_generators', []) as $subject => $generator) {
            if (is_string($subject) && is_string($generator) && ($type === $subject || is_a($model, $subject, true))) {
                return $generator;
            }
        }

        return null;
    }
}
