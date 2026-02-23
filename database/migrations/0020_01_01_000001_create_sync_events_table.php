<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('action');     // create, update, move, delete, restore, purge
            $table->string('resource_type'); // file, folder
            $table->string('resource_id');   // UUID for files, int for folders
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Composite index for efficient cursor-based delta queries
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_events');
    }
};
