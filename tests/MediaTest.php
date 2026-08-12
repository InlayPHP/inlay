<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Inlay\Media\Contracts\MediaAssetContract;
use Inlay\Media\Contracts\MediaTransformer;
use Inlay\Media\Enums\MediaVisibility;
use Inlay\Media\Exceptions\MediaValidationException;
use Inlay\Media\Jobs\TransformMediaAsset;
use Inlay\Media\Models\MediaAsset;
use Inlay\Media\Models\MediaCollection;
use Inlay\Media\Models\MediaFolder;
use Inlay\Media\Services\MediaLibrary;
use Inlay\Media\Services\MediaUploader;
use Inlay\Media\Services\MediaUploadValidator;
use Inlay\Media\Support\TransformerRegistry;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

beforeEach(function (): void {
    $this->mediaRoot = sys_get_temp_dir().'/inlay-media-test-'.bin2hex(random_bytes(6));
    $this->app = new Container;
    $this->config = new Repository([
        'filesystems' => [
            'default' => 'media-test',
            'disks' => [
                'media-test' => [
                    'driver' => 'local',
                    'root' => $this->mediaRoot,
                    'throw' => false,
                ],
            ],
        ],
        'media' => [
            'disk' => 'media-test',
            'directory' => 'library',
            'visibility' => 'private',
            'max_size_kb' => 4,
            'allowed_mime_types' => ['text/plain'],
            'allowed_extensions' => ['txt'],
            'models' => [
                'asset' => MediaAsset::class,
                'collection' => MediaCollection::class,
                'folder' => MediaFolder::class,
            ],
        ],
    ]);
    $this->app->instance('config', $this->config);
    Container::setInstance($this->app);

    $capsule = new Capsule($this->app);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $this->database = $capsule->getConnection();
    $schema = $this->database->getSchemaBuilder();
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
        $table->unique(['disk', 'path']);
    });
    $schema->create('inlay_media_collections', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->unique();
        $table->text('description')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('inlay_media_collection_asset', function (Blueprint $table): void {
        $table->foreignId('collection_id');
        $table->foreignId('asset_id');
        $table->timestamps();
        $table->primary(['collection_id', 'asset_id']);
    });

    $localAdapter = new LocalFilesystemAdapter($this->mediaRoot);
    $filesystem = new FilesystemAdapter(
        new Flysystem($localAdapter),
        $localAdapter,
        ['root' => $this->mediaRoot],
    );
    $this->filesystems = new class($filesystem) implements FilesystemFactory
    {
        public function __construct(private readonly FilesystemAdapter $filesystem) {}

        public function disk($name = null): FilesystemAdapter
        {
            return $this->filesystem;
        }
    };
    $this->transformers = new TransformerRegistry;
    $this->uploader = new MediaUploader(
        $this->filesystems,
        $this->config,
        new MediaUploadValidator,
        $this->transformers,
    );
    $this->library = new MediaLibrary($this->filesystems);
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->mediaRoot);
});

it('securely stores an upload under a randomized path and catalogs its metadata', function (): void {
    $folder = MediaFolder::query()->create(['name' => 'Documents']);
    $asset = $this->uploader->upload(
        UploadedFile::fake()->createWithContent('../quarterly.txt', 'safe content'),
        $folder,
        ['alt' => 'Quarterly report'],
    );

    expect($asset)->toBeInstanceOf(MediaAsset::class)
        ->and($asset->folder_id)->toBe($folder->getKey())
        ->and($asset->file_name)->toBe('quarterly.txt')
        ->and($asset->path())->toMatch('/^library\/\d{4}\/\d{2}\/[0-9a-f-]{36}\.txt$/')
        ->and($asset->mimeType())->toBe('text/plain')
        ->and($asset->visibility())->toBe(MediaVisibility::Private)
        ->and($asset->metadata())->toBe(['alt' => 'Quarterly report'])
        ->and($this->filesystems->disk('media-test')->exists($asset->path()))->toBeTrue();
});

it('rejects disallowed MIME types, extensions, and oversized uploads before storage', function (): void {
    $this->config->set('media.max_size_kb', 1);

    try {
        $this->uploader->upload(UploadedFile::fake()->create('payload.exe', 2, 'application/x-msdownload'));
        $this->fail('Expected upload validation to fail.');
    } catch (MediaValidationException $exception) {
        expect(array_keys($exception->errors()))->toContain('size', 'mime_type', 'extension')
            ->and(MediaAsset::query()->count())->toBe(0)
            ->and($this->filesystems->disk('media-test')->allFiles())->toBe([]);
    }
});

it('rejects unsafe configured storage prefixes', function (): void {
    $this->config->set('media.directory', '../outside');

    $this->uploader->upload(UploadedFile::fake()->createWithContent('notes.txt', 'notes'));
})->throws(RuntimeException::class, 'configured media directory is unsafe');

it('removes the stored object if catalog persistence fails', function (): void {
    $recursive = [];
    $recursive['self'] = &$recursive;

    try {
        $this->uploader->upload(
            UploadedFile::fake()->createWithContent('notes.txt', 'notes'),
            metadata: $recursive,
        );
        $this->fail('Expected metadata serialization to fail.');
    } catch (Throwable) {
        expect(MediaAsset::query()->count())->toBe(0)
            ->and($this->filesystems->disk('media-test')->allFiles())->toBe([]);
    }
});

it('runs compatible transformation extensions after the asset is persisted', function (): void {
    $transformer = new class implements MediaTransformer
    {
        public array $ids = [];

        public function supports(MediaAssetContract $asset): bool
        {
            return $asset->mimeType() === 'text/plain';
        }

        public function transform(MediaAssetContract $asset): void
        {
            $this->ids[] = $asset->key();
        }
    };
    $this->transformers->register($transformer);

    $asset = $this->uploader->upload(UploadedFile::fake()->createWithContent('notes.txt', 'notes'));

    expect($transformer->ids)->toBe([$asset->getKey()]);
});

