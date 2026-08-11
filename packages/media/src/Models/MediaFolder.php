<?php

declare(strict_types=1);

namespace Inlay\Media\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MediaFolder extends Model
{
    use SoftDeletes;

    protected $table = 'inlay_media_folders';

    protected $guarded = [];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(static::class, 'parent_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany($this->assetModel(), 'folder_id');
    }

    /** @return class-string<Model> */
    protected function assetModel(): string
    {
        return config('media.models.asset', MediaAsset::class);
    }
}
