<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'position')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedInteger('position')->default(0)->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'position')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('position');
        });
    }
};