it('can queue transformations with only the asset identity in the job payload', function (): void {
    $transformer = new class implements MediaTransformer
    {
        public array $ids = [];

        public function supports(MediaAssetContract $asset): bool
        {
            return true;
        }

        public function transform(MediaAssetContract $asset): void
        {
            $this->ids[] = $asset->key();
        }
    };
    $this->transformers->register($transformer);
    $this->config->set('media.transformations.async', true);
    $this->config->set('media.transformations.connection', 'redis');
    $this->config->set('media.transformations.queue', 'media-transforms');

    $dispatcher = new class implements Dispatcher
    {
        public array $commands = [];

        public function dispatch($command)
        {
            $this->commands[] = $command;

            return $command;
        }

        public function dispatchSync($command, $handler = null)
        {
            return $this->dispatch($command);
        }

        public function dispatchNow($command, $handler = null)
        {
            return $this->dispatch($command);
        }

        public function dispatchAfterResponse($command, $handler = null): void
        {
            $this->dispatch($command);
        }

        public function chain($jobs = null)
        {
            return $jobs;
        }

        public function hasCommandHandler($command): bool
        {
            return false;
        }

        public function getCommandHandler($command)
        {
            return null;
        }

        public function pipeThrough(array $pipes): static
        {
            return $this;
        }

        public function map(array $map): static
        {
            return $this;
        }
    };
    $uploader = new MediaUploader(
        $this->filesystems,
        $this->config,
        new MediaUploadValidator,
        $this->transformers,
        $dispatcher,
    );

    $asset = $uploader->upload(UploadedFile::fake()->createWithContent('notes.txt', 'notes'));

    expect($transformer->ids)->toBe([])
        ->and($dispatcher->commands)->toHaveCount(1)
        ->and($dispatcher->commands[0])->toBeInstanceOf(TransformMediaAsset::class);
    $job = $dispatcher->commands[0];

    expect($job)
        ->and($job->model)->toBe(MediaAsset::class)
        ->and($job->assetId)->toBe($asset->getKey())
        ->and($job->connection)->toBe('redis')
        ->and($job->queue)->toBe('media-transforms');

    $job->handle($this->transformers);

    expect($transformer->ids)->toBe([$asset->getKey()]);
});

it('moves assets logically without changing their physical object key', function (): void {
    $first = MediaFolder::query()->create(['name' => 'First']);
    $second = MediaFolder::query()->create(['name' => 'Second']);
    $asset = $this->uploader->upload(UploadedFile::fake()->createWithContent('notes.txt', 'notes'), $first);
    $path = $asset->path();

    $this->library->moveAsset($asset, $second);

    expect($asset->folder_id)->toBe($second->getKey())
        ->and($asset->path())->toBe($path)
        ->and($this->filesystems->disk('media-test')->exists($path))->toBeTrue();
});

it('supports reusable named collections without changing an asset folder', function (): void {
    $folder = MediaFolder::query()->create(['name' => 'Images']);
    $asset = $this->uploader->upload(UploadedFile::fake()->createWithContent('notes.txt', 'notes'), $folder);
    $collection = $this->library->createCollection('Homepage', 'Files used on the homepage.');
    $second = $this->library->createCollection('Campaign');

    $this->library->syncCollections($asset, [$collection->getKey(), $second->getKey()]);

    expect($asset->fresh()->folder_id)->toBe($folder->getKey())
        ->and($asset->fresh()->collections->pluck('name')->sort()->values()->all())->toBe(['Campaign', 'Homepage']);

    $this->library->syncCollections($asset, [$second->getKey()]);
    expect($asset->fresh()->collections->pluck('name')->all())->toBe(['Campaign']);

    $this->library->deleteCollection($second);
    expect(MediaCollection::withTrashed()->find($second->getKey())->trashed())->toBeTrue()
        ->and($asset->fresh()->collections)->toHaveCount(0);
});

it('prevents cyclic folder hierarchies', function (): void {
    $parent = MediaFolder::query()->create(['name' => 'Parent']);
    $child = MediaFolder::query()->create(['name' => 'Child', 'parent_id' => $parent->getKey()]);

    $this->library->moveFolder($parent, $child);
})->throws(InvalidArgumentException::class, 'descendants');

it('trashes and restores catalog records without deleting physical files', function (): void {
    $asset = $this->uploader->upload(UploadedFile::fake()->createWithContent('notes.txt', 'notes'));

    $this->library->trash($asset);
    expect($asset->trashed())->toBeTrue()
        ->and($this->filesystems->disk('media-test')->exists($asset->path()))->toBeTrue();

    $this->library->restore($asset);
    expect($asset->trashed())->toBeFalse();
});

it('synchronizes storage and catalog visibility', function (): void {
    $asset = $this->uploader->upload(UploadedFile::fake()->createWithContent('notes.txt', 'notes'));

    $this->library->setVisibility($asset, MediaVisibility::Public);

    expect($asset->fresh()->visibility())->toBe(MediaVisibility::Public)
        ->and($this->filesystems->disk('media-test')->visibility($asset->path()))->toBe('public');
});

it('permanently deletes both the physical object and its catalog record', function (): void {
    $asset = $this->uploader->upload(UploadedFile::fake()->createWithContent('notes.txt', 'notes'));
    $id = $asset->getKey();
    $path = $asset->path();

    $this->library->permanentlyDelete($asset);

    expect($this->filesystems->disk('media-test')->exists($path))->toBeFalse()
        ->and(MediaAsset::withTrashed()->find($id))->toBeNull();
});
