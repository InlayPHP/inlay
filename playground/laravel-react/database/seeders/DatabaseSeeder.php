<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Inlay\Media\Models\MediaAsset;
use Inlay\Media\Models\MediaFolder;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Keep the reorder demo deterministic. A zero value for every seeded
        // row makes the table's initial order depend on the database driver
        // and hides the position column's purpose until the first drag.
        $firstPosition = (int) User::query()->max('position') + 1;
        User::factory(17)
            ->sequence(fn (Sequence $sequence): array => [
                'position' => $firstPosition + $sequence->index,
            ])
            ->create();

        $administrator = User::query()->updateOrCreate([
            'email' => 'test@example.com',
        ], [
            'name' => 'Test User',
            'role' => 'admin',
            'status' => 'active',
            'active' => true,
            'password' => 'password',
            'position' => $firstPosition + 17,
        ]);

        $administrator->assignRole(Role::findOrCreate('super-admin'));
        Role::findOrCreate('editor');
        Role::findOrCreate('reviewer');

        $folder = MediaFolder::query()->firstOrCreate(['name' => 'Demo assets', 'parent_id' => null]);
        $disk = (string) config('media.disk', 'local');
        $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        if (is_string($image)) {
            Storage::disk($disk)->put('media/demo/inlay-blue.png', $image, ['visibility' => 'private']);
            MediaAsset::query()->updateOrCreate([
                'disk' => $disk,
                'path' => 'media/demo/inlay-blue.png',
            ], [
                'folder_id' => $folder->getKey(),
                'file_name' => 'inlay-blue.png',
                'mime_type' => 'image/png',
                'extension' => 'png',
                'size' => strlen($image),
                'visibility' => 'private',
                'metadata' => ['alt' => 'Inlay blue sample', 'width' => 1, 'height' => 1],
            ]);
        }

        $document = "Welcome to the Inlay media library.\n";
        Storage::disk($disk)->put('media/demo/readme.txt', $document, ['visibility' => 'private']);
        MediaAsset::query()->updateOrCreate([
            'disk' => $disk,
            'path' => 'media/demo/readme.txt',
        ], [
            'folder_id' => $folder->getKey(),
            'file_name' => 'readme.txt',
            'mime_type' => 'text/plain',
            'extension' => 'txt',
            'size' => strlen($document),
            'visibility' => 'private',
            'metadata' => ['caption' => 'A seeded document for the package demo.'],
        ]);
    }
}
