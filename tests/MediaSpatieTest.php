<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Inlay\Media\Models\MediaAsset;
use Inlay\Media\Support\MediaReferenceRegistry;
use Inlay\MediaSpatie\Contracts\ConversionGenerator;
use Inlay\MediaSpatie\MediaBridge;
use Inlay\MediaSpatie\Support\CatalogAwareFileRemover;
use Inlay\MediaSpatie\Support\CatalogAwarePathGenerator;
use Inlay\MediaSpatie\Support\MediaMetadataMapper;
use Inlay\MediaSpatie\Support\SpatieReferenceResolver;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Filesystem as SpatieFilesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\FileNamer\DefaultFileNamer;
use Spatie\MediaLibrary\Support\FileRemover\DefaultFileRemover;
use Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator;
use Spatie\MediaLibrary\Support\UrlGenerator\DefaultUrlGenerator;

final class MediaSpatieTestSubject extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'subjects';

    protected $guarded = [];

    public function registerMediaConversions(?Media $media = null): void {}

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('collection-disk')->storeConversionsOnDisk('collection-conversions');
    }
}

beforeEach(function (): void {
    $this->previousApp = Container::getInstance();
    $this->mediaSpatieRoot = sys_get_temp_dir().'/inlay-media-spatie-test-'.bin2hex(random_bytes(6));
    $this->app = new Container;
    $this->config = new Repository([
        'media' => ['models' => ['asset' => MediaAsset::class]],
        'media-spatie' => [
            'reference_mode' => true,
            'idempotent_attachments' => true,
            'generate_conversions' => true,
            'default_visibility' => 'private',
            'conversions_directory' => 'derived',
            'fallback_path_generator' => DefaultPathGenerator::class,
            'fallback_file_remover' => DefaultFileRemover::class,
        ],
        'media-library' => [
            'path_generator' => CatalogAwarePathGenerator::class,
            'custom_path_generators' => [],
            'media_model' => Media::class,
            'url_generator' => DefaultUrlGenerator::class,
            'file_namer' => DefaultFileNamer::class,
            'prefix' => '',
        ],
    ]);
    $this->app->instance('config', $this->config);
    $this->app->instance(Illuminate\Contracts\Container\Container::class, $this->app);
    Container::setInstance($this->app);

    $capsule = new Capsule($this->app);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $schema = $capsule->getConnection()->getSchemaBuilder();
    $schema->create('subjects', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
    $schema->create('media', function (Blueprint $table): void {
        $table->id();
        $table->uuid('uuid')->nullable();
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
        $table->string('collection_name');
        $table->string('name');
        $table->string('file_name');
        $table->string('mime_type')->nullable();
        $table->string('disk');
        $table->string('conversions_disk')->nullable();
        $table->unsignedBigInteger('size');
        $table->json('manipulations');
        $table->json('custom_properties');
        $table->json('generated_conversions');
        $table->json('responsive_images');
        $table->unsignedInteger('order_column')->nullable();
        $table->string('relative_path')->nullable();
        $table->timestamps();
    });
    $schema->create('inlay_media_assets', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('folder_id')->nullable();
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

    $local = new LocalFilesystemAdapter($this->mediaSpatieRoot);
    $adapter = new FilesystemAdapter(new Flysystem($local), $local, ['root' => $this->mediaSpatieRoot]);
    $this->filesystems = new class($adapter) implements FilesystemFactory
    {
        public function __construct(private readonly FilesystemAdapter $adapter) {}

        public function disk($name = null): FilesystemAdapter
        {
            return $this->adapter;
        }
    };
    $this->app->instance('filesystem', $this->filesystems);
    $this->conversions = new class implements ConversionGenerator
    {
        /** @var list<int|string|null> */
        public array $generated = [];

        public function generate(Media $media): void
        {
            $this->generated[] = $media->getKey();
        }
    };
    $this->bridge = new MediaBridge(
        $this->filesystems,
        $this->config,
        $this->conversions,
        new MediaMetadataMapper,
    );
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->mediaSpatieRoot);
    Container::setInstance($this->previousApp);
});

it('attaches a catalog asset without copying its blob and is idempotent per collection', function (): void {
    $this->filesystems->disk('catalog')->write('catalog/2026/report.txt', 'report');
    $asset = MediaAsset::query()->create([
        'disk' => 'catalog',
        'path' => 'catalog/2026/report.txt',
        'file_name' => 'Quarterly report.txt',
        'mime_type' => 'text/plain',
        'extension' => 'txt',
        'size' => 6,
        'visibility' => 'private',
        'metadata' => ['name' => 'Quarterly report'],
    ]);
    $subject = MediaSpatieTestSubject::query()->create(['name' => 'Article']);
    $before = $this->filesystems->disk('catalog')->allFiles();

    $media = $this->bridge->attach($subject, $asset, 'documents', ['caption' => 'Q1']);
    $same = $this->bridge->attach($subject, $asset, 'documents');

    expect($media->getKey())->toBe($same->getKey())
        ->and(Media::query()->count())->toBe(1)
        ->and($media->getAttribute('collection_name'))->toBe('documents')
        ->and($media->getAttribute('file_name'))->toBe('report.txt')
        ->and($media->getCustomProperty(MediaBridge::ASSET_ID))->toBe($asset->getKey())
        ->and($media->getCustomProperty('caption'))->toBe('Q1')
        ->and($this->filesystems->disk('catalog')->allFiles())->toBe($before)
        ->and($this->conversions->generated)->toBe([$media->getKey()]);

    $paths = new CatalogAwarePathGenerator($this->app);
    expect($paths->getPath($media))->toBe('catalog/2026/')
        ->and($paths->getPathForConversions($media))->toContain('derived/')
        ->and($paths->getPathForConversions($media))->toEndWith('/conversions/');
});

it('keeps collections independent and maps manipulations and conversion disks', function (): void {
    $this->filesystems->disk('catalog')->write('catalog/photo.jpg', 'image');
    $asset = MediaAsset::query()->create([
        'disk' => 'catalog', 'path' => 'catalog/photo.jpg', 'file_name' => 'photo.jpg',
        'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'size' => 5,
        'visibility' => 'public', 'metadata' => [],
    ]);
    $subject = MediaSpatieTestSubject::query()->create(['name' => 'Article']);

    $avatar = $this->bridge->attach($subject, $asset, 'avatar', manipulations: ['thumb' => ['width' => 200]], conversionsDisk: 'derived-disk', generateConversions: false);
    $gallery = $this->bridge->attach($subject, $asset, 'gallery', generateConversions: false);
    $configured = $this->bridge->attach($subject, $asset, 'collection-disk', generateConversions: false);

    expect($avatar->getKey())->not->toBe($gallery->getKey())
        ->and($avatar->getAttribute('conversions_disk'))->toBe('derived-disk')
        ->and($configured->getAttribute('conversions_disk'))->toBe('collection-conversions')
        ->and($avatar->getAttribute('manipulations'))->toBe(['thumb' => ['width' => 200]])
        ->and($this->conversions->generated)->toBe([]);

    $references = new MediaReferenceRegistry;
    $references->register('spatie', new SpatieReferenceResolver);
    $usage = $references->resolve($asset);

    expect($usage)->toHaveCount(3)
        ->and($usage[0]->type)->toBe('spatie')
        ->and($usage[0]->label)->toContain('MediaSpatieTestSubject', 'avatar');
});

it('ingests Spatie media by reference and maps collection and conversion metadata', function (): void {
    $this->filesystems->disk('catalog')->write('spatie/original.png', 'image-data');
    $subject = MediaSpatieTestSubject::query()->create(['name' => 'Article']);
    /** @var Media $media */
    $media = $subject->media()->create([
        'collection_name' => 'gallery', 'name' => 'Original', 'file_name' => 'original.png',
        'mime_type' => 'image/png', 'disk' => 'catalog', 'conversions_disk' => 'conversions',
        'size' => 10, 'manipulations' => ['thumb' => ['width' => 100]],
        'custom_properties' => [CatalogAwarePathGenerator::ASSET_PATH => 'spatie/original.png', 'alt' => 'Example'],
        'generated_conversions' => ['thumb' => true], 'responsive_images' => ['urls' => []],
        'order_column' => 3, 'relative_path' => 'spatie/original.png',
    ]);
    $before = $this->filesystems->disk('catalog')->allFiles();

    $asset = $this->bridge->ingest($media);
    $same = $this->bridge->ingest($media);

    expect($asset->getKey())->toBe($same->key())
        ->and(MediaAsset::query()->count())->toBe(1)
        ->and($asset->path())->toBe('spatie/original.png')
        ->and($asset->metadata()['spatie'])->toMatchArray([
            'id' => $media->getKey(),
            'collection' => 'gallery',
            'conversions_disk' => 'conversions',
            'order' => 3,
            'generated_conversions' => ['thumb' => true],
        ])
        ->and($this->filesystems->disk('catalog')->allFiles())->toBe($before);
});

it('preserves linked originals when Spatie removes media', function (): void {
    $this->filesystems->disk('catalog')->write('catalog/report.txt', 'original');
    $subject = MediaSpatieTestSubject::query()->create(['name' => 'Article']);
    /** @var Media $media */
    $media = $subject->media()->create([
        'collection_name' => 'documents',
        'disk' => 'catalog',
        'conversions_disk' => 'derived',
        'file_name' => 'report.txt',
        'name' => 'report',
        'mime_type' => 'text/plain',
        'size' => 8,
        'manipulations' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
        'custom_properties' => [CatalogAwarePathGenerator::ASSET_PATH => 'catalog/report.txt'],
    ]);
    $remover = new CatalogAwareFileRemover(new SpatieFilesystem($this->filesystems), $this->filesystems);

    $remover->removeAllFiles($media);

    expect($this->filesystems->disk('catalog')->exists('catalog/report.txt'))->toBeTrue();
});

it('preserves default Spatie paths and removal for media that is not catalog linked', function (): void {
    $subject = MediaSpatieTestSubject::query()->create(['name' => 'Article']);
    /** @var Media $media */
    $media = $subject->media()->create([
        'collection_name' => 'documents', 'disk' => 'catalog', 'conversions_disk' => 'catalog',
        'file_name' => 'native.txt', 'name' => 'native', 'mime_type' => 'text/plain', 'size' => 6,
        'manipulations' => [], 'generated_conversions' => [], 'responsive_images' => [], 'custom_properties' => [],
    ]);
    $path = $media->getKey().'/native.txt';
    $this->filesystems->disk('catalog')->write($path, 'native');
    $paths = new CatalogAwarePathGenerator($this->app);

    expect($paths->getPath($media))->toBe($media->getKey().'/');

    (new CatalogAwareFileRemover(new SpatieFilesystem($this->filesystems), $this->filesystems))->removeAllFiles($media);
    expect($this->filesystems->disk('catalog')->exists($path))->toBeFalse();
});

it('rejects missing blobs and unsafe reference paths', function (): void {
    $subject = MediaSpatieTestSubject::query()->create(['name' => 'Article']);
    $missing = MediaAsset::query()->create([
        'disk' => 'catalog', 'path' => 'catalog/missing.txt', 'file_name' => 'missing.txt',
        'mime_type' => 'text/plain', 'extension' => 'txt', 'size' => 1,
        'visibility' => 'private', 'metadata' => [],
    ]);

    expect(fn () => $this->bridge->attach($subject, $missing))->toThrow(RuntimeException::class, 'does not exist');

    $unsafe = clone $missing;
    $unsafe->setAttribute('path', '../secret.txt');
    expect(fn () => $this->bridge->attach($subject, $unsafe))->toThrow(InvalidArgumentException::class, 'unsafe');
});
