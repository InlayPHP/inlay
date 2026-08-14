<?php

declare(strict_types=1);

namespace Inlay\MediaManager\Http\Controllers;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Contracts\Validation\Factory as Validator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Inlay\Authorization\AuthorizationManager;
use Inlay\PanelRegistry;
use Inlay\Media\Enums\MediaVisibility;
use Inlay\Media\Models\MediaAsset;
use Inlay\Media\Models\MediaCollection;
use Inlay\Media\Models\MediaFolder;
use Inlay\Media\Services\MediaLibrary;
use Inlay\Media\Services\MediaUploader;
use Inlay\Media\Support\MediaStorageRegistry;
use InvalidArgumentException;
use Inlay\MediaManager\Contracts\BuildsMediaPayloads;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class MediaManagerController
{
    public function __construct(
        private AuthorizationManager $authorization,
        private BuildsMediaPayloads $payloads,
        private MediaUploader $uploader,
        private MediaLibrary $library,
        private Filesystems $filesystems,
        private Validator $validator,
        private Config $config,
        private ?MediaStorageRegistry $storage = null,
    ) {}

    public function index(Request $request, ?PanelRegistry $panels = null): Response|JsonResponse
    {
        $this->authorization->authorize($request->user(), 'media.viewAny');
        $media = $this->payloads->build($request);

        if ($request->expectsJson()) {
            return new JsonResponse(['media' => $media]);
        }

        $props = ['media' => $media];
        $panelRegistry = $panels ?? (app()->bound(PanelRegistry::class) ? app(PanelRegistry::class) : null);
        if ($panelRegistry !== null && ($panel = $panelRegistry->resolveForRequest($request)) !== null) {
            $props['inlayPanel'] = $panel;
        }

        return Inertia::render((string) $this->config->get('media-manager.component', 'inlay-media-manager/index'), $props);
    }

    public function picker(Request $request): JsonResponse
    {
        $this->authorization->authorize($request->user(), 'media.pick');

        return new JsonResponse(['media' => $this->payloads->build($request, true)]);
    }

    public function browseStorage(Request $request): JsonResponse
    {
        $this->authorization->authorize($request->user(), 'media.browseStorage');
        if ($this->storage === null) {
            return new JsonResponse([
                'contract' => 'inlay.media-storage.v1',
                'browser' => null,
                'disk' => null,
                'prefix' => '',
                'objects' => [],
            ]);
        }

        $data = $this->validate($request, [
            'browser' => ['required', 'string', 'max:100'],
            'disk' => ['required', 'string', 'max:100'],
            'prefix' => ['sometimes', 'nullable', 'string', 'max:500'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);
        $browser = (string) $data['browser'];
        $disk = (string) $data['disk'];
        $prefix = is_string($data['prefix'] ?? null) ? $data['prefix'] : '';
        $limit = (int) ($data['limit'] ?? $this->config->get('media-manager.storage_browser_limit', 100));

        try {
            $objects = $this->storage->browse($browser, $disk, $prefix, $limit);
            $definition = $this->storage->browser($browser);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['storage' => $exception->getMessage()]);
        }

        return new JsonResponse([
            'contract' => 'inlay.media-storage.v1',
            'browser' => $browser,
            'disk' => $disk,
            'prefix' => trim(str_replace('\\', '/', $prefix), '/'),
            'objects' => array_map(static fn ($object): array => $object->jsonSerialize(), $objects),
            'disks' => $definition->disks(),
        ]);
    }

    public function upload(Request $request): JsonResponse
    {
        $this->authorization->authorize($request->user(), 'media.upload');
        $data = $this->validate($request, [
            'file' => ['required', 'file'],
            'folder_id' => ['nullable', 'integer'],
            'metadata' => ['sometimes', 'array'],
            'visibility' => ['sometimes', 'in:public,private'],
        ]);
        $folder = isset($data['folder_id']) ? $this->folder((int) $data['folder_id']) : null;
        $asset = $this->uploader->upload(
            $data['file'],
            $folder,
            $data['metadata'] ?? [],
            isset($data['visibility']) ? MediaVisibility::from($data['visibility']) : null,
        );

        return new JsonResponse(['id' => $asset->key()], 201);
    }

    public function updateAsset(Request $request, int|string $asset): JsonResponse
    {
        $model = $this->asset($asset);
        $this->authorization->authorize($request->user(), 'media.update', $model);
        $data = $this->validate($request, [
            'file_name' => ['sometimes', 'string', 'max:255', 'not_regex:/^\s*$/'],
            'metadata' => ['sometimes', 'array'],
            'visibility' => ['sometimes', 'in:public,private'],
        ]);

        if (array_key_exists('file_name', $data)) {
            $model->file_name = trim($data['file_name']);
        }
        if (array_key_exists('metadata', $data)) {
            $model->metadata = $data['metadata'];
        }
        $model->save();
        if (array_key_exists('visibility', $data)) {
            $this->library->setVisibility($model, MediaVisibility::from($data['visibility']));
        }

        return new JsonResponse(['updated' => true]);
    }

    public function moveAsset(Request $request, int|string $asset): JsonResponse
    {
        $model = $this->asset($asset);
        $this->authorization->authorize($request->user(), 'media.update', $model);
        $data = $this->validate($request, ['folder_id' => ['present', 'nullable', 'integer']]);
        $folder = $data['folder_id'] === null ? null : $this->folder((int) $data['folder_id']);
        $this->library->moveAsset($model, $folder);

        return new JsonResponse(['moved' => true]);
    }

    public function syncAssetCollections(Request $request, int|string $asset): JsonResponse
    {
        $model = $this->asset($asset);
        $this->authorization->authorize($request->user(), 'media.update', $model);
        $data = $this->validate($request, [
            'collection_ids' => ['present', 'array', 'max:100'],
            'collection_ids.*' => ['integer'],
        ]);
        $this->library->syncCollections($model, array_map(static fn (mixed $id): int|string => is_int($id) ? $id : (string) $id, $data['collection_ids']));

        return new JsonResponse(['synced' => true]);
    }

    public function trashAsset(Request $request, int|string $asset): JsonResponse
    {
        $model = $this->asset($asset);
        $this->authorization->authorize($request->user(), 'media.delete', $model);
        $this->library->trash($model);

        return new JsonResponse(null, 204);
    }

    public function restoreAsset(Request $request, int|string $asset): JsonResponse
    {
        $model = $this->trashedAsset($asset);
        $this->authorization->authorize($request->user(), 'media.restore', $model);
        $this->library->restore($model);

        return new JsonResponse(['restored' => true]);
    }

    public function destroyAsset(Request $request, int|string $asset): JsonResponse
    {
        $model = $this->assetWithTrashed($asset);
        $this->authorization->authorize($request->user(), 'media.forceDelete', $model);

        if (! $model->trashed()) {
            throw new ConflictHttpException('Only media already in trash can be permanently deleted.');
        }

        $this->library->permanentlyDelete($model);

        return new JsonResponse(null, 204);
    }

    public function deliverAsset(Request $request, int|string $asset): StreamedResponse
    {
        $model = $this->asset($asset);
        $this->authorization->authorize($request->user(), 'media.download', $model);
        $stream = $this->filesystems->disk($model->disk())->readStream($model->path());

        if ($stream === false) {
            throw new NotFoundHttpException('The media object does not exist.');
        }

        $inline = in_array($model->mimeType(), [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/avif',
        ], true);
        $disposition = (new ResponseHeaderBag)->makeDisposition($inline ? 'inline' : 'attachment', $model->file_name);

        return new StreamedResponse(static function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }, 200, [
            'Content-Type' => $model->mimeType(),
            'Content-Length' => (string) $model->size(),
            'Content-Disposition' => $disposition,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function storeFolder(Request $request): JsonResponse
    {
        $this->authorization->authorize($request->user(), 'media.manageFolders');
        $data = $this->validate($request, ['name' => ['required', 'string', 'max:255'], 'parent_id' => ['nullable', 'integer']]);
        $parent = isset($data['parent_id']) ? $this->folder((int) $data['parent_id']) : null;
        $this->assertUniqueFolder($data['name'], $parent);
        $class = $this->folderModel();
        $folder = $class::query()->create(['name' => trim($data['name']), 'parent_id' => $parent?->getKey()]);

        return new JsonResponse(['id' => $folder->getKey()], 201);
    }

    public function moveFolder(Request $request, int|string $folder): JsonResponse
    {
        $model = $this->folder($folder);
        $this->authorization->authorize($request->user(), 'media.manageFolders', $model);
        $data = $this->validate($request, ['parent_id' => ['present', 'nullable', 'integer']]);
        $parent = $data['parent_id'] === null ? null : $this->folder((int) $data['parent_id']);
        $this->assertUniqueFolder($model->name, $parent, $model);
        $this->library->moveFolder($model, $parent);

        return new JsonResponse(['moved' => true]);
    }

    public function destroyFolder(Request $request, int|string $folder): JsonResponse
    {
        $model = $this->folder($folder);
        $this->authorization->authorize($request->user(), 'media.manageFolders', $model);

        if ($model->children()->withTrashed()->exists() || $model->assets()->withTrashed()->exists()) {
            throw new ConflictHttpException('A folder must be empty before it can be deleted.');
        }

        $model->delete();

        return new JsonResponse(null, 204);
    }

    public function storeCollection(Request $request): JsonResponse
    {
        $this->authorization->authorize($request->user(), 'media.manageCollections');
        $data = $this->validate($request, [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        $collection = $this->library->createCollection($data['name'], $data['description'] ?? null);

        return new JsonResponse(['id' => $collection->getKey()], 201);
    }

    public function updateCollection(Request $request, int|string $collection): JsonResponse
    {
        $model = $this->collection($collection);
        $this->authorization->authorize($request->user(), 'media.manageCollections', $model);
        $data = $this->validate($request, [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        $this->library->updateCollection($model, $data['name'], $data['description'] ?? null);

        return new JsonResponse(['updated' => true]);
    }

    public function destroyCollection(Request $request, int|string $collection): JsonResponse
    {
        $model = $this->collection($collection);
        $this->authorization->authorize($request->user(), 'media.manageCollections', $model);
        $this->library->deleteCollection($model);

        return new JsonResponse(null, 204);
    }

    /** @param array<string, mixed> $rules @return array<string, mixed> */
    private function validate(Request $request, array $rules): array
    {
        return $this->validator->make($request->all(), $rules)->validate();
    }

    private function asset(int|string $id): MediaAsset
    {
        return $this->assetModel()::query()->findOrFail($id);
    }

    private function assetWithTrashed(int|string $id): MediaAsset
    {
        return $this->assetModel()::query()->withTrashed()->findOrFail($id);
    }

    private function trashedAsset(int|string $id): MediaAsset
    {
        return $this->assetModel()::query()->onlyTrashed()->findOrFail($id);
    }

    private function folder(int|string $id): MediaFolder
    {
        return $this->folderModel()::query()->findOrFail($id);
    }

    private function collection(int|string $id): MediaCollection
    {
        return $this->collectionModel()::query()->findOrFail($id);
    }

    private function assertUniqueFolder(string $name, ?MediaFolder $parent, ?MediaFolder $except = null): void
    {
        $query = $this->folderModel()::query()->where('name', trim($name));
        $parent === null ? $query->whereNull('parent_id') : $query->where('parent_id', $parent->getKey());
        if ($except !== null) {
            $query->whereKeyNot($except->getKey());
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['name' => 'A folder with this name already exists here.']);
        }
    }

    /** @return class-string<MediaAsset> */
    private function assetModel(): string
    {
        /** @var class-string<MediaAsset> */
        return $this->config->get('media.models.asset', MediaAsset::class);
    }

    /** @return class-string<MediaFolder> */
    private function folderModel(): string
    {
        /** @var class-string<MediaFolder> */
        return $this->config->get('media.models.folder', MediaFolder::class);
    }

    /** @return class-string<MediaCollection> */
    private function collectionModel(): string
    {
        /** @var class-string<MediaCollection> */
        return $this->config->get('media.models.collection', MediaCollection::class);
    }
}
