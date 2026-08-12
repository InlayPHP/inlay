<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Laravel 12/13 does not expose Schema::createIfNotExists(). Guarding
        // with hasTable() gives that same behavior when a previous deploy
        // created the table before the migration was recorded.
        if (! Schema::hasTable('inlay_media_folders')) {
            Schema::create('inlay_media_folders', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('parent_id')->nullable()->constrained('inlay_media_folders')->nullOnDelete();
                $table->string('name');
                $table->timestamps();
                $table->softDeletes();
                $table->index(['parent_id', 'name']);
            });
        }

        if (! Schema::hasTable('inlay_media_assets')) {
            Schema::create('inlay_media_assets', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('folder_id')->nullable()->constrained('inlay_media_folders')->nullOnDelete();
                // Keep the composite unique key within MySQL's 3072-byte limit
                // under utf8mb4 while leaving room for normal object-storage keys.
                $table->string('disk', 50);
                $table->string('path', 500);
                $table->string('file_name');
                $table->string('mime_type', 191);
                $table->string('extension', 32);
                $table->unsignedBigInteger('size');
                $table->string('visibility', 16)->default('private');
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['disk', 'path']);
                $table->index(['folder_id', 'mime_type']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inlay_media_assets');
        Schema::dropIfExists('inlay_media_folders');
    }
};
