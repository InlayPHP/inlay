<?php

declare(strict_types=1);

namespace Inlay\Media\Support;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Inlay\Media\Contracts\MediaStorageBrowser;
use Inlay\Media\Contracts\MediaStorageObject;
use InvalidArgumentException;

final readonly class FilesystemStorageBrowser implements MediaStorageBrowser
{
    public function __construct(
        private Filesystems $filesystems,
        private Config $config,
    ) {}

    public function disks(): array
    {
        $configured = $this->config->get('media-manager.storage_disks', []);
        if (is_array($configured) && $configured !== []) {
            return array_reduce($configured, static function (array $disks, mixed $value): array {
                if (is_string($value) && trim($value) !== '') {
                    $disks[$value] = $value;
                } elseif (is_array($value) && is_string($value['name'] ?? null) && trim($value['name']) !== '') {
                    $disks[$value['name']] = is_string($value['label'] ?? null) && trim($value['label']) !== '' ? $value['label'] : $value['name'];
                }

                return $disks;
            }, []);
        }

        $disk = (string) $this->config->get('media.disk', 'local');

        return [$disk => $disk];
    }

    public function browse(string $disk, string $prefix, int $limit): iterable
    {
        if (! array_key_exists($disk, $this->disks())) {
            throw new InvalidArgumentException('The requested storage disk is not available to the media browser.');
        }

        $prefix = $this->safePrefix($prefix);
        $limit = max(1, min($limit, 500));
        $filesystem = $this->filesystems->disk($disk);
        $objects = [];

        foreach ($filesystem->directories($prefix) as $path) {
            $objects[] = new MediaStorageObject($disk, trim((string) $path, '/'), basename((string) $path), true);
            if (count($objects) >= $limit) {
                return $objects;
            }
        }

        foreach ($filesystem->files($prefix) as $path) {
            $path = trim((string) $path, '/');
            $objects[] = new MediaStorageObject(
                $disk,
                $path,
                basename($path),
                false,
                $this->mimeType($filesystem, $path),
                $this->integerStat(static fn (): mixed => $filesystem->size($path)),
                $this->integerStat(static fn (): mixed => $filesystem->lastModified($path)),
            );
            if (count($objects) >= $limit) {
                return $objects;
            }
        }

        return $objects;
    }

    private function safePrefix(string $prefix): string
    {
        $prefix = trim(str_replace('\\', '/', $prefix), '/');
        if (str_contains($prefix, '..') || str_contains($prefix, "\0")) {
            throw new InvalidArgumentException('The storage browser prefix is unsafe.');
        }

        return $prefix;
    }

    private function mimeType(object $filesystem, string $path): ?string
    {
        try {
            $mime = $filesystem->mimeType($path);

            return is_string($mime) && $mime !== '' ? $mime : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param callable(): mixed $value */
    private function integerStat(callable $value): ?int
    {
        try {
            $result = $value();

            return is_int($result) || is_float($result) || (is_string($result) && is_numeric($result)) ? (int) $result : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
