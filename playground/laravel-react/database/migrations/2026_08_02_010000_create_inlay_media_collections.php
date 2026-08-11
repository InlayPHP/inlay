<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inlay_media_collections', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique('name');
        });

        Schema::create('inlay_media_collection_asset', function (Blueprint $table): void {
            $table->foreignId('collection_id')->constrained('inlay_media_collections')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('inlay_media_assets')->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['collection_id', 'asset_id']);
            $table->index('asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inlay_media_collection_asset');
        Schema::dropIfExists('inlay_media_collections');
    }
};
