<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inlay_media_folders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('inlay_media_folders')->nullOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['parent_id', 'name']);
        });

        Schema::create('inlay_media_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('folder_id')->nullable()->constrained('inlay_media_folders')->nullOnDelete();
            $table->string('disk');
            $table->string('path', 1024);
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

    public function down(): void
    {
        Schema::dropIfExists('inlay_media_assets');
        Schema::dropIfExists('inlay_media_folders');
    }
};
