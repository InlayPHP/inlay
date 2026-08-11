<?php

declare(strict_types=1);

namespace Inlay\MediaSpatie;

use Illuminate\Support\ServiceProvider;
use Inlay\Media\Support\MediaReferenceRegistry;
use Inlay\MediaSpatie\Contracts\ConversionGenerator;
use Inlay\MediaSpatie\Support\CatalogAwareFileRemover;
use Inlay\MediaSpatie\Support\CatalogAwarePathGenerator;
use Inlay\MediaSpatie\Support\SpatieConversionGenerator;
use Inlay\MediaSpatie\Support\SpatieReferenceResolver;

final class MediaSpatieServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/media-spatie.php', 'media-spatie');
        $this->app->bind(ConversionGenerator::class, SpatieConversionGenerator::class);
    }

    public function boot(): void
    {
        if ((bool) config('media-spatie.reference_resolver', true)) {
            $this->app->make(MediaReferenceRegistry::class)->register('spatie', new SpatieReferenceResolver);
        }

        if ((bool) config('media-spatie.reference_mode', true)) {
            $currentPathGenerator = config('media-library.path_generator');
            $currentFileRemover = config('media-library.file_remover_class');
            $customPathGenerators = config('media-library.custom_path_generators', []);
            config()->set(
                'media-spatie.fallback_path_generator',
                config('media-spatie.fallback_path_generator')
                    ?: ($currentPathGenerator === CatalogAwarePathGenerator::class ? null : $currentPathGenerator),
            );
            config()->set(
                'media-spatie.fallback_file_remover',
                config('media-spatie.fallback_file_remover')
                    ?: ($currentFileRemover === CatalogAwareFileRemover::class ? null : $currentFileRemover),
            );
            if (is_array($customPathGenerators)) {
                config()->set('media-spatie.fallback_path_generators', array_merge(
                    $customPathGenerators,
                    (array) config('media-spatie.fallback_path_generators', []),
                ));
                config()->set('media-library.custom_path_generators', array_fill_keys(
                    array_keys($customPathGenerators),
                    CatalogAwarePathGenerator::class,
                ));
            }
            config()->set('media-library.path_generator', config('media-spatie.path_generator', CatalogAwarePathGenerator::class));
            config()->set('media-library.file_remover_class', config('media-spatie.file_remover', CatalogAwareFileRemover::class));
        }

        $this->publishes([
            __DIR__.'/../config/media-spatie.php' => config_path('media-spatie.php'),
        ], 'inlay-media-spatie-config');
    }
}
