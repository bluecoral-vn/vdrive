<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prevent duplicate folder/file names at the same level for the same owner.
 *
 * Folders: unique(owner_id, parent_id, name) — no two folders with the same
 *          name under the same parent for the same user.
 * Files:   unique(owner_id, folder_id, name) — no two files with the same
 *          name in the same folder for the same user.
 *
 * MySQL treats NULLs as distinct in unique indexes, so root-level items
 * (parent_id/folder_id = NULL) are handled correctly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            $table->unique(['owner_id', 'parent_id', 'name'], 'folders_owner_parent_name_unique');
        });

        Schema::table('files', function (Blueprint $table) {
            $table->unique(['owner_id', 'folder_id', 'name'], 'files_owner_folder_name_unique');
        });
    }

    public function down(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            $table->dropUnique('folders_owner_parent_name_unique');
        });

        Schema::table('files', function (Blueprint $table) {
            $table->dropUnique('files_owner_folder_name_unique');
        });
    }
};
