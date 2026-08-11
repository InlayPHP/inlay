<?php

declare(strict_types=1);

namespace Inlay\Media\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Inlay\Media\Contracts\MediaAssetContract;
use Inlay\Media\Enums\MediaVisibility;

class MediaAsset extends Model implements MediaAssetContract
{
    use SoftDeletes;

    protected $table = 'inlay_media_assets';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'size' => 'integer',
            'visibility' => MediaVisibility::class,
        ];
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo($this->folderModel(), 'folder_id');
    }

    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(
            $this->collectionModel(),
            'inlay_media_collection_asset',
            'asset_id',
            'collection_id',
        )->withTimestamps();
    }

    public function key(): int|string|null
    {
        return $this->getKey();
    }

    public function disk(): string
    {
        return (string) $this->getAttribute('disk');
    }

    public function path(): string
    {
        return (string) $this->getAttribute('path');
    }

    public function mimeType(): string
    {
        return (string) $this->getAttribute('mime_type');
    }

    public function size(): int
    {
        return (int) $this->getAttribute('size');
    }

    public function visibility(): MediaVisibility
    {
        $visibility = $this->getAttribute('visibility');

        return $visibility instanceof MediaVisibility
            ? $visibility
            : MediaVisibility::from((string) $visibility);
    }

    public function metadata(): array
    {
        return (array) ($this->getAttribute('metadata') ?? []);
    }

    /** @return class-string<Model> */
    protected function folderModel(): string
    {
        return config('media.models.folder', MediaFolder::class);
    }

    /** @return class-string<Model> */
    protected function collectionModel(): string
    {
        return config('media.models.collection', MediaCollection::class);
    }
}
