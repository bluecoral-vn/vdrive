<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shares', function (Blueprint $table) {
            $table->foreignId('folder_id')->nullable()->after('file_id')
                ->constrained('folders')->cascadeOnDelete();

            $table->index(['shared_with', 'folder_id']);
            $table->index('folder_id');
        });

        // Make file_id nullable (share can target file OR folder)
        Schema::table('shares', function (Blueprint $table) {
            $table->uuid('file_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('shares', function (Blueprint $table) {
            $table->dropForeign(['folder_id']);
            $table->dropColumn('folder_id');
        });
    }
};
