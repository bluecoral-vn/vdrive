<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->foreignId('folder_id')->nullable()->constrained('folders')->nullOnDelete();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('mime_type')->default('application/octet-stream');
            $table->string('r2_object_key')->unique();
            $table->timestamps();

            $table->index(['folder_id', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
