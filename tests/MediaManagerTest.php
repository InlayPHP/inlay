<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    foreach ([
        'Inlay\\MediaManager\\' => __DIR__.'/../packages/media-manager/src/',
        'Inlay\\Media\\' => __DIR__.'/../packages/media/src/',
    ] as $prefix => $root) {
        if (str_starts_with($class, $prefix)) {
            $path = $root.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            if (is_file($path)) {
                require_once $path;
            }
        }
    }
});

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Gate;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidationFactory;
use Inlay\Authorization\AbilityRegistry;
use Inlay\Authorization\AuthorizationManager;
use Inlay\Media\Models\MediaAsset;
use Inlay\Media\Models\MediaCollection;
use Inlay\Media\Models\MediaFolder;
use Inlay\Media\Contracts\MediaAssetContract;
use Inlay\Media\Contracts\MediaReference;
use Inlay\Media\Contracts\MediaReferenceResolver;
use Inlay\Media\Services\MediaLibrary;
use Inlay\Media\Services\MediaUploader;
use Inlay\Media\Services\MediaUploadValidator;
use Inlay\Media\Support\TransformerRegistry;
use Inlay\Media\Support\MediaReferenceRegistry;
use Inlay\Media\Support\MediaStorageRegistry;
use Inlay\Media\Support\FilesystemStorageBrowser;
use Inlay\MediaManager\Contracts\BuildsMediaPayloads;
use Inlay\MediaManager\Http\Controllers\MediaManagerController;
use Inlay\MediaManager\MediaManagerPlugin;
use Inlay\MediaManager\Support\MediaPayloadBuilder;
use Inlay\Panel;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

