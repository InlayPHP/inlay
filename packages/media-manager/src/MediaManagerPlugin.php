<?php

declare(strict_types=1);

namespace Inlay\MediaManager;

use Inlay\Authorization\AbilityDefinition;
use Inlay\Core\Contracts\Plugin;
use Inlay\Core\PluginContext;
use Inlay\MediaManager\Http\Controllers\MediaManagerController;
use Inlay\NavigationItem;
use Inlay\Panel;
use Inlay\PanelRoute;

final class MediaManagerPlugin implements Plugin
{
    public static function make(): self
    {
        return new self;
    }

    public function id(): string
    {
        return 'inlay.media-manager';
    }

    public function register(PluginContext $context): void
    {
        $panel = $context->hostAs(Panel::class);
        $mutationMiddleware = $panel->resourceMutationMiddlewareList();

        $panel
            ->abilities(self::abilityDefinitions(), $this->id())
            ->navigationItem(
                NavigationItem::make('media-manager')
                    ->label('Media')
                    ->icon('photo')
                    ->ability('media.viewAny')
                    ->url($panel->pathValue().'/media')
                    ->activeWhen('mediaManager', true)
                    ->sort(50),
            )
            ->routes([
                PanelRoute::get('media.index', 'media', [MediaManagerController::class, 'index']),
                PanelRoute::get('media.picker', 'media/picker', [MediaManagerController::class, 'picker']),
                PanelRoute::get('media.storage.browse', 'media/storage', [MediaManagerController::class, 'browseStorage']),
                PanelRoute::post('media.upload', 'media/assets', [MediaManagerController::class, 'upload'])->middleware($mutationMiddleware),
                PanelRoute::patch('media.assets.update', 'media/assets/{asset}', [MediaManagerController::class, 'updateAsset'])->middleware($mutationMiddleware),
                PanelRoute::patch('media.assets.move', 'media/assets/{asset}/move', [MediaManagerController::class, 'moveAsset'])->middleware($mutationMiddleware),
                PanelRoute::patch('media.assets.collections.sync', 'media/assets/{asset}/collections', [MediaManagerController::class, 'syncAssetCollections'])->middleware($mutationMiddleware),
                PanelRoute::delete('media.assets.trash', 'media/assets/{asset}', [MediaManagerController::class, 'trashAsset'])->middleware($mutationMiddleware),
                PanelRoute::post('media.assets.restore', 'media/assets/{asset}/restore', [MediaManagerController::class, 'restoreAsset'])->middleware($mutationMiddleware),
                PanelRoute::delete('media.assets.destroy', 'media/assets/{asset}/force', [MediaManagerController::class, 'destroyAsset'])->middleware($mutationMiddleware),
                PanelRoute::get('media.assets.delivery', 'media/assets/{asset}/delivery', [MediaManagerController::class, 'deliverAsset'])
                    ->middleware(['signed']),
                PanelRoute::post('media.folders.store', 'media/folders', [MediaManagerController::class, 'storeFolder'])->middleware($mutationMiddleware),
                PanelRoute::patch('media.folders.move', 'media/folders/{folder}/move', [MediaManagerController::class, 'moveFolder'])->middleware($mutationMiddleware),
                PanelRoute::delete('media.folders.destroy', 'media/folders/{folder}', [MediaManagerController::class, 'destroyFolder'])->middleware($mutationMiddleware),
                PanelRoute::post('media.collections.store', 'media/collections', [MediaManagerController::class, 'storeCollection'])->middleware($mutationMiddleware),
                PanelRoute::patch('media.collections.update', 'media/collections/{collection}', [MediaManagerController::class, 'updateCollection'])->middleware($mutationMiddleware),
                PanelRoute::delete('media.collections.destroy', 'media/collections/{collection}', [MediaManagerController::class, 'destroyCollection'])->middleware($mutationMiddleware),
            ]);
    }

    public function boot(PluginContext $context): void {}

    /** @return list<AbilityDefinition> */
    public static function abilityDefinitions(): array
    {
        return [
            AbilityDefinition::make('media.viewAny')->group('Media library')->description('Browse and search media'),
            AbilityDefinition::make('media.pick')->group('Media library')->description('Use the media picker'),
            AbilityDefinition::make('media.upload')->group('Media library')->description('Upload media'),
            AbilityDefinition::make('media.update')->group('Media library')->description('Update metadata, folders, and visibility'),
            AbilityDefinition::make('media.delete')->group('Media library')->description('Move media to trash')->dangerous(),
            AbilityDefinition::make('media.restore')->group('Media library')->description('Restore trashed media'),
            AbilityDefinition::make('media.forceDelete')->group('Media library')->description('Permanently delete trashed media')->dangerous(),
            AbilityDefinition::make('media.download')->group('Media library')->description('Deliver private media'),
            AbilityDefinition::make('media.browseStorage')->group('Media library')->description('Browse configured storage disks before importing files'),
            AbilityDefinition::make('media.manageFolders')->group('Media library')->description('Create, move, and delete folders'),
            AbilityDefinition::make('media.manageCollections')->group('Media library')->description('Create, edit, and delete media albums'),
        ];
    }
}
