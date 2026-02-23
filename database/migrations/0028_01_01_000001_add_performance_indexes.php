<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add performance indexes for delta-adjacent queries.
 *
 * - (owner_id, updated_at) on files — efficient per-user file change queries
 * - (owner_id, updated_at) on folders — efficient per-user folder change queries
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->index(['owner_id', 'updated_at'], 'files_owner_updated_at_index');
        });

        Schema::table('folders', function (Blueprint $table) {
            $table->index(['owner_id', 'updated_at'], 'folders_owner_updated_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropIndex('files_owner_updated_at_index');
        });

        Schema::table('folders', function (Blueprint $table) {
            $table->dropIndex('folders_owner_updated_at_index');
        });
    }
};
