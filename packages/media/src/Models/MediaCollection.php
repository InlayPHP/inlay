<?php

declare(strict_types=1);

namespace Inlay\Media\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A named, non-hierarchical album. Collections deliberately complement
 * folders: one asset may belong to many collections while keeping one
 * canonical storage location.
 */
class MediaCollection extends Model
{
    use SoftDeletes;

    protected $table = 'inlay_media_collections';

    protected $guarded = [];

    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(
            $this->assetModel(),
            'inlay_media_collection_asset',
            'collection_id',
            'asset_id',
        )->withTimestamps();
    }

    /** @return class-string<Model> */
    protected function assetModel(): string
    {
        return config('media.models.asset', MediaAsset::class);
    }
}
