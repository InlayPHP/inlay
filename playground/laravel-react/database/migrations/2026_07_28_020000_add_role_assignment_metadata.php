<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('permission.table_names.model_has_roles'), function (Blueprint $table): void {
            $table->string('assignment_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table(config('permission.table_names.model_has_roles'), function (Blueprint $table): void {
            $table->dropColumn('assignment_note');
        });
    }
};
