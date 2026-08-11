<?php

declare(strict_types=1);

namespace Inlay\Media;

use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Inlay\Media\Support\FilesystemStorageBrowser;
use Inlay\Media\Support\MediaReferenceRegistry;
use Inlay\Media\Support\MediaStorageRegistry;
use Inlay\Media\Support\TransformerRegistry;

final class MediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/media.php', 'media');
        $this->app->singleton(MediaReferenceRegistry::class);
        $this->app->singleton(MediaStorageRegistry::class, function (): MediaStorageRegistry {
            return (new MediaStorageRegistry)->register('filesystem', new FilesystemStorageBrowser(
                $this->app->make(Filesystems::class),
                $this->app->make(Config::class),
            ));
        });
        $this->app->singleton(TransformerRegistry::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/media.php' => config_path('media.php'),
        ], 'inlay-media-config');
        $this->publishesMigrations([
            __DIR__.'/../database/migrations/2026_01_01_000000_create_inlay_media_tables.php' => database_path('migrations/2026_01_01_000000_create_inlay_media_tables.php'),
            __DIR__.'/../database/migrations/2026_08_02_010000_create_inlay_media_collections.php' => database_path('migrations/2026_08_02_010000_create_inlay_media_collections.php'),
        ], 'inlay-media-migrations');
    }
}
