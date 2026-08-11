<?php

declare(strict_types=1);

namespace Inlay\Media\Services;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Inlay\Media\Contracts\MediaAssetContract;
use Inlay\Media\Enums\MediaVisibility;
use Inlay\Media\Jobs\TransformMediaAsset;
use Inlay\Media\Models\MediaAsset;
use Inlay\Media\Models\MediaFolder;
use Inlay\Media\Support\TransformerRegistry;
use RuntimeException;
use Throwable;

final class MediaUploader
{
    public function __construct(
        private readonly Filesystems $filesystems,
        private readonly Config $config,
        private readonly MediaUploadValidator $validator,
        private readonly TransformerRegistry $transformers,
        private readonly ?Dispatcher $dispatcher = null,
    ) {}

    /** @param array<string, mixed> $metadata */
    public function upload(
        UploadedFile $file,
        ?MediaFolder $folder = null,
        array $metadata = [],
        ?MediaVisibility $visibility = null,
    ): MediaAssetContract {
        $disk = (string) $this->config->get('media.disk', 'local');
        $maxSize = (int) $this->config->get('media.max_size_kb', 10240);
        $mimeTypes = (array) $this->config->get('media.allowed_mime_types', []);
        $extensions = (array) $this->config->get('media.allowed_extensions', []);
        $visibility ??= MediaVisibility::from((string) $this->config->get('media.visibility', 'private'));
        $this->validator->validate($file, $maxSize, $mimeTypes, $extensions);

        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
        $path = $this->makePath($extension);
        $filesystem = $this->filesystems->disk($disk);
        $stream = fopen($file->getRealPath(), 'rb');

        if ($stream === false) {
            throw new RuntimeException('The uploaded file could not be opened.');
        }

        try {
            if (! $filesystem->writeStream($path, $stream, ['visibility' => $visibility->value])) {
                throw new RuntimeException('The uploaded file could not be stored.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        try {
            $model = $this->assetModel();
            /** @var MediaAssetContract $asset */
            $asset = $model::query()->getConnection()->transaction(function () use ($file, $folder, $metadata, $visibility, $disk, $path, $model): MediaAssetContract {
                return $model::query()->create([
                    'folder_id' => $folder?->getKey(),
                    'disk' => $disk,
                    'path' => $path,
                    'file_name' => $this->displayName($file),
                    'mime_type' => (string) $file->getMimeType(),
                    'extension' => strtolower($file->guessExtension() ?: $file->getClientOriginalExtension()),
                    'size' => (int) $file->getSize(),
                    'visibility' => $visibility->value,
                    'metadata' => $metadata,
                ]);
            });
        } catch (Throwable $exception) {
            $filesystem->delete($path);
            throw $exception;
        }

        if ($this->queueTransformations($asset, $model)) {
            return $asset;
        }

        $this->transformers->run($asset);

        return $asset;
    }

    private function makePath(string $extension): string
    {
        $directory = trim((string) $this->config->get('media.directory', 'media'), '/');

        if ($directory === '' || str_contains($directory, '..') || str_contains($directory, '\\')) {
            throw new RuntimeException('The configured media directory is unsafe.');
        }

        return sprintf('%s/%s/%s.%s', $directory, date('Y/m'), Str::uuid()->toString(), $extension);
    }

    private function displayName(UploadedFile $file): string
    {
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';

        return Str::limit($name !== '' ? $name : 'untitled', 255, '');
    }

    /** @param class-string<MediaAsset> $model */
    private function queueTransformations(MediaAssetContract $asset, string $model): bool
    {
        if (! (bool) $this->config->get('media.transformations.async', false)
            || $this->dispatcher === null
            || $asset->key() === null) {
            return false;
        }

        $this->dispatcher->dispatch(new TransformMediaAsset(
            $model,
            $asset->key(),
            $this->nullableString($this->config->get('media.transformations.connection')),
            $this->nullableString($this->config->get('media.transformations.queue')),
        ));

        return true;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return class-string<MediaAsset> */
    private function assetModel(): string
    {
        /** @var class-string<MediaAsset> */
        return $this->config->get('media.models.asset', MediaAsset::class);
    }
}
