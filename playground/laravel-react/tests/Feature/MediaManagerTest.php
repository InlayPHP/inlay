<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Inlay\Media\Models\MediaAsset;
use Inlay\Media\Models\MediaFolder;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Storage::fake('local');
    config()->set('media.disk', 'local');
    $this->administrator = User::factory()->create(['role' => 'admin', 'active' => true]);
    $this->administrator->assignRole(Role::findOrCreate('super-admin'));
    $this->actingAs($this->administrator);
});

it('registers the complete protected media manager route set', function () {
    expect(Route::has('inlay.admin.media.index'))->toBeTrue()
        ->and(Route::has('inlay.admin.media.upload'))->toBeTrue()
        ->and(Route::has('inlay.admin.media.assets.update'))->toBeTrue()
        ->and(Route::has('inlay.admin.media.assets.delivery'))->toBeTrue()
        ->and(Route::has('inlay.admin.media.folders.store'))->toBeTrue();
});

it('renders the versioned media browser contract through the panel', function () {
    $folder = MediaFolder::query()->create(['name' => 'Brand']);
    MediaAsset::query()->create([
        'folder_id' => $folder->getKey(),
        'disk' => 'local',
        'path' => 'media/brand/logo.png',
        'file_name' => 'logo.png',
        'mime_type' => 'image/png',
        'extension' => 'png',
        'size' => 128,
        'visibility' => 'private',
        'metadata' => ['alt' => 'Brand logo'],
    ]);

    $this->get('/admin/media?folder_id='.$folder->getKey())
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('inlay-media-manager/index')
            ->where('media.contract', 'inlay.media-manager.v1')
            ->where('media.currentFolderId', $folder->getKey())
            ->where('media.assets.data.0.file_name', 'logo.png')
            ->has('media.endpoints.upload'));
});

it('renders the same media manager through the Vue panel shell', function () {
    $this->get('/vue/media')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('inlay-media-manager/index')
            ->where('media.contract', 'inlay.media-manager.v1')
            ->where('inlayPanel.contract', 'inlay.panels.v1'));
});

it('securely uploads and catalogs media through the plugin endpoint', function () {
    $response = $this->post('/admin/media/assets', [
        'file' => UploadedFile::fake()->image('avatar.png', 20, 20),
        'visibility' => 'private',
        'metadata' => ['alt' => 'Avatar'],
    ]);

    $response->assertCreated()->assertJsonStructure(['id']);
    $asset = MediaAsset::query()->firstOrFail();

    expect($asset->file_name)->toBe('avatar.png')
        ->and($asset->metadata['alt'])->toBe('Avatar')
        ->and(Storage::disk('local')->exists($asset->path))->toBeTrue();
});

it('authorizes and streams signed private raster previews inline', function () {
    Storage::disk('local')->put('media/preview.png', 'preview bytes');
    $asset = MediaAsset::query()->create([
        'disk' => 'local',
        'path' => 'media/preview.png',
        'file_name' => 'preview.png',
        'mime_type' => 'image/png',
        'extension' => 'png',
        'size' => 13,
        'visibility' => 'private',
        'metadata' => [],
    ]);
    $url = URL::temporarySignedRoute(
        'inlay.admin.media.assets.delivery',
        now()->addMinute(),
        ['asset' => $asset->getKey()],
    );

    $this->get($url)
        ->assertOk()
        ->assertHeader('content-type', 'image/png')
        ->assertHeader('content-disposition', 'inline; filename=preview.png');
});

it('denies the media library to panel admins without media permissions', function () {
    $regularAdmin = User::factory()->create(['role' => 'admin', 'active' => true]);

    $this->actingAs($regularAdmin)->get('/admin/media')->assertForbidden();
});
