<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inlay_table_views', function (Blueprint $table): void {
            $table->id();
            $table->string('table_name', 120);
            $table->string('owner_key', 191);
            $table->string('name', 64);
            $table->string('label', 120);
            $table->string('description', 240)->nullable();
            $table->json('query');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['table_name', 'owner_key', 'name'], 'inlay_table_views_owner_name_unique');
            $table->index(['table_name', 'owner_key', 'is_default'], 'inlay_table_views_owner_default_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inlay_table_views');
    }
};
