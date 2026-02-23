<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('resource_type', 10); // 'file' or 'folder'
            $table->string('resource_id', 36);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'resource_type', 'resource_id'], 'user_favorites_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_favorites');
    }
};
