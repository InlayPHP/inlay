<?php

declare(strict_types=1);

namespace Inlay\MediaManager\Support;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inlay\Media\Models\MediaAsset;
use Inlay\Media\Models\MediaCollection;
use Inlay\Media\Models\MediaFolder;
use Inlay\Media\Support\MediaReferenceRegistry;
use Inlay\Media\Support\MediaStorageRegistry;
use Inlay\MediaManager\Contracts\BuildsMediaPayloads;

final readonly class MediaPayloadBuilder implements BuildsMediaPayloads
{
    public function __construct(
        private Config $config,
        private UrlGenerator $urls,
        private ?MediaReferenceRegistry $references = null,
        private ?MediaStorageRegistry $storage = null,
    ) {}

    public function build(Request $request, bool $picker = false): array
    {
        $model = $this->assetModel();
        $query = $model::query()->with(['folder', 'collections']);
        $this->applyFilters($query, $request);
        $max = max(1, (int) $this->config->get('media-manager.max_per_page', 100));
        $perPage = min($max, max(1, $request->integer('per_page', (int) $this->config->get('media-manager.per_page', 24))));
        $paginator = $query->latest('id')->paginate($perPage)->withQueryString();
        $panel = (string) $request->route('inlayPanel');
        $routeName = "inlay.{$panel}.media.assets.delivery";
        $minutes = max(1, (int) $this->config->get('media-manager.signed_url_minutes', 10));

        $currentFolderId = $request->filled('folder_id') && $request->input('folder_id') !== 'root'
            ? $request->integer('folder_id')
            : null;
        $currentCollectionId = $request->filled('collection_id') ? $request->integer('collection_id') : null;

        return [
            'contract' => 'inlay.media-manager.v1',
            'assets' => [
                'data' => array_map(fn (MediaAsset $asset): array => $this->asset($asset, $routeName, $minutes), $paginator->items()),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
                'links' => [
                    'first' => $paginator->url(1),
                    'last' => $paginator->url($paginator->lastPage()),
                    'previous' => $paginator->previousPageUrl(),
                    'next' => $paginator->nextPageUrl(),
                ],
            ],
            'folders' => $this->folderTree(),
            'collections' => $this->collections(),
            'breadcrumbs' => $this->breadcrumbs($currentFolderId),
            'currentFolderId' => $currentFolderId,
            'currentCollectionId' => $currentCollectionId,
            'view' => in_array($request->input('view'), ['grid', 'list'], true) ? $request->input('view') : 'grid',
            'endpoints' => $this->endpoints($panel),
            'filters' => [
                'search' => $request->string('search')->toString(),
                'folder_id' => $request->input('folder_id'),
                'mime' => $request->input('mime'),
                'visibility' => $request->input('visibility'),
                'trash' => $request->boolean('trash'),
                'collection_id' => $request->input('collection_id'),
            ],
            'picker' => $picker,
            'storage' => [
                'browsers' => $this->storage === null
                    ? []
                    : array_map(
                        static fn ($browser, string $name): array => ['name' => $name, 'disks' => $browser->disks()],
                        $this->storage->all(),
                        array_keys($this->storage->all()),
                    ),
            ],
        ];
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        if (($search = trim($request->string('search')->toString())) !== '') {
            $escaped = addcslashes($search, '%_\\');
            $query->where('file_name', 'like', "%{$escaped}%");
        }

        if ($request->filled('folder_id')) {
            $request->input('folder_id') === 'root'
                ? $query->whereNull('folder_id')
                : $query->where('folder_id', $request->integer('folder_id'));
        }

        if ($request->filled('collection_id')) {
            $query->whereHas('collections', fn (Builder $collections): Builder => $collections->whereKey($request->integer('collection_id')));
        }

        if (($mime = trim((string) $request->input('mime', ''))) !== '' && preg_match('#^[a-z0-9.+-]+/(?:\*|[a-z0-9.+-]+)$#i', $mime) === 1) {
            str_ends_with($mime, '/*')
                ? $query->where('mime_type', 'like', substr($mime, 0, -1).'%')
                : $query->where('mime_type', $mime);
        }

        if (in_array($request->input('visibility'), ['public', 'private'], true)) {
            $query->where('visibility', $request->input('visibility'));
        }

        $request->boolean('trash') ? $query->onlyTrashed() : $query->withoutTrashed();
    }

    /** @return array<string, mixed> */
    private function asset(MediaAsset $asset, string $routeName, int $minutes): array
    {
        return [
            'id' => $asset->getKey(),
            'folder_id' => $asset->folder_id,
            'file_name' => $asset->file_name,
            'mime_type' => $asset->mimeType(),
            'extension' => $asset->extension,
            'size' => $asset->size(),
            'visibility' => $asset->visibility()->value,
            'metadata' => (object) $asset->metadata(),
            'references' => $this->references === null || ! (bool) $this->config->get('media-manager.include_references', true)
                ? []
                : array_map(
                    static fn (object $reference): array => $reference->jsonSerialize(),
                    $this->references->resolve($asset, (int) $this->config->get('media-manager.max_references_per_asset', 50)),
                ),
            'collections' => $asset->relationLoaded('collections')
                ? $asset->collections->map(static fn (MediaCollection $collection): array => ['id' => $collection->getKey(), 'name' => (string) $collection->name, 'description' => $collection->description])->values()->all()
                : [],
            'trashed' => $asset->trashed(),
            'created_at' => $asset->created_at?->toAtomString(),
            'updated_at' => $asset->updated_at?->toAtomString(),
            'delivery_url' => $asset->trashed()
                ? null
                : $this->urls->temporarySignedRoute($routeName, now()->addMinutes($minutes), ['asset' => $asset->getKey()]),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function folderTree(): array
    {
        $model = $this->folderModel();
        $folders = $model::query()->withCount(['assets', 'children'])->orderBy('name')->get();

        return $this->branch($folders->groupBy('parent_id'), null);
    }

    /** @return list<array{id: int|string, name: string, assets_count: int}> */
    private function collections(): array
    {
        $model = $this->collectionModel();

        return $model::query()
            ->withCount('assets')
            ->orderBy('name')
            ->get()
            ->map(static fn (MediaCollection $collection): array => [
                'id' => $collection->getKey(),
                'name' => (string) $collection->name,
                'description' => $collection->description,
                'assets_count' => (int) $collection->assets_count,
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function branch($grouped, int|string|null $parent): array
    {
        return $grouped->get($parent, collect())->map(fn (MediaFolder $folder): array => [
            'id' => $folder->getKey(),
            'parent_id' => $folder->parent_id,
            'name' => $folder->name,
            'assets_count' => $folder->assets_count,
            'children_count' => $folder->children_count,
            'children' => $this->branch($grouped, $folder->getKey()),
        ])->values()->all();
    }

    /** @return list<array{id: int|string|null, name: string}> */
    private function breadcrumbs(?int $folderId): array
    {
        $crumbs = [['id' => null, 'name' => 'Media']];

        if ($folderId === null) {
            return $crumbs;
        }

        $model = $this->folderModel();
        $folder = $model::query()->find($folderId);
        $trail = [];

        while ($folder instanceof MediaFolder) {
            array_unshift($trail, ['id' => $folder->getKey(), 'name' => (string) $folder->name]);
            $folder = $folder->parent()->first();
        }

        return [...$crumbs, ...$trail];
    }

    /** @return array<string, string> */
    private function endpoints(string $panel): array
    {
        $name = "inlay.{$panel}.media.";

        return [
            'index' => $this->urls->route($name.'index'),
            'picker' => $this->urls->route($name.'picker'),
            'upload' => $this->urls->route($name.'upload'),
            'createFolder' => $this->urls->route($name.'folders.store'),
            'updateAsset' => $this->urls->route($name.'assets.update', ['asset' => '__ASSET__']),
            'moveAsset' => $this->urls->route($name.'assets.move', ['asset' => '__ASSET__']),
            'syncAssetCollections' => $this->urls->route($name.'assets.collections.sync', ['asset' => '__ASSET__']),
            'trashAsset' => $this->urls->route($name.'assets.trash', ['asset' => '__ASSET__']),
            'restoreAsset' => $this->urls->route($name.'assets.restore', ['asset' => '__ASSET__']),
            'deleteAsset' => $this->urls->route($name.'assets.destroy', ['asset' => '__ASSET__']),
            'moveFolder' => $this->urls->route($name.'folders.move', ['folder' => '__FOLDER__']),
            'deleteFolder' => $this->urls->route($name.'folders.destroy', ['folder' => '__FOLDER__']),
            'createCollection' => $this->urls->route($name.'collections.store'),
            'updateCollection' => $this->urls->route($name.'collections.update', ['collection' => '__COLLECTION__']),
            'deleteCollection' => $this->urls->route($name.'collections.destroy', ['collection' => '__COLLECTION__']),
            'storageBrowse' => $this->urls->route($name.'storage.browse'),
        ];
    }

    /** @return class-string<MediaAsset> */
    private function assetModel(): string
    {
        /** @var class-string<MediaAsset> */
        return $this->config->get('media.models.asset', MediaAsset::class);
    }

    /** @return class-string<MediaCollection> */
    private function collectionModel(): string
    {
        /** @var class-string<MediaCollection> */
        return $this->config->get('media.models.collection', MediaCollection::class);
    }

    /** @return class-string<MediaFolder> */
    private function folderModel(): string
    {
        /** @var class-string<MediaFolder> */
        return $this->config->get('media.models.folder', MediaFolder::class);
    }
}