beforeEach(function (): void {
    $this->mediaRoot = sys_get_temp_dir().'/inlay-media-manager-test-'.bin2hex(random_bytes(6));
    $this->container = new Container;
    Container::setInstance($this->container);
    $this->config = new Repository([
        'media' => [
            'disk' => 'media-test',
            'directory' => 'library',
            'visibility' => 'private',
            'max_size_kb' => 1024,
            'allowed_mime_types' => ['text/plain'],
            'allowed_extensions' => ['txt'],
            'models' => ['asset' => MediaAsset::class, 'collection' => MediaCollection::class, 'folder' => MediaFolder::class],
        ],
        'media-manager' => ['component' => 'inlay-media-manager/index', 'per_page' => 2, 'max_per_page' => 3, 'signed_url_minutes' => 10],
    ]);
    $this->container->instance('config', $this->config);

    $capsule = new Capsule($this->container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $schema = $capsule->getConnection()->getSchemaBuilder();
    $schema->create('inlay_media_folders', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('parent_id')->nullable();
        $table->string('name');
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('inlay_media_assets', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('folder_id')->nullable();
        $table->string('disk');
        $table->string('path');
        $table->string('file_name');
        $table->string('mime_type');
        $table->string('extension');
        $table->unsignedBigInteger('size');
        $table->string('visibility');
        $table->json('metadata')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('inlay_media_collections', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->text('description')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('inlay_media_collection_asset', function (Blueprint $table): void {
        $table->foreignId('collection_id');
        $table->foreignId('asset_id');
        $table->timestamps();
        $table->primary(['collection_id', 'asset_id']);
        $table->index('asset_id');
    });

    $adapter = new LocalFilesystemAdapter($this->mediaRoot);
    $filesystem = new FilesystemAdapter(new Flysystem($adapter), $adapter, ['root' => $this->mediaRoot]);
    $this->filesystems = new class($filesystem) implements FilesystemFactory
    {
        public function __construct(private readonly FilesystemAdapter $filesystem) {}

        public function disk($name = null): FilesystemAdapter
        {
            return $this->filesystem;
        }
    };

    $routes = new RouteCollection;
    foreach ([
        'index' => 'media',
        'picker' => 'media/picker',
        'storage.browse' => 'media/storage',
        'upload' => 'media/assets',
        'folders.store' => 'media/folders',
        'assets.update' => 'media/assets/{asset}',
        'assets.move' => 'media/assets/{asset}/move',
        'assets.trash' => 'media/assets/{asset}',
        'assets.restore' => 'media/assets/{asset}/restore',
        'assets.destroy' => 'media/assets/{asset}/force',
        'folders.move' => 'media/folders/{folder}/move',
        'folders.destroy' => 'media/folders/{folder}',
        'assets.delivery' => 'media/assets/{asset}/delivery',
        'assets.collections.sync' => 'media/assets/{asset}/collections',
        'collections.store' => 'media/collections',
        'collections.update' => 'media/collections/{collection}',
        'collections.destroy' => 'media/collections/{collection}',
    ] as $name => $uri) {
        $route = new Route('GET', $uri, static fn (): null => null);
        $route->name('inlay.admin.media.'.$name);
        $routes->add($route);
    }
    $baseRequest = Request::create('https://example.test/admin/media');
    $this->urls = new UrlGenerator($routes, $baseRequest);
    $this->urls->setKeyResolver(static fn (): string => 'test-signing-key');
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->mediaRoot);
});

it('contributes protected media routes, navigation, and owned abilities to a panel', function (): void {
    $panel = Panel::make('admin')->resourceMutationMiddleware(['throttle:media'])->plugin(new MediaManagerPlugin);
    $abilities = $panel->getAbilities();

    expect($panel->getRoutes())->toHaveCount(17)
        ->and(collect($panel->getRoutes())->every->requiresAuthentication())->toBeTrue()
        ->and(collect($panel->getRoutes())->firstWhere(fn ($route): bool => $route->name() === 'media.assets.delivery')->middlewareList())->toBe(['signed'])
        ->and(collect($panel->getRoutes())->firstWhere(fn ($route): bool => $route->name() === 'media.upload')->middlewareList())->toBe(['throttle:media'])
        ->and(array_keys($abilities))->toContain('media.viewAny', 'media.upload', 'media.forceDelete', 'media.manageFolders', 'media.manageCollections')
        ->and($abilities['media.forceDelete']['owner'])->toBe('inlay.media-manager')
        ->and(collect($panel->jsonSerialize()['navigationItems'])->contains(fn (array $item): bool => $item['name'] === 'media-manager'))->toBeTrue();
});

it('builds the versioned browser contract with filters, tree, breadcrumbs, and signed delivery URLs', function (): void {
    $root = MediaFolder::query()->create(['name' => 'Root']);
    $child = MediaFolder::query()->create(['name' => 'Child', 'parent_id' => $root->getKey()]);
    MediaAsset::query()->create([
        'folder_id' => $child->getKey(), 'disk' => 'media-test', 'path' => 'private/report.txt',
        'file_name' => 'report.txt', 'mime_type' => 'text/plain', 'extension' => 'txt', 'size' => 12,
        'visibility' => 'private', 'metadata' => ['alt' => 'Report'],
    ]);
    MediaAsset::query()->create([
        'folder_id' => null, 'disk' => 'media-test', 'path' => 'private/photo.txt',
        'file_name' => 'photo.txt', 'mime_type' => 'text/plain', 'extension' => 'txt', 'size' => 10,
        'visibility' => 'public', 'metadata' => [],
    ]);
    $trashed = MediaAsset::query()->create([
        'folder_id' => null, 'disk' => 'media-test', 'path' => 'private/old.txt',
        'file_name' => 'old.txt', 'mime_type' => 'text/plain', 'extension' => 'txt', 'size' => 5,
        'visibility' => 'private', 'metadata' => [],
    ]);
    $trashed->delete();
    $request = Request::create('https://example.test/admin/media', 'GET', [
        'folder_id' => $child->getKey(), 'search' => 'report', 'mime' => 'text/*', 'visibility' => 'private', 'view' => 'list', 'per_page' => 99,
    ]);
    $panelRoute = new Route('GET', 'admin/media', static fn (): null => null);
    $panelRoute->bind($request);
    $panelRoute->setParameter('inlayPanel', 'admin');
    $request->setRouteResolver(static fn (): Route => $panelRoute);

    $payload = (new MediaPayloadBuilder($this->config, $this->urls))->build($request);

    expect($payload['contract'])->toBe('inlay.media-manager.v1')
        ->and($payload['assets']['data'])->toHaveCount(1)
        ->and($payload['assets']['data'][0])->not->toHaveKeys(['disk', 'path'])
        ->and($payload['assets']['data'][0]['delivery_url'])->toContain('signature=')
        ->and($payload['assets']['meta']['per_page'])->toBe(3)
        ->and($payload['currentFolderId'])->toBe($child->getKey())
        ->and(array_column($payload['breadcrumbs'], 'name'))->toBe(['Media', 'Root', 'Child'])
        ->and($payload['view'])->toBe('list')
        ->and($payload['folders'][0]['children'][0]['name'])->toBe('Child')
        ->and($payload['storage']['browsers'])->toBe([])
        ->and($payload['endpoints'])->toHaveKeys([
            'index', 'picker', 'upload', 'createFolder', 'updateAsset', 'moveAsset', 'trashAsset',
            'restoreAsset', 'deleteAsset', 'moveFolder', 'deleteFolder', 'syncAssetCollections',
            'createCollection', 'updateCollection', 'deleteCollection', 'storageBrowse',
        ]);
});

it('browses an explicitly configured filesystem disk with a bounded storage contract', function (): void {
    $this->filesystems->disk('media-test')->makeDirectory('imports/nested');
    $this->filesystems->disk('media-test')->put('imports/a.txt', 'a');
    $this->filesystems->disk('media-test')->put('imports/nested/b.txt', 'b');
    $storage = (new MediaStorageRegistry)->register('filesystem', new FilesystemStorageBrowser($this->filesystems, $this->config));
    $controller = mediaManagerController($this, ['media.browseStorage' => true], $storage);
    $request = Request::create('/admin/media/storage', 'GET', ['browser' => 'filesystem', 'disk' => 'media-test', 'prefix' => 'imports', 'limit' => 10]);
    $request->setUserResolver(static fn (): object => (object) ['id' => 1]);
    $response = $controller->browseStorage($request);
    $payload = $response->getData(true);

    expect($payload['contract'])->toBe('inlay.media-storage.v1')
        ->and($payload['browser'])->toBe('filesystem')
        ->and($payload['disk'])->toBe('media-test')
        ->and($payload['objects'])->toHaveCount(2)
        ->and($payload['objects'][0]['directory'])->toBeTrue()
        ->and($payload['objects'][0]['name'])->toBe('nested')
        ->and($payload['objects'][1]['name'])->toBe('a.txt');
});

it('includes collections in the browser contract and filters by collection', function (): void {
    $collection = MediaCollection::query()->create(['name' => 'Homepage']);
    $asset = mediaManagerAsset('hero.txt');
    $asset->collections()->attach($collection);
    mediaManagerAsset('other.txt');
    $request = Request::create('https://example.test/admin/media', 'GET', ['collection_id' => $collection->getKey()]);
    $route = new Route('GET', 'admin/media', static fn (): null => null);
    $route->bind($request);
    $route->setParameter('inlayPanel', 'admin');
    $request->setRouteResolver(static fn (): Route => $route);

    $payload = (new MediaPayloadBuilder($this->config, $this->urls))->build($request);

    expect($payload['currentCollectionId'])->toBe($collection->getKey())
        ->and($payload['collections'][0]['name'])->toBe('Homepage')
        ->and($payload['assets']['data'])->toHaveCount(1)
        ->and($payload['assets']['data'][0]['collections'][0]['name'])->toBe('Homepage');
});

it('includes bounded, deduplicated usage references from registered resolvers', function (): void {
    $asset = mediaManagerAsset('hero.txt');
    $references = new MediaReferenceRegistry;
    $references->register('test', new class implements MediaReferenceResolver
    {
        public function resolve(MediaAssetContract $asset): iterable
        {
            yield new MediaReference('resource', 'Homepage hero', '/admin/resources/pages/1');
            yield new MediaReference('resource', 'Homepage hero', '/admin/resources/pages/1');
        }
    });
    $request = Request::create('https://example.test/admin/media', 'GET');
    $route = new Route('GET', 'admin/media', static fn (): null => null);
    $route->bind($request);
    $route->setParameter('inlayPanel', 'admin');
    $request->setRouteResolver(static fn (): Route => $route);

    $payload = (new MediaPayloadBuilder($this->config, $this->urls, $references))->build($request);
    $row = collect($payload['assets']['data'])->firstWhere('id', $asset->getKey());

    expect($row['references'])->toHaveCount(1)
        ->and($row['references'][0])->toMatchArray([
            'type' => 'resource',
            'label' => 'Homepage hero',
            'url' => '/admin/resources/pages/1',
        ]);
});

it('lists only trashed assets when the trash filter is active', function (): void {
    $live = mediaManagerAsset('live.txt');
    $trashed = mediaManagerAsset('trashed.txt');
    $trashed->delete();
    $request = Request::create('https://example.test/admin/media', 'GET', ['trash' => '1']);
    $route = new Route('GET', 'admin/media', static fn (): null => null);
    $route->bind($request);
    $route->setParameter('inlayPanel', 'admin');
    $request->setRouteResolver(static fn (): Route => $route);

    $payload = (new MediaPayloadBuilder($this->config, $this->urls))->build($request);

    expect(array_column($payload['assets']['data'], 'id'))->toBe([$trashed->getKey()])
        ->and($payload['assets']['data'][0]['trashed'])->toBeTrue();
});

it('refuses force deletion of a live asset after an authoritative Gate decision', function (): void {
    $asset = mediaManagerAsset('live.txt');
    $controller = mediaManagerController($this, ['media.forceDelete' => true]);
    $request = authenticatedMediaRequest();

    $controller->destroyAsset($request, $asset->getKey());
})->throws(ConflictHttpException::class, 'already in trash');

it('authorizes private delivery before attempting to open storage', function (): void {
    $asset = mediaManagerAsset('missing.txt');
    $controller = mediaManagerController($this, ['media.download' => false]);

    $controller->deliverAsset(authenticatedMediaRequest(), $asset->getKey());
})->throws(AuthorizationException::class);

it('streams authorized private files with hardened download headers', function (): void {
    $asset = mediaManagerAsset('download.txt');
    $this->filesystems->disk('media-test')->put($asset->path(), 'download body');
    $asset->size = 13;
    $asset->save();
    $controller = mediaManagerController($this, ['media.download' => true]);

    $response = $controller->deliverAsset(authenticatedMediaRequest(), $asset->getKey());
    ob_start();
    $response->sendContent();
    $content = ob_get_clean();

    expect($content)->toBe('download body')
        ->and($response->headers->get('content-disposition'))->toContain('attachment', 'download.txt')
        ->and($response->headers->get('x-content-type-options'))->toBe('nosniff')
        ->and($response->headers->get('cache-control'))->toContain('no-store', 'private');
});

it('serves safe raster previews inline while keeping active formats as downloads', function (): void {
    $asset = mediaManagerAsset('preview.png');
    $asset->forceFill(['mime_type' => 'image/png', 'extension' => 'png'])->save();
    $this->filesystems->disk('media-test')->put($asset->path(), 'image bytes');
    $controller = mediaManagerController($this, ['media.download' => true]);

    $response = $controller->deliverAsset(authenticatedMediaRequest(), $asset->getKey());

    expect($response->headers->get('content-disposition'))->toContain('inline', 'preview.png');
});

it('refuses to delete folders that would orphan live or trashed content', function (): void {
    $folder = MediaFolder::query()->create(['name' => 'Used']);
    $asset = mediaManagerAsset('trashed.txt', $folder);
    $asset->delete();
    $controller = mediaManagerController($this, ['media.manageFolders' => true]);

    $controller->destroyFolder(authenticatedMediaRequest(), $folder->getKey());
})->throws(ConflictHttpException::class, 'must be empty');

function mediaManagerAsset(string $name, ?MediaFolder $folder = null): MediaAsset
{
    return MediaAsset::query()->create([
        'folder_id' => $folder?->getKey(), 'disk' => 'media-test', 'path' => 'private/'.$name,
        'file_name' => $name, 'mime_type' => 'text/plain', 'extension' => 'txt', 'size' => 5,
        'visibility' => 'private', 'metadata' => [],
    ]);
}

function authenticatedMediaRequest(): Request
{
    $request = Request::create('/admin/media');
    $request->setUserResolver(static fn (): object => (object) ['id' => 1]);

    return $request;
}

/** @param array<string, bool> $decisions */
function mediaManagerController(object $test, array $decisions, ?MediaStorageRegistry $storage = null): MediaManagerController
{
    $gate = new Gate($test->container, static fn (): null => null);
    foreach ($decisions as $ability => $allowed) {
        $gate->define($ability, static fn (object $user): bool => $allowed);
    }
    $authorization = new AuthorizationManager($gate, new AbilityRegistry);
    $validation = new ValidationFactory(new Translator(new ArrayLoader, 'en'), $test->container);
    $payloads = new class implements BuildsMediaPayloads
    {
        public function build(Request $request, bool $picker = false): array
        {
            return [];
        }
    };
    $transformers = new TransformerRegistry;
    $uploader = new MediaUploader($test->filesystems, $test->config, new MediaUploadValidator, $transformers);

    return new MediaManagerController(
        $authorization,
        $payloads,
        $uploader,
        new MediaLibrary($test->filesystems),
        $test->filesystems,
        $validation,
        $test->config,
        $storage,
    );
}
