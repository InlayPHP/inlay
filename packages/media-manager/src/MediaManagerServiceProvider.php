<?php

declare(strict_types=1);

namespace Inlay\MediaManager;

use Illuminate\Support\ServiceProvider;
use Inlay\MediaManager\Contracts\BuildsMediaPayloads;
use Inlay\MediaManager\Support\MediaPayloadBuilder;

final class MediaManagerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/media-manager.php', 'media-manager');
        $this->app->bind(BuildsMediaPayloads::class, MediaPayloadBuilder::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/media-manager.php' => config_path('media-manager.php'),
        ], 'inlay-media-manager-config');
    }
}
