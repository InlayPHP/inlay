<?php

declare(strict_types=1);

namespace Inlay\Media\Services;

use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Inlay\Media\Enums\MediaVisibility;
use Inlay\Media\Models\MediaAsset;
use Inlay\Media\Models\MediaCollection;
use Inlay\Media\Models\MediaFolder;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class MediaLibrary
{
    public function __construct(private readonly Filesystems $filesystems) {}

    public function moveAsset(MediaAsset $asset, ?MediaFolder $folder): MediaAsset
    {
        $asset->folder()->associate($folder);
        $asset->save();

        return $asset;
    }

    public function createCollection(string $name, ?string $description = null): MediaCollection
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('A media collection name is required.');
        }

        if ($this->collectionModel()::query()->where('name', $name)->exists()) {
            throw new InvalidArgumentException("A media collection named [{$name}] already exists.");
        }

        return $this->collectionModel()::query()->create([
            'name' => $name,
            'description' => $description === null ? null : trim($description),
        ]);
    }

    public function updateCollection(MediaCollection $collection, string $name, ?string $description = null): MediaCollection
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('A media collection name is required.');
        }

        if ($this->collectionModel()::query()
            ->where('name', $name)
            ->whereKeyNot($collection->getKey())
            ->exists()) {
            throw new InvalidArgumentException("A media collection named [{$name}] already exists.");
        }

        $collection->forceFill([
            'name' => $name,
            'description' => $description === null ? null : trim($description),
        ])->save();

        return $collection;
    }

    public function deleteCollection(MediaCollection $collection): bool
    {
        $collection->assets()->detach();

        return (bool) $collection->delete();
    }

    /** @param list<int|string> $collectionIds */
    public function syncCollections(MediaAsset $asset, array $collectionIds): MediaAsset
    {
        $ids = array_values(array_unique(array_map(static fn (int|string $id): string => (string) $id, $collectionIds)));
        $known = $this->collectionModel()::query()->whereKey($ids)->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();

        if (count($known) !== count($ids)) {
            throw new InvalidArgumentException('One or more media collections are unavailable.');
        }

        $asset->collections()->sync($known);

        return $asset->load('collections');
    }

    public function moveFolder(MediaFolder $folder, ?MediaFolder $parent): MediaFolder
    {
        if ($parent?->is($folder) || ($parent !== null && $this->isDescendant($parent, $folder))) {
            throw new InvalidArgumentException('A folder cannot be moved into itself or one of its descendants.');
        }

        $folder->parent()->associate($parent);
        $folder->save();

        return $folder;
    }

    public function trash(MediaAsset $asset): bool
    {
        return (bool) $asset->delete();
    }

    public function restore(MediaAsset $asset): bool
    {
        return (bool) $asset->restore();
    }

    public function permanentlyDelete(MediaAsset $asset): bool
    {
        if (! $this->filesystems->disk($asset->disk())->delete($asset->path())) {
            throw new RuntimeException('The physical media object could not be deleted.');
        }

        return (bool) $asset->forceDelete();
    }

    public function setVisibility(MediaAsset $asset, MediaVisibility $visibility): MediaAsset
    {
        $disk = $this->filesystems->disk($asset->disk());
        $original = $asset->visibility();
        $disk->setVisibility($asset->path(), $visibility->value);

        try {
            $asset->setAttribute('visibility', $visibility->value)->save();
        } catch (Throwable $exception) {
            $disk->setVisibility($asset->path(), $original->value);
            throw $exception;
        }

        return $asset;
    }

    private function isDescendant(MediaFolder $candidate, MediaFolder $ancestor): bool
    {
        $current = $candidate;

        while ($current->parent_id !== null) {
            if ((string) $current->parent_id === (string) $ancestor->getKey()) {
                return true;
            }

            $current = $current->parent()->first();

            if (! $current instanceof MediaFolder) {
                break;
            }
        }

        return false;
    }

    /** @return class-string<MediaCollection> */
    private function collectionModel(): string
    {
        /** @var class-string<MediaCollection> */
        return config('media.models.collection', MediaCollection::class);
    }
}
