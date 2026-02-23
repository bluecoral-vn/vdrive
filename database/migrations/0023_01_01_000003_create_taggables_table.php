<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taggables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->string('resource_type', 10); // 'file' or 'folder'
            $table->string('resource_id', 36);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tag_id', 'resource_type', 'resource_id'], 'taggables_unique');
            $table->index(['resource_type', 'resource_id'], 'taggables_resource_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taggables');
    }
};
